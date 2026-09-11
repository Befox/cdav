"""Run CDav lint and focused tests; ERP services are simulated, Sabre is real.

Pass --core-htdocs once per installed/extracted Dolibarr version to test.
No core checkout is modified. Test fixtures stay in the module's .test-cache.
"""
import argparse
import re
import subprocess
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


def run(command, marker=None):
    result = subprocess.run(command, cwd=ROOT, capture_output=True, text=True, encoding="utf-8", errors="replace")
    if result.returncode or (marker and marker not in result.stdout):
        raise SystemExit(f"FAILED: {' '.join(map(str, command))}\n{result.stdout}\n{result.stderr}")
    if result.stderr:
        print(result.stderr.strip())  # Keep core/library warnings visible.
    if not marker:
        print(result.stdout.strip())


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--php", default="php")
    parser.add_argument("--php-arg", action="append", default=[], help="Extra PHP CLI argument (repeatable, e.g. --php-arg=-d --php-arg=extension=pdo_sqlite)")
    parser.add_argument("--core-htdocs", action="append", type=Path, default=[])
    args = parser.parse_args()
    php = [args.php, *args.php_arg]
    files = sorted(p for p in ROOT.rglob("*.php") if not any(part in {".git", ".test-cache", "vendor"} for part in p.relative_to(ROOT).parts))
    for path in files:
        run([*php, "-l", str(path)], "No syntax errors detected")
    print(f"PHP lint passed: {len(files)} files.")
    catalogs = {}
    for locale in ("en_US", "fr_FR", "de_DE", "es_ES", "it_IT"):
        translations = {}
        for line in (ROOT / "langs" / locale / "cdav.lang").read_text(encoding="utf-8").splitlines():
            if line.startswith("#") or "=" not in line:
                continue
            key, value = line.split("=", 1)
            if key in translations or not value.strip():
                raise SystemExit(f"Duplicate or empty translation: {locale}/{key}")
            translations[key] = value
        catalogs[locale] = translations
    expected = set(catalogs["en_US"])
    for locale, translations in catalogs.items():
        if set(translations) != expected:
            raise SystemExit(f"Translation parity: {locale}: {set(translations) ^ expected}")
        for key, value in translations.items():
            if re.findall(r"%(?:\d+\$)?[sdf]", value) != re.findall(r"%(?:\d+\$)?[sdf]", catalogs["en_US"][key]):
                raise SystemExit(f"Translation placeholders: {locale}/{key}")
    print(f"Translation keys and placeholders passed: {len(expected)} keys in five languages.")
    for test in ("settings", "routes", "generation", "documents"):
        run([*php, str(ROOT / "test" / (test + ".php"))])
    for scenario in ("allowed", "denied", "false", "null", "bad-password", "ambiguous"):
        run([*php, str(ROOT / "test/authentication.php"), scenario], "ADMISSION_CHECK_PASSED")
    print("6 simulated transverse admission checks passed.")
    for core in args.core_htdocs:
        core = core.resolve()
        print(f"Bundled Sabre and native CSRF from: {core}")
        for test in ("sabre", "filesystem", "native", "formats"):
            run([*php, str(ROOT / "test" / (test + ".php")), str(core)])
        for category in ("0", "5"):
            run([*php, str(ROOT / "test/carddav.php"), str(core), category])
        for scenario in ("post-valid", "post-missing", "post-expired", "get-valid", "get-missing", "get-expired"):
            run([*php, str(ROOT / "test/security.php"), str(core), scenario], "CSRF_CHECK_PASSED")
        print("6 native CSRF checks passed (simulated session).")
    if not args.core_htdocs:
        print("Sabre and native CSRF not executed: pass --core-htdocs.")


if __name__ == "__main__":
    main()

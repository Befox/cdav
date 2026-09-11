"""Read immutable Dolibarr tags; never modify or execute the core checkout.

This checks the source contracts used by CDav, not installation or integration.
Run: python scripts/check_core_contracts.py --core ../dolibarr --report doc/core-contracts.md
"""
import argparse
import datetime
import re
import subprocess
from pathlib import Path


CONTRACTS = {
    "Bootstrap": ("htdocs/master.inc.php", ["DOLENTITY", "$conf->setValues($db)"]),
    "CSRF": ("htdocs/main.inc.php", ["CSRFCHECK_WITH_TOKEN", "GETPOST('token'"]),
    "FormSetup": ("htdocs/core/class/html.formsetup.class.php", ["function generateOutput(", "function setAsCategory(", "function setAsSelect(", "fieldInputOverride", "newToken()"]),
    "Selectors": ("htdocs/core/class/html.form.class.php", ["function select_produits(", "$filtertype", "$nooutput"]),
    "Switches": ("htdocs/core/lib/ajax.lib.php", ["function ajax_constantonoff(", "$setzeroinsteadofdel"]),
    "Configuration": ("htdocs/core/lib/functions.lib.php", ["function getDolGlobalString(", "function getDolGlobalInt(", "function isModEnabled(", "function getEntity("]),
    "Authentication": ("htdocs/core/lib/security2.lib.php", ["function checkLoginPassEntity(", "$entity"]),
    "Permissions": ("htdocs/user/class/user.class.php", ["function hasRight(", "function getrights(", "$forcereload"]),
    "Native contacts": ("htdocs/contact/class/contact.class.php", ["function create(", "function update(", "$nosyncuser"]),
    "Native members": ("htdocs/adherents/class/adherent.class.php", ["function create(", "function update(", "$nosyncthirdparty"]),
    "Native agenda": ("htdocs/comm/action/class/actioncomm.class.php", ["function create(", "function update(", "userassigned"]),
    "Native tasks": ("htdocs/projet/class/task.class.php", ["function create(", "function update(", "fk_project"]),
    "Project validation": ("htdocs/projet/class/project.class.php", ["function setValid(", "PROJECT_VALIDATE", "getProjectsAuthorizedForUser"]),
    "Native interventions": ("htdocs/fichinter/class/fichinter.class.php", ["class FichinterLigne", "function update(", "update_total"]),
    "Assignments": ("htdocs/core/class/commonobject.class.php", ["function liste_contact(", "function add_contact(", "function delete_contact(", "public $oldcopy;"]),
    "Documents": ("htdocs/core/lib/files.lib.php", ["function dol_move(", "function dol_delete_file(", "function dol_delete_dir(", "function addFileIntoDatabaseIndex(", "function dol_check_secure_access_document("]),
    "Task numbering": ("htdocs/core/modules/project/task/mod_task_simple.php", ["function getNextValue("]),
    "Sabre CalDAV": ("htdocs/includes/sabre/sabre/dav/lib/CalDAV/Backend/BackendInterface.php", ["function getCalendarsForUser(", "function getCalendarObjects(", "function createCalendarObject(", "function updateCalendarObject(", "function deleteCalendarObject("]),
    "Sabre CardDAV": ("htdocs/includes/sabre/sabre/dav/lib/CardDAV/Backend/BackendInterface.php", ["function getAddressBooksForUser(", "function getCards(", "function createCard(", "function updateCard(", "function deleteCard("]),
}


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--core", type=Path, default=Path("../dolibarr"))
    parser.add_argument("--report", type=Path)
    args = parser.parse_args()

    def git(*command):
        return subprocess.check_output(["git", "-C", str(args.core), *command]).decode("utf-8")

    rows, failures, signatures = [], [], {}
    for major in range(16, 25):
        tag = f"{major}.0.0"
        sha = git("rev-parse", tag + "^{commit}").strip()
        contracts = dict(CONTRACTS)
        if major >= 21:
            contracts["Native interventions"] = ("htdocs/fichinter/class/fichinterligne.class.php", CONTRACTS["Native interventions"][1])
        sources = {path: git("show", f"{sha}:{path}") for path, _ in contracts.values()}
        for label, (path, symbols) in contracts.items():
            source = sources[path]
            missing = [symbol for symbol in symbols if symbol not in source]
            if missing:
                failures.append(f"{tag}: {label}: missing {missing}")
            # Record the actual method declarations, including parameter changes.
            signatures.setdefault(label, []).append((tag, sha, path, re.findall(r"(?:public |protected |abstract |static )*function (?:" + "|".join(re.escape(s[9:-1]) for s in symbols if s.startswith("function ")) + r")\([^\n]*", source)))
        functions = sources["htdocs/core/lib/functions.lib.php"]
        user = sources["htdocs/user/class/user.class.php"]
        if ("function getMultidirOutput(" in functions) != (major >= 18):
            failures.append(f"{tag}: getMultidirOutput availability changed")
        if ("function loadRights(" in user) != (major >= 20):
            failures.append(f"{tag}: loadRights availability changed")
        bootstrap = sources["htdocs/master.inc.php"]
        if bootstrap.index("defined('DOLENTITY')") > bootstrap.index("$conf->setValues($db)"):
            failures.append(f"{tag}: entity resolution moved after configuration")
        version = git("show", f"{sha}:htdocs/includes/sabre/sabre/dav/lib/DAV/Version.php")
        sabre = re.search(r"VERSION\s*=\s*['\"]([^'\"]+)", version).group(1)
        rows.append(f"| {tag} | [`{sha[:12]}`](https://github.com/Dolibarr/dolibarr/tree/{sha}) | {sabre} | {'getMultidirOutput' if major >= 18 else 'multidir_output[owner]'} | {'loadRights' if major >= 20 else 'getrights'} |")
    if failures:
        raise SystemExit("\n".join(failures))
    report = ["# Contrats core CDav 3.3", "", f"Lecture des tags effectuée le {datetime.date.today().isoformat()}. **Contrôle de sources, pas test d’installation.**", "", "| Dolibarr | Commit vérifié | Sabre/dav fourni | Documents | Chargement des droits |", "|---|---|---|---|---|", *rows, "", "## Périmètre et résultats", "", "Les 19 familles ci-dessous sont présentes dans les neuf tags. Le contrôle vérifie les symboles et consigne les signatures ; il ne prouve pas leur comportement en base ni le rendu dans un navigateur.", "", "- `master.inc.php` choisit l’entité avant les constantes. CDav refuse `loginfunction`, une session déjà ouverte ou une entité CLI avant le bootstrap DAV/ICS, puis contrôle l’entité effective.", "- `FormSetup` et les sélecteurs utilisés sont présents dès v16. `select_produits` reçoit le paramètre natif de type service, sans fragment SQL passé comme filtre. Aucun filtre historique n’est transmis à un helper exigeant l’USF en v24.", "- `getMultidirOutput` apparaît dans les tags examinés à partir de v18 ; les versions 16–17 utilisent la configuration documentaire native par propriétaire. Une configuration absente ou un retour `error-...` est refusé.", "- `loadRights` apparaît en v20 ; `getrights` reste utilisé en v16–19. Les permissions fonctionnelles passent directement par `hasRight` dans toutes les versions.", "- La génération utilise le trigger natif `PROJECT_VALIDATE`, les méthodes Task et le modèle natif de numérotation dans la transaction de validation. Aucun nouveau code trigger métier CDav n’est ajouté.", "- `Task::fetch` v16 ne charge pas l’entité : CDav charge le projet propriétaire. `Task::create` v16 utilise l’entité courante : la génération exige le contexte propriétaire.", "", "## Limites", "", "Les exigences PHP du core et de ses bibliothèques restent applicables. Le contrôle des symboles ne remplace pas l’installation v16/PHP 8.0 et v24/PHP compatible. Les tests Sabre, formulaires, transactions et droits simulés sont décrits dans `validation-3.3.md`. Les sources et l’instance Multicompany ne sont pas disponibles : `checkRight`, les partages et les affectations transverses nécessitent une validation réelle. Aucun mode transverse réel n’est certifié.", "", "## Signatures observées", ""]
    for label, values in signatures.items():
        report += [f"### {label}", ""]
        previous = None
        for tag, sha, path, declarations in values:
            current = "\n".join(declarations)
            if current == previous:
                continue
            previous = current
            report += [f"[{tag} — {path}](https://github.com/Dolibarr/dolibarr/blob/{sha}/{path})", "", "```php", current or "// Symbols checked in the source; no method declaration in this group.", "```", ""]
    if args.report:
        destination = args.report.resolve()
        module_root = Path(__file__).resolve().parents[1]
        if module_root not in destination.parents:
            raise SystemExit("Report must stay inside the CDav module")
        destination.write_text("\n".join(report), encoding="utf-8", newline="\n")
    print(f"{len(CONTRACTS) * len(rows)} source contract checks passed across Dolibarr 16–24.")


if __name__ == "__main__":
    main()

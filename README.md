# CDav 3.3.1 for Dolibarr

CDav synchronizes native Dolibarr contacts, third parties, members, calendars, project tasks and interventions through CardDAV and CalDAV. It also provides read-only ICS subscriptions and controlled WebDAV access to native documents.

Original module: **Befox SARL**, module ID **562387**, GPL-3.0-or-later. This fork retains the CDav identity and existing configuration keys. Version 3.3.1 is under development on `fix/3.3.1-fix-contacts-permissions`; no release has been published.

## Compatibility

The module minimum remains **Dolibarr 16.0 and PHP 8.0**. Source contracts have been checked for every major version from **16 through 24**. The PHP requirements of the installed Dolibarr core and its bundled libraries also apply.

The [source matrix](doc/core-contracts.md) records immutable core commits and API signatures. The [validation report](doc/validation-3.3.md) distinguishes source checks, simulations and real integration tests still to run. **A real Multicompany installation, including transverse mode, has not been tested.**

The [3.3.1 correction report](doc/correctifs-3.3.1.md) records the contact access and civility changes, their focused checks and remaining instance/client validation.

The internal Compatibility tab reports the actual runtime, bundled Sabre, required PHP extensions, optional modules, document configuration and unavailable features. HTTPS, Authorization forwarding, PATH_INFO, filesystem permissions and client synchronization require checks on the real server.

| Feature | Dependencies in addition to the DAV runtime |
|---|---|
| Contacts and third parties | Third parties module; native contact/third-party permissions |
| Members | Members module and member synchronization enabled |
| Calendar events | Agenda module and native personal/other-calendar permissions |
| Project tasks | Agenda, Projects, task display and synchronization enabled |
| Interventions | Agenda, Interventions and synchronization enabled |
| Task generation | Projects, Services, task display and generation enabled; document read permissions |
| Contact photos | PHP GD and EXIF; configured document directory of the contact owner |
| ICS | Agenda, OpenSSL and a configured synchronization key |
| WebDAV uploads | PHP Fileinfo; native upload/write permissions and valid document access |
| DAVx⁵ QR code | Native barcode generator and PHP GD |

No Composer installation or additional runtime library is required: CDav uses the Sabre version bundled with Dolibarr.

## Install and upgrade

1. Place the module directory, named `cdav`, in an external-module root recognized by your Dolibarr installation. Keep the module files directly inside that directory.
2. Enable the native modules for the features you need, then enable CDav in the module list.
3. Open its single settings entry and check Compatibility before configuring a DAV client.

For an upgrade, back up the database and documents, temporarily disable CDav, replace its module files and re-enable it **in each entity using CDav**. Activation creates missing structures and extrafields without overwriting configured values. Deactivation preserves the constants, filters, keys and native sharing settings. Do not change `CDAV_URI_KEY` during a routine upgrade: it participates in historical URLs and UIDs.

Version 3.3 adds `cdav_card` for protocol URI/UID mappings, scoped by entity, kind and native object. Business data remains in native Dolibarr tables. The existing `actioncomm_cdav.sourceuid` migration is conditional. Native `cdav_duration` line extrafields are created only when missing; existing definitions are retained.

## Five native settings tabs

- **Settings**: synchronization key, DAVx⁵ QR-code option and links to your client configuration URLs.
- **CardDAV**: contact category, optional contact civility synchronization, third-party synchronization mode and members.
- **CalDAV**: synchronization period, project tasks, interventions, user roles, initial/final services, duration override and working hours.
- **Compatibility**: detected prerequisites and feature availability, with reasons and version thresholds.
- **About**: descriptor version, identity, license, original publisher, maintainer and useful links.

These pages use native FormSetup, Select2 selectors, switches, tables and messages. They are restricted to administrators and protected by the native CSRF mechanism. Saving a form validates only that tab and writes constants in the current entity. Invalid input remains visible; a successful save redirects to the tab. Native switches save their own value immediately, preserving `0` when disabled. The non-Ajax switch links are also handled with tokens.

The interface and DAV labels are available in **English, French, German, Spanish and Italian**. Technical IDs, paths and UIDs do not depend on the language.

## DAV accounts and URLs

Open the CDav URL pages from the Contacts or Agenda menus. They display URLs for the current entity and resources accessible to the current user. Configure the native Dolibarr login and password in the DAV client. Use HTTPS and keep ICS links confidential.

`<module-url>` below means the actual URL of your installed `cdav` directory; it is not a fixed installation path.

```text
<module-url>/server.php/2/
<module-url>/server.php/2/principals/<login>/
<module-url>/server.php/2/calendars/<login>/<calendar-id>-cal-<calendar-login>
<module-url>/server.php/2/addressbooks/<login>/default/
<module-url>/ics.php?entity=2&token=<generated-token>
```

Use **one DAV account per entity**. The path selects the entity before loading its configuration. Browser sessions and login-page parameters cannot change that context. Old DAV URLs without an entity and old ICS URLs without the `entity` parameter continue to target **entity 1**.

Authentication uses native Dolibarr mechanisms. Multicompany admission is checked separately before loading rights for the target entity, including centralized transverse accounts. Discovery includes the target entity's native user/group assignments. CDav relies on native object sharing and does not introduce competing CDav sharing settings.

Creations belong to the target entity; shared objects keep their original owner. Contact photos use that owner's document directory. A missing directory configuration causes an explicit refusal, without falling back to another entity. Administrators still need the relevant native functional rights.

Contacts linked to a third party require access to that third party as well as contact read permission. Without the native extension to all third parties (`societe.client.voir`), only third parties assigned to the current user as a sales representative qualify. Parent and contact entity sharing, private contacts and the existing pure-supplier read restriction also apply. Unlinked contacts retain their own access rules; a missing or inaccessible parent does not make a linked contact public. Lists, discovery metadata, individual/multiple reads and checks before updates or archival use the same scope.

The **Synchronize contact titles (civility)** switch (`CDAV_CONTACT_SYNC_CIVILITY`) is **off by default**, independently per entity. It controls the Dolibarr civility field (Mr, Ms…), carried in the vCard name prefix, in both directions. When off, CardDAV omits this prefix and ignores incoming prefixes, preserving an existing Dolibarr civility. Job titles (`poste` / vCard `TITLE`) remain synchronized. The optional company-name display remains in the formatted name without replacing civility.

Contact collection change tags include the visible membership and the civility option, so revoking an assignment or changing this switch prompts a new scan. CDav still requires a full rescan for unknown sync tokens; it does not maintain an incremental deletion history. Check removal of previously downloaded contacts with your deployed clients after upgrading. No database cleanup is performed by these changes.

### Clients

Clients supporting discovery can use the base DAV URL. Otherwise, use the precise calendar/address-book URL shown by CDav. DAVx⁵ configuration links and QR codes can be enabled from Settings. Existing iOS principal URLs and direct calendar URLs remain available. Actual synchronization must be checked with the client versions you deploy.

Calendar records with a non-applicable percentage are exposed as VEVENT; other records are VTODO. Project tasks can be exposed as events, tasks, or both. Both representations modify the same native task. Recurring events are expanded into native events within the configured period, with a maximum of 1,000 occurrences; full recurrence editing is outside this refactor.

DELETE preserves the historical synchronization behavior: CardDAV archives the native record, while CalDAV removes its calendar/user assignment rather than deleting the underlying project task or intervention. Native business methods and triggers handle mutations inside transactions. URI/UID mismatches and ambiguous legacy mappings are rejected.

ICS remains read-only and retains the historical link encryption format. Full subscriptions expose the authorized calendar content; the version without titles removes descriptions, locations, contacts and other detailed properties. Rotating the synchronization key invalidates the old links and changes historical resource identifiers.

### WebDAV documents

The administrative documents collection retains its historical name, derived from the document root in entity 1. In other entities it uses the entity number beneath the entity-scoped server URL. Only configured native module directories are mounted. The `public` collection uses native ECM rights.

Every operation checks native rights and document access. Shared-owner document paths, missing configuration, symbolic links and path traversal are checked before file access. Uploads have size, extension and MIME controls; images are re-encoded without EXIF/GPS. A failed filesystem or indexing operation is reported. Document layout and sharing remain the responsibility of the native modules.

## Generate project tasks from services

Enable generation in CalDAV settings. Configure the project/task user roles and, optionally, up to three initial services, three final services and a service category. The selected native task numbering model supplies references.

1. Create a draft project in its owning entity and set its start date.
2. Link proposals and/or orders containing the relevant service lines.
3. Assign a project user with the configured role, or the validating user will be used.
4. Validate the project. The native `PROJECT_VALIDATE` trigger generates the initial, source and final tasks in the validation transaction.

Existing tasks prevent another initial generation. An order's linked proposal is not generated a second time. Missing rights, inaccessible services, invalid roles/durations or failed task assignments cancel the whole validation. Validate shared projects from their owning entity, which also preserves compatibility with native Task creation in Dolibarr 16.

Durations use the native service value, or the configured `cdav_duration` override when enabled. Minutes, hours, days and weeks are accepted; an empty duration retains the historical one-hour default. All generated tasks start on the project start date. Multi-day durations remain a single task spanning the configured working days. Empty working hours use 07:00–19:00; an explicit starting hour of `0` is retained. No cron is needed.

After generation, tasks can be edited through Dolibarr or CalDAV. Lines beginning with `- ` in task descriptions retain the historical checklist conversion.

## Tests and troubleshooting

```sh
python scripts/run_checks.py --core-htdocs /path/to/dolibarr/htdocs
python scripts/check_core_contracts.py --core /path/to/dolibarr-git
```

The first command lints the module, checks five-language parity and runs focused tests. Providing core sources also runs real Sabre parsers and the native CSRF block against simulated data/session services. The contact tests require **PDO SQLite for testing only** and evaluate the backend SQL with a documented adaptation of MySQL `GROUP_CONCAT`; production remains MySQL/MariaDB. If the extension is installed but not loaded, append `--php-arg=-d --php-arg=extension=pdo_sqlite`. The second command reads tags 16.0.0 through 24.0.0. Neither command installs Dolibarr or certifies Multicompany.

[GitHub Actions](.github/workflows/checks.yml) runs the focused suite with Dolibarr 16 libraries/PHP 8.0 and Dolibarr 24 libraries/PHP 8.4 using [setup-php](https://github.com/shivammathur/setup-php). Its jobs explicitly identify the simulated ERP environment. PHPStan should additionally be run with the deployment's native core configuration when available.

If authentication loops, first verify the actual Authorization header forwarding and PATH_INFO handling in the web server/reverse proxy. The endpoint accepts forwarded Basic headers and passwords containing colons. Do not weaken Dolibarr CSRF settings to fix a settings form. Check the Compatibility tab, server logs without sensitive payloads, and the [manual validation procedure](doc/validation-3.3.md).

## Credits and support

- Original publisher: [Befox](https://befox.fr/), [upstream repository](https://github.com/Befox/cdav).
- This fork and issue tracker: [mapiolca/cdav](https://github.com/mapiolca/cdav).
- Changes: [ChangeLog.md](ChangeLog.md).
- License: [COPYING](COPYING); Sabre retains its own upstream license.

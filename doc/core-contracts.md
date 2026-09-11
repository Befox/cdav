# Contrats core CDav 3.3

Lecture des tags effectuée le 2026-09-09. **Contrôle de sources, pas test d’installation.**

| Dolibarr | Commit vérifié | Sabre/dav fourni | Documents | Chargement des droits |
|---|---|---|---|---|
| 16.0.0 | [`ad673b320750`](https://github.com/Dolibarr/dolibarr/tree/ad673b3207506de03ee8e745399e3a19505900d2) | 3.2.2 | multidir_output[owner] | getrights |
| 17.0.0 | [`33744f74da1a`](https://github.com/Dolibarr/dolibarr/tree/33744f74da1a5c20f8aa9950018ab4e549e00205) | 3.2.2 | multidir_output[owner] | getrights |
| 18.0.0 | [`126634229f38`](https://github.com/Dolibarr/dolibarr/tree/126634229f389b7e49c3c64d86d483f6dc77390b) | 4.4.0 | getMultidirOutput | getrights |
| 19.0.0 | [`1bfe7acbb59e`](https://github.com/Dolibarr/dolibarr/tree/1bfe7acbb59eb18e638883b10406ed8bd0ed8c80) | 4.4.0 | getMultidirOutput | getrights |
| 20.0.0 | [`697bf0197074`](https://github.com/Dolibarr/dolibarr/tree/697bf01970740a3339cd99cf055b4428fc5e051c) | 4.6.0 | getMultidirOutput | loadRights |
| 21.0.0 | [`fd970b582a4d`](https://github.com/Dolibarr/dolibarr/tree/fd970b582a4d8c5779a2958a4e9f4fce225cf085) | 4.6.0 | getMultidirOutput | loadRights |
| 22.0.0 | [`49b9a6d19f3d`](https://github.com/Dolibarr/dolibarr/tree/49b9a6d19f3deb6d410c0e9b3310e95be4ea7710) | 4.6.0 | getMultidirOutput | loadRights |
| 23.0.0 | [`57a1f05d490a`](https://github.com/Dolibarr/dolibarr/tree/57a1f05d490a7a80944a8232e9c613e6556d2704) | 4.6.0 | getMultidirOutput | loadRights |
| 24.0.0 | [`769c7db90709`](https://github.com/Dolibarr/dolibarr/tree/769c7db907099643558e77d7002c109cfda919e5) | 4.6.0 | getMultidirOutput | loadRights |

## Périmètre et résultats

Les 19 familles ci-dessous sont présentes dans les neuf tags. Le contrôle vérifie les symboles et consigne les signatures ; il ne prouve pas leur comportement en base ni le rendu dans un navigateur.

- `master.inc.php` choisit l’entité avant les constantes. CDav refuse `loginfunction`, une session déjà ouverte ou une entité CLI avant le bootstrap DAV/ICS, puis contrôle l’entité effective.
- `FormSetup` et les sélecteurs utilisés sont présents dès v16. `select_produits` reçoit le paramètre natif de type service, sans fragment SQL passé comme filtre. Aucun filtre historique n’est transmis à un helper exigeant l’USF en v24.
- `getMultidirOutput` apparaît dans les tags examinés à partir de v18 ; les versions 16–17 utilisent la configuration documentaire native par propriétaire. Une configuration absente ou un retour `error-...` est refusé.
- `loadRights` apparaît en v20 ; `getrights` reste utilisé en v16–19. Les permissions fonctionnelles passent directement par `hasRight` dans toutes les versions.
- La génération utilise le trigger natif `PROJECT_VALIDATE`, les méthodes Task et le modèle natif de numérotation dans la transaction de validation. Aucun nouveau code trigger métier CDav n’est ajouté.
- `Task::fetch` v16 ne charge pas l’entité : CDav charge le projet propriétaire. `Task::create` v16 utilise l’entité courante : la génération exige le contexte propriétaire.

## Limites

Les exigences PHP du core et de ses bibliothèques restent applicables. Le contrôle des symboles ne remplace pas l’installation v16/PHP 8.0 et v24/PHP compatible. Les tests Sabre, formulaires, transactions et droits simulés sont décrits dans `validation-3.3.md`. Les sources et l’instance Multicompany ne sont pas disponibles : `checkRight`, les partages et les affectations transverses nécessitent une validation réelle. Aucun mode transverse réel n’est certifié.

## Signatures observées

### Bootstrap

[16.0.0 — htdocs/master.inc.php](https://github.com/Dolibarr/dolibarr/blob/ad673b3207506de03ee8e745399e3a19505900d2/htdocs/master.inc.php)

```php
// Symbols checked in the source; no method declaration in this group.
```

### CSRF

[16.0.0 — htdocs/main.inc.php](https://github.com/Dolibarr/dolibarr/blob/ad673b3207506de03ee8e745399e3a19505900d2/htdocs/main.inc.php)

```php
function ($m) {
function (e) {
function () {
function () {
function (tmp) {
function (data, status, xhr) {   // success callback function (data contains body of response)
function (data,status,xhr) {   // error callback function
```

[20.0.0 — htdocs/main.inc.php](https://github.com/Dolibarr/dolibarr/blob/697bf01970740a3339cd99cf055b4428fc5e051c/htdocs/main.inc.php)

```php
static function ($m) {
function (e) {
function () {
function () {
function (tmp) {
function (data, status, xhr) {   // success callback function (data contains body of response)
function (data,status,xhr) {   // error callback function
```

[22.0.0 — htdocs/main.inc.php](https://github.com/Dolibarr/dolibarr/blob/49b9a6d19f3deb6d410c0e9b3310e95be4ea7710/htdocs/main.inc.php)

```php
function (e) {
function () {
function () {
function (tmp) {
function (data, status, xhr) {   // success callback function (data contains body of response)
function (data,status,xhr) {   // error callback function
```

[23.0.0 — htdocs/main.inc.php](https://github.com/Dolibarr/dolibarr/blob/57a1f05d490a7a80944a8232e9c613e6556d2704/htdocs/main.inc.php)

```php
function (e) {
function () {
function () {
```

[24.0.0 — htdocs/main.inc.php](https://github.com/Dolibarr/dolibarr/blob/769c7db907099643558e77d7002c109cfda919e5/htdocs/main.inc.php)

```php
function () {
function (resp) {
function (htmlcontent) {
function (mod) {
function () {
function (e) {
function () { loading = false; });
function (event) {
function (event) {
function (event) {
function (e) {
function () {
function () {
```

### FormSetup

[16.0.0 — htdocs/core/class/html.formsetup.class.php](https://github.com/Dolibarr/dolibarr/blob/ad673b3207506de03ee8e745399e3a19505900d2/htdocs/core/class/html.formsetup.class.php)

```php
public function generateOutput($editMode = false)
public function setAsCategory($catType)
public function setAsSelect($fieldOptions)
```

[20.0.0 — htdocs/core/class/html.formsetup.class.php](https://github.com/Dolibarr/dolibarr/blob/697bf01970740a3339cd99cf055b4428fc5e051c/htdocs/core/class/html.formsetup.class.php)

```php
public function generateOutput($editMode = false, $hideTitle = false)
public function setAsCategory($catType)
public function setAsSelect($fieldOptions)
```

[23.0.0 — htdocs/core/class/html.formsetup.class.php](https://github.com/Dolibarr/dolibarr/blob/57a1f05d490a7a80944a8232e9c613e6556d2704/htdocs/core/class/html.formsetup.class.php)

```php
public function generateOutput($editMode = false, $hideTitle = false, $title = '', $cssfirstcolumn = '')
public function setAsCategory($catType)
public function setAsSelect($fieldOptions)
```

### Selectors

[16.0.0 — htdocs/core/class/html.form.class.php](https://github.com/Dolibarr/dolibarr/blob/ad673b3207506de03ee8e745399e3a19505900d2/htdocs/core/class/html.form.class.php)

```php
public function select_produits($selected = '', $htmlname = 'productid', $filtertype = '', $limit = 0, $price_level = 0, $status = 1, $finished = 2, $selected_input_value = '', $hidelabel = 0, $ajaxoptions = array(), $socid = 0, $showempty = '1', $forcecombo = 0, $morecss = '', $hidepriceinlabel = 0, $warehouseStatus = '', $selected_combinations = null, $nooutput = 0, $status_purchase = -1)
```

[19.0.0 — htdocs/core/class/html.form.class.php](https://github.com/Dolibarr/dolibarr/blob/1bfe7acbb59eb18e638883b10406ed8bd0ed8c80/htdocs/core/class/html.form.class.php)

```php
public function select_produits($selected = 0, $htmlname = 'productid', $filtertype = '', $limit = 0, $price_level = 0, $status = 1, $finished = 2, $selected_input_value = '', $hidelabel = 0, $ajaxoptions = array(), $socid = 0, $showempty = '1', $forcecombo = 0, $morecss = '', $hidepriceinlabel = 0, $warehouseStatus = '', $selected_combinations = null, $nooutput = 0, $status_purchase = -1)
```

[21.0.0 — htdocs/core/class/html.form.class.php](https://github.com/Dolibarr/dolibarr/blob/fd970b582a4d8c5779a2958a4e9f4fce225cf085/htdocs/core/class/html.form.class.php)

```php
public function select_produits($selected = 0, $htmlname = 'productid', $filtertype = '', $limit = 0, $price_level = 0, $status = 1, $finished = 2, $selected_input_value = '', $hidelabel = 0, $ajaxoptions = array(), $socid = 0, $showempty = '1', $forcecombo = 0, $morecss = '', $hidepriceinlabel = 0, $warehouseStatus = '', $selected_combinations = null, $nooutput = 0, $status_purchase = -1, $warehouseId = 0)
```

### Switches

[16.0.0 — htdocs/core/lib/ajax.lib.php](https://github.com/Dolibarr/dolibarr/blob/ad673b3207506de03ee8e745399e3a19505900d2/htdocs/core/lib/ajax.lib.php)

```php
function ajax_constantonoff($code, $input = array(), $entity = null, $revertonoff = 0, $strict = 0, $forcereload = 0, $marginleftonlyshort = 2, $forcenoajax = 0, $setzeroinsteadofdel = 0, $suffix = '', $mode = '')
```

[18.0.0 — htdocs/core/lib/ajax.lib.php](https://github.com/Dolibarr/dolibarr/blob/126634229f389b7e49c3c64d86d483f6dc77390b/htdocs/core/lib/ajax.lib.php)

```php
function ajax_constantonoff($code, $input = array(), $entity = null, $revertonoff = 0, $strict = 0, $forcereload = 0, $marginleftonlyshort = 2, $forcenoajax = 0, $setzeroinsteadofdel = 0, $suffix = '', $mode = '', $morecss = '')
```

[19.0.0 — htdocs/core/lib/ajax.lib.php](https://github.com/Dolibarr/dolibarr/blob/1bfe7acbb59eb18e638883b10406ed8bd0ed8c80/htdocs/core/lib/ajax.lib.php)

```php
function ajax_constantonoff($code, $input = array(), $entity = null, $revertonoff = 0, $strict = 0, $forcereload = 0, $marginleftonlyshort = 2, $forcenoajax = 0, $setzeroinsteadofdel = 0, $suffix = '', $mode = '', $morecss = 'inline-block')
```

[21.0.0 — htdocs/core/lib/ajax.lib.php](https://github.com/Dolibarr/dolibarr/blob/fd970b582a4d8c5779a2958a4e9f4fce225cf085/htdocs/core/lib/ajax.lib.php)

```php
function ajax_constantonoff($code, $input = array(), $entity = null, $revertonoff = 0, $strict = 0, $forcereload = 0, $marginleftonlyshort = 2, $forcenoajax = 0, $setzeroinsteadofdel = 0, $suffix = '', $mode = '', $morecss = 'inline-block', $userconst = 0, $showwarning = '')
```

[23.0.0 — htdocs/core/lib/ajax.lib.php](https://github.com/Dolibarr/dolibarr/blob/57a1f05d490a7a80944a8232e9c613e6556d2704/htdocs/core/lib/ajax.lib.php)

```php
function ajax_constantonoff($code, $input = array(), $entity = null, $revertonoff = 0, $strict = 0, $forcereload = 0, $marginleftonlyshort = 2, $forcenoajax = 0, $setzeroinsteadofdel = 0, $suffix = '', $mode = '', $morecss = 'inline-block', $userconst = 0, $showwarning = '', $disabled = 0)
```

### Configuration

[16.0.0 — htdocs/core/lib/functions.lib.php](https://github.com/Dolibarr/dolibarr/blob/ad673b3207506de03ee8e745399e3a19505900d2/htdocs/core/lib/functions.lib.php)

```php
function getDolGlobalString($key, $default = '')
function getDolGlobalInt($key, $default = 0)
function isModEnabled($module)
function getEntity($element, $shared = 1, $currentobject = null)
```

### Authentication

[16.0.0 — htdocs/core/lib/security2.lib.php](https://github.com/Dolibarr/dolibarr/blob/ad673b3207506de03ee8e745399e3a19505900d2/htdocs/core/lib/security2.lib.php)

```php
function checkLoginPassEntity($usertotest, $passwordtotest, $entitytotest, $authmode, $context = '')
```

### Permissions

[16.0.0 — htdocs/user/class/user.class.php](https://github.com/Dolibarr/dolibarr/blob/ad673b3207506de03ee8e745399e3a19505900d2/htdocs/user/class/user.class.php)

```php
public function hasRight($module, $permlevel1, $permlevel2 = '')
public function getrights($moduletag = '', $forcereload = 0)
```

### Native contacts

[16.0.0 — htdocs/contact/class/contact.class.php](https://github.com/Dolibarr/dolibarr/blob/ad673b3207506de03ee8e745399e3a19505900d2/htdocs/contact/class/contact.class.php)

```php
public function create($user)
public function update($id, $user = null, $notrigger = 0, $action = 'update', $nosyncuser = 0)
```

[18.0.0 — htdocs/contact/class/contact.class.php](https://github.com/Dolibarr/dolibarr/blob/126634229f389b7e49c3c64d86d483f6dc77390b/htdocs/contact/class/contact.class.php)

```php
public function create($user, $notrigger = 0)
public function update($id, $user = null, $notrigger = 0, $action = 'update', $nosyncuser = 0)
```

### Native members

[16.0.0 — htdocs/adherents/class/adherent.class.php](https://github.com/Dolibarr/dolibarr/blob/ad673b3207506de03ee8e745399e3a19505900d2/htdocs/adherents/class/adherent.class.php)

```php
public function create($user, $notrigger = 0)
public function update($user, $notrigger = 0, $nosyncuser = 0, $nosyncuserpass = 0, $nosyncthirdparty = 0, $action = 'update')
```

### Native agenda

[16.0.0 — htdocs/comm/action/class/actioncomm.class.php](https://github.com/Dolibarr/dolibarr/blob/ad673b3207506de03ee8e745399e3a19505900d2/htdocs/comm/action/class/actioncomm.class.php)

```php
public function create(User $user, $notrigger = 0)
public function update(User $user, $notrigger = 0)
```

### Native tasks

[16.0.0 — htdocs/projet/class/task.class.php](https://github.com/Dolibarr/dolibarr/blob/ad673b3207506de03ee8e745399e3a19505900d2/htdocs/projet/class/task.class.php)

```php
public function create($user, $notrigger = 0)
public function update($user = null, $notrigger = 0)
```

### Project validation

[16.0.0 — htdocs/projet/class/project.class.php](https://github.com/Dolibarr/dolibarr/blob/ad673b3207506de03ee8e745399e3a19505900d2/htdocs/projet/class/project.class.php)

```php
public function setValid($user, $notrigger = 0)
```

### Native interventions

[16.0.0 — htdocs/fichinter/class/fichinter.class.php](https://github.com/Dolibarr/dolibarr/blob/ad673b3207506de03ee8e745399e3a19505900d2/htdocs/fichinter/class/fichinter.class.php)

```php
public function update($user, $notrigger = 0)
public function update($user, $notrigger = 0)
```

[21.0.0 — htdocs/fichinter/class/fichinterligne.class.php](https://github.com/Dolibarr/dolibarr/blob/fd970b582a4d8c5779a2958a4e9f4fce225cf085/htdocs/fichinter/class/fichinterligne.class.php)

```php
public function update($user, $notrigger = 0)
```

### Assignments

[16.0.0 — htdocs/core/class/commonobject.class.php](https://github.com/Dolibarr/dolibarr/blob/ad673b3207506de03ee8e745399e3a19505900d2/htdocs/core/class/commonobject.class.php)

```php
public function add_contact($fk_socpeople, $type_contact, $source = 'external', $notrigger = 0)
public function delete_contact($rowid, $notrigger = 0)
public function liste_contact($status = -1, $source = 'external', $list = 0, $code = '')
```

[17.0.0 — htdocs/core/class/commonobject.class.php](https://github.com/Dolibarr/dolibarr/blob/33744f74da1a5c20f8aa9950018ab4e549e00205/htdocs/core/class/commonobject.class.php)

```php
public function add_contact($fk_socpeople, $type_contact, $source = 'external', $notrigger = 0)
public function delete_contact($rowid, $notrigger = 0)
public function liste_contact($statusoflink = -1, $source = 'external', $list = 0, $code = '', $status = -1, $arrayoftcids = array())
```

### Documents

[16.0.0 — htdocs/core/lib/files.lib.php](https://github.com/Dolibarr/dolibarr/blob/ad673b3207506de03ee8e745399e3a19505900d2/htdocs/core/lib/files.lib.php)

```php
function dol_move($srcfile, $destfile, $newmask = 0, $overwriteifexists = 1, $testvirus = 0, $indexdatabase = 1)
function dol_delete_file($file, $disableglob = 0, $nophperrors = 0, $nohook = 0, $object = null, $allowdotdot = false, $indexdatabase = 1, $nolog = 0)
function dol_delete_dir($dir, $nophperrors = 0)
function addFileIntoDatabaseIndex($dir, $file, $fullpathorig = '', $mode = 'uploaded', $setsharekey = 0, $object = null)
function dol_check_secure_access_document($modulepart, $original_file, $entity, $fuser = '', $refname = '', $mode = 'read')
```

[19.0.0 — htdocs/core/lib/files.lib.php](https://github.com/Dolibarr/dolibarr/blob/1bfe7acbb59eb18e638883b10406ed8bd0ed8c80/htdocs/core/lib/files.lib.php)

```php
function dol_move($srcfile, $destfile, $newmask = 0, $overwriteifexists = 1, $testvirus = 0, $indexdatabase = 1, $moreinfo = array())
function dol_delete_file($file, $disableglob = 0, $nophperrors = 0, $nohook = 0, $object = null, $allowdotdot = false, $indexdatabase = 1, $nolog = 0)
function dol_delete_dir($dir, $nophperrors = 0)
function addFileIntoDatabaseIndex($dir, $file, $fullpathorig = '', $mode = 'uploaded', $setsharekey = 0, $object = null)
function dol_check_secure_access_document($modulepart, $original_file, $entity, $fuser = '', $refname = '', $mode = 'read')
```

[20.0.0 — htdocs/core/lib/files.lib.php](https://github.com/Dolibarr/dolibarr/blob/697bf01970740a3339cd99cf055b4428fc5e051c/htdocs/core/lib/files.lib.php)

```php
function dol_move($srcfile, $destfile, $newmask = '0', $overwriteifexists = 1, $testvirus = 0, $indexdatabase = 1, $moreinfo = array())
function dol_delete_file($file, $disableglob = 0, $nophperrors = 0, $nohook = 0, $object = null, $allowdotdot = false, $indexdatabase = 1, $nolog = 0)
function dol_delete_dir($dir, $nophperrors = 0)
function addFileIntoDatabaseIndex($dir, $file, $fullpathorig = '', $mode = 'uploaded', $setsharekey = 0, $object = null)
function dol_check_secure_access_document($modulepart, $original_file, $entity, $fuser = null, $refname = '', $mode = 'read')
```

[21.0.0 — htdocs/core/lib/files.lib.php](https://github.com/Dolibarr/dolibarr/blob/fd970b582a4d8c5779a2958a4e9f4fce225cf085/htdocs/core/lib/files.lib.php)

```php
function dol_move($srcfile, $destfile, $newmask = '0', $overwriteifexists = 1, $testvirus = 0, $indexdatabase = 1, $moreinfo = array())
function dol_delete_file($file, $disableglob = 0, $nophperrors = 0, $nohook = 0, $object = null, $allowdotdot = false, $indexdatabase = 1, $nolog = 0)
function dol_delete_dir($dir, $nophperrors = 0)
function addFileIntoDatabaseIndex($dir, $file, $fullpathorig = '', $mode = 'uploaded', $setsharekey = 0, $object = null, $forceFullTextIndexation = '')
function dol_check_secure_access_document($modulepart, $original_file, $entity, $fuser = null, $refname = '', $mode = 'read')
```

[23.0.0 — htdocs/core/lib/files.lib.php](https://github.com/Dolibarr/dolibarr/blob/57a1f05d490a7a80944a8232e9c613e6556d2704/htdocs/core/lib/files.lib.php)

```php
function dol_move($srcfile, $destfile, $newmask = '0', $overwriteifexists = 1, $testvirus = 0, $indexdatabase = 1, $moreinfo = array(), $entity = null)
function dol_delete_file($file, $disableglob = 0, $nophperrors = 0, $nohook = 0, $object = null, $allowdotdot = false, $indexdatabase = 1, $nolog = 0)
function dol_delete_dir($dir, $nophperrors = 0)
function addFileIntoDatabaseIndex($dir, $file, $fullpathorig = '', $mode = 'uploaded', $setsharekey = 0, $object = null, $forceFullTextIndexation = '')
function dol_check_secure_access_document($modulepart, $original_file, $entity, $fuser = null, $refname = '', $mode = 'read')
```

### Task numbering

[16.0.0 — htdocs/core/modules/project/task/mod_task_simple.php](https://github.com/Dolibarr/dolibarr/blob/ad673b3207506de03ee8e745399e3a19505900d2/htdocs/core/modules/project/task/mod_task_simple.php)

```php
public function getNextValue($objsoc, $object)
```

### Sabre CalDAV

[16.0.0 — htdocs/includes/sabre/sabre/dav/lib/CalDAV/Backend/BackendInterface.php](https://github.com/Dolibarr/dolibarr/blob/ad673b3207506de03ee8e745399e3a19505900d2/htdocs/includes/sabre/sabre/dav/lib/CalDAV/Backend/BackendInterface.php)

```php
function getCalendarsForUser($principalUri);
function getCalendarObjects($calendarId);
function createCalendarObject($calendarId, $objectUri, $calendarData);
function updateCalendarObject($calendarId, $objectUri, $calendarData);
function deleteCalendarObject($calendarId, $objectUri);
```

[18.0.0 — htdocs/includes/sabre/sabre/dav/lib/CalDAV/Backend/BackendInterface.php](https://github.com/Dolibarr/dolibarr/blob/126634229f389b7e49c3c64d86d483f6dc77390b/htdocs/includes/sabre/sabre/dav/lib/CalDAV/Backend/BackendInterface.php)

```php
public function getCalendarsForUser($principalUri);
public function getCalendarObjects($calendarId);
public function createCalendarObject($calendarId, $objectUri, $calendarData);
public function updateCalendarObject($calendarId, $objectUri, $calendarData);
public function deleteCalendarObject($calendarId, $objectUri);
```

### Sabre CardDAV

[16.0.0 — htdocs/includes/sabre/sabre/dav/lib/CardDAV/Backend/BackendInterface.php](https://github.com/Dolibarr/dolibarr/blob/ad673b3207506de03ee8e745399e3a19505900d2/htdocs/includes/sabre/sabre/dav/lib/CardDAV/Backend/BackendInterface.php)

```php
function getAddressBooksForUser($principalUri);
function getCards($addressbookId);
function createCard($addressBookId, $cardUri, $cardData);
function updateCard($addressBookId, $cardUri, $cardData);
function deleteCard($addressBookId, $cardUri);
```

[18.0.0 — htdocs/includes/sabre/sabre/dav/lib/CardDAV/Backend/BackendInterface.php](https://github.com/Dolibarr/dolibarr/blob/126634229f389b7e49c3c64d86d483f6dc77390b/htdocs/includes/sabre/sabre/dav/lib/CardDAV/Backend/BackendInterface.php)

```php
public function getAddressBooksForUser($principalUri);
public function getCards($addressbookId);
public function createCard($addressBookId, $cardUri, $cardData);
public function updateCard($addressBookId, $cardUri, $cardData);
public function deleteCard($addressBookId, $cardUri);
```

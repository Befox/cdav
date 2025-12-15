# CDav module for Dolibarr

## What is it ?

This module for Dolibarr 16.0/22.0 adds CardDAV / CalDAV and ICS synchronisation. It uses Dolibarr [Sabre/DAV](http://sabre.io/dav/) server library.

You can :

 * Read and edit calendars through CalDAV
 * Read and edit project tasks through CalDAV
 * Read and edit address books through CardDAV
 * Read calendars through ICS Full version or only Free/Busy (hide details)
 * Access Dolibarr documents through WebDAV (if admin)
 * Generate project tasks from documents like proposals and/or orders

Each user can access his/her contacts and thirdparties address books (public and own private contacts), his/her own calendar and other users calendars according to his/her rights.

Dolibarr contact informations fill personnal informations in client software cards (including contact photo).

Society (thirdparty) informations (to which contact is attached) fill professional informations in client software cards.

Three adress books are proposed to sync : Contacts, Thirdparties and Members. If you want to modify a thirdparty infomation, do it in thirdparties address book.

It is possible to select which contacts to sync with CDAV_CONTACT_TAG configuration value in Home / Setup / Other setup. Enter a contact tag value and then only contacts with this tag will be synced (empty value for all).

Calendar records with "Status / Percentage" set to "Not applicable" are converted to events in CalDAV (VEVENT), others are converted to tasks (VTODO).

Recurring events are partially handled (Dolibarr does not handle them fully), when a recurring event is created, it is duplicated automatically until the date specified (exculded) or the max synchronisation time range.

Automatic tasks generation in projects with services from linked Propositions and/or Orders 
Module setup offer you to :

 * generate tasks from linked docuement(s) OR not
 * synchronize project tasks as calendar events AND/OR todo tasks 
 * set up 3 initial tasks that will appear before services coming from document(s)
 * set up 3 final tasks that will appear after services coming from document(s)
 * define user role in project to select user to attribute on generated tasks from document(s)
 * define user role on new project task creation
 * define start and end time of a working day
 * restrict services to be converted as task by specifying a tag
 * force generation of tasks for each service lines from attached documents with cdav duration if tag is missing

Durations are retrieved from service's card if defined (minutes, hours, days or weeks only), otherwise from extrafield filled in documents
All tasks are begining at the starting date of the project, at the begining of the working day
Multi-day durations tasks are maintained as a single task, eg from 31/07/2018 at 8am to 02/08/2018 at 7pm
 
Usage :

 * Manually create a project, link it to a third party, and set up the date ; leave it in draft status
 * Attach document(s) : proposals or orders including at least 1 concerned service.
 * Affect contact(s) with correct role
 * Validate project : all tasks are created ; use your ics client software to retrieve and drag-drop events if necessary
 
Notes :

 * Description of services are useful to create subtasks if '- ' are detected at the begining of a line ; then, with DAVx⁵ (CalDAV/CardDAV Synchronization and Client) and Tasks (Keep track of your list of goals) you will be able to use checkboxes for these tasks
 * If you chose to synchronize project tasks as calendar events AND todo tasks, modifying a task will autoamticaly modify the corresponding event and reciprocally.
 * Tasks can be modified from client application but not cancelled : Dolibarr keep trace of last affectation
 * After generating tasks, you can modify/complete each of them or create more tasks manually (here too you can fill description zone with '- ' at the begining of lines to create subtasks)


## Help improvements

If you find the module is useful and want to finance improvements, consider to pay it on [Dolistore](https://www.dolistore.com/fr/modules/526-Synchronisation-CardDAV---CalDAV---ICS.html)

## How to install

PHP 8.0+ is required.

Dolibarr native calendar module must be activated *before* installing CDav module.

* Clone repository _git clone https://github.com/Befox/cdav.git_ and install cdav directory in dolibarr/htdocs/
* Or unzip [last release](https://github.com/Befox/cdav/archive/master.zip), rename _cdav-master_ to _cdav_ and copy it into dolibarr/htdocs/

Enable CDav module in Interfaces Modules list.

It would add a link in Agenda left menu and in Contacts left menu to access DAV / ICS URLs.

Use these URLs in your CardDAV or CalDAV client software.

## How to upgrade

* Disable CDav module in Interfaces Modules list.
* Unzip last version or _git pull_ in dolibarr/htdocs/cdav
* Enable CDav module in Modules list.


## DAV URLs

### Thunderbird

[Thunderbird](https://www.thunderbird.net) (with [Lightning](https://addons.mozilla.org/thunderbird/addon/lightning/), [TBSync](https://addons.thunderbird.net/thunderbird/addon/tbsync/) and its [Provider for CalDAV/CardDAV](https://addons.thunderbird.net/thunderbird/addon/dav-4-tbsync/) addons) needs a precise URL for each address book and calendar :

    https://server.example.com/dolibarr/htdocs/cdav/server.php/calendars/<connected-user-login>/<calendar-user-id>-cal-<calendar-user-login>

    https://server.example.com/dolibarr/htdocs/cdav/server.php/addressbooks/<connected-user-login>/default/

### DAVx⁵

[DAVx⁵](https://www.davx5.com/) can detect automatically address book and all existing calendars (if an event exists) with generic DAV URL :

    https://server.example.com/dolibarr/htdocs/cdav/server.php

You can use a tasks application to manage Dolibarr tasks (VTODO) on Android. DAVx⁵ is compatible with [OpenTasks](https://github.com/dmfs/opentasks).

In CDav configuration, you can activate a QRCode display to autoconfigure DAVx⁵.

Be carefull, if you use https, DAVx⁵ needs a valid SSL certificate, excluding auto-signed certificates.

DAVx⁵ is also available on [F-Droid](https://f-droid.org/packages/at.bitfire.davdroid/).

### iOS

iOS uses _principals_ url to grab list of CalDAV or CardDAV resources :

    https://server.example.com/dolibarr/htdocs/cdav/server.php/principals/<connected-user-login>

### WebDAV

Admin users can also access Dolibarr documents through WebDAV with WebDAV URL :

    https://server.example.com/dolibarr/htdocs/cdav/server.php/documents/

## Troubleshooting

To test cdav module, you can use DAVx⁵ url https://server.example.com/dolibarr/htdocs/cdav/ in a web browser. Error messages are clearer.

### Apache web server

Apache *rewrite* module is necessary if you use fcgi or php-fpm mode. In this case, .htacess file in cdav module has to be read by Apache or reported in your Apache configuration.

    SetEnvIf Authorization "(.*)" HTTP_AUTHORIZATION=$1

or

    <IfModule mod_fastcgi.c>
    	<IfModule mod_rewrite.c>
    		RewriteEngine on
    		RewriteRule .* - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]
    	</IfModule>
    </IfModule>

or (this is usefull on Plesk when nginx is proxying Apache)

    FcgidPassHeader AUTHORIZATION

It is recommanded to *disable* these Apache modules : *dav* / *dav_fs* / *dav_lock*

### nginx web server

To solve authentication loop, add these directives to your nginx "location" rubrique : 

    fastcgi_param PHP_AUTH_USER $remote_user;
    fastcgi_param PHP_AUTH_PW $http_authorization;

or

    fastcgi_pass_header Authorization;

### nginx reverse proxy

To solve authentication loop, add this directive to your nginx "location" rubrique :

    proxy_pass_header Authorization;



# CDav module for Dolibarr

## What is it ?

This module for Dolibarr 3.7/3.8/3.9/4.0/5.0 add CardDAV / CalDAV and ICS synchronisation. It uses included [Sabre/DAV](http://sabre.io/dav/) library.

You can :

 * Read and Edit calendars through CalDAV
 * Read and Edit addressBooks through CardDAV
 * Read calendars through ICS Full version or only Free/Busy (hide details)
 * Access Dolibarr documents through WebDAV (if admin)

Each user can access his/her contacts address book (public and own private contacts), his/her own calendar and other users calendars according to his/her rights.

Dolibarr contact informations fill personnal informations in client software cards (including contact photo).

Society informations (to which contact is attached) fill professional informations in client software cards.

Cards updated in client software fill only Dolibarr contacts (not Society).

It is possible to select which contacts to sync with CDAV_CONTACT_TAG configuration value in Home / Setup / Other setup. Enter a contact tag value and then only contacts with this tag will be synced (empty value for all).

Calendar records with "Status / Percentage" set to "Not applicable" are converted to events in CalDAV (VEVENT), others are converted to tasks (VTODO).

Recurring events are not handled (Dolibarr does not handle them).

## Help improvements

If you find the module is useful and want to finance improvements, consider to pay it on [Dolistore](https://www.dolistore.com/modules/526-Synchronisation-CardDAV---CalDAV---ICS.html)

## How to install

PHP 5.5 or greater is needed.

Dolibarr native calendar module must be activated before installing CDav module.

Like all Dolibarr modules, _git clone_ this repository and install cdav directory in dolibarr/htdocs/

Enable CDav module in Interfaces Modules list.

It would add a link in Agenda left menu and in Contacts left menu to access DAV / ICS URLs.

Use these URLs in your CardDAV or CalDAV client software.

## How to upgrade

* Disable CDav module in Interfaces Modules list.
* Unzip last version or _git pull_ in dolibarr/htdocs/cdav
* Enable CDav module in Modules list.


## DAV URLs

### Thunderbird

[Thunderbird](https://www.mozilla.org/thunderbird/) (with [Lightning](https://addons.mozilla.org/thunderbird/addon/lightning/) and [SoGo Connector](http://www.sogo.nu/downloads/frontends.html) addons) needs a precise URL for each address book and calendar :

    https://server.example.com/dolibarr/htdocs/cdav/server.php/calendars/<connected-user-login>/<calendar-user-id>-cal-<calendar-user-login>

    https://server.example.com/dolibarr/htdocs/cdav/server.php/addressbooks/<connected-user-login>/default/

### DAVDroid

[DAVDroid](https://davdroid.bitfire.at/) can detect automatically address book and all existing calendars (if an event exists) with generic DAV URL :

    https://server.example.com/dolibarr/htdocs/cdav/

You can use a tasks application to manage Dolibarr tasks (VTODO) on Android. DAVDroid is compatible with [OpenTasks](https://github.com/dmfs/opentasks).

Be carefull, if you use https, DAVDroid needs a valid SSL certificate, excluding auto-signed certificates.

### iOS

iOS uses _principals_ url to grab list of CalDAV or CardDAV resources :

    https://server.example.com/dolibarr/htdocs/cdav/server.php/principals/<connected-user-login>

### WebDAV

Admin users can also access Dolibarr documents through WebDAV with WebDAV URL :

    https://server.example.com/dolibarr/htdocs/cdav/server.php/documents/

## Troubleshooting

To test cdav module, you can use DAVDroid url https://server.example.com/dolibarr/htdocs/cdav/ in a web browser. Error messages are clearer.

### Apache web server

Apache *rewrite* module is necessary if you use fcgi or php-fpm mode. In this case, .htacess file in cdav module has to be read by Apache or reported in your Apache configuration.

    <IfModule mod_fastcgi.c>
    	<IfModule mod_rewrite.c>
    		RewriteEngine on
    		RewriteRule .* - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]
    	</IfModule>
    </IfModule>

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



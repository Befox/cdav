# CDav module for Dolibarr

## What is it ?

This module for Dolibarr 3.7 add CardDAV and CalDAV synchronisation. It uses included [Sabre/DAV](http://sabre.io/dav/) library.

This first release only permit DAV client tools to read calendars and address books.

Each user can access his contacts address book (public and own private contacts), his own calendar and other users calendars according to his rights.


## How to install

Like all Dolibarr modules, git clone this repository and install cdav directory in dolibarr/htdocs/

Enable CDav module in Interfaces Modules list.

It would add a link in Agenda left menu and in Contacts left menu to access DAV URLs.

Use these URLs in your CardDAV or CalDAV client software.

## DAV URLs

### Thunderbird

[Thunderbird](https://www.mozilla.org/thunderbird/) (with [Lightning](https://addons.mozilla.org/thunderbird/addon/lightning/) and [SoGo Connector](http://www.sogo.nu/downloads/frontends.html) addons) needs a precise URL for each address book and calendar :

    https://server.example.com/dolibarr/htdocs/cdav/server.php/calendars/<connected-user-login>/<calendar-user-id>-cal-<calendar-user-login>


    https://server.example.com/dolibarr/htdocs/cdav/server.php/addressbooks/<connected-user-login>/default/

### DAVDroid

[DAVDroid](https://davdroid.bitfire.at/) can detect automatically address book and all existing calendars (if an event exists) with generic DAV URL :

	https://server.example.com/dolibarr/htdocs/cdav/

Be carefull, if you use https, DAVDroid needs a valid SSL certificate, excluding auto-signed certificates.

Admin users can also access Dolibarr documents through WebDAV with WebDAV URL :

    https://server.example.com/dolibarr/htdocs/cdav/server.php/documents/

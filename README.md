sqlite-for-piwik
================

This repository contains the files needed to use Piwik with SQLite instead of MySQL.


How to use it
=============

Put the following file into the correct location:
- libs/mysql2sqlite.php
- core/Db/Adapter/Pdo/Sqlite.php
- core/Tracker.php
- core/Tracker/Db/Pdo/Sqlite.php

Edit the config/config.ini.php and add in the [database] section:
- adapter=Pdo_Sqlite
- dbname=piwik.sqlite

And then, execute piwik...


How convert the DB from MySQL to SQLite
=======================================

Go to the help directory and execute the script
- convert.sh USERNAME PASSWORD DATABASE

This script execute the mysql2sqlite.sh, fixblob.php and sqlite3 to generate the new piwik.sqlite file that contains all database migrated to SQLite.


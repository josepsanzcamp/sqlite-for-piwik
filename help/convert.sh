#!/bin/bash

mysql2sqlite.sh -u$1 -p$2 $3 --hex-blob > piwik.sql.1
php fixblob.php piwik.sql.1 piwik.sql.2
cat piwik.sql.2 | sqlite3 piwik.sqlite

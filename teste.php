<?php

echo "<pre>";

echo "PDO SQLite: ";
var_dump(extension_loaded('pdo_sqlite'));

echo "SQLite3: ";
var_dump(extension_loaded('sqlite3'));

echo "PDO MySQL: ";
var_dump(extension_loaded('pdo_mysql'));

echo "MySQLi: ";
var_dump(extension_loaded('mysqli'));
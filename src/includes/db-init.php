<?php
// Database initialization helper
require_once(dirname(__FILE__) . "/config.php");

$opt = getGlobalOptions();

try {
    $dbh = new PDO(
        $opt["pdo_connection_string"],
        $opt["pdo_username"],
        $opt["pdo_password"]
    );
    $dbh->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $dbh->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
}
catch (PDOException $e) {
    die("Database connection failed: " . $e->getMessage());
}

// Provide a global dbh() helper if one isn't already available
if (!function_exists('dbh')) {
    function dbh($optparam = null) {
        global $dbh;
        return $dbh;
    }
}

// If the main `users` table doesn't exist, attempt to initialize the schema
try {
    $dbh->query("SELECT 1 FROM users LIMIT 1");
}
catch (PDOException $e) {
    $sqlFile = dirname(__DIR__) . "/sql/create-phpgiftregdb.sql";
    if (!file_exists($sqlFile)) {
        die("Database not initialized and SQL file missing: " . $sqlFile);
    }

    $contents = file($sqlFile);
    $buffer = '';
    foreach ($contents as $line) {
        $trim = trim($line);
        if ($trim === '' || strpos($trim, '--') === 0 || strpos($trim, '#') === 0) {
            continue;
        }
        $buffer .= $line;
        if (substr(rtrim($line), -1) === ';') {
            try {
                $dbh->exec($buffer);
            }
            catch (PDOException $ex) {
                error_log('Failed to execute SQL statement: ' . $ex->getMessage());
                die('Failed to initialize database: ' . $ex->getMessage());
            }
            $buffer = '';
        }
    }
}

?>

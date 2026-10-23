<?php
/**
 * includes/db.php
 * Gives the rest of the app one shared database connection via db().
 *
 * PDO is PHP's built-in database layer. We use it with "prepared statements":
 * the SQL and the user's data are sent separately, which is what protects
 * the app from SQL injection.
 */

require_once __DIR__ . '/../config/config.php';

function db(): PDO
{
    // "static" keeps the connection alive between calls, so we connect only once per page load.
    static $pdo = null;

    if ($pdo === null) {
        $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET;

        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,   // throw errors instead of failing silently
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,         // rows come back as ['column' => value]
            PDO::ATTR_EMULATE_PREPARES   => false,                    // use real prepared statements
        ];

        try {
            $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
        } catch (PDOException $e) {
            // Never show raw database errors to visitors on a live site.
            if (APP_DEBUG) {
                die('Database connection failed: ' . htmlspecialchars($e->getMessage()));
            }
            die('Sorry, the system is temporarily unavailable.');
        }
    }

    return $pdo;
}
function get_db(): PDO
{
    return db();
}
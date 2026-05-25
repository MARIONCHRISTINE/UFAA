<?php
/**
 * UFAA - Database Configuration (Root)
 * Sets up connection credentials, configures timezone for Kenya, and establishes PDO.
 * Feel free to modify the DB_PASS or DB_HOST if your XAMPP installation uses custom settings!
 */

// Set default timezone to Kenya
date_default_timezone_set('Africa/Nairobi');

define('DB_HOST', '127.0.0.1'); // If your XAMPP uses a custom port, change to e.g., '127.0.0.1:3307'
define('DB_USER', 'root');
define('DB_PASS', '');          // If your MySQL has a password, enter it here!
define('DB_NAME', 'ufaa_db');

try {
    // Attempt standard connection to the MySQL server
    $pdo_init = new PDO(
        "mysql:host=" . DB_HOST . ";charset=utf8mb4",
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );
} catch (PDOException $e) {
    die("Database connection failed! Please ensure MySQL is running in your XAMPP Control Panel. Error details: " . $e->getMessage());
}

/**
 * Returns a connection to the specific UFAA database.
 */
function get_db_connection() {
    try {
        $pdo = new PDO(
            "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
            DB_USER,
            DB_PASS,
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]
        );
        return $pdo;
    } catch (PDOException $e) {
        return null;
    }
}

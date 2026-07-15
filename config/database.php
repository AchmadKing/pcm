<?php
/**
 * Database Configuration
 * PCM - Project Cost Management System
 */

define('DB_HOST', 'localhost');
define('DB_NAME', 'pcm_db');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_CHARSET', 'utf8mb4');

/**
 * Get PDO Database Connection
 * @return PDO
 */
function getDB() {
    static $pdo = null;
    
    if ($pdo === null) {
        try {
            $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
            $options = [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES " . DB_CHARSET
            ];
            $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
        } catch (PDOException $e) {
            die("Database connection failed: " . $e->getMessage());
        }
    }
    
    return $pdo;
}

/**
 * Execute a query with parameters
 * @param string $sql
 * @param array $params
 * @return PDOStatement
 */
function dbQuery($sql, $params = []) {
    $stmt = getDB()->prepare($sql);
    $stmt->execute($params);
    return $stmt;
}

/**
 * Get single row
 * @param string $sql
 * @param array $params
 * @return array|null
 */
function dbGetRow($sql, $params = []) {
    return dbQuery($sql, $params)->fetch();
}

/**
 * Get all rows
 * @param string $sql
 * @param array $params
 * @return array
 */
function dbGetAll($sql, $params = []) {
    return dbQuery($sql, $params)->fetchAll();
}

/**
 * Insert and return last insert ID
 * @param string $sql
 * @param array $params
 * @return int
 */
function dbInsert($sql, $params = []) {
    dbQuery($sql, $params);
    return getDB()->lastInsertId();
}

/**
 * Update/Delete and return affected rows
 * @param string $sql
 * @param array $params
 * @return int
 */
function dbExecute($sql, $params = []) {
    return dbQuery($sql, $params)->rowCount();
}

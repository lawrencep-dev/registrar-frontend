<?php
function db(): PDO {
    static $connection;

    if ($connection instanceof PDO) {
        return $connection;
    }

    try {
        $config = require __DIR__ . '/config.php';
        $dsn = 'mysql:host=' . $config['host'] . ';dbname=' . $config['database'] . ';charset=utf8mb4';
        $connection = new PDO($dsn, $config['user'], $config['password'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        return $connection;
    } catch (Throwable $exception) {
        error_log('LCUPAY database connection failed: ' . $exception->getMessage());
        throw new RuntimeException('Database connection failed.');
    }
}
<?php
$host = getenv('DB_HOST') ?: 'localhost';
$dbname = getenv('DB_NAME') ?: 'school_erp';
$username = getenv('DB_USER') ?: 'root';
$password = getenv('DB_PASS') ?: '';

function ensure_schema_initialized(PDO $pdo): void
{
    $tableExistsStatement = $pdo->query("SHOW TABLES LIKE 'users'");
    $usersTableExists = (bool)$tableExistsStatement->fetchColumn();

    if ($usersTableExists) {
        return;
    }

    $schemaPath = __DIR__ . '/../database/schema.sql';
    if (!file_exists($schemaPath)) {
        die('Database initialization failed: schema.sql not found.');
    }

    $schemaSql = file_get_contents($schemaPath);
    if ($schemaSql === false) {
        die('Database initialization failed: unable to read schema.sql.');
    }

    $statements = array_filter(array_map('trim', explode(';', $schemaSql)));
    foreach ($statements as $statement) {
        if ($statement !== '') {
            $pdo->exec($statement);
        }
    }
}

try {
    $pdo = new PDO(
        "mysql:host={$host};dbname={$dbname};charset=utf8mb4",
        $username,
        $password,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );
} catch (PDOException $exception) {
    $isUnknownDatabase = isset($exception->errorInfo[1]) && (int)$exception->errorInfo[1] === 1049;

    if ($isUnknownDatabase) {
        try {
            $setupPdo = new PDO(
                "mysql:host={$host};charset=utf8mb4",
                $username,
                $password,
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                ]
            );

            $safeDbName = str_replace('`', '``', $dbname);
            $setupPdo->exec("CREATE DATABASE IF NOT EXISTS `{$safeDbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

            $pdo = new PDO(
                "mysql:host={$host};dbname={$dbname};charset=utf8mb4",
                $username,
                $password,
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                ]
            );

            ensure_schema_initialized($pdo);
        } catch (PDOException $setupException) {
            die('Database setup failed: ' . $setupException->getMessage());
        }
    } else {
        die('Database connection failed: ' . $exception->getMessage());
    }
}

ensure_schema_initialized($pdo);

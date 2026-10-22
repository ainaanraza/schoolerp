<?php
// /config/backup.php
// Automated Database Backup to Google Drive Engine
require_once __DIR__ . '/db.php';

// 1. Authorization: Allow CLI execution OR authenticated web calls
$secretToken = 'ItierpSecretBackupToken2026';
$isCli = (php_sapi_name() === 'cli');

if (!$isCli) {
    $providedToken = $_GET['token'] ?? $_POST['token'] ?? '';
    if (!hash_equals($secretToken, (string)$providedToken)) {
        http_response_code(403);
        die("Access denied. Please run from CLI cron or provide valid token ?token=" . htmlspecialchars($secretToken));
    }
    header('Content-Type: text/plain; charset=utf-8');
}

$webAppUrl = 'https://script.google.com/macros/s/AKfycbynv0RjY6t2ZMEjsW7dek4AffKZT4I_tOIEN7Pq1laG2MCRdSEXSxmMUZH2VcplGrrpNw/exec';

function backup_log(string $msg): void
{
    $line = "[" . date('Y-m-d H:i:s') . "] " . $msg . "\n";
    echo $line;
    $logDir = __DIR__ . '/../cron';
    if (is_dir($logDir)) {
        @file_put_contents($logDir . '/backup_cron.log', $line, FILE_APPEND);
    }
}

backup_log("Starting Automated School ERP Database Backup...");
backup_log("Environment: " . ($isHostinger ? 'Hostinger Production' : 'Local Development') . " | Database: " . $dbname);

// 2. Generate the SQL Backup File
$backupFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'itierp_backup_' . date('Y-m-d_H-i-s') . '.sql';
$dumpMethod = 'unknown';

// Attempt 1: Try mysqldump (if binary is installed and permitted)
$mysqldumpBinaries = ['mysqldump', '/usr/bin/mysqldump', '/usr/local/bin/mysqldump', '/usr/local/mysql/bin/mysqldump'];
$mysqldumpSucceeded = false;

if (function_exists('exec')) {
    foreach ($mysqldumpBinaries as $bin) {
        $command = sprintf(
            '%s --user=%s --password=%s --host=%s %s > %s 2>&1',
            escapeshellcmd($bin),
            escapeshellarg($username),
            escapeshellarg($password),
            escapeshellarg($host),
            escapeshellarg($dbname),
            escapeshellarg($backupFile)
        );
        @exec($command, $output, $returnVar);

        if ($returnVar === 0 && file_exists($backupFile) && filesize($backupFile) > 100) {
            $mysqldumpSucceeded = true;
            $dumpMethod = "mysqldump ({$bin})";
            break;
        }
    }
}

// Attempt 2: Pure PHP PDO Engine Fallback (100% reliable on any shared hosting without external binaries)
if (!$mysqldumpSucceeded) {
    backup_log("Notice: mysqldump binary unavailable or failed. Switching to built-in PHP PDO Dump Engine...");
    
    try {
        $handle = fopen($backupFile, 'w');
        if (!$handle) {
            throw new RuntimeException("Unable to open temp file for writing: " . $backupFile);
        }

        fwrite($handle, "-- ======================================================\n");
        fwrite($handle, "-- School ERP Database Backup (PHP PDO Engine)\n");
        fwrite($handle, "-- Generated: " . date('Y-m-d H:i:s') . "\n");
        fwrite($handle, "-- Database: " . $dbname . "\n");
        fwrite($handle, "-- ======================================================\n\n");
        fwrite($handle, "SET NAMES utf8mb4;\n");
        fwrite($handle, "SET FOREIGN_KEY_CHECKS = 0;\n\n");

        $tablesStmt = $pdo->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'");
        $tables = $tablesStmt->fetchAll(PDO::FETCH_NUM);

        $tableCount = 0;
        $rowCount = 0;

        foreach ($tables as $tRow) {
            $table = $tRow[0];
            $tableCount++;

            fwrite($handle, "--\n-- Table structure for table `{$table}`\n--\n");
            fwrite($handle, "DROP TABLE IF EXISTS `{$table}`;\n");

            $createStmt = $pdo->query("SHOW CREATE TABLE `{$table}`");
            $createRow = $createStmt->fetch(PDO::FETCH_ASSOC);
            if (isset($createRow['Create Table'])) {
                fwrite($handle, $createRow['Create Table'] . ";\n\n");
            }

            fwrite($handle, "--\n-- Dumping data for table `{$table}`\n--\n");

            $rowsStmt = $pdo->query("SELECT * FROM `{$table}`");
            $rows = $rowsStmt->fetchAll(PDO::FETCH_ASSOC);

            if (!empty($rows)) {
                $cols = array_keys($rows[0]);
                $colList = '`' . implode('`, `', $cols) . '`';

                $chunkSize = 100;
                $chunks = array_chunk($rows, $chunkSize);

                foreach ($chunks as $chunk) {
                    $valuesList = [];
                    foreach ($chunk as $row) {
                        $rowCount++;
                        $rowValues = [];
                        foreach ($row as $val) {
                            if ($val === null) {
                                $rowValues[] = 'NULL';
                            } else {
                                $rowValues[] = $pdo->quote((string)$val);
                            }
                        }
                        $valuesList[] = '(' . implode(', ', $rowValues) . ')';
                    }
                    fwrite($handle, "INSERT INTO `{$table}` ({$colList}) VALUES\n" . implode(",\n", $valuesList) . ";\n");
                }
            }
            fwrite($handle, "\n");
        }

        fwrite($handle, "SET FOREIGN_KEY_CHECKS = 1;\n");
        fwrite($handle, "-- Dump completed on " . date('Y-m-d H:i:s') . "\n");
        fclose($handle);

        $dumpMethod = "PHP PDO Engine ({$tableCount} tables, {$rowCount} rows)";
    } catch (Throwable $t) {
        backup_log("FATAL: PHP PDO Dump failed: " . $t->getMessage());
        die("Backup failed during SQL export.");
    }
}

if (!file_exists($backupFile) || filesize($backupFile) === 0) {
    backup_log("FATAL: Generated backup file is empty or missing.");
    die("Backup failed: empty backup file.");
}

$fileSizeBytes = filesize($backupFile);
$fileSizeFormatted = number_format($fileSizeBytes / (1024 * 1024), 2) . ' MB';
backup_log("SQL Dump generated successfully via {$dumpMethod} (Size: {$fileSizeFormatted}).");

// 3. Send to Google Drive via cURL
backup_log("Uploading archive to Google Drive Web App...");
$fileData = base64_encode(file_get_contents($backupFile));

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $webAppUrl);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
    'file' => $fileData,
    'filename' => basename($backupFile),
    'token' => $secretToken
]));
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
curl_setopt($ch, CURLOPT_TIMEOUT, 120);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlErr = curl_error($ch);
curl_close($ch);

// 4. Clean up temporary local file
if (file_exists($backupFile)) {
    @unlink($backupFile);
}

// 5. Evaluate and Report Response
if ($httpCode === 200 && str_contains((string)$response, 'Success')) {
    backup_log("BACKUP SUCCESSFUL! Google Drive response: " . trim((string)$response));
    echo "\n>>> STATUS: SUCCESS\n";
    exit(0);
} else {
    $errDetail = $curlErr ? "cURL Error: {$curlErr}" : "HTTP {$httpCode}: " . substr((string)$response, 0, 300);
    backup_log("BACKUP FAILED. Details: " . $errDetail);
    echo "\n>>> STATUS: FAILED\n";
    exit(1);
}

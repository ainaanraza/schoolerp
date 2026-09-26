<?php
// /config/backup.php
require_once __DIR__ . '/db.php';

// 1. Configuration
$secretToken = 'ItierpSecretBackupToken2026'; // Match this with your Google Script token
$webAppUrl = 'https://script.google.com/macros/s/AKfycbynv0RjY6t2ZMEjsW7dek4AffKZT4I_tOIEN7Pq1laG2MCRdSEXSxmMUZH2VcplGrrpNw/exec'; // You will get this in Step 2

// 2. Generate the SQL Backup File
$backupFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'itierp_backup_' . date('Y-m-d_H-i-s') . '.sql';

// Try using mysqldump (standard for Hostinger)
$command = sprintf(
    'mysqldump --user=%s --password=%s --host=%s %s > %s',
    escapeshellarg($username),
    escapeshellarg($password),
    escapeshellarg($host),
    escapeshellarg($dbname),
    escapeshellarg($backupFile)
);
exec($command, $output, $returnVar);

if ($returnVar !== 0 || !file_exists($backupFile)) {
    die("Backup generation failed (Code: $returnVar).");
}

if (filesize($backupFile) === 0) {
    die("Backup generation failed: The generated SQL file is 0 bytes. Check database credentials or mysqldump output.");
}

// 3. Send to Google Drive via cURL (using Base64 to bypass Google's multipart bugs)
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

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

// 4. Clean up the temporary file
unlink($backupFile);

if ($httpCode === 200) {
    echo "Backup successful: " . $response;
} else {
    echo "Backup failed. Response: " . $response;
}

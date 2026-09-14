<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_roles([ROLE_SUPER_ADMIN, ROLE_ADMIN]);

function redirect_with_message(string $query): void
{
    header('Location: /school-erp/modules/admission/leads.php' . $query);
    exit;
}

function normalize_header(string $header): string
{
    $header = strtolower(trim($header));
    $header = preg_replace('/[^a-z0-9]+/', '_', $header);
    return trim((string)$header, '_');
}

function csv_value(array $data, string $key): string
{
    return trim((string)($data[$key] ?? ''));
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect_with_message('');
}

$file = $_FILES['excel_file'] ?? null;
if ($file === null || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
    redirect_with_message('?import_error=' . urlencode('Please choose a CSV file to upload.'));
}
if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
    redirect_with_message('?import_error=' . urlencode('File upload failed.'));
}

$extension = strtolower(pathinfo((string)$file['name'], PATHINFO_EXTENSION));
if (!in_array($extension, ['csv', 'txt'], true)) {
    redirect_with_message('?import_error=' . urlencode('Only CSV files are supported for import.'));
}

$handle = fopen($file['tmp_name'], 'r');
if ($handle === false) {
    redirect_with_message('?import_error=' . urlencode('Unable to read uploaded file.'));
}

$headerRow = fgetcsv($handle);
if ($headerRow === false) {
    fclose($handle);
    redirect_with_message('?import_error=' . urlencode('CSV file is empty.'));
}

$headers = array_map(static fn($value): string => normalize_header((string)$value), $headerRow);

$allowedStatuses = ['new', 'contacted', 'approved', 'rejected', 'enrolled'];
$allowedModes = ['cash', 'card', 'upi', 'bank_transfer', 'online_gateway'];
$inserted = 0;
$failed = 0;

$classExistsStmt = $pdo->prepare('SELECT id FROM classes WHERE id = :id LIMIT 1');

$insertStmt = $pdo->prepare(
    'INSERT INTO leads (
        lead_name, guardian_name, guardian_phone, guardian_email, email, phone,
          class_id, class_applied, status, admission_fee_paid, admission_fee_amount,
          admission_concession_amount, admission_concession_note,
          payment_mode, payment_confirmed_at, source, notes, created_by
     ) VALUES (
        :lead_name, :guardian_name, :guardian_phone, :guardian_email, :email, :phone,
          :class_id, :class_applied, :status, :admission_fee_paid, :admission_fee_amount,
          :admission_concession_amount, :admission_concession_note,
          :payment_mode, :payment_confirmed_at, :source, :notes, :created_by
     )'
);

$rowNumber = 1;
while (($row = fgetcsv($handle)) !== false) {
    $rowNumber++;
    if (count($row) === 1 && trim((string)$row[0]) === '') {
        continue;
    }

    $data = [];
    foreach ($headers as $index => $key) {
        $data[$key] = trim((string)($row[$index] ?? ''));
    }

    $leadName = csv_value($data, 'lead_name');
    if ($leadName === '') {
        $leadName = 'Imported Lead ' . date('YmdHis') . '-' . $rowNumber;
    }

    $statusValue = csv_value($data, 'status');
    $status = in_array($statusValue, $allowedStatuses, true) ? $statusValue : 'new';
    $amountValue = csv_value($data, 'admission_fee_amount');
    $amount = $amountValue !== '' && is_numeric($amountValue)
        ? (float)$amountValue
        : 0.0;
    $concessionValue = csv_value($data, 'admission_concession_amount');
    $concessionAmount = $concessionValue !== '' && is_numeric($concessionValue)
        ? (float)$concessionValue
        : 0.0;
    $concessionNote = csv_value($data, 'admission_concession_note');
    $modeValue = csv_value($data, 'payment_mode');
    $mode = in_array($modeValue, $allowedModes, true) ? $modeValue : null;
    $classIdValue = csv_value($data, 'class_id');
    $classId = $classIdValue !== '' && ctype_digit($classIdValue) ? (int)$classIdValue : null;
    if ($classId !== null) {
        $classExistsStmt->execute(['id' => $classId]);
        if (!$classExistsStmt->fetch()) {
            $classId = null;
        }
    }

    try {
        $insertStmt->execute([
            'lead_name' => $leadName,
            'guardian_name' => csv_value($data, 'guardian_name') !== '' ? csv_value($data, 'guardian_name') : null,
            'guardian_phone' => csv_value($data, 'guardian_phone') !== '' ? csv_value($data, 'guardian_phone') : null,
            'guardian_email' => csv_value($data, 'guardian_email') !== '' ? csv_value($data, 'guardian_email') : null,
            'email' => csv_value($data, 'email') !== '' ? csv_value($data, 'email') : null,
            'phone' => csv_value($data, 'phone') !== '' ? csv_value($data, 'phone') : null,
            'class_id' => $classId,
            'class_applied' => csv_value($data, 'class_applied') !== '' ? csv_value($data, 'class_applied') : null,
            'status' => $status,
            'admission_fee_paid' => $amount > 0 ? 1 : 0,
            'admission_fee_amount' => $amount > 0 ? $amount : null,
            'admission_concession_amount' => $concessionAmount > 0 ? $concessionAmount : null,
            'admission_concession_note' => $concessionNote !== '' ? $concessionNote : null,
            'payment_mode' => $mode,
            'payment_confirmed_at' => $amount > 0 ? date('Y-m-d H:i:s') : null,
            'source' => csv_value($data, 'source') !== '' ? csv_value($data, 'source') : 'manual',
            'notes' => csv_value($data, 'notes') !== '' ? csv_value($data, 'notes') : null,
            'created_by' => (int)current_user()['id'],
        ]);
        $inserted++;
    } catch (Throwable $throwable) {
        $failed++;
    }
}

fclose($handle);
redirect_with_message('?imported=' . $inserted . '&failed=' . $failed);

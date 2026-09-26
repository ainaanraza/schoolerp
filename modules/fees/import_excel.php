<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_roles([ROLE_SUPER_ADMIN, ROLE_ADMIN]);

function redirect_fees(string $query): void
{
    header('Location: /itierp/modules/fees/student_fees.php' . $query);
    exit;
}

function normalize_header_fees(string $header): string
{
    $header = strtolower(trim($header));
    $header = preg_replace('/[^a-z0-9]+/', '_', $header);
    return trim((string)$header, '_');
}

function csv_value_fees(array $data, string $key): string
{
    return trim((string)($data[$key] ?? ''));
}

function fee_status_from_amount(float $payable, float $paid): string
{
    if ($payable <= 0 || $paid >= $payable) {
        return 'paid';
    }
    if ($paid > 0) {
        return 'partial';
    }
    return 'pending';
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect_fees('');
}

$file = $_FILES['excel_file'] ?? null;
if ($file === null || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
    redirect_fees('?import_error=' . urlencode('Please choose a CSV file to upload.'));
}
if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
    redirect_fees('?import_error=' . urlencode('File upload failed.'));
}

$extension = strtolower(pathinfo((string)$file['name'], PATHINFO_EXTENSION));
if (!in_array($extension, ['csv', 'txt'], true)) {
    redirect_fees('?import_error=' . urlencode('Only CSV files are supported for import.'));
}

$handle = fopen($file['tmp_name'], 'r');
if ($handle === false) {
    redirect_fees('?import_error=' . urlencode('Unable to read uploaded file.'));
}

$headerRow = fgetcsv($handle);
if ($headerRow === false) {
    fclose($handle);
    redirect_fees('?import_error=' . urlencode('CSV file is empty.'));
}

$headers = array_map(static fn($value): string => normalize_header_fees((string)$value), $headerRow);

$allowedMethods = ['cash', 'card', 'upi', 'bank_transfer', 'online_gateway'];
$imported = 0;
$failed = 0;
$skipped = 0;

$findStudent = $pdo->prepare('SELECT id FROM students WHERE admission_no = :admission_no LIMIT 1');
$fetchFees = $pdo->prepare(
    'SELECT sf.id, sf.payable_amount, sf.paid_amount
     FROM student_fees sf
     JOIN fee_structures fs ON fs.id = sf.fee_structure_id
     WHERE sf.student_id = :student_id
     ORDER BY CASE fs.fee_type WHEN "caution_money" THEN 0 WHEN "admission_fee" THEN 1 WHEN "tuition_fee" THEN 2 ELSE 3 END,
              CASE WHEN fs.billing_cycle = "one_time" THEN 0 ELSE 1 END,
              sf.due_date IS NULL,
              sf.due_date,
              sf.id
     FOR UPDATE'
);
$insertPayment = $pdo->prepare(
    'INSERT INTO payments (student_fee_id, amount, payment_date, method, transaction_ref, status, received_by)
     VALUES (:student_fee_id, :amount, NOW(), :method, :transaction_ref, :status, :received_by)'
);
$updateFee = $pdo->prepare(
    'UPDATE student_fees
     SET paid_amount = :paid_amount,
         status = :status
     WHERE id = :id'
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

    $admissionNo = csv_value_fees($data, 'admission_no');
    $amountValue = csv_value_fees($data, 'amount');
    $amount = $amountValue !== '' && is_numeric($amountValue) ? (float)$amountValue : 0.0;
    $methodValue = csv_value_fees($data, 'method');
    $method = in_array($methodValue, $allowedMethods, true) ? $methodValue : 'cash';

    if ($admissionNo === '' || $amount <= 0) {
        $skipped++;
        continue;
    }

    try {
        $pdo->beginTransaction();

        $findStudent->execute(['admission_no' => $admissionNo]);
        $studentId = (int)($findStudent->fetchColumn() ?: 0);
        if ($studentId <= 0) {
            throw new RuntimeException('Student not found');
        }

        $fetchFees->execute(['student_id' => $studentId]);
        $fees = $fetchFees->fetchAll();
        if (empty($fees)) {
            throw new RuntimeException('No fee records found');
        }

        $remaining = $amount;
        $transactionRef = 'IMP-' . date('YmdHis') . '-' . $rowNumber;

        foreach ($fees as $fee) {
            if ($remaining <= 0) {
                break;
            }

            $payable = (float)$fee['payable_amount'];
            $paid = (float)$fee['paid_amount'];
            $outstanding = max(0, $payable - $paid);
            if ($outstanding <= 0) {
                continue;
            }

            $applied = min($remaining, $outstanding);
            $newPaid = $paid + $applied;

            $insertPayment->execute([
                'student_fee_id' => (int)$fee['id'],
                'amount' => $applied,
                'method' => $method,
                'transaction_ref' => $transactionRef,
                'status' => 'success',
                'received_by' => (int)current_user()['id'],
            ]);

            $updateFee->execute([
                'paid_amount' => $newPaid,
                'status' => fee_status_from_amount($payable, $newPaid),
                'id' => (int)$fee['id'],
            ]);

            $remaining -= $applied;
        }

        $pdo->commit();
        $imported++;
    } catch (Throwable $throwable) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $failed++;
    }
}

fclose($handle);
redirect_fees('?imported=' . $imported . '&failed=' . $failed . '&skipped=' . $skipped);

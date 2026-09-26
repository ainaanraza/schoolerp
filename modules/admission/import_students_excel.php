<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_roles([ROLE_SUPER_ADMIN, ROLE_ADMIN]);

function redirect_students(string $query): void
{
    header('Location: /itierp/modules/admission/students.php' . $query);
    exit;
}

function normalize_header_students(string $header): string
{
    $header = strtolower(trim($header));
    $header = preg_replace('/[^a-z0-9]+/', '_', $header);
    return trim((string)$header, '_');
}

function csv_value_students(array $data, string $key): string
{
    return trim((string)($data[$key] ?? ''));
}

function email_exists_students(PDO $pdo, string $email): bool
{
    $statement = $pdo->prepare('SELECT id FROM users WHERE email = :email LIMIT 1');
    $statement->execute(['email' => $email]);
    return (bool)$statement->fetch();
}

function build_unique_student_email(PDO $pdo, string $admissionNo, ?string $candidate): string
{
    if ($candidate !== null && $candidate !== '' && filter_var($candidate, FILTER_VALIDATE_EMAIL) && !email_exists_students($pdo, $candidate)) {
        return $candidate;
    }

    $base = strtolower(preg_replace('/[^a-z0-9]+/', '.', $admissionNo));
    $base = trim((string)$base, '.');
    if ($base === '') {
        $base = 'student';
    }

    $suffix = 1;
    do {
        $email = $base . ($suffix > 1 ? (string)$suffix : '') . '@school.local';
        $suffix++;
    } while (email_exists_students($pdo, $email));

    return $email;
}

function generate_temp_password_students(int $length = 10): string
{
    $characters = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789!@#$%';
    $maxIndex = strlen($characters) - 1;
    $password = '';

    for ($index = 0; $index < $length; $index++) {
        $password .= $characters[random_int(0, $maxIndex)];
    }

    return $password;
}

function admission_no_exists(PDO $pdo, string $admissionNo): bool
{
    $statement = $pdo->prepare('SELECT id FROM students WHERE admission_no = :admission_no LIMIT 1');
    $statement->execute(['admission_no' => $admissionNo]);
    return (bool)$statement->fetch();
}

function generate_unique_admission_no(PDO $pdo): string
{
    $attempt = 1;
    do {
        $candidate = 'AUTO' . date('Ymd') . sprintf('%04d', $attempt);
        $attempt++;
    } while (admission_no_exists($pdo, $candidate));

    return $candidate;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect_students('');
}

$file = $_FILES['excel_file'] ?? null;
if ($file === null || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
    redirect_students('?import_error=' . urlencode('Please choose a CSV file to upload.'));
}
if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
    redirect_students('?import_error=' . urlencode('File upload failed.'));
}

$extension = strtolower(pathinfo((string)$file['name'], PATHINFO_EXTENSION));
if (!in_array($extension, ['csv', 'txt'], true)) {
    redirect_students('?import_error=' . urlencode('Only CSV files are supported for import.'));
}

$handle = fopen($file['tmp_name'], 'r');
if ($handle === false) {
    redirect_students('?import_error=' . urlencode('Unable to read uploaded file.'));
}

$headerRow = fgetcsv($handle);
if ($headerRow === false) {
    fclose($handle);
    redirect_students('?import_error=' . urlencode('CSV file is empty.'));
}

$headers = array_map(static fn($value): string => normalize_header_students((string)$value), $headerRow);

$allowedStatuses = ['lead', 'enrolled', 'inactive'];
$inserted = 0;
$updated = 0;
$failed = 0;

$findStudent = $pdo->prepare('SELECT id, user_id FROM students WHERE admission_no = :admission_no LIMIT 1');
$insertUser = $pdo->prepare('INSERT INTO users (full_name, email, password_hash, role, is_active) VALUES (:full_name, :email, :password_hash, :role, 1)');
$insertStudent = $pdo->prepare(
    'INSERT INTO students (user_id, admission_no, roll_number, dob, gender, address, status)
     VALUES (:user_id, :admission_no, :roll_number, :dob, :gender, :address, :status)'
);
$updateStudent = $pdo->prepare(
    'UPDATE students
     SET roll_number = :roll_number, dob = :dob, gender = :gender, address = :address, status = :status
     WHERE id = :id'
);
$getClassSession = $pdo->prepare('SELECT session_id FROM classes WHERE id = :class_id LIMIT 1');
$upsertEnrollment = $pdo->prepare(
    'INSERT INTO student_class_enrollments (student_id, class_id, session_id, is_active)
     VALUES (:student_id, :class_id, :session_id, 1)
     ON DUPLICATE KEY UPDATE class_id = VALUES(class_id), is_active = 1'
);

while (($row = fgetcsv($handle)) !== false) {
    if (count($row) === 1 && trim((string)$row[0]) === '') {
        continue;
    }

    $data = [];
    foreach ($headers as $index => $key) {
        $data[$key] = trim((string)($row[$index] ?? ''));
    }

    $admissionNo = csv_value_students($data, 'admission_no');
    if ($admissionNo === '') {
        $admissionNo = generate_unique_admission_no($pdo);
    }

    $statusValue = csv_value_students($data, 'status');
    $status = in_array($statusValue, $allowedStatuses, true) ? $statusValue : 'enrolled';
    $dobValue = csv_value_students($data, 'dob');
    $dob = preg_match('/^\d{4}-\d{2}-\d{2}$/', $dobValue) ? $dobValue : null;
    $genderValue = csv_value_students($data, 'gender');
    $gender = in_array($genderValue, ['male', 'female', 'other'], true) ? $genderValue : null;
    $classIdValue = csv_value_students($data, 'class_id');
    $classId = $classIdValue !== '' && ctype_digit($classIdValue) ? (int)$classIdValue : 0;

    try {
        $pdo->beginTransaction();

        $findStudent->execute(['admission_no' => $admissionNo]);
        $existing = $findStudent->fetch();

        if ($existing) {
            $studentId = (int)$existing['id'];
            $updateStudent->execute([
                'roll_number' => csv_value_students($data, 'roll_number') !== '' ? csv_value_students($data, 'roll_number') : null,
                'dob' => $dob,
                'gender' => $gender,
                'address' => csv_value_students($data, 'address') !== '' ? csv_value_students($data, 'address') : null,
                'status' => $status,
                'id' => $studentId,
            ]);
            $updated++;
        } else {
            $fullNameValue = csv_value_students($data, 'full_name');
            $fullName = $fullNameValue !== '' ? $fullNameValue : ('Student ' . $admissionNo);
            $emailValue = csv_value_students($data, 'email');
            $email = build_unique_student_email($pdo, $admissionNo, $emailValue !== '' ? $emailValue : null);

            $insertUser->execute([
                'full_name' => $fullName,
                'email' => $email,
                'password_hash' => password_hash(generate_temp_password_students(), PASSWORD_DEFAULT),
                'role' => ROLE_STUDENT,
            ]);
            $userId = (int)$pdo->lastInsertId();

            $insertStudent->execute([
                'user_id' => $userId,
                'admission_no' => $admissionNo,
                'roll_number' => csv_value_students($data, 'roll_number') !== '' ? csv_value_students($data, 'roll_number') : null,
                'dob' => $dob,
                'gender' => $gender,
                'address' => csv_value_students($data, 'address') !== '' ? csv_value_students($data, 'address') : null,
                'status' => $status,
            ]);
            $studentId = (int)$pdo->lastInsertId();
            $inserted++;
        }

        if ($classId > 0) {
            $getClassSession->execute(['class_id' => $classId]);
            $sessionId = (int)($getClassSession->fetchColumn() ?: 0);
            if ($sessionId > 0) {
                $upsertEnrollment->execute([
                    'student_id' => $studentId,
                    'class_id' => $classId,
                    'session_id' => $sessionId,
                ]);
            }
        }

        $pdo->commit();
    } catch (Throwable $throwable) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $failed++;
    }
}

fclose($handle);
redirect_students('?imported=' . $inserted . '&updated=' . $updated . '&failed=' . $failed);

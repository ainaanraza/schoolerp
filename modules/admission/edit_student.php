<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_roles([ROLE_SUPER_ADMIN, ROLE_ADMIN]);

$errors = [];
$success = [];

$studentId = (int)($_GET['id'] ?? $_POST['student_id'] ?? 0);
if ($studentId <= 0) {
    http_response_code(404);
    echo 'Student not found.';
    exit;
}

$studentStatement = $pdo->prepare(
    'SELECT
        s.id,
        s.admission_no,
        s.roll_number,
        s.dob,
        s.gender,
        s.photo_path,
        s.address,
        s.status,
        s.user_id,
        u.full_name,
        u.email,
        pu.full_name AS parent_name,
        pu.email AS parent_email,
        pu.id AS parent_user_id,
        p.id AS parent_id,
        p.phone AS parent_phone,
        p.address AS parent_address,
        CONCAT(c.class_name, " - ", c.section) AS class_label,
        sce.class_id,
        sce.session_id,
        current_session.title AS enrollment_session_title
     FROM students s
     LEFT JOIN users u ON u.id = s.user_id
     LEFT JOIN parent_student ps ON ps.student_id = s.id
     LEFT JOIN parents p ON p.id = ps.parent_id
     LEFT JOIN users pu ON pu.id = p.user_id
     LEFT JOIN student_class_enrollments sce ON sce.student_id = s.id AND sce.is_active = 1
     LEFT JOIN classes c ON c.id = sce.class_id
    LEFT JOIN academic_sessions current_session ON current_session.id = sce.session_id
     WHERE s.id = :student_id
     LIMIT 1'
);
$studentStatement->execute(['student_id' => $studentId]);
$student = $studentStatement->fetch();

if (!$student) {
    http_response_code(404);
    echo 'Student not found.';
    exit;
}

$classes = [];
$classesStatement = $pdo->query(
    'SELECT c.id, c.class_name, c.section, s.title AS session_title, s.is_active AS session_is_active
     FROM classes c
     JOIN academic_sessions s ON s.id = c.session_id
     ORDER BY s.is_active DESC, s.start_date DESC, c.class_name, c.section'
);
$classes = $classesStatement->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $fullName = trim($_POST['full_name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $rollNumber = trim($_POST['roll_number'] ?? '');
    $dob = trim($_POST['dob'] ?? '');
    $gender = trim($_POST['gender'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $parentPhone = trim($_POST['parent_phone'] ?? '');
    $parentAddress = trim($_POST['parent_address'] ?? '');
    $status = trim($_POST['status'] ?? '');
    $classId = (int)($_POST['class_id'] ?? 0);

    $allowedStatuses = ['lead', 'enrolled', 'inactive'];
    $allowedGenders = ['', 'male', 'female', 'other'];

    if ($fullName === '' || $email === '') {
        $errors[] = 'Student name and email are required.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Invalid email format.';
    }
    if (!in_array($status, $allowedStatuses, true)) {
        $errors[] = 'Invalid student status selected.';
    }
    if (!in_array($gender, $allowedGenders, true)) {
        $errors[] = 'Invalid gender selected.';
    }
    if ($classId <= 0) {
        $errors[] = 'Please select a class.';
    }
    if ($dob !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dob)) {
        $errors[] = 'Date of birth must be in YYYY-MM-DD format.';
    }

    $emailOwnerStatement = $pdo->prepare('SELECT id FROM users WHERE email = :email AND id <> :id LIMIT 1');
    $emailOwnerStatement->execute([
        'email' => $email,
        'id' => (int)$student['user_id'],
    ]);
    if ($emailOwnerStatement->fetch()) {
        $errors[] = 'Another account already uses this email.';
    }

    $classStatement = $pdo->prepare(
        'SELECT id, session_id
         FROM classes
         WHERE id = :class_id
         LIMIT 1'
    );
    if ($classId > 0) {
        $classStatement->execute(['class_id' => $classId]);
        $selectedClass = $classStatement->fetch();
        if (!$selectedClass) {
            $errors[] = 'Selected class was not found.';
        }
    }

    if (empty($errors)) {
        try {
            $pdo->beginTransaction();

            $updateUserStatement = $pdo->prepare(
                'UPDATE users
                 SET full_name = :full_name,
                     email = :email
                 WHERE id = :id'
            );
            $updateUserStatement->execute([
                'full_name' => $fullName,
                'email' => $email,
                'id' => (int)$student['user_id'],
            ]);

            $updateStudentStatement = $pdo->prepare(
                'UPDATE students
                 SET roll_number = :roll_number,
                     dob = :dob,
                     gender = :gender,
                     address = :address,
                     status = :status
                 WHERE id = :id'
            );
            $updateStudentStatement->execute([
                'roll_number' => $rollNumber !== '' ? $rollNumber : null,
                'dob' => $dob !== '' ? $dob : null,
                'gender' => $gender !== '' ? $gender : null,
                'address' => $address !== '' ? $address : null,
                'status' => $status,
                'id' => $studentId,
            ]);

            if ((int)($student['parent_id'] ?? 0) > 0) {
                $updateParentStatement = $pdo->prepare(
                    'UPDATE parents
                     SET phone = :phone,
                         address = :address
                     WHERE id = :id'
                );
                $updateParentStatement->execute([
                    'phone' => $parentPhone !== '' ? $parentPhone : null,
                    'address' => $parentAddress !== '' ? $parentAddress : null,
                    'id' => (int)$student['parent_id'],
                ]);
            }

            if (!empty($selectedClass)) {
                $selectedSessionId = (int)$selectedClass['session_id'];
                $enrollmentStatement = $pdo->prepare(
                    'SELECT id
                     FROM student_class_enrollments
                     WHERE student_id = :student_id
                       AND session_id = :session_id
                     LIMIT 1'
                );
                $enrollmentStatement->execute([
                    'student_id' => $studentId,
                    'session_id' => $selectedSessionId,
                ]);
                $enrollment = $enrollmentStatement->fetch();

                if ($enrollment) {
                    $updateEnrollmentStatement = $pdo->prepare(
                        'UPDATE student_class_enrollments
                         SET class_id = :class_id,
                             is_active = 1
                         WHERE id = :id'
                    );
                    $updateEnrollmentStatement->execute([
                        'class_id' => $classId,
                        'id' => (int)$enrollment['id'],
                    ]);
                } else {
                    $insertEnrollmentStatement = $pdo->prepare(
                        'INSERT INTO student_class_enrollments (student_id, class_id, session_id, is_active)
                         VALUES (:student_id, :class_id, :session_id, 1)'
                    );
                    $insertEnrollmentStatement->execute([
                        'student_id' => $studentId,
                        'class_id' => $classId,
                        'session_id' => $selectedSessionId,
                    ]);
                }
            }

            $pdo->commit();
            header('Location: /school-erp/modules/admission/students.php?updated=1');
            exit;
        } catch (Throwable $throwable) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $errors[] = 'Unable to update student: ' . $throwable->getMessage();
        }
    }

    $student['full_name'] = $fullName;
    $student['email'] = $email;
    $student['roll_number'] = $rollNumber;
    $student['dob'] = $dob;
    $student['gender'] = $gender;
    $student['address'] = $address;
    $student['parent_phone'] = $parentPhone;
    $student['parent_address'] = $parentAddress;
    $student['status'] = $status;
    $student['class_id'] = $classId;
}

$pageTitle = 'Edit Student';
require __DIR__ . '/../../includes/header.php';
?>
<section class="card">
    <h2>Edit Student</h2>
    <p>Update the student profile, current class assignment, and enrollment details.</p>

    <?php if (!empty($errors)): ?>
        <div class="error">
            <?php foreach ($errors as $error): ?>
                <p><?= htmlspecialchars($error) ?></p>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <form method="post" class="form-grid form-grid-wide">
        <input type="hidden" name="student_id" value="<?= (int)$student['id'] ?>">

        <label>Student Name</label>
        <input type="text" name="full_name" value="<?= htmlspecialchars((string)$student['full_name']) ?>" required>

        <label>Student Email</label>
        <input type="email" name="email" value="<?= htmlspecialchars((string)$student['email']) ?>" required>

        <label>Admission No</label>
        <input type="text" value="<?= htmlspecialchars((string)$student['admission_no']) ?>" readonly>

        <label>Roll Number</label>
        <input type="text" name="roll_number" value="<?= htmlspecialchars((string)($student['roll_number'] ?? '')) ?>">

        <label>Date of Birth</label>
        <input type="date" name="dob" value="<?= htmlspecialchars((string)($student['dob'] ?? '')) ?>">

        <label>Gender</label>
        <select name="gender">
            <option value="" <?= empty($student['gender']) ? 'selected' : '' ?>>Not set</option>
            <option value="male" <?= ($student['gender'] ?? '') === 'male' ? 'selected' : '' ?>>Male</option>
            <option value="female" <?= ($student['gender'] ?? '') === 'female' ? 'selected' : '' ?>>Female</option>
            <option value="other" <?= ($student['gender'] ?? '') === 'other' ? 'selected' : '' ?>>Other</option>
        </select>

        <label>Address</label>
        <textarea name="address" rows="3"><?= htmlspecialchars((string)($student['address'] ?? '')) ?></textarea>

        <label>Parent Phone</label>
        <input type="text" name="parent_phone" value="<?= htmlspecialchars((string)($student['parent_phone'] ?? '')) ?>">

        <label>Parent Address</label>
        <textarea name="parent_address" rows="2"><?= htmlspecialchars((string)($student['parent_address'] ?? '')) ?></textarea>

        <label>Status</label>
        <select name="status" required>
            <option value="lead" <?= ($student['status'] ?? '') === 'lead' ? 'selected' : '' ?>>Lead</option>
            <option value="enrolled" <?= ($student['status'] ?? '') === 'enrolled' ? 'selected' : '' ?>>Enrolled</option>
            <option value="inactive" <?= ($student['status'] ?? '') === 'inactive' ? 'selected' : '' ?>>Inactive</option>
        </select>

        <label>Class Assignment</label>
        <select name="class_id" required>
            <option value="">Select class</option>
            <?php foreach ($classes as $class): ?>
                <option value="<?= (int)$class['id'] ?>" <?= (int)($student['class_id'] ?? 0) === (int)$class['id'] ? 'selected' : '' ?>>
                    <?= htmlspecialchars($class['class_name'] . ' - ' . $class['section'] . ' (' . $class['session_title'] . ')') ?>
                </option>
            <?php endforeach; ?>
        </select>

        <label>Current Enrollment Session</label>
        <input type="text" value="<?= htmlspecialchars((string)($student['enrollment_session_title'] ?? 'Not assigned')) ?>" readonly>

        <button type="submit">Save Changes</button>
    </form>
</section>
<?php require __DIR__ . '/../../includes/footer.php'; ?>

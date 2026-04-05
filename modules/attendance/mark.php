<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_roles([ROLE_TEACHER]);

$errors = [];
$success = [];
$selectedClassId = isset($_REQUEST['class_id']) ? (int)$_REQUEST['class_id'] : 0;
$selectedDate = $_REQUEST['attendance_date'] ?? date('Y-m-d');

$teacherStatement = $pdo->prepare('SELECT id FROM teachers WHERE user_id = :user_id LIMIT 1');
$teacherStatement->execute(['user_id' => current_user()['id']]);
$teacher = $teacherStatement->fetch();

$classes = [];
if ($teacher) {
    $classStatement = $pdo->prepare(
        'SELECT DISTINCT c.id, c.class_name, c.section, c.session_id, s.title AS session_title
         FROM classes c
         JOIN academic_sessions s ON s.id = c.session_id
         LEFT JOIN class_subjects cs ON cs.class_id = c.id
         WHERE c.class_teacher_id = :teacher_id OR cs.teacher_id = :teacher_id
         ORDER BY c.class_name, c.section'
    );
    $classStatement->execute(['teacher_id' => $teacher['id']]);
    $classes = $classStatement->fetchAll();
}

$allowedClassIds = array_map(static fn(array $row): int => (int)$row['id'], $classes);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_attendance') {
    $selectedClassId = (int)($_POST['class_id'] ?? 0);
    $selectedDate = $_POST['attendance_date'] ?? date('Y-m-d');
    $attendanceRows = $_POST['attendance'] ?? [];
    $remarksRows = $_POST['remarks'] ?? [];

    if ($selectedClassId <= 0 || !in_array($selectedClassId, $allowedClassIds, true)) {
        $errors[] = 'Invalid class selected.';
    }

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $selectedDate)) {
        $errors[] = 'Invalid attendance date.';
    }

    if (empty($attendanceRows)) {
        $errors[] = 'No attendance entries submitted.';
    }

    if (empty($errors)) {
        $validStatus = ['present', 'absent', 'late', 'leave'];

        $studentCheckStatement = $pdo->prepare(
            'SELECT s.id
             FROM student_class_enrollments sce
             JOIN students s ON s.id = sce.student_id
             WHERE sce.class_id = :class_id
               AND sce.is_active = 1
               AND s.id = :student_id'
        );

        $upsertStatement = $pdo->prepare(
            'INSERT INTO attendance (student_id, class_id, attendance_date, status, marked_by, remarks)
             VALUES (:student_id, :class_id, :attendance_date, :status, :marked_by, :remarks)
             ON DUPLICATE KEY UPDATE
                 status = VALUES(status),
                 marked_by = VALUES(marked_by),
                 remarks = VALUES(remarks)'
        );

        $savedCount = 0;
        foreach ($attendanceRows as $studentIdRaw => $status) {
            $studentId = (int)$studentIdRaw;
            if ($studentId <= 0 || !in_array($status, $validStatus, true)) {
                continue;
            }

            $studentCheckStatement->execute([
                'class_id' => $selectedClassId,
                'student_id' => $studentId,
            ]);
            if (!$studentCheckStatement->fetch()) {
                continue;
            }

            $remarks = trim((string)($remarksRows[$studentIdRaw] ?? ''));
            $upsertStatement->execute([
                'student_id' => $studentId,
                'class_id' => $selectedClassId,
                'attendance_date' => $selectedDate,
                'status' => $status,
                'marked_by' => current_user()['id'],
                'remarks' => $remarks !== '' ? $remarks : null,
            ]);
            $savedCount++;
        }

        $success[] = "Attendance saved for {$savedCount} student(s).";
    }
}

$students = [];
$existingAttendance = [];
if ($selectedClassId > 0 && in_array($selectedClassId, $allowedClassIds, true)) {
    $studentStatement = $pdo->prepare(
        'SELECT s.id, s.admission_no, s.roll_number, u.full_name
         FROM student_class_enrollments sce
         JOIN students s ON s.id = sce.student_id
         LEFT JOIN users u ON u.id = s.user_id
         WHERE sce.class_id = :class_id
           AND sce.is_active = 1
           AND s.status = :status
         ORDER BY s.roll_number, u.full_name'
    );
    $studentStatement->execute([
        'class_id' => $selectedClassId,
        'status' => 'enrolled',
    ]);
    $students = $studentStatement->fetchAll();

    $existingStatement = $pdo->prepare(
        'SELECT student_id, status, remarks
         FROM attendance
         WHERE class_id = :class_id AND attendance_date = :attendance_date'
    );
    $existingStatement->execute([
        'class_id' => $selectedClassId,
        'attendance_date' => $selectedDate,
    ]);
    foreach ($existingStatement->fetchAll() as $row) {
        $existingAttendance[(int)$row['student_id']] = [
            'status' => $row['status'],
            'remarks' => $row['remarks'],
        ];
    }
}

$pageTitle = 'Mark Attendance';
require __DIR__ . '/../../includes/header.php';
?>
<section class="card">
    <h2>Daily Attendance</h2>

    <?php if (!$teacher): ?>
        <p class="error">Teacher profile not found. Please ask Admin to map this user in the teachers table.</p>
    <?php else: ?>
        <?php if (!empty($errors)): ?>
            <div class="error">
                <?php foreach ($errors as $error): ?>
                    <p><?= htmlspecialchars($error) ?></p>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <?php if (!empty($success)): ?>
            <div class="success">
                <?php foreach ($success as $message): ?>
                    <p><?= htmlspecialchars($message) ?></p>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <form method="get" class="form-grid form-grid-wide">
            <label>Class</label>
            <select name="class_id" required>
                <option value="">Select class</option>
                <?php foreach ($classes as $class): ?>
                    <option value="<?= (int)$class['id'] ?>" <?= $selectedClassId === (int)$class['id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($class['class_name'] . ' - ' . $class['section'] . ' (' . $class['session_title'] . ')') ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <label>Date</label>
            <input type="date" name="attendance_date" value="<?= htmlspecialchars($selectedDate) ?>" required>

            <button type="submit">Load Students</button>
        </form>

        <?php if ($selectedClassId > 0): ?>
            <form method="post" class="card" style="margin-top: 16px;">
                <input type="hidden" name="action" value="save_attendance">
                <input type="hidden" name="class_id" value="<?= (int)$selectedClassId ?>">
                <input type="hidden" name="attendance_date" value="<?= htmlspecialchars($selectedDate) ?>">

                <div class="table-wrap" id="section-table">
                    <table>
                        <thead>
                            <tr>
                                <th>Roll</th>
                                <th>Admission</th>
                                <th>Student</th>
                                <th>Status</th>
                                <th>Remarks</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($students as $student): ?>
                                <?php
                                    $studentId = (int)$student['id'];
                                    $rowStatus = $existingAttendance[$studentId]['status'] ?? 'present';
                                    $rowRemarks = $existingAttendance[$studentId]['remarks'] ?? '';
                                ?>
                                <tr>
                                    <td><?= htmlspecialchars((string)($student['roll_number'] ?: '-')) ?></td>
                                    <td><?= htmlspecialchars((string)$student['admission_no']) ?></td>
                                    <td><?= htmlspecialchars((string)$student['full_name']) ?></td>
                                    <td>
                                        <select name="attendance[<?= $studentId ?>]">
                                            <option value="present" <?= $rowStatus === 'present' ? 'selected' : '' ?>>Present</option>
                                            <option value="absent" <?= $rowStatus === 'absent' ? 'selected' : '' ?>>Absent</option>
                                            <option value="late" <?= $rowStatus === 'late' ? 'selected' : '' ?>>Late</option>
                                            <option value="leave" <?= $rowStatus === 'leave' ? 'selected' : '' ?>>Leave</option>
                                        </select>
                                    </td>
                                    <td>
                                        <input type="text" name="remarks[<?= $studentId ?>]" value="<?= htmlspecialchars((string)$rowRemarks) ?>" placeholder="Optional remark">
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if (empty($students)): ?>
                                <tr>
                                    <td colspan="5">No enrolled students found for selected class.</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <?php if (!empty($students)): ?>
                    <button type="submit" style="margin-top: 12px;">Save Attendance</button>
                <?php endif; ?>
            </form>
        <?php endif; ?>
    <?php endif; ?>
</section>
<?php require __DIR__ . '/../../includes/footer.php'; ?>

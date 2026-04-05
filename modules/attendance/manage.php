<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_roles([ROLE_SUPER_ADMIN, ROLE_ADMIN]);

$errors = [];
$success = [];
$selectedClassId = isset($_REQUEST['class_id']) ? (int)$_REQUEST['class_id'] : 0;
$selectedDate = $_REQUEST['attendance_date'] ?? date('Y-m-d');

$classes = $pdo->query(
    'SELECT c.id, c.class_name, c.section, s.title AS session_title
     FROM classes c
     JOIN academic_sessions s ON s.id = c.session_id
     ORDER BY c.class_name, c.section'
)->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_attendance') {
    $selectedClassId = (int)($_POST['class_id'] ?? 0);
    $selectedDate = $_POST['attendance_date'] ?? date('Y-m-d');
    $statusRows = $_POST['status'] ?? [];
    $remarkRows = $_POST['remarks'] ?? [];

    if ($selectedClassId <= 0) {
        $errors[] = 'Class is required.';
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $selectedDate)) {
        $errors[] = 'Invalid date format.';
    }

    if (empty($errors)) {
        $validStatus = ['present', 'absent', 'late', 'leave'];
        $updateStatement = $pdo->prepare(
            'UPDATE attendance
             SET status = :status,
                 remarks = :remarks,
                 marked_by = :marked_by
             WHERE id = :id'
        );

        $updated = 0;
        foreach ($statusRows as $attendanceIdRaw => $status) {
            $attendanceId = (int)$attendanceIdRaw;
            if ($attendanceId <= 0 || !in_array($status, $validStatus, true)) {
                continue;
            }
            $remarks = trim((string)($remarkRows[$attendanceIdRaw] ?? ''));
            $updateStatement->execute([
                'status' => $status,
                'remarks' => $remarks !== '' ? $remarks : null,
                'marked_by' => current_user()['id'],
                'id' => $attendanceId,
            ]);
            $updated++;
        }

        $success[] = "Attendance updated for {$updated} row(s).";
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_attendance_row') {
    $attendanceId = (int)($_POST['attendance_id'] ?? 0);
    if ($attendanceId <= 0) {
        $errors[] = 'Invalid attendance delete request.';
    } else {
        $deleteStmt = $pdo->prepare('DELETE FROM attendance WHERE id = :id');
        $deleteStmt->execute(['id' => $attendanceId]);
        $success[] = 'Attendance row deleted.';

        $selectedClassId = (int)($_POST['class_id'] ?? $selectedClassId);
        $selectedDate = $_POST['attendance_date'] ?? $selectedDate;
    }
}

$records = [];
if ($selectedClassId > 0 && preg_match('/^\d{4}-\d{2}-\d{2}$/', $selectedDate)) {
    $recordStatement = $pdo->prepare(
        'SELECT a.id, a.student_id, a.status, a.remarks, a.attendance_date,
                s.admission_no, s.roll_number,
                u.full_name AS student_name,
                marker.full_name AS marked_by_name
         FROM attendance a
         JOIN students s ON s.id = a.student_id
         LEFT JOIN users u ON u.id = s.user_id
         LEFT JOIN users marker ON marker.id = a.marked_by
         WHERE a.class_id = :class_id
           AND a.attendance_date = :attendance_date
         ORDER BY s.roll_number, u.full_name'
    );
    $recordStatement->execute([
        'class_id' => $selectedClassId,
        'attendance_date' => $selectedDate,
    ]);
    $records = $recordStatement->fetchAll();
}

$pageTitle = 'Attendance Control';
require __DIR__ . '/../../includes/header.php';
?>
<section class="card">
    <h2>Attendance Control</h2>

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

        <button type="submit">Load Attendance</button>
    </form>

    <?php if ($selectedClassId > 0): ?>
        <form method="post" class="card" style="margin-top: 16px;">
            <input type="hidden" name="action" value="update_attendance">
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
                            <th>Last Marked By</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($records as $record): ?>
                            <tr>
                                <td><?= htmlspecialchars((string)($record['roll_number'] ?: '-')) ?></td>
                                <td><?= htmlspecialchars((string)$record['admission_no']) ?></td>
                                <td><?= htmlspecialchars((string)$record['student_name']) ?></td>
                                <td>
                                    <select name="status[<?= (int)$record['id'] ?>]">
                                        <option value="present" <?= $record['status'] === 'present' ? 'selected' : '' ?>>Present</option>
                                        <option value="absent" <?= $record['status'] === 'absent' ? 'selected' : '' ?>>Absent</option>
                                        <option value="late" <?= $record['status'] === 'late' ? 'selected' : '' ?>>Late</option>
                                        <option value="leave" <?= $record['status'] === 'leave' ? 'selected' : '' ?>>Leave</option>
                                    </select>
                                </td>
                                <td><input type="text" name="remarks[<?= (int)$record['id'] ?>]" value="<?= htmlspecialchars((string)$record['remarks']) ?>"></td>
                                <td><?= htmlspecialchars((string)($record['marked_by_name'] ?: '-')) ?></td>
                                <td>
                                    <form method="post" class="inline-form" onsubmit="return confirm('Delete this attendance row?');">
                                        <input type="hidden" name="action" value="delete_attendance_row">
                                        <input type="hidden" name="attendance_id" value="<?= (int)$record['id'] ?>">
                                        <input type="hidden" name="class_id" value="<?= (int)$selectedClassId ?>">
                                        <input type="hidden" name="attendance_date" value="<?= htmlspecialchars($selectedDate) ?>">
                                        <button type="submit" class="danger">Delete</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (empty($records)): ?>
                            <tr>
                                <td colspan="7">No attendance records found for selected class/date.</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <?php if (!empty($records)): ?>
                <button type="submit" style="margin-top: 12px;">Update Attendance</button>
            <?php endif; ?>
        </form>
    <?php endif; ?>
</section>
<?php require __DIR__ . '/../../includes/footer.php'; ?>

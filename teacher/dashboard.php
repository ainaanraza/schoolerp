<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_roles([ROLE_TEACHER]);

$teacherIdStatement = $pdo->prepare('SELECT id FROM teachers WHERE user_id = :user_id LIMIT 1');
$teacherIdStatement->execute(['user_id' => current_user()['id']]);
$teacherId = (int)($teacherIdStatement->fetchColumn() ?: 0);

$assignedCourses = 0;
$assignedStudents = 0;
if ($teacherId > 0) {
    $classCountStmt = $pdo->prepare(
        'SELECT COUNT(DISTINCT c.id)
         FROM classes c
         WHERE c.class_teacher_id = :teacher_id'
    );
    $classCountStmt->execute(['teacher_id' => $teacherId]);
    $assignedCourses = (int)$classCountStmt->fetchColumn();

    $studentCountStmt = $pdo->prepare(
        'SELECT COUNT(DISTINCT sce.student_id)
         FROM student_class_enrollments sce
         WHERE sce.class_id IN (
             SELECT DISTINCT c.id
             FROM classes c
             WHERE c.class_teacher_id = :teacher_id
         )
         AND sce.is_active = 1'
    );
    $studentCountStmt->execute(['teacher_id' => $teacherId]);
    $assignedStudents = (int)$studentCountStmt->fetchColumn();
}

$markedTodayStmt = $pdo->prepare(
    'SELECT COUNT(*)
     FROM attendance
     WHERE marked_by = :user_id
       AND attendance_date = CURDATE()'
);
$markedTodayStmt->execute(['user_id' => current_user()['id']]);
$attendanceMarkedToday = (int)$markedTodayStmt->fetchColumn();

$homeworkPostedStmt = $pdo->prepare(
    'SELECT COUNT(*)
     FROM homework
     WHERE posted_by = :user_id'
);
$homeworkPostedStmt->execute(['user_id' => current_user()['id']]);
$homeworkCount = (int)$homeworkPostedStmt->fetchColumn();

$notificationSentStmt = $pdo->prepare(
    'SELECT COUNT(*)
     FROM notifications
     WHERE sender_id = :user_id'
);
$notificationSentStmt->execute(['user_id' => current_user()['id']]);
$notificationCount = (int)$notificationSentStmt->fetchColumn();

$pageTitle = 'Teacher Dashboard';
require __DIR__ . '/../includes/header.php';
?>
<div class="dashboard-content">
    <section class="card dashboard-hero">
        <h3>Teaching Performance Hub</h3>
        <p>Stay on top of your assigned courses, attendance progress, homework cadence, and communication activity.</p>
    </section>

    <div class="metrics-grid">
        <a class="metric-card" href="/itierp/modules/admission/students.php#section-table">
            <p class="metric-label">Assigned Courses</p>
            <p class="metric-value"><?= $assignedCourses ?></p>
        </a>
        <a class="metric-card" href="/itierp/modules/admission/students.php#section-table">
            <p class="metric-label">Assigned Students</p>
            <p class="metric-value"><?= $assignedStudents ?></p>
        </a>
        <a class="metric-card" href="/itierp/modules/attendance/mark.php#section-table">
            <p class="metric-label">Attendance Marked Today</p>
            <p class="metric-value"><?= $attendanceMarkedToday ?></p>
        </a>
        <a class="metric-card" href="/itierp/modules/academics/homework.php#section-table">
            <p class="metric-label">Homework Posted</p>
            <p class="metric-value"><?= $homeworkCount ?></p>
        </a>
        <a class="metric-card" href="/itierp/modules/notification/send.php#section-table">
            <p class="metric-label">Notifications Sent</p>
            <p class="metric-value"><?= $notificationCount ?></p>
        </a>
    </div>

</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>

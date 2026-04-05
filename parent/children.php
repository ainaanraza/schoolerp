<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_roles([ROLE_PARENT]);

$childrenStatement = $pdo->prepare(
    'SELECT
        s.id,
        s.admission_no,
        s.roll_number,
        su.full_name AS student_name,
        ps.relation,
        CONCAT(c.class_name, " - ", c.section) AS class_label,
        sess.title AS session_title
     FROM parents p
     JOIN parent_student ps ON ps.parent_id = p.id
     JOIN students s ON s.id = ps.student_id
     LEFT JOIN users su ON su.id = s.user_id
     LEFT JOIN student_class_enrollments sce ON sce.student_id = s.id AND sce.is_active = 1
     LEFT JOIN classes c ON c.id = sce.class_id
     LEFT JOIN academic_sessions sess ON sess.id = sce.session_id
     WHERE p.user_id = :user_id
     ORDER BY su.full_name'
);
$childrenStatement->execute(['user_id' => current_user()['id']]);
$children = $childrenStatement->fetchAll();

$pageTitle = 'My Children';
require __DIR__ . '/../includes/header.php';
?>
<section class="card">
    <h2>My Children</h2>
    <div class="table-wrap" id="section-table">
        <table>
            <thead>
                <tr>
                    <th>Student</th>
                    <th>Admission No</th>
                    <th>Roll</th>
                    <th>Relation</th>
                    <th>Class</th>
                    <th>Session</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($children as $child): ?>
                    <tr>
                        <td><?= htmlspecialchars((string)$child['student_name']) ?></td>
                        <td><?= htmlspecialchars((string)$child['admission_no']) ?></td>
                        <td><?= htmlspecialchars((string)($child['roll_number'] ?: '-')) ?></td>
                        <td><?= htmlspecialchars((string)$child['relation']) ?></td>
                        <td><?= htmlspecialchars((string)($child['class_label'] ?: 'Not assigned')) ?></td>
                        <td><?= htmlspecialchars((string)($child['session_title'] ?: '-')) ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($children)): ?>
                    <tr><td colspan="6">No children linked yet. Contact school admin.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>
<?php require __DIR__ . '/../includes/footer.php'; ?>

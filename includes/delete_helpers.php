<?php

function build_in_clause_params(array $ids, string $prefix): array
{
    $placeholders = [];
    $params = [];

    foreach (array_values($ids) as $index => $id) {
        $key = $prefix . '_' . $index;
        $placeholders[] = ':' . $key;
        $params[$key] = (int)$id;
    }

    return [
        'clause' => implode(', ', $placeholders),
        'params' => $params,
    ];
}

function cascade_delete_notifications_by_sender(PDO $pdo, int $senderId): void
{
    $notificationIdsStmt = $pdo->prepare('SELECT id FROM notifications WHERE sender_id = :sender_id');
    $notificationIdsStmt->execute(['sender_id' => $senderId]);
    $notificationIds = array_map(static fn(array $row): int => (int)$row['id'], $notificationIdsStmt->fetchAll());

    if (!empty($notificationIds)) {
        $in = build_in_clause_params($notificationIds, 'notification_id');
        $deleteReads = $pdo->prepare('DELETE FROM notification_reads WHERE notification_id IN (' . $in['clause'] . ')');
        $deleteReads->execute($in['params']);

        $deleteNotifications = $pdo->prepare('DELETE FROM notifications WHERE id IN (' . $in['clause'] . ')');
        $deleteNotifications->execute($in['params']);
    }
}

function cascade_delete_fee_structures_by_ids(PDO $pdo, array $feeStructureIds): void
{
    if (empty($feeStructureIds)) {
        return;
    }

    $in = build_in_clause_params($feeStructureIds, 'fee_structure_id');

    $studentFeeIdsStmt = $pdo->prepare('SELECT id FROM student_fees WHERE fee_structure_id IN (' . $in['clause'] . ')');
    $studentFeeIdsStmt->execute($in['params']);
    $studentFeeIds = array_map(static fn(array $row): int => (int)$row['id'], $studentFeeIdsStmt->fetchAll());

    if (!empty($studentFeeIds)) {
        $sfIn = build_in_clause_params($studentFeeIds, 'student_fee_id');

        $deleteAdjustments = $pdo->prepare('DELETE FROM fee_adjustments WHERE student_fee_id IN (' . $sfIn['clause'] . ')');
        $deleteAdjustments->execute($sfIn['params']);

        $deletePayments = $pdo->prepare('DELETE FROM payments WHERE student_fee_id IN (' . $sfIn['clause'] . ')');
        $deletePayments->execute($sfIn['params']);

        $deleteStudentFees = $pdo->prepare('DELETE FROM student_fees WHERE id IN (' . $sfIn['clause'] . ')');
        $deleteStudentFees->execute($sfIn['params']);
    }

    $deleteStructures = $pdo->prepare('DELETE FROM fee_structures WHERE id IN (' . $in['clause'] . ')');
    $deleteStructures->execute($in['params']);
}

function cascade_delete_student(PDO $pdo, int $studentId): void
{
    $studentRowStmt = $pdo->prepare('SELECT id, user_id FROM students WHERE id = :student_id LIMIT 1 FOR UPDATE');
    $studentRowStmt->execute(['student_id' => $studentId]);
    $studentRow = $studentRowStmt->fetch();

    if (!$studentRow) {
        throw new RuntimeException('Student not found.');
    }

    $studentUserId = (int)($studentRow['user_id'] ?? 0);

    $parentIdsStmt = $pdo->prepare('SELECT DISTINCT parent_id FROM parent_student WHERE student_id = :student_id');
    $parentIdsStmt->execute(['student_id' => $studentId]);
    $parentIds = array_map(static fn(array $row): int => (int)$row['parent_id'], $parentIdsStmt->fetchAll());

    $deleteFeeAdjustments = $pdo->prepare(
        'DELETE fa
         FROM fee_adjustments fa
         JOIN student_fees sf ON sf.id = fa.student_fee_id
         WHERE sf.student_id = :student_id'
    );
    $deleteFeeAdjustments->execute(['student_id' => $studentId]);

    $deletePayments = $pdo->prepare(
        'DELETE p
         FROM payments p
         JOIN student_fees sf ON sf.id = p.student_fee_id
         WHERE sf.student_id = :student_id'
    );
    $deletePayments->execute(['student_id' => $studentId]);

    $deleteStudentFees = $pdo->prepare('DELETE FROM student_fees WHERE student_id = :student_id');
    $deleteStudentFees->execute(['student_id' => $studentId]);

    $deleteAttendance = $pdo->prepare('DELETE FROM attendance WHERE student_id = :student_id');
    $deleteAttendance->execute(['student_id' => $studentId]);

    $deleteSubmissions = $pdo->prepare('DELETE FROM homework_submissions WHERE student_id = :student_id');
    $deleteSubmissions->execute(['student_id' => $studentId]);

    $deleteDocuments = $pdo->prepare('DELETE FROM documents WHERE student_id = :student_id');
    $deleteDocuments->execute(['student_id' => $studentId]);

    $deleteParentLinks = $pdo->prepare('DELETE FROM parent_student WHERE student_id = :student_id');
    $deleteParentLinks->execute(['student_id' => $studentId]);

    $deleteEnrollments = $pdo->prepare('DELETE FROM student_class_enrollments WHERE student_id = :student_id');
    $deleteEnrollments->execute(['student_id' => $studentId]);

    $clearConvertedLead = $pdo->prepare('UPDATE leads SET converted_student_id = NULL WHERE converted_student_id = :student_id');
    $clearConvertedLead->execute(['student_id' => $studentId]);

    $hasCredentialsTable = (bool)$pdo->query("SHOW TABLES LIKE 'student_login_credentials'")->fetchColumn();
    if ($hasCredentialsTable) {
        $deleteCredentials = $pdo->prepare('DELETE FROM student_login_credentials WHERE student_id = :student_id');
        $deleteCredentials->execute(['student_id' => $studentId]);
    }

    $deleteStudent = $pdo->prepare('DELETE FROM students WHERE id = :student_id');
    $deleteStudent->execute(['student_id' => $studentId]);

    if ($studentUserId > 0) {
        $deleteRead = $pdo->prepare('DELETE FROM notification_reads WHERE user_id = :user_id');
        $deleteRead->execute(['user_id' => $studentUserId]);

        $deleteUser = $pdo->prepare('DELETE FROM users WHERE id = :user_id AND role = :role');
        $deleteUser->execute([
            'user_id' => $studentUserId,
            'role' => ROLE_STUDENT,
        ]);
    }

    foreach ($parentIds as $parentId) {
        $remainingLinksStmt = $pdo->prepare('SELECT COUNT(*) FROM parent_student WHERE parent_id = :parent_id');
        $remainingLinksStmt->execute(['parent_id' => $parentId]);
        $remainingLinks = (int)$remainingLinksStmt->fetchColumn();

        if ($remainingLinks === 0) {
            $parentUserStmt = $pdo->prepare('SELECT user_id FROM parents WHERE id = :parent_id LIMIT 1');
            $parentUserStmt->execute(['parent_id' => $parentId]);
            $parentUserId = (int)($parentUserStmt->fetchColumn() ?: 0);

            $deleteParent = $pdo->prepare('DELETE FROM parents WHERE id = :parent_id');
            $deleteParent->execute(['parent_id' => $parentId]);

            if ($parentUserId > 0) {
                $deleteParentReads = $pdo->prepare('DELETE FROM notification_reads WHERE user_id = :user_id');
                $deleteParentReads->execute(['user_id' => $parentUserId]);

                $deleteParentUser = $pdo->prepare('DELETE FROM users WHERE id = :user_id AND role = :role');
                $deleteParentUser->execute([
                    'user_id' => $parentUserId,
                    'role' => ROLE_PARENT,
                ]);
            }
        }
    }
}

function cascade_delete_class(PDO $pdo, int $classId): void
{
    $classStmt = $pdo->prepare('SELECT class_name, section FROM classes WHERE id = :class_id LIMIT 1 FOR UPDATE');
    $classStmt->execute(['class_id' => $classId]);
    $classRow = $classStmt->fetch();

    if (!$classRow) {
        throw new RuntimeException('Class not found.');
    }

    $feeStructureIdsStmt = $pdo->prepare('SELECT id FROM fee_structures WHERE class_id = :class_id');
    $feeStructureIdsStmt->execute(['class_id' => $classId]);
    $feeStructureIds = array_map(static fn(array $row): int => (int)$row['id'], $feeStructureIdsStmt->fetchAll());
    cascade_delete_fee_structures_by_ids($pdo, $feeStructureIds);

    $homeworkIdsStmt = $pdo->prepare('SELECT id FROM homework WHERE class_id = :class_id');
    $homeworkIdsStmt->execute(['class_id' => $classId]);
    $homeworkIds = array_map(static fn(array $row): int => (int)$row['id'], $homeworkIdsStmt->fetchAll());
    if (!empty($homeworkIds)) {
        $in = build_in_clause_params($homeworkIds, 'homework_id');
        $deleteSubmissions = $pdo->prepare('DELETE FROM homework_submissions WHERE homework_id IN (' . $in['clause'] . ')');
        $deleteSubmissions->execute($in['params']);
    }

    $deleteHomework = $pdo->prepare('DELETE FROM homework WHERE class_id = :class_id');
    $deleteHomework->execute(['class_id' => $classId]);

    $deleteAttendance = $pdo->prepare('DELETE FROM attendance WHERE class_id = :class_id');
    $deleteAttendance->execute(['class_id' => $classId]);

    $deleteEnrollments = $pdo->prepare('DELETE FROM student_class_enrollments WHERE class_id = :class_id');
    $deleteEnrollments->execute(['class_id' => $classId]);

    $clearLeads = $pdo->prepare('UPDATE leads SET class_id = NULL, class_applied = NULL WHERE class_id = :class_id');
    $clearLeads->execute(['class_id' => $classId]);

    $deleteClass = $pdo->prepare('DELETE FROM classes WHERE id = :class_id');
    $deleteClass->execute(['class_id' => $classId]);
}

function cascade_delete_subject(PDO $pdo, int $subjectId): void
{
    $subjectStmt = $pdo->prepare('SELECT id FROM subjects WHERE id = :subject_id LIMIT 1 FOR UPDATE');
    $subjectStmt->execute(['subject_id' => $subjectId]);
    if (!$subjectStmt->fetch()) {
        throw new RuntimeException('Subject not found.');
    }

    $homeworkIdsStmt = $pdo->prepare('SELECT id FROM homework WHERE subject_id = :subject_id');
    $homeworkIdsStmt->execute(['subject_id' => $subjectId]);
    $homeworkIds = array_map(static fn(array $row): int => (int)$row['id'], $homeworkIdsStmt->fetchAll());

    if (!empty($homeworkIds)) {
        $in = build_in_clause_params($homeworkIds, 'homework_id');
        $deleteSubmissions = $pdo->prepare('DELETE FROM homework_submissions WHERE homework_id IN (' . $in['clause'] . ')');
        $deleteSubmissions->execute($in['params']);
    }

    $deleteHomework = $pdo->prepare('DELETE FROM homework WHERE subject_id = :subject_id');
    $deleteHomework->execute(['subject_id' => $subjectId]);

    $deleteSubject = $pdo->prepare('DELETE FROM subjects WHERE id = :subject_id');
    $deleteSubject->execute(['subject_id' => $subjectId]);
}

function cascade_delete_staff_user(PDO $pdo, int $userId, string $role): void
{
    if (!in_array($role, [ROLE_ADMIN, ROLE_TEACHER], true)) {
        throw new RuntimeException('Only admin or teacher can be deleted here.');
    }

    $roleStatement = $pdo->prepare('SELECT role FROM users WHERE id = :id LIMIT 1 FOR UPDATE');
    $roleStatement->execute(['id' => $userId]);
    $actualRole = (string)($roleStatement->fetchColumn() ?? '');
    if ($actualRole !== $role) {
        throw new RuntimeException('Staff record not found.');
    }

    if ($role === ROLE_TEACHER) {
        $teacherStatement = $pdo->prepare('SELECT id FROM teachers WHERE user_id = :user_id LIMIT 1');
        $teacherStatement->execute(['user_id' => $userId]);
        $teacherId = (int)($teacherStatement->fetchColumn() ?: 0);

        if ($teacherId > 0) {
            $clearClassTeacher = $pdo->prepare('UPDATE classes SET class_teacher_id = NULL WHERE class_teacher_id = :teacher_id');
            $clearClassTeacher->execute(['teacher_id' => $teacherId]);

            $deleteTeacherStmt = $pdo->prepare('DELETE FROM teachers WHERE id = :teacher_id');
            $deleteTeacherStmt->execute(['teacher_id' => $teacherId]);
        }
    }

    $deleteAttendance = $pdo->prepare('DELETE FROM attendance WHERE marked_by = :user_id');
    $deleteAttendance->execute(['user_id' => $userId]);

    $deleteFeeAdjustments = $pdo->prepare('DELETE FROM fee_adjustments WHERE approved_by = :user_id');
    $deleteFeeAdjustments->execute(['user_id' => $userId]);

    $createdFeeStructuresStmt = $pdo->prepare('SELECT id FROM fee_structures WHERE created_by = :user_id');
    $createdFeeStructuresStmt->execute(['user_id' => $userId]);
    $createdFeeStructures = array_map(static fn(array $row): int => (int)$row['id'], $createdFeeStructuresStmt->fetchAll());
    cascade_delete_fee_structures_by_ids($pdo, $createdFeeStructures);

    $homeworkIdsStmt = $pdo->prepare('SELECT id FROM homework WHERE posted_by = :user_id');
    $homeworkIdsStmt->execute(['user_id' => $userId]);
    $homeworkIds = array_map(static fn(array $row): int => (int)$row['id'], $homeworkIdsStmt->fetchAll());
    if (!empty($homeworkIds)) {
        $in = build_in_clause_params($homeworkIds, 'homework_id');
        $deleteSubmissions = $pdo->prepare('DELETE FROM homework_submissions WHERE homework_id IN (' . $in['clause'] . ')');
        $deleteSubmissions->execute($in['params']);
    }
    $deleteHomework = $pdo->prepare('DELETE FROM homework WHERE posted_by = :user_id');
    $deleteHomework->execute(['user_id' => $userId]);

    $deleteDocuments = $pdo->prepare('DELETE FROM documents WHERE uploaded_by = :user_id');
    $deleteDocuments->execute(['user_id' => $userId]);

    cascade_delete_notifications_by_sender($pdo, $userId);

    $clearLeads = $pdo->prepare('UPDATE leads SET created_by = NULL WHERE created_by = :user_id');
    $clearLeads->execute(['user_id' => $userId]);

    $clearPayments = $pdo->prepare('UPDATE payments SET received_by = NULL WHERE received_by = :user_id');
    $clearPayments->execute(['user_id' => $userId]);

    $hasCredentialsTable = (bool)$pdo->query("SHOW TABLES LIKE 'student_login_credentials'")->fetchColumn();
    if ($hasCredentialsTable) {
        $clearCredentials = $pdo->prepare('UPDATE student_login_credentials SET generated_by = NULL WHERE generated_by = :user_id');
        $clearCredentials->execute(['user_id' => $userId]);
    }

    $deleteUserReads = $pdo->prepare('DELETE FROM notification_reads WHERE user_id = :user_id');
    $deleteUserReads->execute(['user_id' => $userId]);

    $deleteUserStmt = $pdo->prepare('DELETE FROM users WHERE id = :id AND role = :role');
    $deleteUserStmt->execute([
        'id' => $userId,
        'role' => $role,
    ]);

    if ($deleteUserStmt->rowCount() < 1) {
        throw new RuntimeException('Delete failed for selected staff user.');
    }
}

function cascade_delete_parent(PDO $pdo, int $parentId): void
{
    $parentStmt = $pdo->prepare('SELECT id, user_id FROM parents WHERE id = :parent_id LIMIT 1 FOR UPDATE');
    $parentStmt->execute(['parent_id' => $parentId]);
    $parent = $parentStmt->fetch();

    if (!$parent) {
        throw new RuntimeException('Parent not found.');
    }

    $parentUserId = (int)($parent['user_id'] ?? 0);

    $deleteLinks = $pdo->prepare('DELETE FROM parent_student WHERE parent_id = :parent_id');
    $deleteLinks->execute(['parent_id' => $parentId]);

    $deleteParent = $pdo->prepare('DELETE FROM parents WHERE id = :parent_id');
    $deleteParent->execute(['parent_id' => $parentId]);

    if ($parentUserId > 0) {
        $deleteReads = $pdo->prepare('DELETE FROM notification_reads WHERE user_id = :user_id');
        $deleteReads->execute(['user_id' => $parentUserId]);

        $deleteUser = $pdo->prepare('DELETE FROM users WHERE id = :id AND role = :role');
        $deleteUser->execute([
            'id' => $parentUserId,
            'role' => ROLE_PARENT,
        ]);
    }
}

function cascade_delete_fee_structure(PDO $pdo, int $feeStructureId): void
{
    cascade_delete_fee_structures_by_ids($pdo, [$feeStructureId]);
}

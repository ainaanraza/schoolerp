<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_roles([ROLE_SUPER_ADMIN, ROLE_ADMIN, ROLE_STUDENT]);

$errors = [];
$success = [];
$role = current_role();
$user = current_user();
$studentId = 0;

if ($role === ROLE_STUDENT) {
    $studentStatement = $pdo->prepare('SELECT id FROM students WHERE user_id = :user_id LIMIT 1');
    $studentStatement->execute(['user_id' => $user['id']]);
    $studentId = (int)($studentStatement->fetchColumn() ?: 0);

    if ($studentId <= 0) {
        $errors[] = 'Student profile not linked with this user account.';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'upload_document' && $role === ROLE_STUDENT) {
    $documentType = trim($_POST['document_type'] ?? '');
    $file = $_FILES['document_file'] ?? null;

    if ($studentId <= 0) {
        $errors[] = 'Unable to upload document without a valid student profile.';
    }

    if ($documentType === '') {
        $errors[] = 'Document type is required.';
    }

    if ($file === null || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        $errors[] = 'Please choose a file to upload.';
    } elseif (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
        $errors[] = 'Document upload failed. Please retry.';
    }

    if (empty($errors) && $file !== null) {
        $maxSize = 500 * 1024;
        if (($file['size'] ?? 0) > $maxSize) {
            $errors[] = 'Document file must be 500KB or smaller.';
        }

        $extension = strtolower(pathinfo((string)($file['name'] ?? ''), PATHINFO_EXTENSION));
        $allowedExtensions = ['pdf', 'jpg', 'jpeg', 'png'];
        if ($extension === '' || !in_array($extension, $allowedExtensions, true)) {
            $errors[] = 'Allowed document types: pdf, jpg, jpeg, png.';
        }
    }

    if (empty($errors) && $file !== null) {
        $targetDirectory = __DIR__ . '/../../uploads/documents';
        if (!is_dir($targetDirectory) && !mkdir($targetDirectory, 0775, true)) {
            $errors[] = 'Unable to create document upload directory.';
        } else {
            try {
                $safeFileName = 'doc_' . $studentId . '_' . date('YmdHis') . '_' . bin2hex(random_bytes(4)) . '.' . $extension;
                $targetPath = $targetDirectory . '/' . $safeFileName;

                if (!move_uploaded_file($file['tmp_name'], $targetPath)) {
                    $errors[] = 'Unable to store uploaded document.';
                } else {
                    $insertDocumentStatement = $pdo->prepare(
                        'INSERT INTO documents (student_id, uploaded_by, document_type, file_path, verification_status)
                         VALUES (:student_id, :uploaded_by, :document_type, :file_path, :verification_status)'
                    );
                    $insertDocumentStatement->execute([
                        'student_id' => $studentId,
                        'uploaded_by' => $user['id'],
                        'document_type' => $documentType,
                        'file_path' => 'uploads/documents/' . $safeFileName,
                        'verification_status' => 'pending',
                    ]);
                    $success[] = 'Document uploaded successfully.';
                }
            } catch (Throwable $throwable) {
                $errors[] = 'Unable to upload document: ' . $throwable->getMessage();
            }
        }
    }
}

if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    && ($_POST['action'] ?? '') === 'update_verification'
    && in_array($role, [ROLE_SUPER_ADMIN, ROLE_ADMIN], true)
) {
    $documentId = (int)($_POST['document_id'] ?? 0);
    $verificationStatus = $_POST['verification_status'] ?? '';
    $allowedStatuses = ['pending', 'verified', 'rejected'];

    if ($documentId <= 0 || !in_array($verificationStatus, $allowedStatuses, true)) {
        $errors[] = 'Invalid verification request.';
    } else {
        try {
            $updateDocumentStatement = $pdo->prepare(
                'UPDATE documents
                 SET verification_status = :verification_status
                 WHERE id = :id'
            );
            $updateDocumentStatement->execute([
                'verification_status' => $verificationStatus,
                'id' => $documentId,
            ]);
            $success[] = 'Document verification status updated.';
        } catch (Throwable $throwable) {
            $errors[] = 'Unable to update verification status: ' . $throwable->getMessage();
        }
    }
}

$documents = [];
$studentsWithDocs = [];

if ($role === ROLE_STUDENT && $studentId > 0) {
    $documentsStatement = $pdo->prepare(
        'SELECT d.id, d.document_type, d.file_path, d.verification_status, d.created_at
         FROM documents d
         WHERE d.student_id = :student_id
         ORDER BY d.created_at DESC'
    );
    $documentsStatement->execute(['student_id' => $studentId]);
    $documents = $documentsStatement->fetchAll();
}

if (in_array($role, [ROLE_SUPER_ADMIN, ROLE_ADMIN], true)) {
    $documentsStatement = $pdo->query(
        'SELECT d.id, d.document_type, d.file_path, d.verification_status, d.created_at,
                s.id AS student_id, su.full_name AS student_name, s.admission_no,
                uu.full_name AS uploaded_by_name
         FROM documents d
         JOIN students s ON s.id = d.student_id
         LEFT JOIN users su ON su.id = s.user_id
         LEFT JOIN users uu ON uu.id = d.uploaded_by
         ORDER BY d.created_at DESC
         LIMIT 1000'
    );
    $allDocs = $documentsStatement->fetchAll();
    
    foreach ($allDocs as $doc) {
        $sid = $doc['student_id'];
        if (!isset($studentsWithDocs[$sid])) {
            $studentsWithDocs[$sid] = [
                'student_id' => $sid,
                'student_name' => $doc['student_name'],
                'admission_no' => $doc['admission_no'],
                'documents' => []
            ];
        }
        $studentsWithDocs[$sid]['documents'][] = $doc;
    }
}

$pageTitle = 'Document Management';
require __DIR__ . '/../../includes/header.php';
?>
<section class="card">
    <div class="form-header-actions">
        <h2>Document Management</h2>
        <?php if ($role === ROLE_STUDENT && $studentId > 0): ?>
            <div>
                <button type="button" class="btn-toggle-form" onclick="toggleForm('form-upload-document', this)">+ Upload Document</button>
            </div>
        <?php endif; ?>
    </div>

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

    <div class="table-wrap">
        <table>
            <?php if (in_array($role, [ROLE_SUPER_ADMIN, ROLE_ADMIN], true)): ?>
                <thead>
                    <tr>
                        <th>Student</th>
                        <th>Admission No</th>
                        <th>Documents Count</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($studentsWithDocs as $student): ?>
                        <tr>
                            <td><?= htmlspecialchars((string)($student['student_name'] ?: '-')) ?></td>
                            <td><?= htmlspecialchars((string)($student['admission_no'] ?: '-')) ?></td>
                            <td><?= count($student['documents']) ?></td>
                            <td>
                                <button type="button" class="btn-toggle-form btn-compact" onclick="toggleForm('modal-student-docs-<?= $student['student_id'] ?>', this)">View Documents</button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($studentsWithDocs)): ?>
                        <tr>
                            <td colspan="4">No students with documents found.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            <?php else: ?>
                <thead>
                    <tr>
                        <th>Document Type</th>
                        <th>Uploaded At</th>
                        <th>File</th>
                        <th>Verification</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($documents as $document): ?>
                        <tr>
                            <td><?= htmlspecialchars((string)$document['document_type']) ?></td>
                            <td><?= htmlspecialchars((string)$document['created_at']) ?></td>
                            <td>
                                <a href="/itierp/<?= htmlspecialchars((string)$document['file_path']) ?>" target="_blank" rel="noopener">View File</a>
                            </td>
                            <td>
                                <span class="pill"><?= htmlspecialchars((string)$document['verification_status']) ?></span>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($documents)): ?>
                        <tr>
                            <td colspan="4">No documents found.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            <?php endif; ?>
        </table>
    </div>

</section>

<?php if (in_array($role, [ROLE_SUPER_ADMIN, ROLE_ADMIN], true)): ?>
    <?php foreach ($studentsWithDocs as $student): ?>
        <div id="modal-student-docs-<?= $student['student_id'] ?>" class="collapsible-form">
            <section class="card" style="max-width: 900px;">
                <h3>Documents: <?= htmlspecialchars((string)($student['student_name'] ?: '-')) ?> (<?= htmlspecialchars((string)($student['admission_no'] ?: '-')) ?>)</h3>
                <div class="table-wrap" style="max-height: 400px; overflow-y: auto;">
                    <table>
                        <thead>
                            <tr>
                                <th>Document Type</th>
                                <th>Uploaded At</th>
                                <th>Uploaded By</th>
                                <th>File</th>
                                <th>Verification</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($student['documents'] as $document): ?>
                                <tr>
                                    <td><?= htmlspecialchars((string)$document['document_type']) ?></td>
                                    <td><?= htmlspecialchars((string)$document['created_at']) ?></td>
                                    <td><?= htmlspecialchars((string)($document['uploaded_by_name'] ?: '-')) ?></td>
                                    <td>
                                        <a href="/itierp/<?= htmlspecialchars((string)$document['file_path']) ?>" target="_blank" rel="noopener">View File</a>
                                    </td>
                                    <td>
                                        <form method="post" class="inline-form">
                                            <input type="hidden" name="action" value="update_verification">
                                            <input type="hidden" name="document_id" value="<?= (int)$document['id'] ?>">
                                            <select name="verification_status">
                                                <option value="pending" <?= $document['verification_status'] === 'pending' ? 'selected' : '' ?>>Pending</option>
                                                <option value="verified" <?= $document['verification_status'] === 'verified' ? 'selected' : '' ?>>Verified</option>
                                                <option value="rejected" <?= $document['verification_status'] === 'rejected' ? 'selected' : '' ?>>Rejected</option>
                                            </select>
                                            <button type="submit">Update</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </section>
        </div>
    <?php endforeach; ?>
<?php endif; ?>

<?php if ($role === ROLE_STUDENT && $studentId > 0): ?>
    <div id="form-upload-document" class="collapsible-form">
        <section class="card">
            <h3>Upload New Document</h3>
            <form method="post" enctype="multipart/form-data" class="form-grid form-grid-wide">
                <input type="hidden" name="action" value="upload_document">

                <label>Document Type</label>
                <input type="text" name="document_type" placeholder="Aadhaar Card / Birth Certificate / Transfer Certificate" maxlength="80" required>

                <label>File (PDF/JPG/PNG, max 5MB)</label>
                <input type="file" name="document_file" accept=".pdf,.jpg,.jpeg,.png" required>

                <button type="submit">Upload Document</button>
            </form>
        </section>
    </div>
<?php endif; ?>

<?php require __DIR__ . '/../../includes/footer.php'; ?>

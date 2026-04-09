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

$uploadDir = __DIR__ . '/../../uploads/photos/';
if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0755, true);
}

$isLocalPhotoPath = static function (?string $path): bool {
    if ($path === null) {
        return false;
    }

    return str_starts_with($path, '/school-erp/uploads/photos/');
};

$deleteLocalPhoto = static function (?string $path) use ($isLocalPhotoPath): void {
    if ($path === null || !$isLocalPhotoPath($path)) {
        return;
    }

    $relativePath = ltrim(str_replace('/school-erp/', '', $path), '/');
    $absolutePath = __DIR__ . '/../../' . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
    if (is_file($absolutePath)) {
        @unlink($absolutePath);
    }
};

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
    $removePhoto = (string)($_POST['remove_photo'] ?? '') === '1';
    $currentPhotoPath = !empty($student['photo_path']) ? (string)$student['photo_path'] : null;
    $updatedPhotoPath = $currentPhotoPath;

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

    if (empty($errors) && $removePhoto) {
        $updatedPhotoPath = null;
    }

    if (empty($errors) && isset($_FILES['photo']) && ($_FILES['photo']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
        $allowed = ['image/jpeg', 'image/png', 'image/webp'];
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = finfo_file($finfo, $_FILES['photo']['tmp_name']);
        finfo_close($finfo);

        if (!in_array($mime, $allowed, true)) {
            $errors[] = 'Photo must be a JPEG, PNG, or WebP image.';
        } elseif (($_FILES['photo']['size'] ?? 0) > 5 * 1024 * 1024) {
            $errors[] = 'Photo must be under 5 MB.';
        } else {
            $ext = match($mime) {
                'image/jpeg' => 'jpg',
                'image/png' => 'png',
                'image/webp' => 'webp',
                default => 'jpg',
            };
            $filename = 'student_' . $studentId . '_' . time() . '_' . random_int(1000, 9999) . '.' . $ext;
            if (move_uploaded_file($_FILES['photo']['tmp_name'], $uploadDir . $filename)) {
                $updatedPhotoPath = '/school-erp/uploads/photos/' . $filename;
            } else {
                $errors[] = 'Failed to save uploaded photo.';
            }
        }
    }

    if (empty($errors)) {
        $cameraData = trim((string)($_POST['camera_photo'] ?? ''));
        if ($cameraData !== '' && str_starts_with($cameraData, 'data:image/')) {
            $parts = explode(',', $cameraData, 2);
            if (count($parts) === 2) {
                $decoded = base64_decode($parts[1]);
                if ($decoded !== false && strlen($decoded) > 100) {
                    $ext = str_contains($parts[0], 'png') ? 'png' : 'jpg';
                    $filename = 'student_cam_' . $studentId . '_' . time() . '_' . random_int(1000, 9999) . '.' . $ext;
                    if (file_put_contents($uploadDir . $filename, $decoded) !== false) {
                        $updatedPhotoPath = '/school-erp/uploads/photos/' . $filename;
                    } else {
                        $errors[] = 'Failed to save captured photo.';
                    }
                }
            }
        }
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
                     photo_path = :photo_path,
                     address = :address,
                     status = :status
                 WHERE id = :id'
            );
            $updateStudentStatement->execute([
                'roll_number' => $rollNumber !== '' ? $rollNumber : null,
                'dob' => $dob !== '' ? $dob : null,
                'gender' => $gender !== '' ? $gender : null,
                'photo_path' => $updatedPhotoPath,
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

            if (($currentPhotoPath !== $updatedPhotoPath || $removePhoto) && $currentPhotoPath !== null) {
                $deleteLocalPhoto($currentPhotoPath);
            }

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
    $student['photo_path'] = $updatedPhotoPath;
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

    <form method="post" enctype="multipart/form-data" class="form-grid form-grid-wide">
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

        <label>Student Photo</label>
        <div class="photo-upload-area">
            <div class="photo-preview" id="photoPreview">
                <?php if (!empty($student['photo_path'])): ?>
                    <img id="previewImg" src="<?= htmlspecialchars((string)$student['photo_path']) ?>" alt="Student Photo">
                    <span id="previewPlaceholder" style="display:none;">No photo selected</span>
                <?php else: ?>
                    <img id="previewImg" src="" alt="Student Photo" style="display:none;">
                    <span id="previewPlaceholder">No photo selected</span>
                <?php endif; ?>
            </div>
            <div class="photo-actions">
                <label class="photo-btn" for="photoFileInput">Upload</label>
                <input type="file" id="photoFileInput" name="photo" accept="image/jpeg,image/png,image/webp" style="display:none;">
                <button type="button" class="photo-btn" id="cameraBtn">Camera</button>
                <button type="button" class="photo-btn photo-btn-danger" id="clearPhotoBtn">Clear</button>
            </div>
            <input type="hidden" name="camera_photo" id="cameraPhotoData" value="">
            <input type="hidden" name="remove_photo" id="removePhotoInput" value="0">
        </div>

        <div id="cameraModal" class="camera-modal" style="display:none;">
            <div class="camera-modal-inner">
                <video id="cameraFeed" autoplay playsinline></video>
                <canvas id="cameraCanvas" style="display:none;"></canvas>
                <div class="camera-modal-actions">
                    <button type="button" id="captureBtn" class="photo-btn">Capture</button>
                    <button type="button" id="closeCameraBtn" class="photo-btn photo-btn-danger">Cancel</button>
                </div>
            </div>
        </div>

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
<script>
(function() {
    var fileInput = document.getElementById('photoFileInput');
    var previewImg = document.getElementById('previewImg');
    var placeholder = document.getElementById('previewPlaceholder');
    var cameraBtn = document.getElementById('cameraBtn');
    var clearBtn = document.getElementById('clearPhotoBtn');
    var cameraModal = document.getElementById('cameraModal');
    var cameraFeed = document.getElementById('cameraFeed');
    var cameraCanvas = document.getElementById('cameraCanvas');
    var captureBtn = document.getElementById('captureBtn');
    var closeCameraBtn = document.getElementById('closeCameraBtn');
    var cameraData = document.getElementById('cameraPhotoData');
    var removePhotoInput = document.getElementById('removePhotoInput');
    var stream = null;

    function showPreview(src) {
        previewImg.src = src;
        previewImg.style.display = 'block';
        placeholder.style.display = 'none';
        removePhotoInput.value = '0';
    }

    function clearPreview() {
        previewImg.src = '';
        previewImg.style.display = 'none';
        placeholder.style.display = '';
        fileInput.value = '';
        cameraData.value = '';
        removePhotoInput.value = '1';
    }

    fileInput.addEventListener('change', function() {
        if (this.files && this.files[0]) {
            cameraData.value = '';
            var reader = new FileReader();
            reader.onload = function(e) { showPreview(e.target.result); };
            reader.readAsDataURL(this.files[0]);
        }
    });

    clearBtn.addEventListener('click', clearPreview);

    cameraBtn.addEventListener('click', function() {
        cameraModal.style.display = 'flex';
        navigator.mediaDevices.getUserMedia({ video: { facingMode: 'user', width: 640, height: 480 } })
            .then(function(s) {
                stream = s;
                cameraFeed.srcObject = stream;
            })
            .catch(function() {
                alert('Camera access denied or not available.');
                cameraModal.style.display = 'none';
            });
    });

    captureBtn.addEventListener('click', function() {
        cameraCanvas.width = cameraFeed.videoWidth;
        cameraCanvas.height = cameraFeed.videoHeight;
        cameraCanvas.getContext('2d').drawImage(cameraFeed, 0, 0);
        var dataUrl = cameraCanvas.toDataURL('image/jpeg', 0.85);
        cameraData.value = dataUrl;
        fileInput.value = '';
        showPreview(dataUrl);
        closeCamera();
    });

    function closeCamera() {
        cameraModal.style.display = 'none';
        if (stream) {
            stream.getTracks().forEach(function(track) { track.stop(); });
            stream = null;
        }
    }

    closeCameraBtn.addEventListener('click', closeCamera);
})();
</script>
<?php require __DIR__ . '/../../includes/footer.php'; ?>

<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_roles([ROLE_SUPER_ADMIN, ROLE_ADMIN]);

$errors = [];
$success = [];

function ensure_lead_profile_columns(PDO $pdo): void
{
    $columns = [];
    foreach ($pdo->query('SHOW COLUMNS FROM leads')->fetchAll() as $row) {
        $columns[$row['Field']] = true;
    }

    if (!isset($columns['dob'])) {
        $pdo->exec('ALTER TABLE leads ADD COLUMN dob DATE NULL AFTER class_applied');
    }
    if (!isset($columns['gender'])) {
        $pdo->exec('ALTER TABLE leads ADD COLUMN gender ENUM("male", "female", "other") NULL AFTER dob');
    }
    if (!isset($columns['address'])) {
        $pdo->exec('ALTER TABLE leads ADD COLUMN address VARCHAR(255) NULL AFTER gender');
    }
    if (!isset($columns['photo_path'])) {
        $pdo->exec('ALTER TABLE leads ADD COLUMN photo_path VARCHAR(255) NULL AFTER notes');
    }
    if (!isset($columns['guardian_phone'])) {
        $pdo->exec('ALTER TABLE leads ADD COLUMN guardian_phone VARCHAR(20) NULL AFTER guardian_name');
    }
    if (!isset($columns['guardian_email'])) {
        $pdo->exec('ALTER TABLE leads ADD COLUMN guardian_email VARCHAR(150) NULL AFTER guardian_phone');
    }
    if (!isset($columns['class_id'])) {
        $pdo->exec('ALTER TABLE leads ADD COLUMN class_id INT NULL AFTER phone');
    }
    if (!isset($columns['admission_concession_amount'])) {
        $pdo->exec('ALTER TABLE leads ADD COLUMN admission_concession_amount DECIMAL(10,2) NULL AFTER notes');
    }
    if (!isset($columns['admission_concession_note'])) {
        $pdo->exec('ALTER TABLE leads ADD COLUMN admission_concession_note TEXT NULL AFTER admission_concession_amount');
    }
}

ensure_lead_profile_columns($pdo);

$leadId = (int)($_GET['id'] ?? $_POST['lead_id'] ?? 0);
if ($leadId <= 0) {
    http_response_code(404);
    echo 'Lead not found.';
    exit;
}

$leadStmt = $pdo->prepare('SELECT * FROM leads WHERE id = :id LIMIT 1');
$leadStmt->execute(['id' => $leadId]);
$lead = $leadStmt->fetch();

if (!$lead) {
    http_response_code(404);
    echo 'Lead not found.';
    exit;
}

$classOptions = $pdo->query(
    'SELECT c.id, c.class_name, c.section, s.title AS session_title
     FROM classes c
     JOIN academic_sessions s ON s.id = c.session_id
     ORDER BY s.is_active DESC, s.start_date DESC, c.class_name, c.section'
)->fetchAll();

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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $leadName = trim($_POST['lead_name'] ?? '');
    $guardianName = trim($_POST['guardian_name'] ?? '');
    $guardianPhone = trim($_POST['guardian_phone'] ?? '');
    $guardianEmail = trim($_POST['guardian_email'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $classId = (int)($_POST['class_id'] ?? 0);
    $dob = trim($_POST['dob'] ?? '');
    $gender = trim($_POST['gender'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $notes = trim($_POST['notes'] ?? '');
    $status = trim($_POST['status'] ?? 'new');
    $removePhoto = (string)($_POST['remove_photo'] ?? '') === '1';

    $allowedStatuses = ['new', 'contacted', 'approved', 'rejected', 'enrolled'];
    $allowedGenders = ['', 'male', 'female', 'other'];

    $currentPhotoPath = !empty($lead['photo_path']) ? (string)$lead['photo_path'] : null;
    $updatedPhotoPath = $currentPhotoPath;
    $classApplied = (string)($lead['class_applied'] ?? '');

    if ($leadName === '') {
        $errors[] = 'Student name is required.';
    }
    if ($classId <= 0) {
        $errors[] = 'Please select a course.';
    }
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Invalid student email format.';
    }
    if ($guardianEmail !== '' && !filter_var($guardianEmail, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Invalid guardian email format.';
    }
    if (!in_array($status, $allowedStatuses, true)) {
        $errors[] = 'Invalid lead status.';
    }
    if (!in_array($gender, $allowedGenders, true)) {
        $errors[] = 'Invalid gender selected.';
    }
    if ($dob !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dob)) {
        $errors[] = 'Date of birth must be in YYYY-MM-DD format.';
    }

    $selectedClass = null;
    if ($classId > 0) {
        $classStmt = $pdo->prepare(
            'SELECT id, class_name, section
             FROM classes
             WHERE id = :class_id
             LIMIT 1'
        );
        $classStmt->execute(['class_id' => $classId]);
        $selectedClass = $classStmt->fetch();
        if (!$selectedClass) {
            $errors[] = 'Selected course is invalid.';
        } else {
            $classApplied = (string)$selectedClass['class_name'];
        }
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
            $filename = 'lead_' . $leadId . '_' . time() . '_' . random_int(1000, 9999) . '.' . $ext;
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
                    $filename = 'lead_cam_' . $leadId . '_' . time() . '_' . random_int(1000, 9999) . '.' . $ext;
                    if (file_put_contents($uploadDir . $filename, $decoded) !== false) {
                        $updatedPhotoPath = '/school-erp/uploads/photos/' . $filename;
                    } else {
                        $errors[] = 'Failed to save captured photo.';
                    }
                }
            }
        }
    }

    if (empty($errors)) {
        $updateStmt = $pdo->prepare(
            'UPDATE leads
             SET lead_name = :lead_name,
                 guardian_name = :guardian_name,
                 guardian_phone = :guardian_phone,
                 guardian_email = :guardian_email,
                 email = :email,
                 phone = :phone,
                 class_id = :class_id,
                 class_applied = :class_applied,
                 dob = :dob,
                 gender = :gender,
                 address = :address,
                 notes = :notes,
                 photo_path = :photo_path,
                 status = :status
             WHERE id = :id'
        );
        $updateStmt->execute([
            'lead_name' => $leadName,
            'guardian_name' => $guardianName !== '' ? $guardianName : null,
            'guardian_phone' => $guardianPhone !== '' ? $guardianPhone : null,
            'guardian_email' => $guardianEmail !== '' ? $guardianEmail : null,
            'email' => $email !== '' ? $email : null,
            'phone' => $phone !== '' ? $phone : null,
            'class_id' => $classId,
            'class_applied' => $classApplied,
            'dob' => $dob !== '' ? $dob : null,
            'gender' => $gender !== '' ? $gender : null,
            'address' => $address !== '' ? $address : null,
            'notes' => $notes !== '' ? $notes : null,
            'photo_path' => $updatedPhotoPath,
            'status' => $status,
            'id' => $leadId,
        ]);

        if (($currentPhotoPath !== $updatedPhotoPath || $removePhoto) && $currentPhotoPath !== null) {
            $deleteLocalPhoto($currentPhotoPath);
        }

        $success[] = 'Lead details updated successfully.';

        $leadStmt->execute(['id' => $leadId]);
        $lead = $leadStmt->fetch();
    } else {
        $lead['lead_name'] = $leadName;
        $lead['guardian_name'] = $guardianName;
        $lead['guardian_phone'] = $guardianPhone;
        $lead['guardian_email'] = $guardianEmail;
        $lead['email'] = $email;
        $lead['phone'] = $phone;
        $lead['class_id'] = $classId;
        $lead['class_applied'] = $classApplied;
        $lead['dob'] = $dob;
        $lead['gender'] = $gender;
        $lead['address'] = $address;
        $lead['notes'] = $notes;
        $lead['photo_path'] = $updatedPhotoPath;
        $lead['status'] = $status;
    }
}

$pageTitle = 'Edit Lead';
require __DIR__ . '/../../includes/header.php';
?>
<section class="card">
    <h2>Edit Lead</h2>
    <p>View and update lead profile details, course preference, and photo.</p>

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

    <form method="post" enctype="multipart/form-data" class="form-grid form-grid-wide">
        <input type="hidden" name="lead_id" value="<?= (int)$lead['id'] ?>">

        <label>Lead ID</label>
        <input type="text" value="<?= (int)$lead['id'] ?>" readonly>

        <label>Student Name</label>
        <input type="text" name="lead_name" value="<?= htmlspecialchars((string)$lead['lead_name']) ?>" required>

        <label>Guardian Name</label>
        <input type="text" name="guardian_name" value="<?= htmlspecialchars((string)($lead['guardian_name'] ?? '')) ?>">

        <label>Guardian Phone</label>
        <input type="text" name="guardian_phone" value="<?= htmlspecialchars((string)($lead['guardian_phone'] ?? '')) ?>">

        <label>Guardian Email</label>
        <input type="email" name="guardian_email" value="<?= htmlspecialchars((string)($lead['guardian_email'] ?? '')) ?>">

        <label>Student Email</label>
        <input type="email" name="email" value="<?= htmlspecialchars((string)($lead['email'] ?? '')) ?>">

        <label>Student Phone</label>
        <input type="text" name="phone" value="<?= htmlspecialchars((string)($lead['phone'] ?? '')) ?>">

        <label>Course Applied</label>
        <select name="class_id" required>
            <option value="">Select course</option>
            <?php foreach ($classOptions as $class): ?>
                <option value="<?= (int)$class['id'] ?>" <?= (int)($lead['class_id'] ?? 0) === (int)$class['id'] ? 'selected' : '' ?>>
                    <?= htmlspecialchars((string)$class['class_name'] . ' (' . (string)$class['session_title'] . ')') ?>
                </option>
            <?php endforeach; ?>
        </select>

        <label>Date of Birth</label>
        <input type="date" name="dob" value="<?= htmlspecialchars((string)($lead['dob'] ?? '')) ?>">

        <label>Gender</label>
        <select name="gender">
            <option value="" <?= empty($lead['gender']) ? 'selected' : '' ?>>Not set</option>
            <option value="male" <?= ($lead['gender'] ?? '') === 'male' ? 'selected' : '' ?>>Male</option>
            <option value="female" <?= ($lead['gender'] ?? '') === 'female' ? 'selected' : '' ?>>Female</option>
            <option value="other" <?= ($lead['gender'] ?? '') === 'other' ? 'selected' : '' ?>>Other</option>
        </select>

        <label>Status</label>
        <select name="status" required>
            <option value="new" <?= ($lead['status'] ?? '') === 'new' ? 'selected' : '' ?>>New</option>
            <option value="contacted" <?= ($lead['status'] ?? '') === 'contacted' ? 'selected' : '' ?>>Contacted</option>
            <option value="approved" <?= ($lead['status'] ?? '') === 'approved' ? 'selected' : '' ?>>Approved</option>
            <option value="rejected" <?= ($lead['status'] ?? '') === 'rejected' ? 'selected' : '' ?>>Rejected</option>
            <option value="enrolled" <?= ($lead['status'] ?? '') === 'enrolled' ? 'selected' : '' ?>>Enrolled</option>
        </select>

        <label>Student Photo</label>
        <div class="photo-upload-area">
            <div class="photo-preview" id="photoPreview">
                <?php if (!empty($lead['photo_path'])): ?>
                    <img id="previewImg" src="<?= htmlspecialchars((string)$lead['photo_path']) ?>" alt="Lead Photo">
                    <span id="previewPlaceholder" class="is-hidden">No photo selected</span>
                <?php else: ?>
                    <img id="previewImg" src="" alt="Lead Photo" class="is-hidden">
                    <span id="previewPlaceholder">No photo selected</span>
                <?php endif; ?>
            </div>
            <div class="photo-actions">
                <label class="photo-btn" for="photoFileInput">Upload</label>
                <input type="file" id="photoFileInput" name="photo" accept="image/jpeg,image/png,image/webp" class="is-hidden">
                <button type="button" class="photo-btn" id="cameraBtn">Camera</button>
                <button type="button" class="photo-btn photo-btn-danger" id="clearPhotoBtn">Clear</button>
            </div>
            <input type="hidden" name="camera_photo" id="cameraPhotoData" value="">
            <input type="hidden" name="remove_photo" id="removePhotoInput" value="0">
        </div>

        <div id="cameraModal" class="camera-modal is-hidden">
            <div class="camera-modal-inner">
                <video id="cameraFeed" autoplay playsinline></video>
            <canvas id="cameraCanvas" class="is-hidden"></canvas>
                <div class="camera-modal-actions">
                    <button type="button" id="captureBtn" class="photo-btn">Capture</button>
                    <button type="button" id="closeCameraBtn" class="photo-btn photo-btn-danger">Cancel</button>
                </div>
            </div>
        </div>

        <label>Address</label>
        <textarea name="address" rows="2"><?= htmlspecialchars((string)($lead['address'] ?? '')) ?></textarea>

        <label>Notes</label>
        <textarea name="notes" rows="3"><?= htmlspecialchars((string)($lead['notes'] ?? '')) ?></textarea>

        <button type="submit">Save Changes</button>
        <a class="nav-item" href="/school-erp/modules/admission/leads.php">Back to Leads</a>
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

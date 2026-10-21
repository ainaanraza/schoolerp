<?php
require_once __DIR__ . '/../../includes/bootstrap.php';

$errors = [];
$success = null;

// Public link for sharing
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'] ?? 'localhost:8000';
$admissionFormLink = $scheme . '://' . $host . '/itierp/modules/admission/apply.php';

function ensure_lead_profile_columns(PDO $pdo): void
{
    $columns = [];
    $columnRows = $pdo->query('SHOW COLUMNS FROM leads')->fetchAll();
    foreach ($columnRows as $row) {
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

$classOptions = $pdo->query(
    'SELECT c.id, c.class_name, c.section, s.title AS session_title
     FROM classes c
     JOIN academic_sessions s ON s.id = c.session_id
     ORDER BY s.is_active DESC, s.start_date DESC, c.class_name, c.section'
)->fetchAll();

// Ensure uploads directory exists
$uploadDir = __DIR__ . '/../../uploads/photos/';
if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0755, true);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $leadName = trim($_POST['lead_name'] ?? '');
    $guardianName = trim($_POST['guardian_name'] ?? '');
    $guardianPhone = trim($_POST['guardian_phone'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $classId = (int)($_POST['class_id'] ?? 0);
    $classApplied = '';
    $dob = trim($_POST['dob'] ?? '');
    $gender = trim($_POST['gender'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $notes = trim($_POST['notes'] ?? '');
    $photoPath = null;

    if ($leadName === '' || $classId <= 0) {
        $errors[] = 'Student name and course selection are required.';
    }
        $selectedClass = null;
        if ($classId > 0) {
            $classStatement = $pdo->prepare(
                'SELECT c.id, c.class_name, c.section
                 FROM classes c
                 WHERE c.id = :class_id
                 LIMIT 1'
            );
            $classStatement->execute(['class_id' => $classId]);
            $selectedClass = $classStatement->fetch();
            if (!$selectedClass) {
                $errors[] = 'Selected course is invalid.';
            } else {
                $classApplied = (string)$selectedClass['class_name'];
            }
        }

    if ($gender !== '' && !in_array($gender, ['male', 'female', 'other'], true)) {
        $gender = '';
    }
    if ($dob !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dob)) {
        $errors[] = 'Date of birth must be in YYYY-MM-DD format.';
    }

    // Handle photo upload (file input)
    if (empty($errors) && isset($_FILES['photo']) && $_FILES['photo']['error'] === UPLOAD_ERR_OK) {
        $allowed = ['image/jpeg', 'image/png', 'image/webp'];
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = finfo_file($finfo, $_FILES['photo']['tmp_name']);
        finfo_close($finfo);

        if (!in_array($mime, $allowed, true)) {
            $errors[] = 'Photo must be a JPEG, PNG, or WebP image.';
        } elseif ($_FILES['photo']['size'] > 5 * 1024 * 1024) {
            $errors[] = 'Photo must be under 5 MB.';
        } else {
            $ext = match($mime) {
                'image/jpeg' => 'jpg',
                'image/png' => 'png',
                'image/webp' => 'webp',
                default => 'jpg',
            };
            $filename = 'lead_' . time() . '_' . random_int(1000, 9999) . '.' . $ext;
            if (move_uploaded_file($_FILES['photo']['tmp_name'], $uploadDir . $filename)) {
                $photoPath = 'uploads/photos/' . $filename;
            } else {
                $errors[] = 'Failed to save uploaded photo.';
            }
        }
    }

    // Handle camera capture (base64 data URI from hidden input)
    if (empty($errors) && $photoPath === null) {
        $cameraData = trim($_POST['camera_photo'] ?? '');
        if ($cameraData !== '' && str_starts_with($cameraData, 'data:image/')) {
            $parts = explode(',', $cameraData, 2);
            if (count($parts) === 2) {
                $decoded = base64_decode($parts[1]);
                if ($decoded !== false && strlen($decoded) > 100) {
                    $ext = str_contains($parts[0], 'png') ? 'png' : 'jpg';
                    $filename = 'lead_cam_' . time() . '_' . random_int(1000, 9999) . '.' . $ext;
                    if (file_put_contents($uploadDir . $filename, $decoded)) {
                        $photoPath = 'uploads/photos/' . $filename;
                    }
                }
            }
        }
    }

    if (empty($errors)) {
        $source = 'form_link';
        if (is_logged_in() && in_array((string)current_role(), [ROLE_SUPER_ADMIN, ROLE_ADMIN], true)) {
            $source = 'manual';
        }

        $statement = $pdo->prepare(
            'INSERT INTO leads (lead_name, guardian_name, guardian_phone, email, phone, class_id, class_applied, dob, gender, address, source, notes, photo_path)
             VALUES (:lead_name, :guardian_name, :guardian_phone, :email, :phone, :class_id, :class_applied, :dob, :gender, :address, :source, :notes, :photo_path)'
        );
        $statement->execute([
            'lead_name' => $leadName,
            'guardian_name' => $guardianName !== '' ? $guardianName : null,
            'guardian_phone' => $guardianPhone !== '' ? $guardianPhone : null,
            'email' => $email !== '' ? $email : null,
            'phone' => $phone !== '' ? $phone : null,
            'class_id' => $classId,
            'class_applied' => $classApplied,
            'dob' => $dob !== '' ? $dob : null,
            'gender' => $gender !== '' ? $gender : null,
            'address' => $address !== '' ? $address : null,
            'source' => $source,
            'notes' => $notes !== '' ? $notes : null,
            'photo_path' => $photoPath,
        ]);

        $success = 'Application submitted successfully. School admin will contact you soon.';
    }
}

$pageTitle = 'Admission Application';
require __DIR__ . '/../../includes/header.php';
?>
<section class="card">
    <h2>Admission Application Form</h2>
    <?php if (is_logged_in() && in_array(current_role(), [ROLE_ADMIN, ROLE_SUPER_ADMIN], true)): ?>
        <div class="admission-link-card">
            <div class="admission-link-card-header">
                <span class="admission-link-tag">Public Admission Form Link</span>
                <button type="button" class="btn-copy-link" onclick="copyAdmissionLink(this)">Copy Link</button>
            </div>
            <div class="admission-link-card-url">
                <a href="<?= htmlspecialchars($admissionFormLink) ?>" target="_blank" id="admissionFormUrlText"><?= htmlspecialchars($admissionFormLink) ?></a>
            </div>
            <span class="admission-link-hint">Share this URL with prospective candidates for online applications.</span>
        </div>
        <script>
        function copyAdmissionLink(btn) {
            const urlEl = document.getElementById('admissionFormUrlText');
            if (!urlEl) return;
            const url = urlEl.href || urlEl.innerText;
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(url).then(function() {
                    btn.innerText = 'Copied!';
                    btn.classList.add('copied');
                    setTimeout(function() { btn.innerText = 'Copy Link'; btn.classList.remove('copied'); }, 2000);
                }).catch(function() { fallbackCopy(url, btn); });
            } else {
                fallbackCopy(url, btn);
            }
        }
        function fallbackCopy(text, btn) {
            const ta = document.createElement('textarea');
            ta.value = text;
            ta.style.position = 'fixed';
            ta.style.opacity = '0';
            document.body.appendChild(ta);
            ta.select();
            document.execCommand('copy');
            document.body.removeChild(ta);
            btn.innerText = 'Copied!';
            btn.classList.add('copied');
            setTimeout(function() { btn.innerText = 'Copy Link'; btn.classList.remove('copied'); }, 2000);
        }
        </script>
    <?php endif; ?>

    <?php if (!empty($errors)): ?>
        <div class="error">
            <?php foreach ($errors as $error): ?>
                <p><?= htmlspecialchars($error) ?></p>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <?php if ($success): ?>
        <div class="success">
            <p><?= htmlspecialchars($success) ?></p>
        </div>
    <?php endif; ?>

    <form method="post" enctype="multipart/form-data" class="form-grid form-grid-wide">
        <label>Student Name</label>
        <input type="text" name="lead_name" required>

        <label>Guardian Name</label>
        <input type="text" name="guardian_name">

        <label>Guardian Phone</label>
        <input type="text" name="guardian_phone">

        <label>Student Email</label>
        <input type="email" name="email">

        <label>Student Phone</label>
        <input type="text" name="phone">

        <label>Course Applied</label>
        <select name="class_id" required>
            <option value="">Select course</option>
            <?php foreach ($classOptions as $class): ?>
                <option value="<?= (int)$class['id'] ?>">
                    <?= htmlspecialchars((string)$class['class_name'] . ' (' . (string)$class['session_title'] . ')') ?>
                </option>
            <?php endforeach; ?>
        </select>

        <label>Date of Birth</label>
        <input type="date" name="dob">

        <label>Gender</label>
        <select name="gender">
            <option value="">Select gender</option>
            <option value="male">Male</option>
            <option value="female">Female</option>
            <option value="other">Other</option>
        </select>

        <label>Address</label>
        <textarea name="address" rows="2" placeholder="Student address"></textarea>

        <!-- Photo Upload / Camera -->
        <label>Student Photo</label>
        <div class="photo-upload-area" id="photoArea">
            <div class="photo-preview" id="photoPreview">
                <img id="previewImg" src="" alt="Preview" class="is-hidden">
                <span id="previewPlaceholder">No photo selected</span>
            </div>
            <div class="photo-actions">
                <label class="photo-btn" for="photoFileInput">&#128194; Upload</label>
                <input type="file" id="photoFileInput" name="photo" accept="image/jpeg,image/png,image/webp" class="is-hidden">
                <button type="button" class="photo-btn" id="cameraBtn">&#128247; Camera</button>
                <button type="button" class="photo-btn photo-btn-danger is-hidden" id="clearPhotoBtn">&#10006; Clear</button>
            </div>
            <input type="hidden" name="camera_photo" id="cameraPhotoData" value="">
        </div>

        <label>Notes</label>
        <textarea name="notes" rows="3"></textarea>

        <button type="submit">Submit Application</button>
    </form>
</section>

<!-- Camera Modal (Positioned at viewport root) -->
<div id="cameraModal" class="camera-modal is-hidden">
    <div class="camera-modal-inner">
        <video id="cameraFeed" autoplay playsinline></video>
        <canvas id="cameraCanvas" class="is-hidden"></canvas>
        <div class="camera-modal-actions">
            <button type="button" id="captureBtn" class="photo-btn">&#128248; Capture</button>
            <button type="button" id="closeCameraBtn" class="photo-btn photo-btn-danger">Cancel</button>
        </div>
    </div>
</div>

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
    var stream = null;

    // Ensure camera modal is attached directly to document.body so that
    // transformed parent containers (.card, etc.) cannot disrupt fixed viewport centering
    if (cameraModal && cameraModal.parentNode !== document.body) {
        document.body.appendChild(cameraModal);
    }

    function showPreview(src) {
        previewImg.src = src;
        previewImg.style.display = 'block';
        placeholder.style.display = 'none';
        clearBtn.style.display = '';
    }

    function clearPreview() {
        previewImg.src = '';
        previewImg.style.display = 'none';
        placeholder.style.display = '';
        clearBtn.style.display = 'none';
        fileInput.value = '';
        cameraData.value = '';
    }

    // File Upload preview
    fileInput.addEventListener('change', function() {
        if (this.files && this.files[0]) {
            cameraData.value = ''; // clear camera data if file chosen
            var reader = new FileReader();
            reader.onload = function(e) { showPreview(e.target.result); };
            reader.readAsDataURL(this.files[0]);
        }
    });

    // Clear
    clearBtn.addEventListener('click', clearPreview);

    // Open Camera
    cameraBtn.addEventListener('click', function() {
        if (cameraModal.parentNode !== document.body) {
            document.body.appendChild(cameraModal);
        }
        cameraModal.classList.remove('is-hidden');
        cameraModal.style.display = 'flex';
        document.body.classList.add('modal-open');

        if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
            alert('Camera is not supported on this device/browser.');
            closeCamera();
            return;
        }

        navigator.mediaDevices.getUserMedia({
            video: {
                facingMode: 'user',
                width: { ideal: 640 },
                height: { ideal: 480 }
            }
        })
        .then(function(s) {
            stream = s;
            cameraFeed.srcObject = stream;
        })
        .catch(function(err) {
            console.error('Camera access error:', err);
            alert('Camera access denied or not available. Please allow camera permissions in your browser.');
            closeCamera();
        });
    });

    // Capture
    captureBtn.addEventListener('click', function() {
        if (!cameraFeed.videoWidth || !cameraFeed.videoHeight) {
            return;
        }
        cameraCanvas.width = cameraFeed.videoWidth;
        cameraCanvas.height = cameraFeed.videoHeight;
        cameraCanvas.getContext('2d').drawImage(cameraFeed, 0, 0);
        var dataUrl = cameraCanvas.toDataURL('image/jpeg', 0.85);
        cameraData.value = dataUrl;
        fileInput.value = ''; // clear file input
        showPreview(dataUrl);
        closeCamera();
    });

    // Close Camera
    function closeCamera() {
        cameraModal.style.display = 'none';
        cameraModal.classList.add('is-hidden');
        document.body.classList.remove('modal-open');
        if (stream) {
            stream.getTracks().forEach(function(t) { t.stop(); });
            stream = null;
        }
    }

    closeCameraBtn.addEventListener('click', closeCamera);

    // Close if clicking outside the modal content (on the overlay backdrop)
    cameraModal.addEventListener('click', function(e) {
        if (e.target === cameraModal) {
            closeCamera();
        }
    });

    // Close on Escape key
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape' && cameraModal.style.display === 'flex') {
            closeCamera();
        }
    });
})();
</script>

<?php require __DIR__ . '/../../includes/footer.php'; ?>

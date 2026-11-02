<?php
$pageTitle = 'My Profile';
require_once '../includes/auth.php';
require_once '../config/db.php';
require_once '../includes/header.php';
require_once '../includes/navbar.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'student') {
    header('Location: /jkkniu-admission/student/login.php'); exit();
}
$applicant_id = $_SESSION['user_id'];

$stmt = $pdo->prepare("
    SELECT a.*, f.faculty_name
    FROM gst_applicants a
    JOIN faculties f ON a.faculty_id = f.faculty_id
    WHERE a.applicant_id = ?
");
$stmt->execute([$applicant_id]);
$student = $stmt->fetch();

$stmtExam = $pdo->prepare("
    SELECT e.*, GROUP_CONCAT(s.subject_name, ':', s.marks_obtained ORDER BY s.subject_mark_id SEPARATOR '|') AS subjects
    FROM gst_exam_details e
    LEFT JOIN gst_subject_marks s ON e.gst_exam_id = s.gst_exam_id
    WHERE e.applicant_id = ?
    GROUP BY e.gst_exam_id
");
$stmtExam->execute([$applicant_id]);
$examDetails = $stmtExam->fetch();

$stmtDepts = $pdo->prepare("
    SELECT dc.preference_order, d.dept_name, f.faculty_name
    FROM dept_choices dc
    JOIN departments d ON dc.dept_id = d.dept_id
    JOIN faculties f ON d.faculty_id = f.faculty_id
    WHERE dc.applicant_id = ?
    ORDER BY dc.preference_order
");
$stmtDepts->execute([$applicant_id]);
$deptChoices = $stmtDepts->fetchAll();

$stmtMerit = $pdo->prepare("
    SELECT ma.*, d.dept_name
    FROM merit_allocations ma
    JOIN departments d ON ma.dept_id = d.dept_id
    WHERE ma.applicant_id = ?
");
$stmtMerit->execute([$applicant_id]);
$merit = $stmtMerit->fetch();

$error = $success = '';

// Handle photo/signature update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_photo'])) {
    $allowed = ['jpg','jpeg','png'];
    $max     = 2 * 1024 * 1024;

    function uploadMedia($file, $dir, $pfx) {
        global $allowed, $max;
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, $allowed) || $file['size'] > $max) return false;
        $name = $pfx . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
        return move_uploaded_file($file['tmp_name'], $dir . $name) ? $name : false;
    }

    $updates = [];
    $params  = [];

    if (!empty($_FILES['photo']['name'])) {
        $p = uploadMedia($_FILES['photo'], '../uploads/photos/', 'photo');
        if ($p) { $updates[] = 'photo = ?'; $params[] = $p; }
        else $error = 'Photo upload failed. JPG/PNG only, max 2MB.';
    }
    if (!empty($_FILES['signature']['name'])) {
        $s = uploadMedia($_FILES['signature'], '../signatures/', 'sig');
        if ($s) { $updates[] = 'signature = ?'; $params[] = $s; }
        else $error = 'Signature upload failed. JPG/PNG only, max 2MB.';
    }

    if (empty($error) && !empty($updates)) {
        $params[] = $applicant_id;
        $pdo->prepare("UPDATE gst_applicants SET " . implode(', ', $updates) . " WHERE applicant_id = ?")
            ->execute($params);
        $success = 'Photo/signature updated successfully.';
        $stmt->execute([$applicant_id]);
        $student = $stmt->fetch();
    }
}
?>

<div class="container py-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h4 class="fw-bold mb-0">
            <i class="bi bi-person-circle text-primary"></i> My Profile
        </h4>
        <div class="d-flex gap-2">
            <a href="print_application.php" target="_blank" class="btn btn-outline-success btn-sm">
                <i class="bi bi-printer-fill"></i> Print Application
            </a>
            <a href="dashboard.php" class="btn btn-outline-secondary btn-sm">
                <i class="bi bi-arrow-left"></i> Dashboard
            </a>
        </div>
    </div>

    <?php if ($error): ?>
        <div class="alert alert-danger"><?= $error ?></div>
    <?php endif; ?>
    <?php if ($success): ?>
        <div class="alert alert-success"><?= $success ?></div>
    <?php endif; ?>

    <div class="row g-4">

        <!-- Left: Photo & Signature -->
        <div class="col-lg-3">
            <div class="card shadow-sm text-center">
                <div class="card-body p-4">
                    <?php if (!empty($student['photo'])): ?>
                        <img src="/jkkniu-admission/uploads/photos/<?= htmlspecialchars($student['photo']) ?>"
                             class="rounded-circle mb-3" width="110" height="110"
                             style="object-fit:cover;border:3px solid var(--jkkniu-secondary);" alt="Photo">
                    <?php else: ?>
                        <div class="rounded-circle bg-secondary d-flex align-items-center justify-content-center mx-auto mb-3"
                             style="width:110px;height:110px;">
                            <i class="bi bi-person-fill text-white" style="font-size:3rem;"></i>
                        </div>
                    <?php endif; ?>

                    <h6 class="fw-bold"><?= htmlspecialchars($student['full_name']) ?></h6>
                    <p class="text-muted small mb-1">Roll: <strong><?= htmlspecialchars($student['gst_roll']) ?></strong></p>
                    <p class="text-muted small mb-3">GST Score: <strong><?= $student['gst_score'] ?></strong></p>

                    <?php if (!empty($student['signature'])): ?>
                        <div class="border rounded p-2 bg-light mb-3">
                            <img src="/jkkniu-admission/signatures/<?= htmlspecialchars($student['signature']) ?>"
                                 height="50" class="img-fluid" alt="Signature">
                        </div>
                    <?php endif; ?>

                    <!-- Update Photo/Signature -->
                    <button class="btn btn-outline-primary btn-sm w-100"
                            data-bs-toggle="collapse" data-bs-target="#updatePhotoForm">
                        <i class="bi bi-camera-fill"></i> Update Photo/Signature
                    </button>
                    <div class="collapse mt-3" id="updatePhotoForm">
                        <form method="POST" enctype="multipart/form-data">
                            <div class="mb-2 text-start">
                                <label class="form-label small fw-semibold">Photo (JPG/PNG ≤ 2MB)</label>
                                <input type="file" name="photo" class="form-control form-control-sm"
                                       accept=".jpg,.jpeg,.png">
                            </div>
                            <div class="mb-2 text-start">
                                <label class="form-label small fw-semibold">Signature (JPG/PNG ≤ 2MB)</label>
                                <input type="file" name="signature" class="form-control form-control-sm"
                                       accept=".jpg,.jpeg,.png">
                            </div>
                            <button type="submit" name="update_photo" class="btn btn-primary btn-sm w-100">
                                <i class="bi bi-upload"></i> Update
                            </button>
                        </form>
                    </div>
                </div>
            </div>

            <?php if ($merit): ?>
            <div class="card shadow-sm mt-3">
                <div class="card-body text-center">
                    <div class="text-muted small mb-1">Allocated Department</div>
                    <h6 class="fw-bold text-primary"><?= htmlspecialchars($merit['dept_name']) ?></h6>
                    <span class="badge bg-success">Merit Rank #<?= $merit['merit_rank'] ?></span>
                </div>
            </div>
            <?php endif; ?>
        </div>

        <!-- Right: Details -->
        <div class="col-lg-9">

            <!-- Personal Info -->
            <div class="card shadow-sm mb-4">
                <div class="card-header">
                    <i class="bi bi-person-fill"></i> Personal Information
                </div>
                <div class="card-body">
                    <div class="row g-3">
                        <?php
                        $fields = [
                            'Full Name'     => $student['full_name'],
                            'Father\'s Name' => $student['father_name'],
                            'Mother\'s Name' => $student['mother_name'],
                            'Date of Birth' => $student['date_of_birth']
                                ? date('d M Y', strtotime($student['date_of_birth'])) : '—',
                            'Gender'        => $student['gender'] ?? '—',
                            'Nationality'   => $student['nationality'] ?? '—',
                            'Phone'         => $student['phone'],
                            'Email'         => $student['email'],
                            'Quota'         => $student['quota'] ?? 'General',
                            'Faculty'       => $student['faculty_name'],
                        ];
                        foreach ($fields as $label => $value): ?>
                        <div class="col-md-4 col-6">
                            <small class="text-muted d-block"><?= $label ?></small>
                            <span class="fw-semibold"><?= htmlspecialchars($value ?? '—') ?></span>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <!-- HSC Info -->
            <div class="card shadow-sm mb-4">
                <div class="card-header">
                    <i class="bi bi-mortarboard-fill"></i> HSC Information
                </div>
                <div class="card-body">
                    <div class="row g-3">
                        <?php
                        $hscFields = [
                            'Roll'        => $student['hsc_roll'],
                            'Reg. No.'    => $student['hsc_reg'],
                            'Board'       => $student['hsc_board'],
                            'Year'        => $student['hsc_year'],
                            'Group'       => $student['hsc_group'],
                            'GPA'         => $student['hsc_gpa'],
                            'Institute'   => $student['hsc_institute'],
                        ];
                        foreach ($hscFields as $label => $value): ?>
                        <div class="col-md-3 col-6">
                            <small class="text-muted d-block"><?= $label ?></small>
                            <span class="fw-semibold"><?= htmlspecialchars($value ?? '—') ?></span>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <!-- SSC Info -->
            <div class="card shadow-sm mb-4">
                <div class="card-header">
                    <i class="bi bi-book-fill"></i> SSC Information
                </div>
                <div class="card-body">
                    <div class="row g-3">
                        <?php
                        $sscFields = [
                            'Roll'      => $student['ssc_roll'],
                            'Reg. No.'  => $student['ssc_reg'],
                            'Board'     => $student['ssc_board'],
                            'Year'      => $student['ssc_year'],
                            'GPA'       => $student['ssc_gpa'],
                            'Institute' => $student['ssc_institute'],
                        ];
                        foreach ($sscFields as $label => $value): ?>
                        <div class="col-md-3 col-6">
                            <small class="text-muted d-block"><?= $label ?></small>
                            <span class="fw-semibold"><?= htmlspecialchars($value ?? '—') ?></span>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <!-- GST Exam Details -->
            <?php if ($examDetails): ?>
            <div class="card shadow-sm mb-4">
                <div class="card-header">
                    <i class="bi bi-card-checklist"></i> GST Exam Details
                </div>
                <div class="card-body">
                    <div class="row g-3 mb-3">
                        <div class="col-md-4 col-6">
                            <small class="text-muted d-block">GST Roll</small>
                            <span class="fw-semibold"><?= htmlspecialchars($examDetails['gst_roll']) ?></span>
                        </div>
                        <div class="col-md-4 col-6">
                            <small class="text-muted d-block">Applicant ID</small>
                            <span class="fw-semibold"><?= htmlspecialchars($examDetails['gst_applicant_id']) ?></span>
                        </div>
                        <div class="col-md-4 col-6">
                            <small class="text-muted d-block">Total Mark</small>
                            <span class="fw-bold text-primary fs-5"><?= $examDetails['total_mark'] ?></span>
                        </div>
                    </div>
                    <?php if (!empty($examDetails['subjects'])): ?>
                    <h6 class="small fw-bold text-muted mb-2">Subject-wise Marks</h6>
                    <div class="d-flex flex-wrap gap-2">
                        <?php foreach (explode('|', $examDetails['subjects']) as $sub):
                            [$sname, $smark] = array_pad(explode(':', $sub, 2), 2, '—'); ?>
                            <span class="badge bg-light text-dark border px-3 py-2">
                                <?= htmlspecialchars($sname) ?>: <strong><?= htmlspecialchars($smark) ?></strong>
                            </span>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>

            <!-- Department Preferences -->
            <?php if (!empty($deptChoices)): ?>
            <div class="card shadow-sm">
                <div class="card-header">
                    <i class="bi bi-list-ol"></i> Department Preferences
                </div>
                <div class="card-body p-0">
                    <ul class="list-group list-group-flush">
                        <?php foreach ($deptChoices as $choice): ?>
                        <li class="list-group-item d-flex align-items-center gap-3">
                            <span class="badge bg-primary rounded-pill"><?= $choice['preference_order'] ?></span>
                            <span class="fw-semibold"><?= htmlspecialchars($choice['dept_name']) ?></span>
                            <span class="text-muted small">(<?= htmlspecialchars($choice['faculty_name']) ?>)</span>
                            <?php if ($merit && $merit['dept_name'] === $choice['dept_name']): ?>
                                <span class="badge bg-success ms-auto">Allocated</span>
                            <?php endif; ?>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php require_once '../includes/footer.php'; ?>

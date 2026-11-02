<?php

declare(strict_types=1);

$pageTitle = 'Step 1 — Department Submission';
$currentStep = 1;

require_once '../includes/auth.php';
require_once '../config/db.php';
require_once '../includes/admission_session.php';
require_once '../includes/student_biodata.php';
require_once '../includes/student_submission_context.php';
require_once '../includes/payments/sslcommerz.php';
require_once '../includes/payments/sonali_bank.php';

requireLogin('student');

$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$applicant_id = (int) ($_SESSION['user_id'] ?? 0);
$currentAcademicYear = admissionSessionCurrentYear($pdo);

$student = fetchStudentBiodata(
    $pdo,
    $applicant_id,
    $currentAcademicYear
);

if (!$student) {
    header('Location: /jkkniu-admission/student/login.php');
    exit;
}

try {
    $context = studentSubmissionContext(
        $pdo,
        $applicant_id,
        $currentAcademicYear
    );
} catch (Throwable $e) {
    require_once '../includes/header.php';
    require_once '../includes/navbar.php';
    ?>
    <div class="container py-5">
        <div class="alert alert-warning">
            <i class="bi bi-exclamation-triangle-fill me-1"></i>
            <?= htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') ?>
        </div>
        <a href="/jkkniu-admission/student/dashboard.php" class="btn btn-outline-secondary">
            <i class="bi bi-arrow-left"></i> Back to Dashboard
        </a>
    </div>
    <?php
    require_once '../includes/footer.php';
    exit;
}

$merit = $context['merit'];
$progress = $context['progress'];

$stmtSub = $pdo->prepare("
    SELECT *
    FROM dept_submissions
    WHERE applicant_id = ?
    LIMIT 1
");
$stmtSub->execute([$applicant_id]);
$existing = $stmtSub->fetch(PDO::FETCH_ASSOC);

$can_resubmit =
    $existing
    &&
    in_array(
        (string) $existing['submission_status'],
        ['Pending', 'Rejected'],
        true
    );

$error = '';
$success = '';

$deptPayment = paymentFetchLatest(
    $pdo,
    $applicant_id,
    PAYMENT_STEP_DEPT
);

$deptPaid = paymentIsSettled(
    $pdo,
    $applicant_id,
    PAYMENT_STEP_DEPT
);

if (isset($_GET['pay_error'])) {
    $error = 'Payment could not be completed: '
        . htmlspecialchars(
            (string) $_GET['pay_error'],
            ENT_QUOTES,
            'UTF-8'
        );
}

if (isset($_GET['pay_ok'])) {
    $success = htmlspecialchars(
        (string) $_GET['pay_ok'],
        ENT_QUOTES,
        'UTF-8'
    );
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (
        $existing
        &&
        (string) $existing['submission_status'] === 'Verified'
    ) {
        $error = 'Step 1 is already VERIFIED. Cannot modify.';
    } else {

        $formAction =
            trim((string) ($_POST['form_action'] ?? 'submit'));

        $provider =
            trim((string) ($_POST['provider'] ?? 'sslcommerz'));

        $present_address   = trim((string) ($_POST['present_address'] ?? ''));
        $permanent_address = trim((string) ($_POST['permanent_address'] ?? ''));
        $guardian_name     = trim((string) ($_POST['guardian_name'] ?? ''));
        $guardian_phone    = trim((string) ($_POST['guardian_phone'] ?? ''));
        $guardian_relation = trim((string) ($_POST['guardian_relation'] ?? ''));
        $fee               = paymentFeeAmount(PAYMENT_STEP_DEPT);
        $method            = $provider === 'sonali'
            ? 'Sonali Bank'
            : 'SSLCommerz';

        $errs = [];

        if ($present_address === '')   $errs[] = 'Present address is required.';
        if ($permanent_address === '') $errs[] = 'Permanent address is required.';
        if ($guardian_name === '')     $errs[] = 'Guardian name is required.';
        if ($guardian_phone === '')    $errs[] = 'Guardian phone is required.';

        if (
            $guardian_phone !== ''
            &&
            !preg_match('/^01[3-9]\d{8}$/', $guardian_phone)
        ) {
            $errs[] = 'Invalid guardian phone (01XXXXXXXXX).';
        }

        if ($guardian_relation === '') $errs[] = 'Guardian relation is required.';

        if (
            $formAction !== 'pay_submit'
            &&
            !paymentIsSettled($pdo, $applicant_id, PAYMENT_STEP_DEPT)
        ) {
            $errs[] = 'Please complete the Department Fee payment first.';
        }

        if (empty($_FILES['hsc_transcript']['name']) && !$existing) {
            $errs[] = 'HSC transcript is required.';
        }

        if (empty($_FILES['ssc_transcript']['name']) && !$existing) {
            $errs[] = 'SSC transcript is required.';
        }

        if (!$errs) {

            $allowed = ['pdf', 'jpg', 'jpeg', 'png'];
            $max = 5 * 1024 * 1024;

            function step1Up(array $file, string $dir, string $pfx): string|false
            {
                global $allowed, $max, $applicant_id;

                $ext = strtolower(
                    pathinfo(
                        (string) $file['name'],
                        PATHINFO_EXTENSION
                    )
                );

                if (
                    !in_array($ext, $allowed, true)
                    ||
                    (int) ($file['size'] ?? 0) > $max
                ) {
                    return false;
                }

                @mkdir($dir, 0777, true);

                $name =
                    $pfx . '_' .
                    $applicant_id . '_' .
                    time() . '_' .
                    bin2hex(random_bytes(4)) . '.' .
                    $ext;

                return move_uploaded_file(
                    (string) $file['tmp_name'],
                    $dir . $name
                )
                    ? $name
                    : false;
            }

            $hsc_tr =
                empty($_FILES['hsc_transcript']['name'])
                    ? ($existing ? (string) ($existing['hsc_transcript'] ?? '') : '')
                    : step1Up(
                        $_FILES['hsc_transcript'],
                        '../uploads/transcripts/',
                        'hsc'
                    );

            $ssc_tr =
                empty($_FILES['ssc_transcript']['name'])
                    ? ($existing ? (string) ($existing['ssc_transcript'] ?? '') : '')
                    : step1Up(
                        $_FILES['ssc_transcript'],
                        '../uploads/transcripts/',
                        'ssc'
                    );

            if (!$hsc_tr) $errs[] = 'HSC transcript upload failed (PDF/JPG/PNG ≤ 5MB).';
            if (!$ssc_tr) $errs[] = 'SSC transcript upload failed (PDF/JPG/PNG ≤ 5MB).';

            if (!$errs) {

                if ($existing && $can_resubmit) {

                    $stmt = $pdo->prepare("
                        UPDATE dept_submissions SET
                            dept_id = ?,
                            present_address = ?,
                            permanent_address = ?,
                            guardian_name = ?,
                            guardian_phone = ?,
                            guardian_relation = ?,
                            hsc_transcript = ?,
                            ssc_transcript = ?,
                            dept_fee_amount = ?,
                            payment_method = ?,
                            dept_fee_receipt = ?,
                            submission_status = 'Pending',
                            verified_at = NULL
                        WHERE applicant_id = ?
                    ");

                    $stmt->execute([
                        (int) $merit['dept_id'],
                        $present_address,
                        $permanent_address,
                        $guardian_name,
                        $guardian_phone,
                        $guardian_relation,
                        $hsc_tr,
                        $ssc_tr,
                        $fee,
                        $method,
                        null,
                        $applicant_id
                    ]);

                    $success = '✅ Step 1 UPDATED successfully! Awaiting department verification.';

                } else {

                    $stmt = $pdo->prepare("
                        INSERT INTO dept_submissions
                        (
                            applicant_id,
                            dept_id,
                            present_address,
                            permanent_address,
                            guardian_name,
                            guardian_phone,
                            guardian_relation,
                            hsc_transcript,
                            ssc_transcript,
                            dept_fee_amount,
                            dept_fee_receipt,
                            payment_method,
                            submission_status
                        )
                        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)
                    ");

                    $stmt->execute([
                        $applicant_id,
                        (int) $merit['dept_id'],
                        $present_address,
                        $permanent_address,
                        $guardian_name,
                        $guardian_phone,
                        $guardian_relation,
                        $hsc_tr,
                        $ssc_tr,
                        $fee,
                        null,
                        $method,
                        'Pending'
                    ]);

                    $success = '✅ Step 1 submitted! Awaiting department verification.';
                }

                $stmtSub->execute([$applicant_id]);
                $existing = $stmtSub->fetch(PDO::FETCH_ASSOC);

                if ($formAction === 'pay_submit') {
                    if ($provider === 'sonali') {
                        header(
                            'Location: '
                            . sonaliBankPaymentUrl()
                        );
                    } else {
                        header(
                            'Location: /jkkniu-admission/api/payment/initiate.php?step='
                            . PAYMENT_STEP_DEPT
                        );
                    }
                    exit;
                }
            }
        }

        if ($errs) {
            $error = implode('<br>', $errs);
        }
    }
}

require_once '../includes/header.php';
require_once '../includes/navbar.php';
?>

<div class="container py-4">
    <nav aria-label="breadcrumb" class="mb-3">
        <ol class="breadcrumb">
            <li class="breadcrumb-item">
                <a href="/jkkniu-admission/student/dashboard.php">Dashboard</a>
            </li>
            <li class="breadcrumb-item active">
                Step 1: Department Submission
            </li>
        </ol>
    </nav>

    <?php require_once '_steps_nav.php'; ?>

    <div class="row justify-content-center">
        <div class="col-lg-10">

            <div class="alert alert-info mb-4">
                <div class="d-flex align-items-center gap-3">
                    <i class="bi bi-award-fill fs-1 text-primary"></i>
                    <div>
                        <strong>🎉 Allocated:</strong>
                        <span class="h5 fw-bold">
                            <?= htmlspecialchars((string) $merit['dept_name']) ?>
                        </span><br>
                        <small class="text-muted">
                            <?= htmlspecialchars((string) $merit['faculty_name']) ?> Faculty
                        </small><br>
                        <strong>Merit Rank: #<?= (int) $merit['merit_rank'] ?></strong>
                    </div>
                </div>
            </div>

            <?php if ($error): ?>
                <div class="alert alert-danger">
                    <i class="bi bi-x-circle-fill"></i>
                    <?= $error ?>
                </div>
            <?php endif; ?>

            <?php if ($success): ?>
                <div class="alert alert-success">
                    <i class="bi bi-check-circle-fill"></i>
                    <?= $success ?>
                </div>
            <?php endif; ?>

            <?php if ($existing && $existing['submission_status'] === 'Verified'): ?>

                <div class="card shadow-lg">
                    <div class="card-header bg-success text-white">
                        <i class="bi bi-check-circle-fill"></i>
                        Step 1 VERIFIED ✓
                    </div>

                    <div class="card-body">

                        <div class="row g-4 text-center text-md-start">

                            <div class="col-md-3">
                                <div class="badge fs-2 bg-success mb-2 d-block">
                                    Verified
                                </div>
                            </div>

                            <div class="col-md-3">
                                <strong>Guardian:</strong>
                                <?= htmlspecialchars((string) $existing['guardian_name']) ?>
                            </div>

                            <div class="col-md-3">
                                <strong>
                                    ৳<?= number_format((float) $existing['dept_fee_amount'], 2) ?>
                                </strong>
                                <?= paymentBadgeHtml($deptPayment) ?><br>
                                <small>
                                    <?= htmlspecialchars((string) $existing['payment_method']) ?>
                                </small>
                            </div>

                            <div class="col-md-3">
                                <strong>
                                    <?= date(
                                        'd M Y, h:i A',
                                        strtotime((string) $existing['submitted_at'])
                                    ) ?>
                                </strong>
                            </div>

                        </div>

                        <div class="alert alert-success mt-4">
                            <i class="bi bi-check2-circle"></i>
                            Step 1 Verified!
                            <a href="step2_hall.php" class="btn btn-success btn-lg">
                                Go to Step 2 →
                            </a>
                        </div>

                    </div>
                </div>

            <?php else: ?>

                <div class="card shadow-lg">

                    <div class="card-header <?= $existing ? 'bg-warning text-dark' : 'bg-primary text-white' ?>">
                        <i class="bi bi-<?= $existing ? 'arrow-clockwise' : 'file-person-fill' ?>"></i>
                        <?= $existing
                            ? 'Step 1 (' . ucfirst((string) $existing['submission_status']) . ') - Edit & Resubmit'
                            : 'Step 1 — Complete Biodata + Documents'
                        ?>
                    </div>

                    <div class="card-body p-4">

                        <?php if ($existing && in_array($existing['submission_status'], ['Pending', 'Rejected'], true)): ?>
                            <div class="alert alert-warning mb-4">
                                <div class="d-flex flex-wrap justify-content-between align-items-center gap-2">
                                    <div>
                                        <i class="bi bi-exclamation-triangle"></i>
                                        Status:
                                        <strong>
                                            <?= htmlspecialchars((string) $existing['submission_status']) ?>
                                        </strong><br>
                                        Your Step 1 has already been submitted. You may edit/resubmit it if needed.
                                    </div>

                                    <a href="step2_hall.php" class="btn btn-success">
                                        Go to Step 2 →
                                    </a>
                                </div>
                            </div>
                        <?php endif; ?>

                        <form method="POST" enctype="multipart/form-data" novalidate>

                            <h6 class="fw-bold text-primary mb-3 mt-0">
                                <i class="bi bi-geo-alt-fill"></i>
                                Addresses
                            </h6>

                            <div class="row g-3 mb-4">

                                <div class="col-md-6">
                                    <label class="form-label fw-semibold">
                                        Present Address <span class="text-danger">*</span>
                                    </label>

                                    <textarea
                                        name="present_address"
                                        class="form-control"
                                        rows="2"
                                        placeholder="e.g. 123 College Road, Mymensingh"
                                        required
                                    ><?= htmlspecialchars((string) ($existing['present_address'] ?? '')) ?></textarea>

                                    <div class="form-text">
                                        Current living address
                                    </div>
                                </div>

                                <div class="col-md-6">
                                    <label class="form-label fw-semibold">
                                        Permanent Address <span class="text-danger">*</span>
                                    </label>

                                    <textarea
                                        name="permanent_address"
                                        class="form-control"
                                        rows="2"
                                        placeholder="e.g. Village: X, PO: Y, Upazila: Z, Mymensingh"
                                        required
                                    ><?= htmlspecialchars((string) ($existing['permanent_address'] ?? '')) ?></textarea>

                                    <div class="form-text">
                                        Home/village address
                                    </div>
                                </div>

                            </div>

                            <h6 class="fw-bold text-primary mb-3">
                                <i class="bi bi-people-fill"></i>
                                Guardian Information
                            </h6>

                            <div class="row g-3 mb-4">

                                <div class="col-md-6">
                                    <label class="form-label fw-semibold">
                                        Guardian Name <span class="text-danger">*</span>
                                    </label>

                                    <input
                                        type="text"
                                        name="guardian_name"
                                        class="form-control"
                                        placeholder="e.g. Abdul Karim"
                                        value="<?= htmlspecialchars((string) ($existing['guardian_name'] ?? '')) ?>"
                                        required
                                    >
                                </div>

                                <div class="col-md-3">
                                    <label class="form-label fw-semibold">
                                        Phone <span class="text-danger">*</span>
                                    </label>

                                    <input
                                        type="tel"
                                        name="guardian_phone"
                                        class="form-control"
                                        placeholder="01XXXXXXXXX"
                                        pattern="01[3-9]\d{8}"
                                        value="<?= htmlspecialchars((string) ($existing['guardian_phone'] ?? '')) ?>"
                                        required
                                    >
                                </div>

                                <div class="col-md-3">
                                    <label class="form-label fw-semibold">
                                        Relation <span class="text-danger">*</span>
                                    </label>

                                    <select name="guardian_relation" class="form-select" required>
                                        <option value="">Select</option>

                                        <?php
                                        $relations = [
                                            'Father',
                                            'Mother',
                                            'Brother',
                                            'Sister',
                                            'Uncle',
                                            'Aunt',
                                            'Other'
                                        ];

                                        foreach ($relations as $relation):
                                        ?>
                                            <option
                                                value="<?= htmlspecialchars($relation) ?>"
                                                <?= (($existing['guardian_relation'] ?? '') === $relation) ? 'selected' : '' ?>
                                            >
                                                <?= htmlspecialchars($relation) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                            </div>

                            <hr class="my-4">

                            <h6 class="fw-bold text-primary mb-3">
                                <i class="bi bi-file-earmark-text"></i>
                                Academic Documents
                                <span class="text-danger">(Both Required)</span>
                            </h6>

                            <div class="row g-4 mb-4">

                                <div class="col-md-6">

                                    <label class="form-label fw-semibold">
                                        1. HSC Transcript <span class="text-danger">*</span>
                                    </label>

                                    <?php if ($existing && !empty($existing['hsc_transcript'])): ?>

                                        <div class="alert alert-info mb-2">
                                            <i class="bi bi-check-circle"></i>
                                            Current:
                                            <a
                                                href="../uploads/transcripts/<?= htmlspecialchars((string) $existing['hsc_transcript']) ?>"
                                                target="_blank"
                                                class="fw-bold"
                                            >
                                                View HSC
                                            </a>
                                        </div>

                                    <?php endif; ?>

                                    <input
                                        type="file"
                                        name="hsc_transcript"
                                        class="form-control"
                                        accept=".pdf,.jpg,.jpeg,.png"
                                    >

                                    <div class="form-text">
                                        <?php if (!empty($student['hsc_gpa'])): ?>
                                            HSC GPA:
                                            <?= htmlspecialchars((string) $student['hsc_gpa']) ?>
                                            (<?= htmlspecialchars((string) ($student['hsc_year'] ?? '')) ?>)
                                        <?php else: ?>
                                            HSC details
                                        <?php endif; ?>
                                        <br>
                                        PDF/JPG/PNG, max 5MB (leave empty to keep current)
                                    </div>

                                </div>


                                <div class="col-md-6">

                                    <label class="form-label fw-semibold">
                                        2. SSC Transcript <span class="text-danger">*</span>
                                    </label>

                                    <?php if ($existing && !empty($existing['ssc_transcript'])): ?>

                                        <div class="alert alert-info mb-2">
                                            <i class="bi bi-check-circle"></i>
                                            Current:
                                            <a
                                                href="../uploads/transcripts/<?= htmlspecialchars((string) $existing['ssc_transcript']) ?>"
                                                target="_blank"
                                                class="fw-bold"
                                            >
                                                View SSC
                                            </a>
                                        </div>

                                    <?php endif; ?>

                                    <input
                                        type="file"
                                        name="ssc_transcript"
                                        class="form-control"
                                        accept=".pdf,.jpg,.jpeg,.png"
                                    >

                                    <div class="form-text">
                                        <?php if (!empty($student['ssc_gpa'])): ?>
                                            SSC GPA:
                                            <?= htmlspecialchars((string) $student['ssc_gpa']) ?>
                                            (<?= htmlspecialchars((string) ($student['ssc_year'] ?? '')) ?>)
                                        <?php else: ?>
                                            SSC details
                                        <?php endif; ?>
                                        <br>
                                        PDF/JPG/PNG, max 5MB (leave empty to keep current)
                                    </div>

                                </div>

                            </div>

                            <h6 class="fw-bold text-primary mb-3">
                                <i class="bi bi-cash-coin"></i>
                                Department Fee (SSLCommerz)
                            </h6>

                            <div class="row g-3 mb-4">

                                <div class="col-md-4">
                                    <label class="form-label fw-semibold">
                                        Amount (৳)
                                    </label>

                                    <input
                                        type="text"
                                        class="form-control"
                                        value="৳<?= number_format((float) DEPT_FEE_AMOUNT, 2) ?>"
                                        readonly
                                    >
                                </div>

                                <div class="col-md-8">

                                    <label class="form-label fw-semibold">
                                        Payment Status
                                    </label>

                                    <div class="d-flex align-items-center flex-wrap gap-2">

                                        <?php if ($deptPaid): ?>

                                            <?= paymentBadgeHtml($deptPayment) ?>

                                            <span class="small text-success">
                                                <i class="bi bi-check-circle"></i>
                                                Fee paid online — you can submit Step 1 now.
                                            </span>

                                        <?php else: ?>

                                            <span class="badge bg-secondary">Unpaid</span>

                                            <span class="small text-muted">
                                                <i class="bi bi-info-circle"></i>
                                                Payment happens when you submit below.
                                            </span>

                                        <?php endif; ?>

                                    </div>

                                    <?php if (!$deptPaid): ?>
                                        <div class="form-text">
                                            Choose a payment method below and click
                                            <strong>Pay</strong>
                                            — your details are saved first, then you
                                            pay ৳<?= number_format((float) DEPT_FEE_AMOUNT, 2) ?>.
                                        </div>
                                        <div class="mt-2">
                                            <a
                                                href="/jkkniu-admission/api/payment/verify_sonali.php?step=<?= PAYMENT_STEP_DEPT ?>"
                                                class="btn btn-sm btn-outline-success"
                                            >
                                                <i class="bi bi-arrow-repeat"></i>
                                                Check Payment Status
                                            </a>
                                            <span class="small text-muted ms-1">
                                                Already paid on Sonali Bank portal? Verify here.
                                            </span>
                                        </div>
                                    <?php endif; ?>

                                </div>

                            </div>

                            <div class="d-grid d-md-flex gap-2 justify-content-md-end">

                                <a
                                    href="/jkkniu-admission/student/dashboard.php"
                                    class="btn btn-outline-secondary"
                                >
                                    <i class="bi bi-arrow-left"></i>
                                    Dashboard
                                </a>

                                <?php if ($deptPaid): ?>

                                    <button
                                        type="submit"
                                        name="form_action"
                                        value="submit"
                                        class="btn btn-primary btn-lg px-5"
                                    >
                                        <i class="bi bi-check-lg"></i>
                                        <?= ($existing && $can_resubmit) ? 'Update & Resubmit' : 'Submit Step 1' ?>
                                    </button>

                                <?php else: ?>

                                    <input type="hidden" name="form_action" value="pay_submit">

                                    <button
                                        type="submit"
                                        name="provider"
                                        value="sslcommerz"
                                        class="btn btn-success btn-lg px-4"
                                    >
                                        <i class="bi bi-credit-card"></i>
                                        Pay via SSLCommerz (Demo)
                                    </button>

                                    <button
                                        type="submit"
                                        name="provider"
                                        value="sonali"
                                        class="btn btn-primary btn-lg px-4"
                                    >
                                        <i class="bi bi-bank"></i>
                                        Pay via Sonali Bank
                                    </button>

                                <?php endif; ?>

                            </div>

                        </form>

                    </div>
                </div>

            <?php endif; ?>

        </div>
    </div>
</div>

<?php require_once '../includes/footer.php'; ?>

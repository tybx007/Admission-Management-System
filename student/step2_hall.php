<?php

declare(strict_types=1);

$pageTitle = 'Step 2 — Hall Submission';
$currentStep = 2;

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

function step2E(mixed $value): string
{
    return htmlspecialchars(
        (string) ($value ?? ''),
        ENT_QUOTES,
        'UTF-8'
    );
}

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
            <?= step2E($e->getMessage()) ?>
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

$stmtStep1 = $pdo->prepare("
    SELECT *
    FROM dept_submissions
    WHERE applicant_id = ?
    LIMIT 1
");
$stmtStep1->execute([$applicant_id]);
$step1 = $stmtStep1->fetch(PDO::FETCH_ASSOC);

/*
|--------------------------------------------------------------------------
| Unlock rule
|--------------------------------------------------------------------------
| Step 2 opens immediately after Step 1 has been submitted.
| Department verification is still tracked separately.
|--------------------------------------------------------------------------
*/

if (!$step1) {
    header('Location: /jkkniu-admission/student/step1_dept.php');
    exit;
}

$stmtHalls = $pdo->query("
    SELECT DISTINCT hall_name
    FROM admin_users
    WHERE role = 'hall_admin'
      AND status = 'Active'
      AND hall_name IS NOT NULL
      AND hall_name <> ''
    ORDER BY hall_name ASC
");

$halls = $stmtHalls->fetchAll(PDO::FETCH_COLUMN);

$stmtSub = $pdo->prepare("
    SELECT *
    FROM hall_submissions
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

$hallPayment = paymentFetchLatest(
    $pdo,
    $applicant_id,
    PAYMENT_STEP_HALL
);

$hallPaid = paymentIsSettled(
    $pdo,
    $applicant_id,
    PAYMENT_STEP_HALL
);

if (isset($_GET['pay_error'])) {
    $error = 'Payment could not be completed: '
        . step2E(
            (string) $_GET['pay_error']
        );
}

if (isset($_GET['pay_ok'])) {
    $success = step2E(
        (string) $_GET['pay_ok']
    );
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (
        $existing
        &&
        (string) ($existing['submission_status'] ?? '') === 'Verified'
    ) {
        $error = 'Step 2 is already VERIFIED. Cannot modify.';
    } else {

        $formAction =
            trim((string) ($_POST['form_action'] ?? 'submit'));

        $provider =
            trim((string) ($_POST['provider'] ?? 'sslcommerz'));

        $selected_hall = trim((string) ($_POST['hall_name'] ?? ''));
        $fee = paymentFeeAmount(PAYMENT_STEP_HALL);
        $method = $provider === 'sonali'
            ? 'Sonali Bank'
            : 'SSLCommerz';

        $errs = [];

        if ($selected_hall === '') {
            $errs[] = 'Please select a hall.';
        }

        if (
            $selected_hall !== ''
            &&
            !in_array($selected_hall, $halls, true)
        ) {
            $errs[] = 'Invalid hall selection.';
        }

        if (
            $formAction !== 'pay_submit'
            &&
            !paymentIsSettled($pdo, $applicant_id, PAYMENT_STEP_HALL)
        ) {
            $errs[] = 'Please complete the Hall Fee payment first.';
        }

        if (!$errs) {

            $allowed = ['pdf', 'jpg', 'jpeg', 'png'];
            $max = 5 * 1024 * 1024;

            function step2Up(array $file, string $dir, string $pfx): string|false
            {
                global $allowed, $max, $applicant_id;

                $ext = strtolower(
                    pathinfo(
                        (string) $file['name'],
                        PATHINFO_EXTENSION
                    )
                );

                if (!in_array($ext, $allowed, true)) {
                    return false;
                }

                if ((int) ($file['size'] ?? 0) > $max) {
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

            if (!$errs) {

                $biodata_file =
                    $existing['biodata_file']
                    ?? null;

                if ($existing && $can_resubmit) {

                    $stmt = $pdo->prepare("
                        UPDATE hall_submissions SET
                            hall_name = ?,
                            biodata_file = ?,
                            hall_fee_amount = ?,
                            hall_fee_receipt = ?,
                            payment_method = ?,
                            submission_status = 'Pending',
                            verified_at = NULL
                        WHERE applicant_id = ?
                    ");

                    $stmt->execute([
                        $selected_hall,
                        $biodata_file,
                        $fee,
                        null,
                        $method,
                        $applicant_id
                    ]);

                    $success = '✅ Step 2 UPDATED successfully! Awaiting hall verification.';

                } else {

                    $stmt = $pdo->prepare("
                        INSERT INTO hall_submissions
                        (
                            applicant_id,
                            hall_name,
                            biodata_file,
                            hall_fee_amount,
                            hall_fee_receipt,
                            payment_method,
                            submission_status
                        )
                        VALUES (?,?,?,?,?,?, 'Pending')
                    ");

                    $stmt->execute([
                        $applicant_id,
                        $selected_hall,
                        $biodata_file,
                        $fee,
                        null,
                        $method
                    ]);

                    $success = '✅ Step 2 submitted! Awaiting hall verification.';
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
                            . PAYMENT_STEP_HALL
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
                Step 2: Hall Submission
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
                        <strong>Allocated:</strong>
                        <span class="h5 fw-bold">
                            <?= step2E($merit['dept_name']) ?>
                        </span><br>
                        <small class="text-muted">
                            <?= step2E($merit['faculty_name']) ?> Faculty
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

            <div class="card shadow-sm mb-4">

                <div class="card-header bg-light fw-semibold">
                    <i class="bi bi-file-person"></i>
                    Biodata Preview (Auto Fetched)
                </div>

                <div class="card-body">

                    <div class="row g-3">

                        <div class="col-md-4">
                            <b>Name:</b>
                            <?= step2E($student['full_name'] ?? '—') ?>
                        </div>

                        <div class="col-md-4">
                            <b>GST Roll:</b>
                            <?= step2E($student['gst_roll'] ?? '—') ?>
                        </div>

                        <div class="col-md-4">
                            <b>Phone:</b>
                            <?= step2E($student['phone'] ?? '—') ?>
                        </div>

                        <div class="col-md-4">
                            <b>Email:</b>
                            <?= step2E($student['email'] ?? '—') ?>
                        </div>

                        <div class="col-md-4">
                            <b>DOB:</b>
                            <?= step2E($student['date_of_birth'] ?? '—') ?>
                        </div>

                        <div class="col-md-4">
                            <b>Gender:</b>
                            <?= step2E($student['gender'] ?? '—') ?>
                        </div>

                        <div class="col-md-6">
                            <b>Father's Name:</b>
                            <?= step2E($student['father_name'] ?? '—') ?>
                        </div>

                        <div class="col-md-6">
                            <b>Mother's Name:</b>
                            <?= step2E($student['mother_name'] ?? '—') ?>
                        </div>

                        <hr class="my-1">

                        <div class="col-md-6">
                            <b>Present Address:</b>
                            <?= step2E($step1['present_address'] ?? '—') ?>
                        </div>

                        <div class="col-md-6">
                            <b>Permanent Address:</b>
                            <?= step2E($step1['permanent_address'] ?? '—') ?>
                        </div>

                        <div class="col-md-4">
                            <b>Guardian:</b>
                            <?= step2E($step1['guardian_name'] ?? '—') ?>
                        </div>

                        <div class="col-md-4">
                            <b>Guardian Phone:</b>
                            <?= step2E($step1['guardian_phone'] ?? '—') ?>
                        </div>

                        <div class="col-md-4">
                            <b>Relation:</b>
                            <?= step2E($step1['guardian_relation'] ?? '—') ?>
                        </div>

                        <hr class="my-1">

                        <div class="col-md-3">
                            <b>HSC Roll:</b>
                            <?= step2E($student['hsc_roll'] ?? '—') ?>
                        </div>

                        <div class="col-md-3">
                            <b>HSC Registration:</b>
                            <?= step2E($student['hsc_reg'] ?? '—') ?>
                        </div>

                        <div class="col-md-3">
                            <b>HSC Group:</b>
                            <?= step2E($student['hsc_group'] ?? '—') ?>
                        </div>

                        <div class="col-md-3">
                            <b>HSC Board:</b>
                            <?= step2E($student['hsc_board'] ?? '—') ?>
                        </div>

                        <div class="col-md-3">
                            <b>HSC Year:</b>
                            <?= step2E($student['hsc_year'] ?? '—') ?>
                        </div>

                        <div class="col-md-3">
                            <b>HSC GPA:</b>
                            <?= step2E($student['hsc_gpa'] ?? '—') ?>
                        </div>

                        <div class="col-md-6">
                            <b>HSC Institute:</b>
                            <?= step2E($student['hsc_institute'] ?? '—') ?>
                        </div>

                        <div class="col-md-3">
                            <b>SSC Roll:</b>
                            <?= step2E($student['ssc_roll'] ?? '—') ?>
                        </div>

                        <div class="col-md-3">
                            <b>SSC Registration:</b>
                            <?= step2E($student['ssc_reg'] ?? '—') ?>
                        </div>

                        <div class="col-md-3">
                            <b>SSC Board:</b>
                            <?= step2E($student['ssc_board'] ?? '—') ?>
                        </div>

                        <div class="col-md-3">
                            <b>SSC Year:</b>
                            <?= step2E($student['ssc_year'] ?? '—') ?>
                        </div>

                        <div class="col-md-3">
                            <b>SSC GPA:</b>
                            <?= step2E($student['ssc_gpa'] ?? '—') ?>
                        </div>

                        <div class="col-md-9">
                            <b>SSC Institute:</b>
                            <?= step2E($student['ssc_institute'] ?? '—') ?>
                        </div>

                    </div>

                    <div class="alert alert-secondary mt-3 mb-0">
                        <i class="bi bi-info-circle"></i>
                        If anything is wrong here, update it from your profile / Step-1 and resubmit Step-1.
                    </div>

                </div>
            </div>

            <?php if ($existing && ($existing['submission_status'] ?? '') === 'Verified'): ?>

                <div class="card shadow-lg">

                    <div class="card-header bg-success text-white">
                        <i class="bi bi-check-circle-fill"></i>
                        Step 2 VERIFIED ✓
                    </div>

                    <div class="card-body">

                        <div class="row g-4 text-center text-md-start">

                            <div class="col-md-3">
                                <div class="badge fs-2 bg-success mb-2 d-block">
                                    Verified
                                </div>
                            </div>

                            <div class="col-md-3">
                                <strong>Hall:</strong>
                                <?= step2E($existing['hall_name']) ?>
                            </div>

                            <div class="col-md-3">
                                <strong>
                                    ৳<?= number_format((float) $existing['hall_fee_amount'], 2) ?>
                                </strong>
                                <?= paymentBadgeHtml($hallPayment) ?><br>
                                <small>
                                    <?= step2E($existing['payment_method']) ?>
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
                            Step 2 Verified!
                            <a href="step3_registrar.php" class="btn btn-success btn-lg">
                                Go to Step 3 →
                            </a>
                        </div>

                    </div>
                </div>

            <?php else: ?>

                <div class="card shadow-lg">

                    <div class="card-header <?= $existing ? 'bg-warning text-dark' : 'bg-primary text-white'; ?>">
                        <i class="bi bi-building"></i>
                        <?= $existing
                            ? 'Step 2 (' . ucfirst((string) $existing['submission_status']) . ') - Edit & Resubmit'
                            : 'Step 2 — Select Hall + Pay Hall Fee'
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
                                            <?= step2E($existing['submission_status']) ?>
                                        </strong><br>
                                        Your Step 2 has already been submitted. You may edit/resubmit it if needed.
                                    </div>

                                    <a href="step3_registrar.php" class="btn btn-success">
                                        Go to Step 3 →
                                    </a>
                                </div>
                            </div>

                        <?php endif; ?>

                        <?php if (empty($halls)): ?>

                            <div class="alert alert-danger">
                                No halls available. Please ask Super Admin to create/activate Hall Admin accounts with hall_name.
                            </div>

                        <?php else: ?>

                            <form method="POST" enctype="multipart/form-data" novalidate>

                                <h6 class="fw-bold text-primary mb-3 mt-0">
                                    <i class="bi bi-building"></i>
                                    Hall Selection
                                </h6>

                                <div class="row g-3 mb-4">

                                    <div class="col-md-12">

                                        <label class="form-label fw-semibold">
                                            Select Hall <span class="text-danger">*</span>
                                        </label>

                                        <select name="hall_name" class="form-select" required>

                                            <option value="">
                                                -- Select Hall --
                                            </option>

                                            <?php foreach ($halls as $hall): ?>

                                                <option
                                                    value="<?= step2E($hall) ?>"
                                                    <?= ($existing && ($existing['hall_name'] ?? '') === $hall)
                                                        ? 'selected'
                                                        : ''
                                                    ?>
                                                >
                                                    <?= step2E($hall) ?>
                                                </option>

                                            <?php endforeach; ?>

                                        </select>

                                        <div class="form-text">
                                            Hall list is fetched from active Hall Admin accounts.
                                        </div>

                                    </div>

                                </div>

                                <hr class="my-4">

                                <h6 class="fw-bold text-primary mb-3">
                                    <i class="bi bi-cash-coin"></i>
                                    Hall Fee Payment (SSLCommerz)
                                </h6>

                                <div class="row g-3 mb-4">

                                    <div class="col-md-4">

                                        <label class="form-label fw-semibold">
                                            Amount (৳)
                                        </label>

                                        <input
                                            type="text"
                                            class="form-control"
                                            value="৳<?= number_format((float) HALL_FEE_AMOUNT, 2) ?>"
                                            readonly
                                        >

                                    </div>

                                    <div class="col-md-8">

                                        <label class="form-label fw-semibold">
                                            Payment Status
                                        </label>

                                        <div class="d-flex align-items-center flex-wrap gap-2">

                                            <?php if ($hallPaid): ?>

                                                <?= paymentBadgeHtml($hallPayment) ?>

                                                <span class="small text-success">
                                                    <i class="bi bi-check-circle"></i>
                                                    Fee paid online — you can submit Step 2 now.
                                                </span>

                                            <?php else: ?>

                                                <span class="badge bg-secondary">Unpaid</span>

                                                <span class="small text-muted">
                                                    <i class="bi bi-info-circle"></i>
                                                    Payment happens when you submit below.
                                                </span>

                                            <?php endif; ?>

                                        </div>

                                        <?php if (!$hallPaid): ?>
                                            <div class="form-text">
                                                Choose a payment method below and click
                                                <strong>Pay</strong>
                                                — your details are saved first, then you
                                                pay ৳<?= number_format((float) HALL_FEE_AMOUNT, 2) ?>.
                                            </div>
                                            <div class="mt-2">
                                                <a
                                                    href="/jkkniu-admission/api/payment/verify_sonali.php?step=<?= PAYMENT_STEP_HALL ?>"
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

                                    <?php if ($hallPaid): ?>

                                        <button
                                            type="submit"
                                            name="form_action"
                                            value="submit"
                                            class="btn btn-primary btn-lg px-5"
                                        >
                                            <i class="bi bi-check-lg"></i>
                                            <?= ($existing && $can_resubmit) ? 'Update & Resubmit' : 'Submit Step 2' ?>
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

                        <?php endif; ?>

                    </div>
                </div>

            <?php endif; ?>

        </div>
    </div>
</div>

<?php require_once '../includes/footer.php'; ?>
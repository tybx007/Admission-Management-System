<?php

declare(strict_types=1);

$pageTitle = 'Step 3 — Registrar Submission';
$currentStep = 3;

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

/*
|--------------------------------------------------------------------------
| Unlock rule
|--------------------------------------------------------------------------
| Step 3 no longer waits for Department/Hall approval.
| It opens as soon as both Step 1 and Step 2 have been submitted.
|--------------------------------------------------------------------------
*/

$student = fetchStudentBiodata(
    $pdo,
    $applicant_id,
    $currentAcademicYear
);

if ($student && !empty($student['faculty_id'])) {
    $facultyStmt = $pdo->prepare("
        SELECT faculty_name
        FROM faculties
        WHERE faculty_id = ?
        LIMIT 1
    ");

    $facultyStmt->execute([
        (int) $student['faculty_id']
    ]);

    $student['faculty_name'] =
        $facultyStmt->fetchColumn() ?: '—';
} elseif ($student) {
    $student['faculty_name'] = '—';
}

$stmtDept = $pdo->prepare("
    SELECT
        ds.*,
        d.dept_name
    FROM dept_submissions ds
    LEFT JOIN departments d
        ON d.dept_id = ds.dept_id
    WHERE ds.applicant_id = ?
    LIMIT 1
");
$stmtDept->execute([$applicant_id]);
$deptSub = $stmtDept->fetch(PDO::FETCH_ASSOC);

$stmtHall = $pdo->prepare("
    SELECT *
    FROM hall_submissions
    WHERE applicant_id = ?
    LIMIT 1
");
$stmtHall->execute([$applicant_id]);
$hallSub = $stmtHall->fetch(PDO::FETCH_ASSOC);

if (!$deptSub) {
    header('Location: /jkkniu-admission/student/step1_dept.php');
    exit;
}

if (!$hallSub) {
    header('Location: /jkkniu-admission/student/step2_hall.php');
    exit;
}

$stmtSub = $pdo->prepare("
    SELECT *
    FROM central_admissions
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

$hallPayment = paymentFetchLatest(
    $pdo,
    $applicant_id,
    PAYMENT_STEP_HALL
);

$admissionPayment = paymentFetchLatest(
    $pdo,
    $applicant_id,
    PAYMENT_STEP_ADMISSION
);

$deptPaid = paymentIsSettled(
    $pdo,
    $applicant_id,
    PAYMENT_STEP_DEPT
);

$hallPaid = paymentIsSettled(
    $pdo,
    $applicant_id,
    PAYMENT_STEP_HALL
);

$admissionPaid = paymentIsSettled(
    $pdo,
    $applicant_id,
    PAYMENT_STEP_ADMISSION
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

function step3DisplayValue(mixed $value): string
{
    if ($value === null || $value === '') {
        return '—';
    }

    return (string) $value;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (
        $existing
        &&
        (string) ($existing['submission_status'] ?? '') === 'Verified'
    ) {
        $error = 'Step 3 is already VERIFIED. Cannot modify.';
    } else {

        $formAction =
            trim((string) ($_POST['form_action'] ?? 'submit'));

        $provider =
            trim((string) ($_POST['provider'] ?? 'sslcommerz'));

        $deptAttach = paymentIsSettled($pdo, $applicant_id, PAYMENT_STEP_DEPT) ? 1 : 0;
        $hallAttach = paymentIsSettled($pdo, $applicant_id, PAYMENT_STEP_HALL) ? 1 : 0;

        $fee = paymentFeeAmount(PAYMENT_STEP_ADMISSION);
        $method = $provider === 'sonali'
            ? 'Sonali Bank'
            : 'SSLCommerz';

        $errs = [];

        if (!$deptAttach) {
            $errs[] = 'Department Fee has not been paid yet.';
        }

        if (!$hallAttach) {
            $errs[] = 'Hall Fee has not been paid yet.';
        }

        if (
            $formAction !== 'pay_submit'
            &&
            !paymentIsSettled($pdo, $applicant_id, PAYMENT_STEP_ADMISSION)
        ) {
            $errs[] = 'Please complete the Admission Fee payment first.';
        }

        if (!$errs) {

            $rc = null;

            if ($existing && $can_resubmit) {

                $pdo->prepare("
                    UPDATE central_admissions SET
                        admission_fee_amount = ?,
                        admission_fee_receipt = ?,
                        payment_method = ?,
                        submission_status = 'Pending',
                        verified_at = NULL
                    WHERE applicant_id = ?
                ")->execute([
                    $fee,
                    $rc,
                    $method,
                    $applicant_id
                ]);

                $success = '✅ Step 3 UPDATED successfully! Awaiting registrar verification.';

            } else {

                $pdo->prepare("
                    INSERT INTO central_admissions
                    (
                        applicant_id,
                        admission_fee_amount,
                        admission_fee_receipt,
                        payment_method,
                        submission_status
                    )
                    VALUES (?,?,?,?, 'Pending')
                ")->execute([
                    $applicant_id,
                    $fee,
                    $rc,
                    $method
                ]);

                $success = '✅ Step 3 submitted! Awaiting registrar verification.';
            }

                $checkRA = $pdo->prepare("
                    SELECT approval_id
                    FROM registrar_approvals
                    WHERE applicant_id = ?
                    LIMIT 1
                ");
                $checkRA->execute([$applicant_id]);
                $ra = $checkRA->fetch(PDO::FETCH_ASSOC);

                if ($ra) {

                    $pdo->prepare("
                        UPDATE registrar_approvals
                        SET
                            dept_receipt_attached = ?,
                            hall_receipt_attached = ?,
                            admission_receipt_attached = 1
                        WHERE applicant_id = ?
                    ")->execute([
                        $deptAttach,
                        $hallAttach,
                        $applicant_id
                    ]);

                } else {

                    $pdo->prepare("
                        INSERT INTO registrar_approvals
                        (
                            applicant_id,
                            dept_receipt_attached,
                            hall_receipt_attached,
                            admission_receipt_attached,
                            approval_status
                        )
                        VALUES (?,?,?,1,'Submitted')
                    ")->execute([
                        $applicant_id,
                        $deptAttach,
                        $hallAttach
                    ]);
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
                            . PAYMENT_STEP_ADMISSION
                        );
                    }
                    exit;
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
                Step 3: Registrar Submission
            </li>
        </ol>
    </nav>

    <?php require_once '_steps_nav.php'; ?>

    <div class="row justify-content-center">
        <div class="col-lg-10">

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

            <?php if ($existing && ($existing['submission_status'] ?? '') === 'Verified'): ?>

                <div class="card shadow-lg">

                    <div class="card-header bg-success text-white">
                        <i class="bi bi-check-circle-fill"></i>
                        Step 3 VERIFIED ✓
                    </div>

                    <div class="card-body">

                        <div class="row g-4 text-center text-md-start">

                            <div class="col-md-4">
                                <div class="badge fs-2 bg-success mb-2 d-block">
                                    Verified
                                </div>
                                <small class="text-muted">
                                    Registrar has verified your final admission fee receipt.
                                </small>
                            </div>

                            <div class="col-md-4">
                                <strong>Amount:</strong>
                                ৳<?= number_format((float) $existing['admission_fee_amount'], 2) ?><br>
                                <strong>Method:</strong>
                                <?= htmlspecialchars((string) ($existing['payment_method'] ?? '')) ?><br>
                                <?= paymentBadgeHtml($admissionPayment) ?>
                            </div>

                            <div class="col-md-4">
                                <strong>Submitted:</strong><br>
                                <?= !empty($existing['submitted_at'])
                                    ? date(
                                        'd M Y, h:i A',
                                        strtotime((string) $existing['submitted_at'])
                                    )
                                    : '—'
                                ?>
                            </div>

                        </div>

                        <div class="mt-3 d-flex gap-2 flex-wrap">

                            <?php if (!empty($existing['admission_fee_receipt'])): ?>

                                <a
                                    href="/jkkniu-admission/uploads/receipts/admission/<?= htmlspecialchars((string) $existing['admission_fee_receipt']) ?>"
                                    target="_blank"
                                    class="btn btn-outline-success"
                                >
                                    <i class="bi bi-receipt"></i>
                                    View Admission Receipt
                                </a>

                            <?php endif; ?>

                            <a
                                href="/jkkniu-admission/student/dashboard.php"
                                class="btn btn-outline-secondary"
                            >
                                <i class="bi bi-arrow-left"></i>
                                Dashboard
                            </a>

                        </div>

                    </div>
                </div>

            <?php else: ?>

                <div class="card shadow-lg">

                    <div class="card-header <?= $existing ? 'bg-warning text-dark' : 'bg-primary text-white' ?>">
                        <i class="bi <?= $existing ? 'bi-arrow-clockwise' : 'bi-file-earmark-check' ?>"></i>
                        <?= $existing
                            ? 'Step 3 (' . ucfirst((string) $existing['submission_status']) . ') - Edit & Resubmit'
                            : 'Step 3 — Registrar Submission'
                        ?>
                    </div>

                    <div class="card-body p-4">

                        <div class="card mb-4">

                            <div class="card-header bg-dark text-white fw-semibold">
                                <i class="bi bi-person-lines-fill text-primary"></i>
                                Student Information (All Data from GST Applicants)
                            </div>

                            <div class="card-body">

                                <?php if (!$student): ?>

                                    <div class="alert alert-danger mb-0">
                                        Student data not found.
                                    </div>

                                <?php else: ?>

                                    <div class="row g-3">

                                        <?php
                                        $ordered = [
                                            'applicant_id',
                                            'gst_roll',
                                            'full_name',
                                            'email',
                                            'phone',
                                            'date_of_birth',
                                            'gender',
                                            'nationality',
                                            'quota',
                                            'gst_score',
                                            'faculty_id',
                                            'faculty_name',
                                            'created_at',
                                            'father_name',
                                            'mother_name',
                                            'hsc_roll',
                                            'hsc_reg',
                                            'hsc_group',
                                            'hsc_board',
                                            'hsc_year',
                                            'hsc_gpa',
                                            'hsc_institute',
                                            'ssc_roll',
                                            'ssc_reg',
                                            'ssc_board',
                                            'ssc_year',
                                            'ssc_gpa',
                                            'ssc_institute',
                                            'photo',
                                            'signature'
                                        ];

                                        foreach ($ordered as $field) {

                                            if (!array_key_exists($field, $student)) {
                                                continue;
                                            }

                                            if ($field === 'password') {
                                                continue;
                                            }

                                            $label =
                                                ucwords(
                                                    str_replace('_', ' ', $field)
                                                );

                                            $value =
                                                $student[$field];

                                            $isFile =
                                                in_array(
                                                    $field,
                                                    ['photo', 'signature'],
                                                    true
                                                );
                                            ?>

                                            <div class="col-md-4">

                                                <label class="form-label text-muted small mb-1">
                                                    <?= htmlspecialchars($label) ?>
                                                </label>

                                                <?php if ($isFile && $value): ?>

                                                    <div class="fw-semibold">
                                                        <a
                                                            target="_blank"
                                                            href="/jkkniu-admission/uploads/<?= $field === 'photo' ? 'photos' : 'signatures' ?>/<?= htmlspecialchars((string) $value) ?>"
                                                            class="text-decoration-none"
                                                        >
                                                            <i class="bi bi-eye"></i>
                                                            View <?= $field === 'photo' ? 'Photo' : 'Signature' ?>
                                                        </a>
                                                    </div>

                                                <?php else: ?>

                                                    <div class="fw-semibold">
                                                        <?= htmlspecialchars(step3DisplayValue($value)) ?>
                                                    </div>

                                                <?php endif; ?>

                                            </div>

                                            <?php
                                        }

                                        foreach ($student as $field => $value) {

                                            if (in_array($field, $ordered, true)) {
                                                continue;
                                            }

                                            if ($field === 'password') {
                                                continue;
                                            }

                                            $label =
                                                ucwords(
                                                    str_replace('_', ' ', $field)
                                                );
                                            ?>

                                            <div class="col-md-4">

                                                <label class="form-label text-muted small mb-1">
                                                    <?= htmlspecialchars($label) ?>
                                                </label>

                                                <div class="fw-semibold">
                                                    <?= htmlspecialchars(step3DisplayValue($value)) ?>
                                                </div>

                                            </div>

                                            <?php
                                        }
                                        ?>

                                    </div>

                                <?php endif; ?>

                            </div>
                        </div>

                        <form method="POST" enctype="multipart/form-data" novalidate>

                            <div class="card mb-4">

                                <div class="card-header bg-dark text-white fw-semibold">
                                    <i class="bi bi-paperclip text-primary"></i>
                                    Attach Previous Receipts
                                </div>

                                <div class="card-body">

                                    <div class="row g-3">

                                        <div class="col-md-6">

                                            <div class="border rounded p-3 h-100">

                                                <div class="fw-semibold mb-2">
                                                    Step 1 — Department Fee
                                                    <?php if ($deptPaid): ?>
                                                        <span class="badge bg-success ms-2">Paid Online</span>
                                                    <?php else: ?>
                                                        <span class="badge bg-warning text-dark ms-2">Unpaid</span>
                                                    <?php endif; ?>
                                                </div>

                                                <div class="small text-muted mb-2">
                                                    Dept:
                                                    <?= htmlspecialchars((string) ($deptSub['dept_name'] ?? '—')) ?>
                                                    |
                                                    Amount:
                                                    ৳<?= number_format((float) ($deptSub['dept_fee_amount'] ?? 0), 2) ?>
                                                </div>

                                                <div class="mb-2">
                                                    <?= paymentBadgeHtml($deptPayment) ?>
                                                </div>

                                                <?php if (!empty($deptSub['dept_fee_receipt'])): ?>

                                                    <a
                                                        target="_blank"
                                                        href="/jkkniu-admission/uploads/receipts/dept/<?= htmlspecialchars((string) $deptSub['dept_fee_receipt']) ?>"
                                                        class="btn btn-sm btn-outline-primary"
                                                    >
                                                        <i class="bi bi-eye"></i>
                                                        View Dept Receipt
                                                    </a>

                                                <?php else: ?>

                                                    <div class="small text-muted">
                                                        <i class="bi bi-check-circle text-success"></i>
                                                        Payment verified online by the gateway.
                                                    </div>

                                                <?php endif; ?>

                                            </div>

                                        </div>

                                        <div class="col-md-6">

                                            <div class="border rounded p-3 h-100">

                                                <div class="fw-semibold mb-2">
                                                    Step 2 — Hall Fee
                                                    <?php if ($hallPaid): ?>
                                                        <span class="badge bg-success ms-2">Paid Online</span>
                                                    <?php else: ?>
                                                        <span class="badge bg-warning text-dark ms-2">Unpaid</span>
                                                    <?php endif; ?>
                                                </div>

                                                <div class="small text-muted mb-2">
                                                    Hall:
                                                    <?= htmlspecialchars((string) ($hallSub['hall_name'] ?? '—')) ?>
                                                    |
                                                    Amount:
                                                    ৳<?= number_format((float) ($hallSub['hall_fee_amount'] ?? 0), 2) ?>
                                                </div>

                                                <div class="mb-2">
                                                    <?= paymentBadgeHtml($hallPayment) ?>
                                                </div>

                                                <?php if (!empty($hallSub['hall_fee_receipt'])): ?>

                                                    <a
                                                        target="_blank"
                                                        href="/jkkniu-admission/uploads/receipts/hall/<?= htmlspecialchars((string) $hallSub['hall_fee_receipt']) ?>"
                                                        class="btn btn-sm btn-outline-primary"
                                                    >
                                                        <i class="bi bi-eye"></i>
                                                        View Hall Receipt
                                                    </a>

                                                <?php else: ?>

                                                    <div class="small text-muted">
                                                        <i class="bi bi-check-circle text-success"></i>
                                                        Payment verified online by the gateway.
                                                    </div>

                                                <?php endif; ?>

                                            </div>

                                        </div>

                                    </div>

                                </div>
                            </div>

                            <h6 class="fw-bold text-primary mb-3">
                                <i class="bi bi-cash-coin"></i>
                                Final Admission Fee (SSLCommerz)
                            </h6>

                            <div class="row g-3 mb-4">

                                <div class="col-md-4">

                                    <label class="form-label fw-semibold">
                                        Amount (৳)
                                    </label>

                                    <input
                                        type="text"
                                        class="form-control"
                                        value="৳<?= number_format((float) ADMISSION_FEE_AMOUNT, 2) ?>"
                                        readonly
                                    >

                                </div>

                                <div class="col-md-8">

                                    <label class="form-label fw-semibold">
                                        Payment Status
                                    </label>

                                    <div class="d-flex align-items-center flex-wrap gap-2">

                                        <?php if ($admissionPaid): ?>

                                            <?= paymentBadgeHtml($admissionPayment) ?>

                                            <span class="small text-success">
                                                <i class="bi bi-check-circle"></i>
                                                Fee paid online — you can submit Step 3 now.
                                            </span>

                                        <?php else: ?>

                                            <span class="badge bg-secondary">Unpaid</span>

                                            <span class="small text-muted">
                                                <i class="bi bi-info-circle"></i>
                                                Payment happens when you submit below.
                                            </span>

                                        <?php endif; ?>

                                    </div>

                                    <?php if (!$admissionPaid): ?>
                                        <div class="form-text">
                                            Choose a payment method below and click
                                            <strong>Pay</strong>
                                            — your details are saved first, then you
                                            pay ৳<?= number_format((float) ADMISSION_FEE_AMOUNT, 2) ?>.
                                        </div>
                                        <div class="mt-2">
                                            <a
                                                href="/jkkniu-admission/api/payment/verify_sonali.php?step=<?= PAYMENT_STEP_ADMISSION ?>"
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
                                    href="/jkkniu-admission/student/step2_hall.php"
                                    class="btn btn-outline-secondary"
                                >
                                    <i class="bi bi-arrow-left"></i>
                                    Back
                                </a>

                                <?php if ($admissionPaid): ?>

                                    <button
                                        type="submit"
                                        name="form_action"
                                        value="submit"
                                        class="btn btn-primary btn-lg px-5"
                                    >
                                        <i class="bi bi-check-lg"></i>
                                        <?= ($existing && $can_resubmit) ? 'Update & Resubmit' : 'Submit Step 3' ?>
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

<?php

declare(strict_types=1);

$pageTitle = 'Student Registration';

require_once '../includes/auth.php';
require_once '../config/db.php';
require_once '../includes/otp/registration_otp.php';
require_once '../includes/admission_session.php';
require_once '../includes/merit_call_registration_guard.php';

redirectIfLoggedIn();

$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$currentAcademicYear = admissionSessionCurrentYear($pdo);

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

if (!isset($_SESSION['registration_flow'])) {
    $_SESSION['registration_flow'] = [];
}

$flow = &$_SESSION['registration_flow'];

$error = '';
$success = '';

function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function resetRegistrationFlow(): void
{
    unset($_SESSION['registration_flow']);
}

function requireValidCsrf(): void
{
    $token = $_POST['csrf_token'] ?? '';

    if (
        !is_string($token) ||
        !hash_equals((string) $_SESSION['csrf_token'], $token)
    ) {
        throw new RuntimeException(
            'Invalid request token. Refresh the page and try again.'
        );
    }
}


/*
|--------------------------------------------------------------------------
| POST REQUEST HANDLING
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    try {

        requireValidCsrf();

        $action = trim((string) ($_POST['action'] ?? ''));


        /*
        |--------------------------------------------------------------------------
        | RESTART REGISTRATION
        |--------------------------------------------------------------------------
        */

        if ($action === 'restart') {

            resetRegistrationFlow();

            header('Location: ' . basename($_SERVER['PHP_SELF']));
            exit;
        }


        /*
        |--------------------------------------------------------------------------
        | STEP 1: VERIFY GST IDENTITY
        |--------------------------------------------------------------------------
        */

        if ($action === 'verify_identity') {

            $gstRoll = trim(
                (string) ($_POST['gst_roll'] ?? '')
            );

            $gstApplicantId = trim(
                (string) ($_POST['gst_applicant_id'] ?? '')
            );

            if ($gstRoll === '' || $gstApplicantId === '') {
                throw new RuntimeException(
                    'GST Roll and GST Applicant ID are required.'
                );
            }


            $stmt = $pdo->prepare("
                SELECT
                    gic.import_candidate_id,
                    gic.gst_roll,
                    gic.gst_applicant_id,
                    gic.full_name,
                    gic.phone,
                    gic.gst_score,
                    gic.faculty_id,
                    gic.selection_status,
                    gic.is_registered,
                    gic.applicant_id,
                    gic.academic_year

                FROM gst_imported_candidates gic

                WHERE gic.gst_roll = ?
                  AND gic.gst_applicant_id = ?
                  AND gic.academic_year = ?

                LIMIT 1
            ");

            $stmt->execute([
                $gstRoll,
                $gstApplicantId,
                $currentAcademicYear
            ]);

            $candidate = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$candidate) {
                throw new RuntimeException(
                    'No GST candidate was found in the current admission session.'
                );
            }

            if (
                strcasecmp(
                    trim((string) $candidate['selection_status']),
                    'Selected'
                ) !== 0
            ) {
                throw new RuntimeException(
                    'This candidate is not marked as selected.'
                );
            }

            if (
                (int) $candidate['is_registered'] === 1 ||
                !empty($candidate['applicant_id'])
            ) {
                throw new RuntimeException(
                    'An account has already been registered for this GST candidate.'
                );
            }


            /*
            |--------------------------------------------------------------------------
            | MERIT CALL REGISTRATION GUARD
            |--------------------------------------------------------------------------
            */

            $openCall = requireOpenMeritCallForCandidate(
                $pdo,
                (int) $candidate['import_candidate_id'],
                $currentAcademicYear
            );


            /*
            |--------------------------------------------------------------------------
            | GET DEPARTMENT
            |--------------------------------------------------------------------------
            */

            $deptStmt = $pdo->prepare("
                SELECT
                    dept_id,
                    dept_name,
                    dept_code

                FROM departments

                WHERE dept_id = ?

                LIMIT 1
            ");

            $deptStmt->execute([
                (int) $openCall['dept_id']
            ]);

            $department = $deptStmt->fetch(PDO::FETCH_ASSOC);

            if (!$department) {
                throw new RuntimeException(
                    'Allocated department was not found.'
                );
            }


            /*
            |--------------------------------------------------------------------------
            | SAVE REGISTRATION FLOW
            |--------------------------------------------------------------------------
            */

            $flow = [

                'stage' => 2,

                'academic_year' => $currentAcademicYear,

                'import_candidate_id' =>
                    (int) $candidate['import_candidate_id'],

                'allocation_id' =>
                    (int) $openCall['allocation_id'],

                'merit_call_id' =>
                    (int) $openCall['merit_call_id'],

                'call_title' =>
                    (string) $openCall['call_title'],

                'registration_start' =>
                    (string) $openCall['registration_start'],

                'registration_deadline' =>
                    (string) $openCall['registration_deadline'],

                'dept_id' =>
                    (int) $department['dept_id'],

                'dept_name' =>
                    (string) $department['dept_name'],

                'dept_code' =>
                    (string) ($department['dept_code'] ?? ''),

                'gst_roll' =>
                    (string) $candidate['gst_roll'],

                'gst_applicant_id' =>
                    (string) $candidate['gst_applicant_id'],

                'full_name' =>
                    (string) $candidate['full_name'],

                'phone' =>
                    (string) ($candidate['phone'] ?? ''),

                'gst_score' =>
                    (float) $candidate['gst_score'],

                'faculty_id' =>
                    (int) $candidate['faculty_id']
            ];
        }


        /*
        |--------------------------------------------------------------------------
        | STEP 2: SEND OTP
        |--------------------------------------------------------------------------
        */

        elseif ($action === 'send_otp') {

            if (empty($flow['import_candidate_id'])) {
                throw new RuntimeException(
                    'Registration session expired. Start again.'
                );
            }

            $method = strtolower(
                trim((string) ($_POST['signup_method'] ?? ''))
            );

            if (!in_array($method, ['email', 'phone'], true)) {
                throw new RuntimeException(
                    'Choose Email or Phone.'
                );
            }


            /*
            |--------------------------------------------------------------------------
            | EMAIL
            |--------------------------------------------------------------------------
            */

            if ($method === 'email') {

                $destination = strtolower(
                    trim((string) ($_POST['email'] ?? ''))
                );

                if (!filter_var($destination, FILTER_VALIDATE_EMAIL)) {
                    throw new RuntimeException(
                        'Enter a valid email address.'
                    );
                }

                $dup = $pdo->prepare("
                    SELECT applicant_id

                    FROM gst_applicants

                    WHERE email = ?

                    LIMIT 1
                ");

                $dup->execute([$destination]);

                if ($dup->fetchColumn()) {
                    throw new RuntimeException(
                        'This email address is already registered.'
                    );
                }
            }


            /*
            |--------------------------------------------------------------------------
            | PHONE
            |--------------------------------------------------------------------------
            */

            else {

                $destination = trim(
                    (string) ($flow['phone'] ?? '')
                );

                if ($destination === '') {
                    throw new RuntimeException(
                        'No phone number exists in the imported GST record. Use email verification.'
                    );
                }

                $destination = normalizeRegistrationPhone($destination);
            }


            /*
            |--------------------------------------------------------------------------
            | CREATE AND SEND OTP
            |--------------------------------------------------------------------------
            */

            $otpResult = createAndSendRegistrationOtp(
                $pdo,
                (int) $flow['import_candidate_id'],
                $method,
                $destination
            );

            $flow['signup_method'] = $method;

            $flow['destination'] = $destination;

            $flow['otp_resend_available_at'] =
                $otpResult['resend_available_at'];

            $flow['otp_expires_at'] =
                $otpResult['expires_at'];

            if ($method === 'email') {
                $flow['email'] = $destination;
            }

            $flow['stage'] = 3;
        }


        /*
        |--------------------------------------------------------------------------
        | STEP 3: VERIFY OTP
        |--------------------------------------------------------------------------
        */

        elseif ($action === 'verify_otp') {

            if (
                empty($flow['import_candidate_id']) ||
                empty($flow['signup_method']) ||
                empty($flow['destination'])
            ) {
                throw new RuntimeException(
                    'Registration session expired. Start again.'
                );
            }

            $otp = trim(
                (string) ($_POST['otp'] ?? '')
            );

            $pdo->beginTransaction();

            try {

                verifyRegistrationOtp(
                    $pdo,
                    (int) $flow['import_candidate_id'],
                    (string) $flow['signup_method'],
                    (string) $flow['destination'],
                    $otp
                );

                $pdo->commit();

            } catch (Throwable $exception) {

                if ($pdo->inTransaction()) {
                    $pdo->commit();
                }

                throw $exception;
            }

            $flow['otp_verified'] = true;

            $flow['stage'] = 4;
        }


        /*
        |--------------------------------------------------------------------------
        | RESEND OTP
        |--------------------------------------------------------------------------
        */

        elseif ($action === 'resend_otp') {

            if (
                empty($flow['import_candidate_id']) ||
                empty($flow['signup_method']) ||
                empty($flow['destination'])
            ) {
                throw new RuntimeException(
                    'Registration session expired. Start again.'
                );
            }

            $otpResult = createAndSendRegistrationOtp(
                $pdo,
                (int) $flow['import_candidate_id'],
                (string) $flow['signup_method'],
                (string) $flow['destination']
            );

            $flow['otp_resend_available_at'] =
                $otpResult['resend_available_at'];

            $flow['otp_expires_at'] =
                $otpResult['expires_at'];

            $flow['stage'] = 3;
        }


        /*
        |--------------------------------------------------------------------------
        | STEP 4: CREATE ACCOUNT
        |--------------------------------------------------------------------------
        */

        elseif ($action === 'create_account') {

            if (
                empty($flow['otp_verified']) ||
                empty($flow['import_candidate_id']) ||
                empty($flow['allocation_id']) ||
                empty($flow['merit_call_id']) ||
                empty($flow['academic_year'])
            ) {
                throw new RuntimeException(
                    'OTP verification is required.'
                );
            }


            if (
                !hasVerifiedRegistrationOtp(
                    $pdo,
                    (int) $flow['import_candidate_id'],
                    (string) $flow['signup_method'],
                    (string) $flow['destination']
                )
            ) {
                throw new RuntimeException(
                    'OTP verification could not be confirmed.'
                );
            }


            if (
                (string) $flow['academic_year'] !==
                $currentAcademicYear
            ) {
                throw new RuntimeException(
                    'The admission session has changed. Start registration again.'
                );
            }


            requireOpenMeritCallForCandidate(
                $pdo,
                (int) $flow['import_candidate_id'],
                $currentAcademicYear
            );


            $password = (string) ($_POST['password'] ?? '');

            $confirmPassword =
                (string) ($_POST['confirm_password'] ?? '');


            if (strlen($password) < 8) {
                throw new RuntimeException(
                    'Password must contain at least 8 characters.'
                );
            }

            if (strlen($password) > 72) {
                throw new RuntimeException(
                    'Password cannot exceed 72 characters.'
                );
            }

            if ($password !== $confirmPassword) {
                throw new RuntimeException(
                    'Passwords do not match.'
                );
            }


            $pdo->beginTransaction();


            /*
            |--------------------------------------------------------------------------
            | LOCK GST CANDIDATE
            |--------------------------------------------------------------------------
            */

            $candidateStmt = $pdo->prepare("
                SELECT
                    import_candidate_id,
                    gst_roll,
                    full_name,
                    phone,
                    gst_score,
                    faculty_id,
                    is_registered,
                    applicant_id,
                    academic_year

                FROM gst_imported_candidates

                WHERE import_candidate_id = ?
                  AND academic_year = ?

                LIMIT 1

                FOR UPDATE
            ");

            $candidateStmt->execute([
                (int) $flow['import_candidate_id'],
                $currentAcademicYear
            ]);

            $candidate = $candidateStmt->fetch(PDO::FETCH_ASSOC);

            if (!$candidate) {
                throw new RuntimeException(
                    'GST candidate record was not found.'
                );
            }

            if (
                (int) $candidate['is_registered'] === 1 ||
                !empty($candidate['applicant_id'])
            ) {
                throw new RuntimeException(
                    'This candidate is already registered.'
                );
            }


            /*
            |--------------------------------------------------------------------------
            | VERIFY ACTIVE ALLOCATION
            |--------------------------------------------------------------------------
            */

            $allocationStmt = $pdo->prepare("
                SELECT
                    ma.allocation_id,
                    ma.merit_call_id,
                    mc.call_title,
                    mc.registration_start,
                    mc.registration_deadline

                FROM merit_allocations ma

                INNER JOIN merit_calls mc
                    ON mc.merit_call_id = ma.merit_call_id

                INNER JOIN gst_imported_candidates gic
                    ON gic.import_candidate_id = ma.import_candidate_id

                WHERE ma.allocation_id = ?
                  AND ma.import_candidate_id = ?
                  AND ma.merit_call_id = ?
                  AND ma.allocation_status = 'Allocated'

                  AND gic.academic_year = ?
                  AND mc.academic_year = ?

                  AND NOW() >= mc.registration_start
                  AND NOW() <= mc.registration_deadline

                LIMIT 1

                FOR UPDATE
            ");

            $allocationStmt->execute([
                (int) $flow['allocation_id'],
                (int) $flow['import_candidate_id'],
                (int) $flow['merit_call_id'],
                $currentAcademicYear,
                $currentAcademicYear
            ]);

            $activeRegistrationAllocation =
                $allocationStmt->fetch(PDO::FETCH_ASSOC);

            if (!$activeRegistrationAllocation) {
                throw new RuntimeException(
                    'Registration is no longer open for this Merit Call or your department allocation is no longer active.'
                );
            }


            /*
            |--------------------------------------------------------------------------
            | CHECK DUPLICATE GST ROLL
            |--------------------------------------------------------------------------
            */

            $duplicateRoll = $pdo->prepare("
                SELECT ga.applicant_id

                FROM gst_applicants ga

                INNER JOIN gst_imported_candidates gic
                    ON gic.applicant_id = ga.applicant_id

                WHERE ga.gst_roll = ?
                  AND gic.academic_year = ?

                LIMIT 1

                FOR UPDATE
            ");

            $duplicateRoll->execute([
                (string) $candidate['gst_roll'],
                $currentAcademicYear
            ]);

            if ($duplicateRoll->fetchColumn()) {
                throw new RuntimeException(
                    'This GST Roll is already registered in the current admission session.'
                );
            }


            /*
            |--------------------------------------------------------------------------
            | PREPARE ACCOUNT DATA
            |--------------------------------------------------------------------------
            */

            $verifiedEmail =
                $flow['signup_method'] === 'email'
                    ? (string) $flow['destination']
                    : null;

            $accountPhone = !empty($candidate['phone'])
                ? normalizeRegistrationPhone(
                    (string) $candidate['phone']
                )
                : null;

            $passwordHash = password_hash(
                $password,
                PASSWORD_DEFAULT
            );

            if ($passwordHash === false) {
                throw new RuntimeException(
                    'The password could not be secured.'
                );
            }


            /*
            |--------------------------------------------------------------------------
            | INSERT APPLICANT
            |--------------------------------------------------------------------------
            */

            $insert = $pdo->prepare("
                INSERT INTO gst_applicants (
                    gst_roll,
                    full_name,
                    email,
                    phone,
                    password,
                    gst_score,
                    faculty_id
                )

                VALUES (?, ?, ?, ?, ?, ?, ?)
            ");

            $insert->execute([
                (string) $candidate['gst_roll'],
                (string) $candidate['full_name'],
                $verifiedEmail,
                $accountPhone,
                $passwordHash,
                (float) $candidate['gst_score'],
                (int) $candidate['faculty_id']
            ]);

            $applicantId = (int) $pdo->lastInsertId();

            if ($applicantId <= 0) {
                throw new RuntimeException(
                    'The applicant account could not be created.'
                );
            }


            /*
            |--------------------------------------------------------------------------
            | LINK IMPORTED CANDIDATE
            |--------------------------------------------------------------------------
            */

            $linkImported = $pdo->prepare("
                UPDATE gst_imported_candidates

                SET
                    is_registered = 1,
                    applicant_id = ?,
                    registered_at = NOW()

                WHERE import_candidate_id = ?
                  AND academic_year = ?
                  AND is_registered = 0
                  AND applicant_id IS NULL
            ");

            $linkImported->execute([
                $applicantId,
                (int) $flow['import_candidate_id'],
                $currentAcademicYear
            ]);

            if ($linkImported->rowCount() !== 1) {
                throw new RuntimeException(
                    'The imported GST record could not be linked.'
                );
            }


            /*
            |--------------------------------------------------------------------------
            | LINK MERIT ALLOCATION
            |--------------------------------------------------------------------------
            */

            $linkAllocation = $pdo->prepare("
                UPDATE merit_allocations

                SET applicant_id = ?

                WHERE allocation_id = ?
                  AND import_candidate_id = ?
                  AND merit_call_id = ?
                  AND allocation_status = 'Allocated'
            ");

            $linkAllocation->execute([
                $applicantId,
                (int) $flow['allocation_id'],
                (int) $flow['import_candidate_id'],
                (int) $flow['merit_call_id']
            ]);


            /*
            |--------------------------------------------------------------------------
            | LINK WAITING LIST IF APPLICABLE
            |--------------------------------------------------------------------------
            */

            $linkWaiting = $pdo->prepare("
                UPDATE waiting_list wl

                INNER JOIN gst_imported_candidates gic
                    ON gic.import_candidate_id = wl.import_candidate_id

                SET wl.applicant_id = ?

                WHERE wl.import_candidate_id = ?
                  AND gic.academic_year = ?
                  AND wl.waiting_status = 'Called'
            ");

            $linkWaiting->execute([
                $applicantId,
                (int) $flow['import_candidate_id'],
                $currentAcademicYear
            ]);


            /*
            |--------------------------------------------------------------------------
            | COMMIT
            |--------------------------------------------------------------------------
            */

            $pdo->commit();

            $department = (string) ($flow['dept_name'] ?? '');

            resetRegistrationFlow();

            $_SESSION['registration_success'] =
                'Registration successful. You are provisionally allocated to '
                . $department
                . '. Log in with your GST Roll and password.';

            header(
                'Location: ' . basename($_SERVER['PHP_SELF'])
            );

            exit;
        }

    } catch (Throwable $exception) {

        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        $error = $exception->getMessage();
    }
}


/*
|--------------------------------------------------------------------------
| SUCCESS MESSAGE
|--------------------------------------------------------------------------
*/

if (!empty($_SESSION['registration_success'])) {

    $success = (string) $_SESSION['registration_success'];

    unset($_SESSION['registration_success']);
}


$stage = (int) ($flow['stage'] ?? 1);

require_once '../includes/header.php';
require_once '../includes/navbar.php';

?>

<div class="container py-5">

    <div class="row justify-content-center">

        <div class="col-xl-7 col-lg-8 col-md-10">

            <div class="card border-0 shadow-lg">

                <div class="card-body p-4 p-md-5">

                    <div class="text-center mb-4">

                        <img
                            src="/jkkniu-admission/assets/img/logo.png"
                            height="58"
                            alt="JKKNIU"
                            class="mb-2"
                        >

                        <h3 class="fw-bold mb-1">
                            Student Registration
                        </h3>

                        <p class="text-muted mb-2">
                            JKKNIU GST Admission Management System
                        </p>

                        <span class="badge bg-primary">
                            Admission Session:
                            <?= e($currentAcademicYear) ?>
                        </span>

                    </div>


                    <?php if ($success !== ''): ?>

                        <div class="alert alert-success text-center">

                            <h5 class="fw-bold">
                                Account Created Successfully
                            </h5>

                            <p><?= e($success) ?></p>

                            <a
                                href="/jkkniu-admission/student/login.php"
                                class="btn btn-primary"
                            >
                                Go to Student Login
                            </a>

                        </div>

                    <?php else: ?>


                        <?php if ($error !== ''): ?>

                            <div class="alert alert-danger">
                                <?= e($error) ?>
                            </div>

                        <?php endif; ?>


                        <div class="mb-4">

                            <div class="progress">

                                <div
                                    class="progress-bar"
                                    style="width: <?= min(100, $stage * 25) ?>%"
                                >
                                    Step <?= $stage ?> of 4
                                </div>

                            </div>

                        </div>


                        <?php if ($stage === 1): ?>

                            <!-- STEP 1 -->

                            <h5 class="fw-bold mb-3">
                                1. Verify GST Identity
                            </h5>

                            <form method="POST">

                                <input
                                    type="hidden"
                                    name="csrf_token"
                                    value="<?= e((string) $_SESSION['csrf_token']) ?>"
                                >

                                <input
                                    type="hidden"
                                    name="action"
                                    value="verify_identity"
                                >

                                <div class="row g-3">

                                    <div class="col-md-6">

                                        <label class="form-label fw-semibold">
                                            GST Roll
                                        </label>

                                        <input
                                            type="text"
                                            name="gst_roll"
                                            class="form-control"
                                            required
                                        >

                                    </div>

                                    <div class="col-md-6">

                                        <label class="form-label fw-semibold">
                                            GST Applicant ID
                                        </label>

                                        <input
                                            type="text"
                                            name="gst_applicant_id"
                                            class="form-control"
                                            required
                                        >

                                    </div>

                                </div>

                                <button class="btn btn-primary w-100 mt-4">
                                    Verify Candidate
                                </button>

                            </form>


                        <?php elseif ($stage === 2): ?>

                            <!-- STEP 2 -->

                            <h5 class="fw-bold">
                                2. Choose Verification Method
                            </h5>

                            <div class="alert alert-light border">

                                <strong>
                                    <?= e((string) $flow['full_name']) ?>
                                </strong>

                                <br>

                                Allocated Department:

                                <strong>
                                    <?= e((string) $flow['dept_name']) ?>
                                </strong>

                                <br>

                                Merit Call:

                                <strong>
                                    <?= e((string) $flow['call_title']) ?>
                                </strong>

                                <br>

                                Registration Deadline:

                                <strong class="text-danger">
                                    <?= e(
                                        date(
                                            'd M Y, h:i A',
                                            strtotime((string) $flow['registration_deadline'])
                                        )
                                    ) ?>
                                </strong>

                            </div>


                            <form method="POST" id="methodForm">

                                <input
                                    type="hidden"
                                    name="csrf_token"
                                    value="<?= e((string) $_SESSION['csrf_token']) ?>"
                                >

                                <input
                                    type="hidden"
                                    name="action"
                                    value="send_otp"
                                >

                                <div class="row g-3 mb-3">

                                    <div class="col-md-6">

                                        <input
                                            type="radio"
                                            class="btn-check"
                                            name="signup_method"
                                            id="emailMethod"
                                            value="email"
                                            checked
                                        >

                                        <label
                                            class="btn btn-outline-primary w-100 py-3"
                                            for="emailMethod"
                                        >
                                            Email OTP
                                        </label>

                                    </div>

                                    <div class="col-md-6">

                                        <input
                                            type="radio"
                                            class="btn-check"
                                            name="signup_method"
                                            id="phoneMethod"
                                            value="phone"
                                        >

                                        <label
                                            class="btn btn-outline-primary w-100 py-3"
                                            for="phoneMethod"
                                        >
                                            SMS OTP
                                        </label>

                                    </div>

                                </div>


                                <div id="emailBox" class="mb-3">

                                    <label class="form-label fw-semibold">
                                        Email Address
                                    </label>

                                    <input
                                        type="email"
                                        name="email"
                                        id="emailInput"
                                        class="form-control"
                                        placeholder="you@example.com"
                                    >

                                </div>


                                <div
                                    id="phoneBox"
                                    class="alert alert-info d-none"
                                >

                                    OTP will be sent to the GST registered phone:

                                    <strong>

                                        <?php

                                        if (!empty($flow['phone'])) {

                                            try {

                                                echo e(
                                                    maskPhone(
                                                        (string) $flow['phone']
                                                    )
                                                );

                                            } catch (Throwable $ignore) {

                                                echo 'Unavailable';
                                            }

                                        } else {

                                            echo 'Unavailable';
                                        }

                                        ?>

                                    </strong>

                                </div>


                                <button class="btn btn-primary w-100">
                                    Send OTP
                                </button>

                            </form>


                        <?php elseif ($stage === 3): ?>

                            <!-- STEP 3 -->

                            <h5 class="fw-bold">
                                3. Verify OTP
                            </h5>


                            <div class="alert alert-info">

                                Verification code sent to:

                                <strong>

                                    <?php

                                    if ($flow['signup_method'] === 'email') {

                                        echo e(
                                            maskEmail(
                                                (string) $flow['destination']
                                            )
                                        );

                                    } else {

                                        echo e(
                                            maskPhone(
                                                (string) $flow['destination']
                                            )
                                        );
                                    }

                                    ?>

                                </strong>


                                <div class="small mt-1">

                                    The OTP expires in 5 minutes.

                                    <?php if ($flow['signup_method'] === 'phone'): ?>

                                        In demo SMS mode, check
                                        <code>storage/logs/sms.log</code>.

                                    <?php endif; ?>

                                </div>

                            </div>


                            <form method="POST">

                                <input
                                    type="hidden"
                                    name="csrf_token"
                                    value="<?= e((string) $_SESSION['csrf_token']) ?>"
                                >

                                <input
                                    type="hidden"
                                    name="action"
                                    value="verify_otp"
                                >


                                <div class="mb-3">

                                    <label class="form-label fw-semibold">
                                        6-digit OTP
                                    </label>

                                    <input
                                        type="text"
                                        name="otp"
                                        class="form-control form-control-lg text-center"
                                        maxlength="6"
                                        inputmode="numeric"
                                        pattern="[0-9]{6}"
                                        placeholder="000000"
                                        autocomplete="one-time-code"
                                        required
                                    >

                                </div>


                                <button class="btn btn-success w-100">
                                    Verify OTP
                                </button>

                            </form>


                            <?php

                            $resendAvailableAt = (int) (
                                $flow['otp_resend_available_at'] ?? 0
                            );

                            $remainingSeconds = max(
                                0,
                                $resendAvailableAt - time()
                            );

                            ?>


                            <!-- RESEND OTP WITH COUNTDOWN -->

                            <form
                                method="POST"
                                class="mt-2"
                                id="resendOtpForm"
                            >

                                <input
                                    type="hidden"
                                    name="csrf_token"
                                    value="<?= e((string) $_SESSION['csrf_token']) ?>"
                                >

                                <input
                                    type="hidden"
                                    name="action"
                                    value="resend_otp"
                                >

                                <button
                                    type="submit"
                                    id="resendOtpButton"
                                    class="btn btn-outline-secondary w-100"
                                    <?= $remainingSeconds > 0 ? 'disabled' : '' ?>
                                >

                                    <span id="resendOtpText">

                                        <?= $remainingSeconds > 0
                                            ? 'Resend OTP in ' . $remainingSeconds . 's'
                                            : 'Resend OTP'
                                        ?>

                                    </span>

                                </button>


                                <div
                                    class="small text-muted text-center mt-2"
                                    id="resendOtpMessage"
                                >

                                    <?php if ($remainingSeconds > 0): ?>

                                        Please wait before requesting another OTP.

                                    <?php else: ?>

                                        You can request another OTP now.

                                    <?php endif; ?>

                                </div>

                            </form>


                        <?php elseif ($stage === 4): ?>

                            <!-- STEP 4 -->

                            <h5 class="fw-bold">
                                4. Create Password
                            </h5>


                            <div class="alert alert-success">
                                OTP verified successfully.
                            </div>


                            <div class="alert alert-warning">

                                <strong>
                                    Registration Deadline:
                                </strong>

                                <?= e(
                                    date(
                                        'd M Y, h:i A',
                                        strtotime((string) $flow['registration_deadline'])
                                    )
                                ) ?>

                                <div class="small mt-1">

                                    Your account must be created before this
                                    Merit Call registration deadline.

                                </div>

                            </div>


                            <form method="POST">

                                <input
                                    type="hidden"
                                    name="csrf_token"
                                    value="<?= e((string) $_SESSION['csrf_token']) ?>"
                                >

                                <input
                                    type="hidden"
                                    name="action"
                                    value="create_account"
                                >


                                <div class="mb-3">

                                    <label class="form-label fw-semibold">
                                        Password
                                    </label>

                                    <input
                                        type="password"
                                        name="password"
                                        class="form-control"
                                        minlength="8"
                                        maxlength="72"
                                        required
                                    >

                                </div>


                                <div class="mb-3">

                                    <label class="form-label fw-semibold">
                                        Confirm Password
                                    </label>

                                    <input
                                        type="password"
                                        name="confirm_password"
                                        class="form-control"
                                        minlength="8"
                                        maxlength="72"
                                        required
                                    >

                                </div>


                                <button class="btn btn-primary w-100">
                                    Create Student Account
                                </button>

                            </form>

                        <?php endif; ?>


                        <?php if ($stage > 1): ?>

                            <form
                                method="POST"
                                class="mt-3 text-center"
                            >

                                <input
                                    type="hidden"
                                    name="csrf_token"
                                    value="<?= e((string) $_SESSION['csrf_token']) ?>"
                                >

                                <input
                                    type="hidden"
                                    name="action"
                                    value="restart"
                                >

                                <button class="btn btn-link text-muted">
                                    Start Over
                                </button>

                            </form>

                        <?php endif; ?>


                    <?php endif; ?>

                </div>

            </div>

        </div>

    </div>

</div>


<script>

/*
|--------------------------------------------------------------------------
| EMAIL / PHONE METHOD SWITCHING
|--------------------------------------------------------------------------
*/

const emailMethod = document.getElementById('emailMethod');

const phoneMethod = document.getElementById('phoneMethod');

const emailBox = document.getElementById('emailBox');

const phoneBox = document.getElementById('phoneBox');

const emailInput = document.getElementById('emailInput');


function updateMethod() {

    if (
        !emailMethod ||
        !phoneMethod ||
        !emailBox ||
        !phoneBox
    ) {
        return;
    }

    const phoneSelected = phoneMethod.checked;

    emailBox.classList.toggle(
        'd-none',
        phoneSelected
    );

    phoneBox.classList.toggle(
        'd-none',
        !phoneSelected
    );

    if (emailInput) {
        emailInput.required = !phoneSelected;
    }
}


if (emailMethod && phoneMethod) {

    emailMethod.addEventListener(
        'change',
        updateMethod
    );

    phoneMethod.addEventListener(
        'change',
        updateMethod
    );

    updateMethod();
}


/*
|--------------------------------------------------------------------------
| OTP RESEND COUNTDOWN
|--------------------------------------------------------------------------
*/

(function () {

    const resendButton =
        document.getElementById('resendOtpButton');

    const resendText =
        document.getElementById('resendOtpText');

    const resendMessage =
        document.getElementById('resendOtpMessage');


    if (!resendButton || !resendText) {
        return;
    }


    let remaining = <?= (int) max(
        0,
        ((int) ($flow['otp_resend_available_at'] ?? 0)) - time()
    ) ?>;


    function updateCountdown() {

        if (remaining > 0) {

            resendButton.disabled = true;

            resendText.textContent =
                'Resend OTP in ' + remaining + 's';

            if (resendMessage) {

                resendMessage.textContent =
                    'Please wait before requesting another OTP.';
            }

            remaining--;

        } else {

            resendButton.disabled = false;

            resendText.textContent = 'Resend OTP';

            if (resendMessage) {

                resendMessage.textContent =
                    'You can request another OTP now.';
            }

            clearInterval(timer);
        }
    }


    if (remaining > 0) {

        updateCountdown();

        const timer = setInterval(
            updateCountdown,
            1000
        );

    } else {

        resendButton.disabled = false;

        resendText.textContent = 'Resend OTP';
    }

})();

</script>


<?php require_once '../includes/footer.php'; ?>
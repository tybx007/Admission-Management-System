<?php

declare(strict_types=1);

$pageTitle = 'Verify Registration OTP';

require_once '../includes/auth.php';
require_once '../config/db.php';
require_once '../includes/mail/send_mail.php';

redirectIfLoggedIn();

$pdo->setAttribute(
    PDO::ATTR_ERRMODE,
    PDO::ERRMODE_EXCEPTION
);

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(
        random_bytes(32)
    );
}


/*
|--------------------------------------------------------------------------
| Helper
|--------------------------------------------------------------------------
*/

function deptOtpE(mixed $value): string
{
    return htmlspecialchars(
        (string) ($value ?? ''),
        ENT_QUOTES,
        'UTF-8'
    );
}


/*
|--------------------------------------------------------------------------
| Pending registration required
|--------------------------------------------------------------------------
*/

$pending =
    $_SESSION[
        'pending_dept_admin_registration'
    ]
    ?? null;


if (
    !is_array($pending) ||
    empty($pending['otp_id']) ||
    empty($pending['email'])
) {

    header(
        'Location: register.php'
    );

    exit;
}


$error = '';
$success = '';


/*
|--------------------------------------------------------------------------
| Mask email
|--------------------------------------------------------------------------
*/

function maskEmail(string $email): string
{
    $parts = explode(
        '@',
        $email,
        2
    );

    if (count($parts) !== 2) {
        return $email;
    }

    [$name, $domain] = $parts;

    if (strlen($name) <= 2) {
        $maskedName =
            substr($name, 0, 1)
            . '*';
    } else {
        $maskedName =
            substr($name, 0, 2)
            . str_repeat(
                '*',
                max(
                    2,
                    strlen($name) - 2
                )
            );
    }

    return
        $maskedName
        . '@'
        . $domain;
}


/*
|--------------------------------------------------------------------------
| POST
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    try {

        $token = (string) (
            $_POST['csrf_token']
            ?? ''
        );

        if (
            $token === '' ||
            !hash_equals(
                (string) $_SESSION['csrf_token'],
                $token
            )
        ) {
            throw new RuntimeException(
                'Invalid request token. Refresh the page and try again.'
            );
        }


        $action = trim(
            (string) (
                $_POST['action']
                ?? 'verify'
            )
        );


        /*
        |--------------------------------------------------------------------------
        | RESEND OTP
        |--------------------------------------------------------------------------
        */

        if ($action === 'resend') {

            /*
            | Minimum 60-second resend interval.
            */

            $stmt = $pdo->prepare("
                SELECT created_at
                FROM admin_registration_otps
                WHERE otp_id = ?
                  AND email = ?
                LIMIT 1
            ");

            $stmt->execute([
                (int) $pending['otp_id'],
                (string) $pending['email']
            ]);

            $lastCreatedAt =
                $stmt->fetchColumn();


            if ($lastCreatedAt) {

                $lastTimestamp =
                    strtotime(
                        (string) $lastCreatedAt
                    );

                if (
                    $lastTimestamp !== false &&
                    time() - $lastTimestamp < 60
                ) {
                    throw new RuntimeException(
                        'Please wait 60 seconds before requesting another OTP.'
                    );
                }
            }


            /*
            | Expire previous OTP.
            */

            $stmt = $pdo->prepare("
                UPDATE admin_registration_otps
                SET used_at = NOW()
                WHERE otp_id = ?
                  AND email = ?
                  AND used_at IS NULL
            ");

            $stmt->execute([
                (int) $pending['otp_id'],
                (string) $pending['email']
            ]);


            $newOtp = (string) random_int(
                100000,
                999999
            );

            $newOtpHash =
                password_hash(
                    $newOtp,
                    PASSWORD_DEFAULT
                );


            if ($newOtpHash === false) {
                throw new RuntimeException(
                    'OTP could not be generated.'
                );
            }


            $stmt = $pdo->prepare("
                INSERT INTO admin_registration_otps (
                    email,
                    otp_hash,
                    expires_at,
                    attempts,
                    used_at,
                    created_at
                )
                VALUES (
                    ?,
                    ?,
                    DATE_ADD(
                        NOW(),
                        INTERVAL 10 MINUTE
                    ),
                    0,
                    NULL,
                    NOW()
                )
            ");

            $stmt->execute([
                (string) $pending['email'],
                $newOtpHash
            ]);

            $newOtpId =
                (int) $pdo->lastInsertId();


            $subject =
                'JKKNIU Department Admin Registration OTP';


            $body = "
                <div style=\"font-family:Arial,sans-serif;
                            max-width:620px;
                            margin:auto;
                            border:1px solid #dddddd;
                            border-radius:10px;
                            overflow:hidden;\">

                    <div style=\"background:#0d6efd;
                                color:#ffffff;
                                padding:22px;
                                text-align:center;\">

                        <h2 style=\"margin:0;\">
                            JKKNIU Admission Management
                        </h2>

                    </div>


                    <div style=\"padding:25px;\">

                        <p>
                            Your new verification OTP is:
                        </p>

                        <div style=\"font-size:32px;
                                    font-weight:bold;
                                    letter-spacing:8px;
                                    text-align:center;
                                    background:#f4f6f8;
                                    padding:18px;
                                    margin:20px 0;
                                    border-radius:8px;\">

                            {$newOtp}

                        </div>

                        <p>
                            This OTP expires in
                            <strong>10 minutes</strong>.
                        </p>

                    </div>
                </div>
            ";


            $mailSent = sendMail(
                (string) $pending['email'],
                $subject,
                $body
            );


            if (!$mailSent) {

                $pdo->prepare("
                    UPDATE admin_registration_otps
                    SET used_at = NOW()
                    WHERE otp_id = ?
                ")->execute([
                    $newOtpId
                ]);

                throw new RuntimeException(
                    'New OTP could not be sent.'
                );
            }


            $_SESSION[
                'pending_dept_admin_registration'
            ]['otp_id'] =
                $newOtpId;


            $pending =
                $_SESSION[
                    'pending_dept_admin_registration'
                ];


            $success =
                'A new OTP has been sent to your email.';
        }


        /*
        |--------------------------------------------------------------------------
        | VERIFY OTP
        |--------------------------------------------------------------------------
        */

        elseif ($action === 'verify') {

            $otp = trim(
                (string) (
                    $_POST['otp']
                    ?? ''
                )
            );


            if (
                !preg_match(
                    '/^\d{6}$/',
                    $otp
                )
            ) {
                throw new RuntimeException(
                    'Enter the 6-digit OTP.'
                );
            }


            /*
            |--------------------------------------------------------------------------
            | Lock OTP
            |--------------------------------------------------------------------------
            */

            $pdo->beginTransaction();


            $stmt = $pdo->prepare("
                SELECT
                    otp_id,
                    email,
                    otp_hash,
                    expires_at,
                    attempts,
                    used_at

                FROM admin_registration_otps

                WHERE otp_id = ?
                  AND email = ?

                LIMIT 1
                FOR UPDATE
            ");

            $stmt->execute([
                (int) $pending['otp_id'],
                (string) $pending['email']
            ]);

            $otpRow = $stmt->fetch(
                PDO::FETCH_ASSOC
            );


            if (!$otpRow) {
                throw new RuntimeException(
                    'OTP request was not found. Please register again.'
                );
            }


            if (
                !empty(
                    $otpRow['used_at']
                )
            ) {
                throw new RuntimeException(
                    'This OTP has already been used. Request a new OTP.'
                );
            }


            if (
                strtotime(
                    (string)
                    $otpRow['expires_at']
                ) < time()
            ) {
                throw new RuntimeException(
                    'OTP has expired. Request a new OTP.'
                );
            }


            if (
                (int) $otpRow['attempts']
                >= 5
            ) {
                throw new RuntimeException(
                    'Too many incorrect attempts. Request a new OTP.'
                );
            }


            if (
                !password_verify(
                    $otp,
                    (string)
                    $otpRow['otp_hash']
                )
            ) {

                $stmt = $pdo->prepare("
                    UPDATE admin_registration_otps
                    SET attempts =
                        attempts + 1
                    WHERE otp_id = ?
                ");

                $stmt->execute([
                    (int) $otpRow['otp_id']
                ]);


                $pdo->commit();


                $remaining =
                    max(
                        0,
                        4 -
                        (int)
                        $otpRow['attempts']
                    );


                throw new RuntimeException(
                    'Incorrect OTP. '
                    . $remaining
                    . ' attempt(s) remaining.'
                );
            }


            /*
            |--------------------------------------------------------------------------
            | Re-check username/email before final insert
            |--------------------------------------------------------------------------
            */

            $stmt = $pdo->prepare("
                SELECT admin_id
                FROM admin_users
                WHERE username = ?
                   OR email = ?
                LIMIT 1
            ");

            $stmt->execute([
                (string) $pending['username'],
                (string) $pending['email']
            ]);


            if ($stmt->fetchColumn()) {
                throw new RuntimeException(
                    'Username or email was registered while you were verifying OTP.'
                );
            }


            /*
            |--------------------------------------------------------------------------
            | Re-check department availability
            |--------------------------------------------------------------------------
            */

            $stmt = $pdo->prepare("
                SELECT admin_id
                FROM admin_users
                WHERE dept_id = ?
                  AND role = 'dept_admin'
                  AND status IN (
                      'Active',
                      'Pending'
                  )
                LIMIT 1
            ");

            $stmt->execute([
                (int) $pending['dept_id']
            ]);


            if ($stmt->fetchColumn()) {
                throw new RuntimeException(
                    'This department already has an Active or Pending admin.'
                );
            }


            /*
            |--------------------------------------------------------------------------
            | Create Pending admin account
            |--------------------------------------------------------------------------
            */

            $stmt = $pdo->prepare("
                INSERT INTO admin_users (
                    full_name,
                    username,
                    email,
                    password,
                    role,
                    dept_id,
                    status
                )
                VALUES (
                    ?,
                    ?,
                    ?,
                    ?,
                    'dept_admin',
                    ?,
                    'Pending'
                )
            ");

            $stmt->execute([
                (string) $pending['full_name'],
                (string) $pending['username'],
                (string) $pending['email'],
                (string) $pending['password_hash'],
                (int) $pending['dept_id']
            ]);


            /*
            |--------------------------------------------------------------------------
            | Mark OTP used
            |--------------------------------------------------------------------------
            */

            $stmt = $pdo->prepare("
                UPDATE admin_registration_otps
                SET used_at = NOW()
                WHERE otp_id = ?
            ");

            $stmt->execute([
                (int) $otpRow['otp_id']
            ]);


            $pdo->commit();


            unset(
                $_SESSION[
                    'pending_dept_admin_registration'
                ]
            );


            $_SESSION['dept_registration_success'] =
                'Email verified successfully. Your Department Admin registration is now pending approval by the Super Admin.';


            header(
                'Location: login.php'
            );

            exit;
        }


        else {
            throw new RuntimeException(
                'Invalid OTP action.'
            );
        }


    } catch (Throwable $exception) {

        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        $error =
            $exception->getMessage();
    }
}


/*
|--------------------------------------------------------------------------
| HTML
|--------------------------------------------------------------------------
*/

require_once '../includes/header.php';

?>

<div class="login-wrapper" style="padding:40px 0;">

    <div
        class="card shadow-lg"
        style="
            width:100%;
            max-width:480px;
            border-radius:16px;
        "
    >

        <div class="card-body p-4">


            <div class="text-center mb-4">

                <img
                    src="/jkkniu-admission/assets/img/logo.png"
                    height="70"
                    alt="JKKNIU"
                    class="mb-2"
                >

                <h5 class="fw-bold">
                    Verify Email OTP
                </h5>

                <p class="text-muted small">
                    Department Admin Registration
                </p>

            </div>


            <?php if ($error !== ''): ?>

                <div class="alert alert-danger">

                    <?= deptOtpE(
                        $error
                    ) ?>

                </div>

            <?php endif; ?>


            <?php if ($success !== ''): ?>

                <div class="alert alert-success">

                    <?= deptOtpE(
                        $success
                    ) ?>

                </div>

            <?php endif; ?>


            <div class="alert alert-info small">

                We sent a 6-digit OTP to:

                <strong>

                    <?= deptOtpE(
                        maskEmail(
                            (string)
                            $pending['email']
                        )
                    ) ?>

                </strong>

                <br>

                OTP expires after
                <strong>10 minutes</strong>.

            </div>


            <form method="POST" class="mb-3">

                <input
                    type="hidden"
                    name="csrf_token"
                    value="<?= deptOtpE(
                        $_SESSION[
                            'csrf_token'
                        ]
                    ) ?>"
                >

                <input
                    type="hidden"
                    name="action"
                    value="verify"
                >


                <div class="mb-3">

                    <label class="form-label fw-semibold">
                        Verification OTP
                    </label>

                    <input
                        type="text"
                        name="otp"
                        class="form-control form-control-lg text-center fw-bold"
                        maxlength="6"
                        inputmode="numeric"
                        autocomplete="one-time-code"
                        pattern="\d{6}"
                        placeholder="000000"
                        style="letter-spacing:8px;"
                        required
                        autofocus
                    >

                </div>


                <button
                    type="submit"
                    class="btn btn-success w-100 fw-bold"
                >

                    <i
                        class="bi bi-shield-check me-1"
                    ></i>

                    Verify & Submit Registration

                </button>

            </form>


            <form method="POST">

                <input
                    type="hidden"
                    name="csrf_token"
                    value="<?= deptOtpE(
                        $_SESSION[
                            'csrf_token'
                        ]
                    ) ?>"
                >

                <input
                    type="hidden"
                    name="action"
                    value="resend"
                >


                <button
                    type="submit"
                    class="btn btn-outline-primary w-100"
                >

                    <i
                        class="bi bi-arrow-clockwise me-1"
                    ></i>

                    Resend OTP

                </button>

            </form>


            <div class="text-center mt-3">

                <a
                    href="register.php"
                    class="text-muted small"
                >

                    <i
                        class="bi bi-arrow-left me-1"
                    ></i>

                    Change registration information

                </a>

            </div>

        </div>

    </div>

</div>


<?php require_once '../includes/footer.php'; ?>
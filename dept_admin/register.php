<?php

declare(strict_types=1);

$pageTitle = 'Department Admin Registration';

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

function deptRegisterE(mixed $value): string
{
    return htmlspecialchars(
        (string) ($value ?? ''),
        ENT_QUOTES,
        'UTF-8'
    );
}


/*
|--------------------------------------------------------------------------
| Departments
|--------------------------------------------------------------------------
*/

$departments = $pdo->query("
    SELECT
        d.dept_id,
        d.dept_name,
        f.faculty_name

    FROM departments d

    INNER JOIN faculties f
        ON f.faculty_id =
           d.faculty_id

    ORDER BY
        f.faculty_name,
        d.dept_name
")->fetchAll(PDO::FETCH_ASSOC);


$error = '';


/*
|--------------------------------------------------------------------------
| Registration form submit
|--------------------------------------------------------------------------
|
| Account is NOT inserted yet.
| First:
|   validate
|   -> generate OTP
|   -> send OTP to email
|   -> redirect to verify_otp.php
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


        $fullName = trim(
            (string) (
                $_POST['full_name']
                ?? ''
            )
        );

        $username = trim(
            (string) (
                $_POST['username']
                ?? ''
            )
        );

        $email = strtolower(
            trim(
                (string) (
                    $_POST['email']
                    ?? ''
                )
            )
        );

        $deptId = (int) (
            $_POST['dept_id']
            ?? 0
        );

        $password = (string) (
            $_POST['password']
            ?? ''
        );

        $confirmPassword = (string) (
            $_POST['confirm_password']
            ?? ''
        );


        /*
        |--------------------------------------------------------------------------
        | Validation
        |--------------------------------------------------------------------------
        */

        if (
            $fullName === '' ||
            $username === '' ||
            $email === '' ||
            $deptId <= 0 ||
            $password === '' ||
            $confirmPassword === ''
        ) {
            throw new RuntimeException(
                'All fields are required.'
            );
        }


        if (
            !filter_var(
                $email,
                FILTER_VALIDATE_EMAIL
            )
        ) {
            throw new RuntimeException(
                'Enter a valid email address.'
            );
        }


        if (
            !preg_match(
                '/^[A-Za-z0-9._-]{3,50}$/',
                $username
            )
        ) {
            throw new RuntimeException(
                'Username must be 3–50 characters and may contain letters, numbers, dot, underscore or hyphen.'
            );
        }


        if ($password !== $confirmPassword) {
            throw new RuntimeException(
                'Passwords do not match.'
            );
        }


        if (strlen($password) < 8) {
            throw new RuntimeException(
                'Password must be at least 8 characters.'
            );
        }


        /*
        |--------------------------------------------------------------------------
        | Validate department
        |--------------------------------------------------------------------------
        */

        $stmt = $pdo->prepare("
            SELECT
                dept_id,
                dept_name
            FROM departments
            WHERE dept_id = ?
            LIMIT 1
        ");

        $stmt->execute([
            $deptId
        ]);

        $department = $stmt->fetch(
            PDO::FETCH_ASSOC
        );


        if (!$department) {
            throw new RuntimeException(
                'Selected department does not exist.'
            );
        }


        /*
        |--------------------------------------------------------------------------
        | Username/email uniqueness
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
            $username,
            $email
        ]);


        if ($stmt->fetchColumn()) {
            throw new RuntimeException(
                'Username or email already exists.'
            );
        }


        /*
        |--------------------------------------------------------------------------
        | One active/pending admin per department
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
            $deptId
        ]);


        if ($stmt->fetchColumn()) {
            throw new RuntimeException(
                'This department already has a registered admin. Contact Super Admin.'
            );
        }


        /*
        |--------------------------------------------------------------------------
        | Generate OTP
        |--------------------------------------------------------------------------
        */

        $otp = (string) random_int(
            100000,
            999999
        );

        $otpHash = password_hash(
            $otp,
            PASSWORD_DEFAULT
        );

        if ($otpHash === false) {
            throw new RuntimeException(
                'OTP could not be secured.'
            );
        }


        /*
        |--------------------------------------------------------------------------
        | Invalidate previous active OTPs for this email
        |--------------------------------------------------------------------------
        */

        $stmt = $pdo->prepare("
            UPDATE admin_registration_otps
            SET used_at = NOW()
            WHERE email = ?
              AND used_at IS NULL
        ");

        $stmt->execute([
            $email
        ]);


        /*
        |--------------------------------------------------------------------------
        | Create OTP record
        |--------------------------------------------------------------------------
        */

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
            $email,
            $otpHash
        ]);

        $otpId = (int) $pdo->lastInsertId();


        /*
        |--------------------------------------------------------------------------
        | Store pending registration securely in session
        |--------------------------------------------------------------------------
        |
        | Password itself is NOT stored.
        |--------------------------------------------------------------------------
        */

        $passwordHash = password_hash(
            $password,
            PASSWORD_DEFAULT
        );

        if ($passwordHash === false) {
            throw new RuntimeException(
                'Password could not be secured.'
            );
        }


        $_SESSION['pending_dept_admin_registration'] = [
            'otp_id' =>
                $otpId,

            'full_name' =>
                $fullName,

            'username' =>
                $username,

            'email' =>
                $email,

            'dept_id' =>
                $deptId,

            'dept_name' =>
                (string) $department[
                    'dept_name'
                ],

            'password_hash' =>
                $passwordHash,

            'created_at' =>
                time()
        ];


        /*
        |--------------------------------------------------------------------------
        | Send OTP email
        |--------------------------------------------------------------------------
        */

        $safeName = deptRegisterE(
            $fullName
        );

        $safeDepartment =
            deptRegisterE(
                $department[
                    'dept_name'
                ]
            );


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

                    <div style=\"margin-top:6px;\">
                        Department Admin Email Verification
                    </div>

                </div>


                <div style=\"padding:25px;\">

                    <p>
                        Dear <strong>{$safeName}</strong>,
                    </p>

                    <p>
                        You requested registration as Department Admin for:
                    </p>

                    <p>
                        <strong>{$safeDepartment}</strong>
                    </p>

                    <p>
                        Your verification OTP is:
                    </p>

                    <div style=\"font-size:32px;
                                font-weight:bold;
                                letter-spacing:8px;
                                text-align:center;
                                background:#f4f6f8;
                                padding:18px;
                                margin:20px 0;
                                border-radius:8px;\">

                        {$otp}

                    </div>

                    <p>
                        This OTP will expire in
                        <strong>10 minutes</strong>.
                    </p>

                    <p>
                        Do not share this OTP with anyone.
                    </p>

                    <p style=\"margin-top:25px;\">
                        Regards,<br>
                        <strong>
                            JKKNIU Admission Authority
                        </strong>
                    </p>

                </div>
            </div>
        ";


        $mailSent = sendMail(
            $email,
            $subject,
            $body
        );


        if (!$mailSent) {

            /*
            | Do not allow verification when delivery failed.
            */

            $stmt = $pdo->prepare("
                UPDATE admin_registration_otps
                SET used_at = NOW()
                WHERE otp_id = ?
            ");

            $stmt->execute([
                $otpId
            ]);


            unset(
                $_SESSION[
                    'pending_dept_admin_registration'
                ]
            );


            throw new RuntimeException(
                'OTP email could not be sent. Please try again.'
            );
        }


        header(
            'Location: verify_otp.php'
        );

        exit;


    } catch (Throwable $exception) {

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
            max-width:520px;
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
                    Department Admin Registration
                </h5>

                <p class="text-muted small">
                    JKKNIU Admission Management
                </p>

            </div>


            <?php if ($error !== ''): ?>

                <div class="alert alert-danger">

                    <?= deptRegisterE(
                        $error
                    ) ?>

                </div>

            <?php endif; ?>


            <div class="alert alert-info small">

                <i
                    class="bi bi-shield-lock-fill me-1"
                ></i>

                Your email will be verified using a
                <strong>6-digit OTP</strong>
                before the registration is submitted for
                Super Admin approval.

            </div>


            <form method="POST">

                <input
                    type="hidden"
                    name="csrf_token"
                    value="<?= deptRegisterE(
                        $_SESSION[
                            'csrf_token'
                        ]
                    ) ?>"
                >


                <div class="mb-3">

                    <label class="form-label fw-semibold">
                        Full Name
                    </label>

                    <input
                        type="text"
                        name="full_name"
                        class="form-control"
                        value="<?= deptRegisterE(
                            $_POST[
                                'full_name'
                            ]
                            ?? ''
                        ) ?>"
                        placeholder="Your full name"
                        required
                    >

                </div>


                <div class="mb-3">

                    <label class="form-label fw-semibold">
                        Department
                    </label>


                    <select
                        name="dept_id"
                        class="form-select"
                        required
                    >

                        <option value="">
                            -- Select Your Department --
                        </option>


                        <?php
                        $currentFaculty = '';

                        foreach (
                            $departments as
                            $department
                        ):

                            if (
                                $currentFaculty !==
                                $department[
                                    'faculty_name'
                                ]
                            ):

                                if (
                                    $currentFaculty !== ''
                                ) {
                                    echo '</optgroup>';
                                }

                                $currentFaculty =
                                    $department[
                                        'faculty_name'
                                    ];

                                echo '<optgroup label="' .
                                    deptRegisterE(
                                        $currentFaculty
                                    )
                                    . '">';

                            endif;
                        ?>

                            <option
                                value="<?= (int)
                                    $department[
                                        'dept_id'
                                    ]
                                ?>"
                                <?= (
                                    isset(
                                        $_POST[
                                            'dept_id'
                                        ]
                                    )
                                    &&
                                    (int) $_POST[
                                        'dept_id'
                                    ] ===
                                    (int) $department[
                                        'dept_id'
                                    ]
                                )
                                    ? 'selected'
                                    : ''
                                ?>
                            >

                                <?= deptRegisterE(
                                    $department[
                                        'dept_name'
                                    ]
                                ) ?>

                            </option>


                        <?php
                        endforeach;

                        if ($currentFaculty !== '') {
                            echo '</optgroup>';
                        }
                        ?>

                    </select>

                </div>


                <div class="mb-3">

                    <label class="form-label fw-semibold">
                        Username
                    </label>

                    <input
                        type="text"
                        name="username"
                        class="form-control"
                        value="<?= deptRegisterE(
                            $_POST[
                                'username'
                            ]
                            ?? ''
                        ) ?>"
                        placeholder="Choose a username"
                        required
                    >

                </div>


                <div class="mb-3">

                    <label class="form-label fw-semibold">
                        Email Address
                    </label>

                    <input
                        type="email"
                        name="email"
                        class="form-control"
                        value="<?= deptRegisterE(
                            $_POST[
                                'email'
                            ]
                            ?? ''
                        ) ?>"
                        placeholder="Official email"
                        required
                    >

                </div>


                <div class="mb-3">

                    <label class="form-label fw-semibold">
                        Password
                    </label>


                    <div class="input-group">

                        <input
                            type="password"
                            name="password"
                            id="passwordField"
                            class="form-control"
                            placeholder="Min 8 characters"
                            minlength="8"
                            required
                        >

                        <button
                            type="button"
                            class="btn btn-outline-secondary"
                            onclick="
                                togglePassword(
                                    'passwordField',
                                    'eyeIcon'
                                )
                            "
                        >

                            <i
                                class="bi bi-eye"
                                id="eyeIcon"
                            ></i>

                        </button>

                    </div>

                </div>


                <div class="mb-4">

                    <label class="form-label fw-semibold">
                        Confirm Password
                    </label>


                    <div class="input-group">

                        <input
                            type="password"
                            name="confirm_password"
                            id="confirmPassword"
                            class="form-control"
                            placeholder="Repeat password"
                            minlength="8"
                            required
                        >

                        <button
                            type="button"
                            class="btn btn-outline-secondary"
                            onclick="
                                togglePassword(
                                    'confirmPassword',
                                    'eyeConfirm'
                                )
                            "
                        >

                            <i
                                class="bi bi-eye"
                                id="eyeConfirm"
                            ></i>

                        </button>

                    </div>


                    <small id="matchMessage"></small>

                </div>


                <button
                    type="submit"
                    class="btn btn-primary w-100 fw-bold"
                >

                    <i
                        class="bi bi-envelope-lock-fill me-1"
                    ></i>

                    Send OTP

                </button>

            </form>


            <div class="text-center mt-3">

                <small>

                    Already registered?

                    <a href="/jkkniu-admission/dept_admin/login.php">
                        Login here
                    </a>

                </small>

            </div>


            <div class="text-center mt-2">

                <a
                    href="/jkkniu-admission/index.php"
                    class="text-muted small"
                >

                    <i class="bi bi-arrow-left"></i>
                    Back to Home

                </a>

            </div>

        </div>

    </div>

</div>


<script>
function togglePassword(fieldId, iconId) {

    const field =
        document.getElementById(
            fieldId
        );

    const icon =
        document.getElementById(
            iconId
        );

    if (field.type === 'password') {

        field.type = 'text';

        icon.classList.replace(
            'bi-eye',
            'bi-eye-slash'
        );

    } else {

        field.type = 'password';

        icon.classList.replace(
            'bi-eye-slash',
            'bi-eye'
        );
    }
}


const passwordField =
    document.getElementById(
        'passwordField'
    );

const confirmPasswordField =
    document.getElementById(
        'confirmPassword'
    );

const matchMessage =
    document.getElementById(
        'matchMessage'
    );


function checkPasswordMatch() {

    if (
        confirmPasswordField.value === ''
    ) {
        matchMessage.textContent = '';
        return;
    }


    if (
        passwordField.value ===
        confirmPasswordField.value
    ) {

        matchMessage.textContent =
            '✔ Password matched';

        matchMessage.style.color =
            'green';

    } else {

        matchMessage.textContent =
            '✖ Password does not match';

        matchMessage.style.color =
            'red';
    }
}


passwordField.addEventListener(
    'input',
    checkPasswordMatch
);

confirmPasswordField.addEventListener(
    'input',
    checkPasswordMatch
);


document
    .querySelector('form')
    .addEventListener(
        'submit',
        function (event) {

            if (
                passwordField.value !==
                confirmPasswordField.value
            ) {

                event.preventDefault();

                matchMessage.textContent =
                    '✖ Please match the passwords before submitting';

                matchMessage.style.color =
                    'red';

                confirmPasswordField.focus();
            }
        }
    );
</script>


<?php require_once '../includes/footer.php'; ?>
<?php

declare(strict_types=1);

$pageTitle = 'Student Login';

require_once '../includes/auth.php';
require_once '../config/db.php';

redirectIfLoggedIn();

$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$error = '';

/**
 * Normalize Bangladeshi mobile numbers to 01XXXXXXXXX.
 *
 * Accepted examples:
 * 01712345678
 * +8801712345678
 * 8801712345678
 */
function normalizeBangladeshPhone(string $phone): string
{
    $phone = preg_replace(
        '/[\s\-\(\)]/',
        '',
        trim($phone)
    ) ?? '';

    if (str_starts_with($phone, '+880')) {
        $phone = '0' . substr($phone, 4);
    } elseif (str_starts_with($phone, '880')) {
        $phone = '0' . substr($phone, 3);
    }

    return $phone;
}

/*
|--------------------------------------------------------------------------
| Student Login
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $submittedToken = $_POST['csrf_token'] ?? '';

    $identifier = trim(
        (string) ($_POST['identifier'] ?? '')
    );

    /*
     * Do NOT trim passwords.
     * A password may legitimately begin/end with spaces.
     */
    $password = (string) ($_POST['password'] ?? '');

    if (
        !is_string($submittedToken) ||
        !hash_equals(
            $_SESSION['csrf_token'],
            $submittedToken
        )
    ) {
        $error =
            'Invalid request token. Refresh the page and try again.';

    } elseif ($identifier === '' || $password === '') {

        $error = 'Please fill in all fields.';

    } else {

        try {

            /*
            |--------------------------------------------------------------------------
            | Determine login method
            |--------------------------------------------------------------------------
            */

            if (
                filter_var(
                    $identifier,
                    FILTER_VALIDATE_EMAIL
                )
            ) {

                /*
                 * Login using Email
                 */
                $email = strtolower($identifier);

                $stmt = $pdo->prepare("
                    SELECT *
                    FROM gst_applicants
                    WHERE LOWER(email) = ?
                    LIMIT 1
                ");

                $stmt->execute([$email]);

            } else {

                /*
                 * Check whether identifier looks like
                 * a Bangladeshi phone number.
                 */
                $normalizedPhone =
                    normalizeBangladeshPhone($identifier);

                if (
                    preg_match(
                        '/^01[3-9][0-9]{8}$/',
                        $normalizedPhone
                    )
                ) {

                    /*
                     * Login using Phone
                     */
                    $stmt = $pdo->prepare("
                        SELECT *
                        FROM gst_applicants
                        WHERE phone = ?
                        LIMIT 1
                    ");

                    $stmt->execute([
                        $normalizedPhone
                    ]);

                } else {

                    /*
                     * Otherwise treat it as GST Roll
                     */
                    $stmt = $pdo->prepare("
                        SELECT *
                        FROM gst_applicants
                        WHERE gst_roll = ?
                        LIMIT 1
                    ");

                    $stmt->execute([
                        $identifier
                    ]);
                }
            }

            $student = $stmt->fetch(
                PDO::FETCH_ASSOC
            );

            /*
            |--------------------------------------------------------------------------
            | Verify password
            |--------------------------------------------------------------------------
            */

            if (
                $student &&
                password_verify(
                    $password,
                    (string) $student['password']
                )
            ) {

                /*
                 * Prevent session fixation.
                 */
                session_regenerate_id(true);

                $_SESSION['user_id'] =
                    (int) $student['applicant_id'];

                $_SESSION['role'] =
                    'student';

                $_SESSION['name'] =
                    (string) $student['full_name'];

                $_SESSION['gst_roll'] =
                    (string) $student['gst_roll'];

                header(
                    'Location: /jkkniu-admission/student/dashboard.php'
                );

                exit;
            }

            /*
             * Generic error avoids revealing whether
             * an email/phone/GST Roll actually exists.
             */
            $error =
                'Invalid GST Roll, email, phone number, or password.';

        } catch (PDOException $exception) {

            $error =
                'Login could not be completed. Please try again.';
        }
    }
}

/*
|--------------------------------------------------------------------------
| Load page layout only after redirects / POST processing
|--------------------------------------------------------------------------
*/

require_once '../includes/header.php';

?>

<div class="login-wrapper">

    <div class="login-card card shadow-lg">

        <div class="card-body p-4">

            <div class="text-center mb-4">

                <img
                    src="/jkkniu-admission/assets/img/logo.png"
                    class="login-logo mb-2"
                    alt="JKKNIU"
                >

                <h5 class="fw-bold">
                    Student Login
                </h5>

                <p class="text-muted small">
                    JKKNIU Admission Management
                </p>

            </div>

            <?php if ($error !== ''): ?>

                <div class="alert alert-danger">

                    <i
                        class="bi bi-exclamation-triangle-fill me-1"
                    ></i>

                    <?= htmlspecialchars(
                        $error,
                        ENT_QUOTES,
                        'UTF-8'
                    ) ?>

                </div>

            <?php endif; ?>

            <form
                method="POST"
                id="studentLoginForm"
            >

                <input
                    type="hidden"
                    name="csrf_token"
                    value="<?= htmlspecialchars(
                        $_SESSION['csrf_token'],
                        ENT_QUOTES,
                        'UTF-8'
                    ) ?>"
                >

                <!-- Login Identifier -->

                <div class="mb-3">

                    <label
                        for="identifier"
                        class="form-label fw-semibold"
                    >
                        GST Roll / Email / Phone
                    </label>

                    <input
                        type="text"
                        id="identifier"
                        name="identifier"
                        class="form-control"
                        maxlength="100"
                        value="<?= htmlspecialchars(
                            trim(
                                (string) (
                                    $_POST['identifier']
                                    ?? ''
                                )
                            ),
                            ENT_QUOTES,
                            'UTF-8'
                        ) ?>"
                        placeholder="Enter GST Roll, email or phone"
                        autocomplete="username"
                        required
                        autofocus
                    >

                    <div class="form-text">
                        You can use your GST Roll,
                        registered email address,
                        or registered phone number.
                    </div>

                </div>

                <!-- Password -->

                <div class="mb-3">

                    <label
                        for="passwordField"
                        class="form-label fw-semibold"
                    >
                        Password
                    </label>

                    <div class="input-group">

                        <input
                            type="password"
                            name="password"
                            id="passwordField"
                            class="form-control"
                            placeholder="Enter password"
                            autocomplete="current-password"
                            required
                        >

                        <button
                            type="button"
                            class="btn btn-outline-secondary"
                            onclick="togglePassword()"
                            aria-label="Show or hide password"
                        >
                            <i
                                class="bi bi-eye"
                                id="eyeIcon"
                            ></i>
                        </button>

                    </div>

                </div>

                <!-- Login Button -->

                <button
                    type="submit"
                    class="btn btn-primary w-100 fw-bold"
                >
                    <i
                        class="bi bi-box-arrow-in-right me-1"
                    ></i>

                    Login
                </button>

            </form>

            <div class="text-center mt-3">

                <small>

                    Not registered?

                    <a
                        href="/jkkniu-admission/student/signup.php"
                        class="fw-semibold"
                    >
                        Register here
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

function togglePassword() {

    const field =
        document.getElementById('passwordField');

    const icon =
        document.getElementById('eyeIcon');

    if (!field || !icon) {
        return;
    }

    if (field.type === 'password') {

        field.type = 'text';

        icon.classList.remove('bi-eye');
        icon.classList.add('bi-eye-slash');

    } else {

        field.type = 'password';

        icon.classList.remove('bi-eye-slash');
        icon.classList.add('bi-eye');
    }
}

</script>

<?php require_once '../includes/footer.php'; ?>
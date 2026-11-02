<?php

declare(strict_types=1);

$pageTitle = 'Manage Users';

require_once '../includes/auth.php';
require_once '../config/db.php';
require_once '../includes/mail/send_mail.php';

/*
|--------------------------------------------------------------------------
| Authentication BEFORE any HTML output
|--------------------------------------------------------------------------
*/

requireLogin('super_admin');

if (
    !isset($_SESSION['user_id']) ||
    ($_SESSION['role'] ?? '') !== 'super_admin'
) {
    header('Location: /jkkniu-admission/super_admin/login.php');
    exit;
}

$selfId = (int) $_SESSION['user_id'];

if ($selfId <= 0) {
    header('Location: /jkkniu-admission/super_admin/login.php');
    exit;
}

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
| Helpers
|--------------------------------------------------------------------------
*/

function e(mixed $value): string
{
    return htmlspecialchars(
        (string) ($value ?? ''),
        ENT_QUOTES,
        'UTF-8'
    );
}


/*
|--------------------------------------------------------------------------
| Admin Email Helpers
|--------------------------------------------------------------------------
*/

function adminMailEscape(mixed $value): string
{
    return htmlspecialchars(
        (string) ($value ?? ''),
        ENT_QUOTES,
        'UTF-8'
    );
}


function adminRoleLabel(string $role): string
{
    return match ($role) {
        'dept_admin'  => 'Department Admin',
        'hall_admin'  => 'Hall Admin',
        'registrar'   => 'Registrar',
        'super_admin' => 'Super Admin',
        default       => ucfirst(
            str_replace('_', ' ', $role)
        )
    };
}


function adminLoginPath(string $role): string
{
    return match ($role) {
        'dept_admin' =>
            '/jkkniu-admission/dept_admin/login.php',

        'hall_admin' =>
            '/jkkniu-admission/hall_admin/login.php',

        'registrar' =>
            '/jkkniu-admission/registrar/login.php',

        'super_admin' =>
            '/jkkniu-admission/super_admin/login.php',

        default =>
            '/jkkniu-admission/index.php'
    };
}


function sendAdminCredentialsEmail(
    string $email,
    string $fullName,
    string $username,
    string $plainPassword,
    string $role,
    ?string $departmentName = null,
    ?string $hallName = null
): bool {

    if (
        !filter_var(
            $email,
            FILTER_VALIDATE_EMAIL
        )
    ) {
        return false;
    }

    $safeName =
        adminMailEscape($fullName);

    $safeUsername =
        adminMailEscape($username);

    $safePassword =
        adminMailEscape($plainPassword);

    $safeRole =
        adminMailEscape(
            adminRoleLabel($role)
        );

    $safeLoginPath =
        adminMailEscape(
            adminLoginPath($role)
        );

    $assignmentHtml = '';

    if (
        $departmentName !== null &&
        trim($departmentName) !== ''
    ) {
        $assignmentHtml = '
            <p style="margin:5px 0;">
                <strong>Department:</strong>
                ' .
                adminMailEscape(
                    $departmentName
                )
                . '
            </p>
        ';
    }

    if (
        $hallName !== null &&
        trim($hallName) !== ''
    ) {
        $assignmentHtml = '
            <p style="margin:5px 0;">
                <strong>Hall:</strong>
                ' .
                adminMailEscape(
                    $hallName
                )
                . '
            </p>
        ';
    }

    $subject =
        'JKKNIU Admin Account Credentials';

    $body = "
        <div style=\"font-family:Arial,sans-serif;
                    max-width:650px;
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
                    Administrator Account Created
                </div>
            </div>

            <div style=\"padding:25px;\">

                <p>
                    Dear <strong>{$safeName}</strong>,
                </p>

                <p>
                    A JKKNIU Admission Management administrator
                    account has been created for you by the Super Admin.
                </p>

                <div style=\"background:#f5f7fa;
                            border-left:4px solid #0d6efd;
                            padding:16px;
                            margin:18px 0;\">

                    <p style=\"margin:5px 0;\">
                        <strong>Role:</strong>
                        {$safeRole}
                    </p>

                    {$assignmentHtml}

                    <p style=\"margin:5px 0;\">
                        <strong>Username:</strong>
                        {$safeUsername}
                    </p>

                    <p style=\"margin:5px 0;\">
                        <strong>Password:</strong>
                        {$safePassword}
                    </p>

                    <p style=\"margin:5px 0;\">
                        <strong>Login Page:</strong>
                        {$safeLoginPath}
                    </p>
                </div>

                <div style=\"background:#fff3cd;
                            border:1px solid #ffe69c;
                            padding:12px;
                            border-radius:6px;\">

                    <strong>Security notice:</strong>
                    Keep these credentials private. After your first login,
                    change the password if a password-change option is
                    available.
                </div>

                <p style=\"margin-top:25px;\">
                    Regards,<br>
                    <strong>
                        JKKNIU Admission Authority
                    </strong>
                </p>
            </div>
        </div>
    ";

    try {
        return (bool) sendMail(
            $email,
            $subject,
            $body
        );
    } catch (Throwable $exception) {

        error_log(
            'Admin credentials email failed: '
            . $exception->getMessage()
        );

        return false;
    }
}


function sendAdminApprovalEmail(
    string $email,
    string $fullName,
    string $username,
    string $role,
    ?string $departmentName = null,
    ?string $hallName = null
): bool {

    if (
        !filter_var(
            $email,
            FILTER_VALIDATE_EMAIL
        )
    ) {
        return false;
    }

    $safeName =
        adminMailEscape($fullName);

    $safeUsername =
        adminMailEscape($username);

    $safeRole =
        adminMailEscape(
            adminRoleLabel($role)
        );

    $safeLoginPath =
        adminMailEscape(
            adminLoginPath($role)
        );

    $assignmentHtml = '';

    if (
        $departmentName !== null &&
        trim($departmentName) !== ''
    ) {
        $assignmentHtml = '
            <p style="margin:5px 0;">
                <strong>Department:</strong>
                ' .
                adminMailEscape(
                    $departmentName
                )
                . '
            </p>
        ';
    }

    if (
        $hallName !== null &&
        trim($hallName) !== ''
    ) {
        $assignmentHtml = '
            <p style="margin:5px 0;">
                <strong>Hall:</strong>
                ' .
                adminMailEscape(
                    $hallName
                )
                . '
            </p>
        ';
    }

    $subject =
        'JKKNIU Admin Account Approved';

    $body = "
        <div style=\"font-family:Arial,sans-serif;
                    max-width:650px;
                    margin:auto;
                    border:1px solid #dddddd;
                    border-radius:10px;
                    overflow:hidden;\">

            <div style=\"background:#198754;
                        color:#ffffff;
                        padding:22px;
                        text-align:center;\">

                <h2 style=\"margin:0;\">
                    JKKNIU Admission Management
                </h2>

                <div style=\"margin-top:6px;\">
                    Administrator Account Approved
                </div>
            </div>

            <div style=\"padding:25px;\">

                <p>
                    Dear <strong>{$safeName}</strong>,
                </p>

                <p>
                    Your administrator registration has been
                    <strong style=\"color:#198754;\">approved</strong>
                    by the Super Admin.
                </p>

                <p>
                    Your account is now <strong>Active</strong> and you
                    may log in to the system.
                </p>

                <div style=\"background:#f5f7fa;
                            border-left:4px solid #198754;
                            padding:16px;
                            margin:18px 0;\">

                    <p style=\"margin:5px 0;\">
                        <strong>Role:</strong>
                        {$safeRole}
                    </p>

                    {$assignmentHtml}

                    <p style=\"margin:5px 0;\">
                        <strong>Username:</strong>
                        {$safeUsername}
                    </p>

                    <p style=\"margin:5px 0;\">
                        <strong>Login Page:</strong>
                        {$safeLoginPath}
                    </p>
                </div>

                <p>
                    Use the password you created during registration.
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

    try {
        return (bool) sendMail(
            $email,
            $subject,
            $body
        );
    } catch (Throwable $exception) {

        error_log(
            'Admin approval email failed: '
            . $exception->getMessage()
        );

        return false;
    }
}


function redirectManageUsers(
    string $role = '',
    string $status = '',
    string $search = ''
): never {
    $query = [];

    if ($role !== '') {
        $query['role'] = $role;
    }

    if ($status !== '') {
        $query['status'] = $status;
    }

    if ($search !== '') {
        $query['q'] = $search;
    }

    $url = 'manageusers.php';

    if ($query) {
        $url .= '?' . http_build_query($query);
    }

    header('Location: ' . $url);
    exit;
}


/*
|--------------------------------------------------------------------------
| Allowed values
|--------------------------------------------------------------------------
*/

$validRoles = [
    'dept_admin',
    'hall_admin',
    'registrar',
    'super_admin',
];

$validStatuses = [
    'Pending',
    'Active',
    'Suspended',
];


/*
|--------------------------------------------------------------------------
| Current filters
|--------------------------------------------------------------------------
*/

$filterRole = trim(
    (string) (
        $_GET['role']
        ?? $_POST['return_role']
        ?? ''
    )
);

$filterStatus = trim(
    (string) (
        $_GET['status']
        ?? $_POST['return_status']
        ?? ''
    )
);

$search = trim(
    (string) (
        $_GET['q']
        ?? $_POST['return_q']
        ?? ''
    )
);

if (
    $filterRole !== '' &&
    !in_array(
        $filterRole,
        $validRoles,
        true
    )
) {
    $filterRole = '';
}

if (
    $filterStatus !== '' &&
    !in_array(
        $filterStatus,
        $validStatuses,
        true
    )
) {
    $filterStatus = '';
}


/*
|--------------------------------------------------------------------------
| Handle POST actions
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    try {

        /*
        |--------------------------------------------------------------------------
        | CSRF
        |--------------------------------------------------------------------------
        */

        $token = (string) (
            $_POST['csrf_token'] ?? ''
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
            (string) ($_POST['action'] ?? '')
        );

        $targetId = (int) (
            $_POST['admin_id'] ?? 0
        );


        /*
        |--------------------------------------------------------------------------
        | CREATE
        |--------------------------------------------------------------------------
        */

        if ($action === 'create') {

            $fullName = trim(
                (string) (
                    $_POST['full_name'] ?? ''
                )
            );

            $username = trim(
                (string) (
                    $_POST['username'] ?? ''
                )
            );

            $email = strtolower(
                trim(
                    (string) (
                        $_POST['email'] ?? ''
                    )
                )
            );

            $password = (string) (
                $_POST['password'] ?? ''
            );

            $role = trim(
                (string) (
                    $_POST['role'] ?? ''
                )
            );

            $deptId = (int) (
                $_POST['dept_id'] ?? 0
            );

            $hallName = trim(
                (string) (
                    $_POST['hall_name'] ?? ''
                )
            );


            /*
            | Required basic fields
            */

            if (
                $fullName === '' ||
                $username === '' ||
                $email === '' ||
                $password === '' ||
                $role === ''
            ) {
                throw new RuntimeException(
                    'All required fields must be filled.'
                );
            }


            if (
                mb_strlen(
                    $fullName,
                    'UTF-8'
                ) > 150
            ) {
                throw new RuntimeException(
                    'Full name is too long.'
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
                mb_strlen(
                    $email,
                    'UTF-8'
                ) > 150
            ) {
                throw new RuntimeException(
                    'Email address is too long.'
                );
            }


            if (strlen($password) < 8) {
                throw new RuntimeException(
                    'Password must be at least 8 characters.'
                );
            }


            if (
                !in_array(
                    $role,
                    $validRoles,
                    true
                )
            ) {
                throw new RuntimeException(
                    'Invalid administrator role.'
                );
            }


            /*
            |--------------------------------------------------------------------------
            | Role-specific relationship rules
            |--------------------------------------------------------------------------
            */

            $departmentName = null;

            if ($role === 'dept_admin') {

                if ($deptId <= 0) {
                    throw new RuntimeException(
                        'Department is required for a Department Admin.'
                    );
                }

                $deptCheck = $pdo->prepare("
                    SELECT
                        dept_id,
                        dept_name
                    FROM departments
                    WHERE dept_id = ?
                    LIMIT 1
                ");

                $deptCheck->execute([
                    $deptId
                ]);

                $departmentRow =
                    $deptCheck->fetch(
                        PDO::FETCH_ASSOC
                    );

                if (!$departmentRow) {
                    throw new RuntimeException(
                        'Selected department does not exist.'
                    );
                }

                $departmentName =
                    (string)
                    $departmentRow[
                        'dept_name'
                    ];

                $hallName = null;

            } elseif ($role === 'hall_admin') {

                if ($hallName === '') {
                    throw new RuntimeException(
                        'Hall name is required for a Hall Admin.'
                    );
                }

                if (
                    mb_strlen(
                        $hallName,
                        'UTF-8'
                    ) > 150
                ) {
                    throw new RuntimeException(
                        'Hall name is too long.'
                    );
                }

                $deptId = null;

            } else {

                /*
                | Registrar / Super Admin should not be linked to a
                | department or hall.
                */

                $deptId = null;
                $hallName = null;
            }


            /*
            |--------------------------------------------------------------------------
            | Unique username / email
            |--------------------------------------------------------------------------
            */

            $duplicateCheck = $pdo->prepare("
                SELECT
                    admin_id,
                    username,
                    email
                FROM admin_users
                WHERE username = ?
                   OR email = ?
                LIMIT 1
            ");

            $duplicateCheck->execute([
                $username,
                $email
            ]);

            $duplicate = $duplicateCheck->fetch(
                PDO::FETCH_ASSOC
            );

            if ($duplicate) {

                if (
                    strcasecmp(
                        (string) $duplicate['username'],
                        $username
                    ) === 0
                ) {
                    throw new RuntimeException(
                        'This username already exists.'
                    );
                }

                throw new RuntimeException(
                    'This email address already exists.'
                );
            }


            /*
            |--------------------------------------------------------------------------
            | Create as Active because Super Admin is creating it directly
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


            $insert = $pdo->prepare("
                INSERT INTO admin_users (
                    full_name,
                    username,
                    email,
                    password,
                    role,
                    dept_id,
                    hall_name,
                    status,
                    approved_at,
                    approved_by
                )
                VALUES (
                    ?,
                    ?,
                    ?,
                    ?,
                    ?,
                    ?,
                    ?,
                    'Active',
                    NOW(),
                    ?
                )
            ");

            $insert->execute([
                $fullName,
                $username,
                $email,
                $passwordHash,
                $role,
                $deptId,
                $hallName,
                $selfId
            ]);


            /*
            |--------------------------------------------------------------------------
            | Send username + ORIGINAL entered password
            |--------------------------------------------------------------------------
            |
            | The database stores only the hash. The plaintext password exists
            | here only for this immediate notification and is never persisted.
            |--------------------------------------------------------------------------
            */

            $credentialsMailSent =
                sendAdminCredentialsEmail(
                    $email,
                    $fullName,
                    $username,
                    $password,
                    $role,
                    $departmentName,
                    $hallName
                );


            $_SESSION['flash'] = [
                'type' =>
                    $credentialsMailSent
                        ? 'success'
                        : 'warning',

                'msg' =>
                    $credentialsMailSent
                        ? "Account for '{$username}' created successfully. Username and password were sent to {$email}."
                        : "Account for '{$username}' created successfully, but the credentials email could not be sent."
            ];


            redirectManageUsers(
                $filterRole,
                $filterStatus,
                $search
            );
        }


        /*
        |--------------------------------------------------------------------------
        | Every other action needs a target
        |--------------------------------------------------------------------------
        */

        if ($targetId <= 0) {
            throw new RuntimeException(
                'Invalid administrator account.'
            );
        }


        /*
        |--------------------------------------------------------------------------
        | Prevent modifying own account
        |--------------------------------------------------------------------------
        */

        if ($targetId === $selfId) {
            throw new RuntimeException(
                'You cannot suspend, delete, approve or modify your own account here.'
            );
        }


        /*
        |--------------------------------------------------------------------------
        | Fetch target once
        |--------------------------------------------------------------------------
        */

        $targetStmt = $pdo->prepare("
            SELECT
                a.admin_id,
                a.full_name,
                a.username,
                a.email,
                a.role,
                a.status,
                a.dept_id,
                a.hall_name,
                d.dept_name

            FROM admin_users a

            LEFT JOIN departments d
                ON d.dept_id =
                   a.dept_id

            WHERE a.admin_id = ?

            LIMIT 1
        ");

        $targetStmt->execute([
            $targetId
        ]);

        $target = $targetStmt->fetch(
            PDO::FETCH_ASSOC
        );

        if (!$target) {
            throw new RuntimeException(
                'Administrator account was not found.'
            );
        }


        /*
        |--------------------------------------------------------------------------
        | APPROVE
        |--------------------------------------------------------------------------
        */

        if ($action === 'approve') {

            if ($target['status'] !== 'Pending') {
                throw new RuntimeException(
                    'Only a Pending account can be approved.'
                );
            }

            $stmt = $pdo->prepare("
                UPDATE admin_users
                SET
                    status = 'Active',
                    approved_at = NOW(),
                    approved_by = ?
                WHERE admin_id = ?
                  AND status = 'Pending'
            ");

            $stmt->execute([
                $selfId,
                $targetId
            ]);

            if ($stmt->rowCount() !== 1) {
                throw new RuntimeException(
                    'The account could not be approved.'
                );
            }

            /*
            |--------------------------------------------------------------------------
            | Approval email
            |--------------------------------------------------------------------------
            |
            | Self-registered admins already know the password they created,
            | so this email confirms activation and includes the username/login.
            |--------------------------------------------------------------------------
            */

            $approvalMailSent =
                sendAdminApprovalEmail(
                    (string)
                    $target['email'],

                    (string)
                    $target['full_name'],

                    (string)
                    $target['username'],

                    (string)
                    $target['role'],

                    !empty(
                        $target[
                            'dept_name'
                        ]
                    )
                        ? (string)
                          $target[
                              'dept_name'
                          ]
                        : null,

                    !empty(
                        $target[
                            'hall_name'
                        ]
                    )
                        ? (string)
                          $target[
                              'hall_name'
                          ]
                        : null
                );


            $_SESSION['flash'] = [
                'type' =>
                    $approvalMailSent
                        ? 'success'
                        : 'warning',

                'msg' =>
                    $approvalMailSent
                        ? 'Account approved successfully and approval email sent.'
                        : 'Account approved successfully, but the approval email could not be sent.'
            ];
        }


        /*
        |--------------------------------------------------------------------------
        | SUSPEND
        |--------------------------------------------------------------------------
        */

        elseif ($action === 'suspend') {

            if ($target['status'] !== 'Active') {
                throw new RuntimeException(
                    'Only an Active account can be suspended.'
                );
            }


            /*
            | Do not suspend the final active Super Admin.
            */

            if ($target['role'] === 'super_admin') {

                $activeSuperAdmins = (int) $pdo
                    ->query("
                        SELECT COUNT(*)
                        FROM admin_users
                        WHERE role = 'super_admin'
                          AND status = 'Active'
                    ")
                    ->fetchColumn();

                if ($activeSuperAdmins <= 1) {
                    throw new RuntimeException(
                        'The last active Super Admin cannot be suspended.'
                    );
                }
            }


            $stmt = $pdo->prepare("
                UPDATE admin_users
                SET status = 'Suspended'
                WHERE admin_id = ?
                  AND status = 'Active'
            ");

            $stmt->execute([
                $targetId
            ]);

            if ($stmt->rowCount() !== 1) {
                throw new RuntimeException(
                    'The account could not be suspended.'
                );
            }

            $_SESSION['flash'] = [
                'type' => 'warning',
                'msg' =>
                    'Account suspended.'
            ];
        }


        /*
        |--------------------------------------------------------------------------
        | ACTIVATE
        |--------------------------------------------------------------------------
        */

        elseif ($action === 'activate') {

            if ($target['status'] !== 'Suspended') {
                throw new RuntimeException(
                    'Only a Suspended account can be re-activated.'
                );
            }

            $stmt = $pdo->prepare("
                UPDATE admin_users
                SET status = 'Active'
                WHERE admin_id = ?
                  AND status = 'Suspended'
            ");

            $stmt->execute([
                $targetId
            ]);

            if ($stmt->rowCount() !== 1) {
                throw new RuntimeException(
                    'The account could not be re-activated.'
                );
            }

            $_SESSION['flash'] = [
                'type' => 'success',
                'msg' =>
                    'Account re-activated.'
            ];
        }


        /*
        |--------------------------------------------------------------------------
        | DELETE
        |--------------------------------------------------------------------------
        */

        elseif ($action === 'delete') {

            /*
            | Do not delete the final Super Admin account.
            */

            if ($target['role'] === 'super_admin') {

                $superAdminCount = (int) $pdo
                    ->query("
                        SELECT COUNT(*)
                        FROM admin_users
                        WHERE role = 'super_admin'
                    ")
                    ->fetchColumn();

                if ($superAdminCount <= 1) {
                    throw new RuntimeException(
                        'The last Super Admin account cannot be deleted.'
                    );
                }
            }


            $stmt = $pdo->prepare("
                DELETE FROM admin_users
                WHERE admin_id = ?
            ");

            $stmt->execute([
                $targetId
            ]);

            if ($stmt->rowCount() !== 1) {
                throw new RuntimeException(
                    'The account could not be deleted.'
                );
            }

            $_SESSION['flash'] = [
                'type' => 'danger',
                'msg' =>
                    'Account deleted permanently.'
            ];
        }


        else {
            throw new RuntimeException(
                'Invalid administrator action.'
            );
        }


    } catch (Throwable $exception) {

        $_SESSION['flash'] = [
            'type' => 'danger',
            'msg' =>
                $exception->getMessage()
        ];
    }


    redirectManageUsers(
        $filterRole,
        $filterStatus,
        $search
    );
}


/*
|--------------------------------------------------------------------------
| Flash message
|--------------------------------------------------------------------------
*/

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);


/*
|--------------------------------------------------------------------------
| User filters
|--------------------------------------------------------------------------
*/

$where = ['1 = 1'];
$params = [];


if ($filterRole !== '') {

    $where[] = 'a.role = ?';
    $params[] = $filterRole;
}


if ($filterStatus !== '') {

    $where[] = 'a.status = ?';
    $params[] = $filterStatus;
}


if ($search !== '') {

    $where[] = "
        (
            a.full_name LIKE ?
            OR a.username LIKE ?
            OR a.email LIKE ?
        )
    ";

    $searchPattern =
        '%' . $search . '%';

    $params[] = $searchPattern;
    $params[] = $searchPattern;
    $params[] = $searchPattern;
}


/*
|--------------------------------------------------------------------------
| Admin account list
|--------------------------------------------------------------------------
*/

$sql = "
    SELECT
        a.admin_id,
        a.full_name,
        a.username,
        a.email,
        a.role,
        a.dept_id,
        a.hall_name,
        a.status,
        a.approved_at,
        a.approved_by,
        a.created_at,

        d.dept_name,
        f.faculty_name

    FROM admin_users a

    LEFT JOIN departments d
        ON d.dept_id =
           a.dept_id

    LEFT JOIN faculties f
        ON f.faculty_id =
           d.faculty_id

    WHERE " .
    implode(
        ' AND ',
        $where
    ) .
    "

    ORDER BY
        FIELD(
            a.status,
            'Pending',
            'Active',
            'Suspended'
        ),
        a.created_at DESC,
        a.admin_id DESC
";


$stmt = $pdo->prepare($sql);
$stmt->execute($params);

$users = $stmt->fetchAll(
    PDO::FETCH_ASSOC
);


/*
|--------------------------------------------------------------------------
| Role counts
|--------------------------------------------------------------------------
*/

$roleCountsRows = $pdo->query("
    SELECT
        role,
        COUNT(*) AS cnt
    FROM admin_users
    GROUP BY role
")->fetchAll(PDO::FETCH_ASSOC);

$roleCounts = [];

foreach ($roleCountsRows as $row) {
    $roleCounts[
        (string) $row['role']
    ] = (int) $row['cnt'];
}


$pendingCount = (int) $pdo
    ->query("
        SELECT COUNT(*)
        FROM admin_users
        WHERE status = 'Pending'
    ")
    ->fetchColumn();


/*
|--------------------------------------------------------------------------
| Department dropdown
|--------------------------------------------------------------------------
*/

$departments = $pdo->query("
    SELECT
        d.dept_id,
        d.dept_code,
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


/*
|--------------------------------------------------------------------------
| HTML after all redirects
|--------------------------------------------------------------------------
*/

require_once '../includes/header.php';
require_once '../includes/navbar.php';

?>

<div class="container py-4">

  <!-- ── BANNER ── -->
  <div class="alert alert-primary d-flex align-items-center justify-content-between flex-wrap gap-2 mb-4">
    <div class="d-flex align-items-center gap-2">
      <i class="bi bi-people-fill fs-4"></i>
      <div>
        <strong>Manage Admin Users</strong>
        <span class="badge bg-warning text-dark ms-2">Super Admin</span>
      </div>
    </div>
    <a href="dashboard.php" class="btn btn-sm btn-light">
      <i class="bi bi-arrow-left me-1"></i>Back to Dashboard
    </a>
  </div>

  <!-- ── FLASH ── -->
  <?php if ($flash): ?>
    <div class="alert alert-<?= e($flash['type']) ?> alert-dismissible fade show d-flex align-items-center gap-2">
      <i class="bi bi-<?= $flash['type']==='success' ? 'check-circle-fill' : ($flash['type']==='danger' ? 'x-circle-fill' : 'exclamation-triangle-fill') ?>"></i>
      <?= e($flash['msg']) ?>
      <button type="button" class="btn-close ms-auto" data-bs-dismiss="alert"></button>
    </div>
  <?php endif; ?>

  <div class="row g-4">

    <!-- ════ LEFT — Create New Admin ════ -->
    <div class="col-lg-4">
      <div class="card shadow-sm mb-4">
        <div class="card-header bg-dark py-2 fw-semibold">
          <i class="bi bi-person-plus-fill text-success me-1"></i> Create New Admin
        </div>
        <div class="card-body">
          <form method="POST" id="createForm">
            <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
            <input type="hidden" name="action" value="create">
            <input type="hidden" name="admin_id" value="0">
            <input type="hidden" name="return_role" value="<?= e($filterRole) ?>">
            <input type="hidden" name="return_status" value="<?= e($filterStatus) ?>">
            <input type="hidden" name="return_q" value="<?= e($search) ?>">

            <div class="mb-3">
              <label class="form-label fw-semibold">Full Name <span class="text-danger">*</span></label>
              <input type="text" name="full_name" class="form-control" placeholder="Full name" maxlength="150" required>
            </div>

            <div class="mb-3">
              <label class="form-label fw-semibold">Username <span class="text-danger">*</span></label>
              <input type="text" name="username" class="form-control" placeholder="Choose username" maxlength="50" required>
            </div>

            <div class="mb-3">
              <label class="form-label fw-semibold">Email <span class="text-danger">*</span></label>
              <input type="email" name="email" class="form-control" placeholder="Official email" maxlength="150" required>
            </div>

            <div class="mb-3">
              <label class="form-label fw-semibold">Role <span class="text-danger">*</span></label>
              <select name="role" id="roleSelect" class="form-select" required
                      onchange="toggleRoleFields(this.value)">
                <option value="">-- Select Role --</option>
                <option value="dept_admin">Department Admin</option>
                <option value="hall_admin">Hall Admin</option>
                <option value="registrar">Registrar</option>
                <option value="super_admin">Super Admin</option>
              </select>
            </div>

            <!-- Department field — Department Admin only -->
            <div class="mb-3 d-none" id="deptField">
              <label class="form-label fw-semibold">
                Department <span class="text-danger">*</span>
              </label>

              <select name="dept_id" id="deptSelect" class="form-select">
                <option value="">-- Select Department --</option>

                <?php
                $currentFaculty = '';

                foreach ($departments as $department):

                    if (
                        $currentFaculty !==
                        $department['faculty_name']
                    ) {

                        if ($currentFaculty !== '') {
                            echo '</optgroup>';
                        }

                        echo '<optgroup label="' .
                            e($department['faculty_name']) .
                            '">';

                        $currentFaculty =
                            $department['faculty_name'];
                    }
                ?>

                  <option value="<?= (int) $department['dept_id'] ?>">
                    <?= e(
                        (
                            !empty($department['dept_code'])
                                ? $department['dept_code'] . ' — '
                                : ''
                        )
                        . $department['dept_name']
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

            <!-- Hall field — Hall Admin only -->
            <div class="mb-3 d-none" id="hallField">
              <label class="form-label fw-semibold">
                Hall Name <span class="text-danger">*</span>
              </label>

              <input
                type="text"
                name="hall_name"
                id="hallNameField"
                class="form-control"
                maxlength="150"
                placeholder="e.g. Bidrohi Hall"
              >
            </div>

            <div class="mb-3">
              <label class="form-label fw-semibold">Password <span class="text-danger">*</span></label>
              <div class="input-group">
                <input type="password" name="password" id="newPassField"
                       class="form-control" placeholder="Min 8 characters"
                       minlength="8" required>
                <button type="button" class="btn btn-outline-secondary" onclick="toggleNewPass()">
                  <i class="bi bi-eye" id="newPassEye"></i>
                </button>
              </div>
            </div>

            <button type="submit" class="btn btn-success w-100 fw-bold">
              <i class="bi bi-person-plus-fill me-1"></i>Create Account
            </button>
          </form>
        </div>
      </div>

      <!-- Account Summary -->
      <div class="card shadow-sm">
        <div class="card-header bg-dark py-2 fw-semibold">
          <i class="bi bi-bar-chart-fill text-info me-1"></i> Account Summary
        </div>
        <div class="card-body p-0">
          <?php
          $summary = [
            ['role'=>'dept_admin',  'label'=>'Dept Admins',  'icon'=>'bi-building',         'color'=>'text-primary'],
            ['role'=>'hall_admin',  'label'=>'Hall Admins',  'icon'=>'bi-house-fill',        'color'=>'text-success'],
            ['role'=>'registrar',   'label'=>'Registrars',   'icon'=>'bi-person-badge-fill', 'color'=>'text-info'],
            ['role'=>'super_admin', 'label'=>'Super Admins', 'icon'=>'bi-shield-lock-fill',  'color'=>'text-warning'],
          ];

          foreach ($summary as $s): ?>
          <div class="d-flex align-items-center justify-content-between px-3 py-2 border-bottom">
            <span class="small">
              <i class="bi <?= e($s['icon']) ?> <?= e($s['color']) ?> me-2"></i><?= e($s['label']) ?>
            </span>
            <span class="badge bg-secondary"><?= (int) ($roleCounts[$s['role']] ?? 0) ?></span>
          </div>
          <?php endforeach; ?>

          <div class="d-flex align-items-center justify-content-between px-3 py-2">
            <span class="small">
              <i class="bi bi-hourglass-split text-danger me-2"></i>Pending Approval
            </span>
            <span class="badge bg-danger"><?= $pendingCount ?></span>
          </div>
        </div>
      </div>
    </div><!-- /col-lg-4 -->

    <!-- ════ RIGHT — User List ════ -->
    <div class="col-lg-8">
      <div class="card shadow-sm">
        <div class="card-header bg-dark py-2 fw-semibold d-flex align-items-center justify-content-between flex-wrap gap-2">
          <span><i class="bi bi-list-ul text-warning me-1"></i> All Admin Accounts</span>
          <?php if ($pendingCount > 0): ?>
            <span class="badge bg-danger"><?= $pendingCount ?> Pending</span>
          <?php endif; ?>
        </div>

        <!-- Filters -->
        <div class="card-body border-bottom pb-3">
          <form method="GET" class="row g-2 align-items-end">
            <div class="col-md-4">
              <label class="form-label small fw-semibold mb-1">Search</label>
              <input type="text" name="q" class="form-control form-control-sm"
                     placeholder="Name, username, email..."
                     value="<?= e($search) ?>">
            </div>

            <div class="col-md-3">
              <label class="form-label small fw-semibold mb-1">Role</label>
              <select name="role" class="form-select form-select-sm">
                <option value="">All Roles</option>
                <option value="dept_admin"  <?= $filterRole==='dept_admin'  ? 'selected':'' ?>>Dept Admin</option>
                <option value="hall_admin"  <?= $filterRole==='hall_admin'  ? 'selected':'' ?>>Hall Admin</option>
                <option value="registrar"   <?= $filterRole==='registrar'   ? 'selected':'' ?>>Registrar</option>
                <option value="super_admin" <?= $filterRole==='super_admin' ? 'selected':'' ?>>Super Admin</option>
              </select>
            </div>

            <div class="col-md-3">
              <label class="form-label small fw-semibold mb-1">Status</label>
              <select name="status" class="form-select form-select-sm">
                <option value="">All Status</option>
                <option value="Pending"   <?= $filterStatus==='Pending'   ? 'selected':'' ?>>Pending</option>
                <option value="Active"    <?= $filterStatus==='Active'    ? 'selected':'' ?>>Active</option>
                <option value="Suspended" <?= $filterStatus==='Suspended' ? 'selected':'' ?>>Suspended</option>
              </select>
            </div>

            <div class="col-md-2 d-flex gap-1">
              <button type="submit" class="btn btn-primary btn-sm w-100">
                <i class="bi bi-search"></i>
              </button>

              <a href="manageusers.php" class="btn btn-outline-secondary btn-sm w-100">
                <i class="bi bi-x-lg"></i>
              </a>
            </div>
          </form>
        </div>

        <!-- Table -->
        <div class="card-body p-0">

          <?php if (empty($users)): ?>

            <div class="text-center py-5 text-muted">
              <i class="bi bi-people fs-1 d-block mb-2"></i>
              No accounts found matching your filters.
            </div>

          <?php else: ?>

          <div class="table-responsive">
            <table class="table table-sm table-bordered table-hover mb-0 align-middle">
              <thead class="table-light">
                <tr>
                  <th>#</th>
                  <th>Name</th>
                  <th>Username</th>
                  <th>Role</th>
                  <th>Dept / Hall</th>
                  <th class="text-center">Status</th>
                  <th>Registered</th>
                  <th class="text-center">Actions</th>
                </tr>
              </thead>

              <tbody>

                <?php foreach ($users as $i => $u):

                  $isSelf =
                      (int) $u['admin_id'] ===
                      $selfId;

                  $statusBadge = match(
                      (string) $u['status']
                  ) {
                      'Active' =>
                          'bg-success',

                      'Pending' =>
                          'bg-warning text-dark',

                      'Suspended' =>
                          'bg-danger',

                      default =>
                          'bg-secondary'
                  };

                  $roleBadge = match(
                      (string) $u['role']
                  ) {
                      'dept_admin' =>
                          'bg-primary',

                      'hall_admin' =>
                          'bg-success',

                      'registrar' =>
                          'bg-info text-dark',

                      'super_admin' =>
                          'bg-warning text-dark',

                      default =>
                          'bg-secondary'
                  };

                ?>

                <tr class="<?= $u['status'] === 'Pending' ? 'table-warning' : '' ?>">

                  <td><?= $i + 1 ?></td>

                  <td>
                    <div class="fw-semibold small">
                      <?= e($u['full_name']) ?>
                    </div>

                    <small class="text-muted">
                      <?= e($u['email'] ?? '') ?>
                    </small>

                    <?php if ($isSelf): ?>
                      <span class="badge bg-dark ms-1" style="font-size:10px">You</span>
                    <?php endif; ?>
                  </td>

                  <td>
                    <small>@<?= e($u['username']) ?></small>
                  </td>

                  <td>
                    <span class="badge <?= $roleBadge ?>">
                      <?= e($u['role']) ?>
                    </span>
                  </td>

                  <td>
                    <small>
                      <?php
                      if (
                          $u['role'] === 'dept_admin' &&
                          !empty($u['dept_name'])
                      ) {
                          echo e($u['dept_name']);
                      } elseif (
                          $u['role'] === 'hall_admin' &&
                          !empty($u['hall_name'])
                      ) {
                          echo e($u['hall_name']);
                      } else {
                          echo '—';
                      }
                      ?>
                    </small>
                  </td>

                  <td class="text-center">
                    <span class="badge <?= $statusBadge ?>">
                      <?= e($u['status']) ?>
                    </span>
                  </td>

                  <td>
                    <small>
                      <?= !empty($u['created_at'])
                          ? date(
                              'd M Y',
                              strtotime(
                                  (string) $u['created_at']
                              )
                          )
                          : '—'
                      ?>
                    </small>
                  </td>

                  <td class="text-center">

                    <?php if ($isSelf): ?>

                      <span class="text-muted small" title="Cannot modify own account">
                        <i class="bi bi-lock-fill"></i> You
                      </span>

                    <?php else: ?>

                    <div class="d-flex gap-1 justify-content-center flex-wrap">

                      <?php if ($u['status'] === 'Pending'): ?>

                        <form method="POST" class="d-inline">
                          <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
                          <input type="hidden" name="action" value="approve">
                          <input type="hidden" name="admin_id" value="<?= (int) $u['admin_id'] ?>">
                          <input type="hidden" name="return_role" value="<?= e($filterRole) ?>">
                          <input type="hidden" name="return_status" value="<?= e($filterStatus) ?>">
                          <input type="hidden" name="return_q" value="<?= e($search) ?>">

                          <button type="submit" class="btn btn-success btn-sm py-0 px-2" title="Approve">
                            <i class="bi bi-check-lg"></i>
                          </button>
                        </form>

                        <form method="POST" class="d-inline"
                              onsubmit="return confirm('Delete this account?');">
                          <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
                          <input type="hidden" name="action" value="delete">
                          <input type="hidden" name="admin_id" value="<?= (int) $u['admin_id'] ?>">
                          <input type="hidden" name="return_role" value="<?= e($filterRole) ?>">
                          <input type="hidden" name="return_status" value="<?= e($filterStatus) ?>">
                          <input type="hidden" name="return_q" value="<?= e($search) ?>">

                          <button type="submit" class="btn btn-danger btn-sm py-0 px-2" title="Delete">
                            <i class="bi bi-trash-fill"></i>
                          </button>
                        </form>

                      <?php elseif ($u['status'] === 'Active'): ?>

                        <form method="POST" class="d-inline"
                              onsubmit="return confirm('Suspend this account?');">
                          <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
                          <input type="hidden" name="action" value="suspend">
                          <input type="hidden" name="admin_id" value="<?= (int) $u['admin_id'] ?>">
                          <input type="hidden" name="return_role" value="<?= e($filterRole) ?>">
                          <input type="hidden" name="return_status" value="<?= e($filterStatus) ?>">
                          <input type="hidden" name="return_q" value="<?= e($search) ?>">

                          <button type="submit" class="btn btn-warning btn-sm py-0 px-2" title="Suspend">
                            <i class="bi bi-pause-fill"></i>
                          </button>
                        </form>

                        <form method="POST" class="d-inline"
                              onsubmit="return confirm('Permanently delete this account?');">
                          <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
                          <input type="hidden" name="action" value="delete">
                          <input type="hidden" name="admin_id" value="<?= (int) $u['admin_id'] ?>">
                          <input type="hidden" name="return_role" value="<?= e($filterRole) ?>">
                          <input type="hidden" name="return_status" value="<?= e($filterStatus) ?>">
                          <input type="hidden" name="return_q" value="<?= e($search) ?>">

                          <button type="submit" class="btn btn-danger btn-sm py-0 px-2" title="Delete">
                            <i class="bi bi-trash-fill"></i>
                          </button>
                        </form>

                      <?php elseif ($u['status'] === 'Suspended'): ?>

                        <form method="POST" class="d-inline">
                          <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
                          <input type="hidden" name="action" value="activate">
                          <input type="hidden" name="admin_id" value="<?= (int) $u['admin_id'] ?>">
                          <input type="hidden" name="return_role" value="<?= e($filterRole) ?>">
                          <input type="hidden" name="return_status" value="<?= e($filterStatus) ?>">
                          <input type="hidden" name="return_q" value="<?= e($search) ?>">

                          <button type="submit" class="btn btn-success btn-sm py-0 px-2" title="Re-activate">
                            <i class="bi bi-play-fill"></i>
                          </button>
                        </form>

                        <form method="POST" class="d-inline"
                              onsubmit="return confirm('Permanently delete this account?');">
                          <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
                          <input type="hidden" name="action" value="delete">
                          <input type="hidden" name="admin_id" value="<?= (int) $u['admin_id'] ?>">
                          <input type="hidden" name="return_role" value="<?= e($filterRole) ?>">
                          <input type="hidden" name="return_status" value="<?= e($filterStatus) ?>">
                          <input type="hidden" name="return_q" value="<?= e($search) ?>">

                          <button type="submit" class="btn btn-danger btn-sm py-0 px-2" title="Delete">
                            <i class="bi bi-trash-fill"></i>
                          </button>
                        </form>

                      <?php endif; ?>

                    </div>

                    <?php endif; ?>

                  </td>
                </tr>

                <?php endforeach; ?>

              </tbody>
            </table>
          </div>

          <div class="p-2 text-muted small text-end">
            Showing <?= count($users) ?> account(s)
          </div>

          <?php endif; ?>

        </div>
      </div>
    </div><!-- /col-lg-8 -->

  </div><!-- /row -->
</div>


<script>
function toggleRoleFields(role) {
    const deptField = document.getElementById('deptField');
    const hallField = document.getElementById('hallField');
    const deptSelect = document.getElementById('deptSelect');
    const hallName = document.getElementById('hallNameField');

    const isDeptAdmin = role === 'dept_admin';
    const isHallAdmin = role === 'hall_admin';

    deptField.classList.toggle(
        'd-none',
        !isDeptAdmin
    );

    hallField.classList.toggle(
        'd-none',
        !isHallAdmin
    );

    deptSelect.required = isDeptAdmin;
    hallName.required = isHallAdmin;

    if (!isDeptAdmin) {
        deptSelect.value = '';
    }

    if (!isHallAdmin) {
        hallName.value = '';
    }
}


function toggleNewPass() {
    const field =
        document.getElementById(
            'newPassField'
        );

    const eye =
        document.getElementById(
            'newPassEye'
        );

    field.type =
        field.type === 'password'
            ? 'text'
            : 'password';

    eye.className =
        field.type === 'password'
            ? 'bi bi-eye'
            : 'bi bi-eye-slash';
}
</script>


<?php require_once '../includes/footer.php'; ?>
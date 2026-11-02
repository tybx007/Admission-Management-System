<?php

declare(strict_types=1);

$pageTitle = 'Super Admin Dashboard';

require_once '../includes/auth.php';
require_once '../config/db.php';

/*
|--------------------------------------------------------------------------
| Authentication BEFORE HTML output
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

$currentAdminId = (int) $_SESSION['user_id'];
$currentAdminName = trim(
    (string) ($_SESSION['name'] ?? 'Super Admin')
);

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
| Quick approve / suspend admin
|--------------------------------------------------------------------------
*/

if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    isset($_POST['action'], $_POST['admin_id'])
) {

    try {

        $submittedToken =
            (string) ($_POST['csrf_token'] ?? '');

        if (
            $submittedToken === '' ||
            !hash_equals(
                (string) $_SESSION['csrf_token'],
                $submittedToken
            )
        ) {
            throw new RuntimeException(
                'Invalid request token.'
            );
        }

        $targetId = (int) $_POST['admin_id'];
        $action = trim(
            (string) $_POST['action']
        );

        if ($targetId <= 0) {
            throw new RuntimeException(
                'Invalid administrator account.'
            );
        }

        if ($targetId === $currentAdminId) {
            throw new RuntimeException(
                'You cannot change your own account status from this action.'
            );
        }

        if ($action === 'approve') {

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
                $currentAdminId,
                $targetId
            ]);

            if ($stmt->rowCount() !== 1) {
                throw new RuntimeException(
                    'The admin request is no longer pending.'
                );
            }

            $_SESSION['flash'] = [
                'type' => 'success',
                'msg' =>
                    'Admin account approved successfully.'
            ];

        } elseif ($action === 'reject') {

            $stmt = $pdo->prepare("
                UPDATE admin_users
                SET
                    status = 'Suspended',
                    approved_by = ?
                WHERE admin_id = ?
                  AND status = 'Pending'
            ");

            $stmt->execute([
                $currentAdminId,
                $targetId
            ]);

            if ($stmt->rowCount() !== 1) {
                throw new RuntimeException(
                    'The admin request is no longer pending.'
                );
            }

            $_SESSION['flash'] = [
                'type' => 'danger',
                'msg' =>
                    'Admin account rejected / suspended.'
            ];

        } else {

            throw new RuntimeException(
                'Invalid administrator action.'
            );
        }

    } catch (Throwable $exception) {

        $_SESSION['flash'] = [
            'type' => 'danger',
            'msg' => $exception->getMessage()
        ];
    }

    header('Location: dashboard.php');
    exit;
}


/*
|--------------------------------------------------------------------------
| Flash
|--------------------------------------------------------------------------
*/

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);


/*
|--------------------------------------------------------------------------
| System statistics
|--------------------------------------------------------------------------
|
| Imported candidate count is the correct admission-pool count.
| Registered applicants are shown separately.
|--------------------------------------------------------------------------
*/

$totalImported = (int) $pdo
    ->query("
        SELECT COUNT(*)
        FROM gst_imported_candidates
        WHERE selection_status = 'Selected'
    ")
    ->fetchColumn();

$totalRegistered = (int) $pdo
    ->query("
        SELECT COUNT(*)
        FROM gst_applicants
    ")
    ->fetchColumn();

$totalVivaSelected = (int) $pdo
    ->query("
        SELECT COUNT(DISTINCT applicant_id)
        FROM viva_results
        WHERE viva_status = 'Selected'
    ")
    ->fetchColumn();

$totalAllocated = (int) $pdo
    ->query("
        SELECT COUNT(*)
        FROM merit_allocations
        WHERE allocation_status IN (
            'Allocated',
            'Admitted'
        )
    ")
    ->fetchColumn();

$totalAdmitted = (int) $pdo
    ->query("
        SELECT COUNT(*)
        FROM merit_allocations
        WHERE allocation_status = 'Admitted'
    ")
    ->fetchColumn();

$totalWaiting = (int) $pdo
    ->query("
        SELECT COUNT(*)
        FROM waiting_list
        WHERE waiting_status = 'Waiting'
    ")
    ->fetchColumn();

$pendingAdmins = (int) $pdo
    ->query("
        SELECT COUNT(*)
        FROM admin_users
        WHERE status = 'Pending'
    ")
    ->fetchColumn();

$totalFaculties = (int) $pdo
    ->query("
        SELECT COUNT(*)
        FROM faculties
    ")
    ->fetchColumn();

$totalDepts = (int) $pdo
    ->query("
        SELECT COUNT(*)
        FROM departments
    ")
    ->fetchColumn();


/*
|--------------------------------------------------------------------------
| Faculty-wise admission breakdown
|--------------------------------------------------------------------------
|
| Merit allocations are linked through import_candidate_id.
| Viva remains linked through the registered applicant_id.
|--------------------------------------------------------------------------
*/

$facultyStatsStmt = $pdo->query("
    SELECT
        f.faculty_id,
        f.faculty_name,

        COUNT(
            DISTINCT gic.import_candidate_id
        ) AS total_candidates,

        COUNT(
            DISTINCT CASE
                WHEN v.viva_status = 'Selected'
                THEN gic.import_candidate_id
            END
        ) AS viva_selected,

        COUNT(
            DISTINCT CASE
                WHEN ma.allocation_status IN (
                    'Allocated',
                    'Admitted'
                )
                THEN ma.import_candidate_id
            END
        ) AS allocated,

        COUNT(
            DISTINCT CASE
                WHEN ma.allocation_status = 'Admitted'
                THEN ma.import_candidate_id
            END
        ) AS admitted

    FROM faculties f

    LEFT JOIN gst_imported_candidates gic
        ON gic.faculty_id = f.faculty_id
       AND gic.selection_status = 'Selected'

    LEFT JOIN merit_allocations ma
        ON ma.import_candidate_id =
           gic.import_candidate_id

    LEFT JOIN viva_results v
        ON v.applicant_id =
           gic.applicant_id

    GROUP BY
        f.faculty_id,
        f.faculty_name

    ORDER BY
        f.faculty_id
");

$facultyStats = $facultyStatsStmt->fetchAll(
    PDO::FETCH_ASSOC
);


/*
|--------------------------------------------------------------------------
| Pending admin requests
|--------------------------------------------------------------------------
*/

$pendingStmt = $pdo->query("
    SELECT
        a.admin_id,
        a.full_name,
        a.username,
        a.email,
        a.role,
        a.requested_at,
        d.dept_name

    FROM admin_users a

    LEFT JOIN departments d
        ON d.dept_id = a.dept_id

    WHERE a.status = 'Pending'

    ORDER BY
        a.requested_at ASC

    LIMIT 8
");

$pendingList = $pendingStmt->fetchAll(
    PDO::FETCH_ASSOC
);


/*
|--------------------------------------------------------------------------
| Active admin counts by role
|--------------------------------------------------------------------------
*/

$adminCountsStmt = $pdo->query("
    SELECT
        role,
        COUNT(*) AS cnt
    FROM admin_users
    WHERE status = 'Active'
    GROUP BY role
");

$adminCounts = [];

foreach (
    $adminCountsStmt->fetchAll(
        PDO::FETCH_ASSOC
    ) as $row
) {
    $adminCounts[
        (string) $row['role']
    ] = (int) $row['cnt'];
}


/*
|--------------------------------------------------------------------------
| Layout after all redirects / POST processing
|--------------------------------------------------------------------------
*/

require_once '../includes/header.php';
require_once '../includes/navbar.php';

?>




<div class="container py-4">


    <!-- =========================================================
         WELCOME BANNER
    ========================================================== -->

    <div
        class="alert alert-primary d-flex align-items-center justify-content-between
               flex-wrap gap-2 mb-4"
    >

        <div class="d-flex align-items-center gap-3">

            <div
                class="d-flex align-items-center justify-content-center"
                style="width:46px;height:46px;"
            >
                <i
                    class="bi bi-shield-lock-fill fs-4"
                ></i>
            </div>

            <div>

                <div class="fw-bold fs-5">
                    Super Admin Panel
                </div>

                <div class="small">
                    Welcome,
                    <?= e($currentAdminName) ?>
                </div>

            </div>

        </div>


        <div class="text-end">

            <small class="opacity-75 d-block">
                JKKNIU Admission Management System
            </small>

            <small>
                <?= date('d M Y, h:i A') ?>
            </small>

        </div>

    </div>


    <!-- =========================================================
         FLASH
    ========================================================== -->

    <?php if ($flash): ?>

        <div
            class="alert alert-<?= e($flash['type']) ?>
                   alert-dismissible fade show
                   d-flex align-items-center gap-2"
            role="alert"
        >

            <i
                class="bi bi-<?= $flash['type'] === 'success'
                    ? 'check-circle-fill'
                    : 'x-circle-fill'
                ?>"
            ></i>

            <?= e($flash['msg']) ?>

            <button
                type="button"
                class="btn-close ms-auto"
                data-bs-dismiss="alert"
            ></button>

        </div>

    <?php endif; ?>


    <!-- =========================================================
         SYSTEM OVERVIEW
    ========================================================== -->

    <div class="card shadow-sm mb-4">

        <div class="card-header bg-dark py-2 fw-semibold">

            <i class="bi bi-ui-checks text-primary me-1"></i>
            System Overview

        </div>


        <div class="card-body py-4">

            <?php
            $stats = [
                [
                    'label' => 'GST Candidates',
                    'icon' => 'bi-people-fill',
                    'value' => $totalImported,
                    'color' => 'text-primary',
                    'bg' => 'bg-primary'
                ],
                [
                    'label' => 'Registered',
                    'icon' => 'bi-person-check-fill',
                    'value' => $totalRegistered,
                    'color' => 'text-success',
                    'bg' => 'bg-success'
                ],
                [
                    'label' => 'Viva Selected',
                    'icon' => 'bi-person-badge-fill',
                    'value' => $totalVivaSelected,
                    'color' => 'text-info',
                    'bg' => 'bg-info'
                ],
                [
                    'label' => 'Allocated',
                    'icon' => 'bi-award-fill',
                    'value' => $totalAllocated,
                    'color' => 'text-primary',
                    'bg' => 'bg-primary'
                ],
                [
                    'label' => 'Admitted',
                    'icon' => 'bi-mortarboard-fill',
                    'value' => $totalAdmitted,
                    'color' => 'text-warning',
                    'bg' => 'bg-warning'
                ],
                [
                    'label' => 'Waiting',
                    'icon' => 'bi-hourglass-split',
                    'value' => $totalWaiting,
                    'color' => 'text-secondary',
                    'bg' => 'bg-secondary'
                ],
            ];
            ?>

            <div class="row g-3 text-center">

                <?php foreach ($stats as $stat): ?>

                    <div class="col-6 col-md-4 col-xl-2">

                        <div
                            class="d-flex flex-column align-items-center gap-2"
                        >

                            <div
                                class="rounded-circle d-flex align-items-center justify-content-center <?= e($stat['bg']) ?>"
                            >

                                <i class="bi <?= e($stat['icon']) ?> fs-4 text-white"></i>

                            </div>

                            <small
                                class="fw-semibold <?= e($stat['color']) ?>"
                            >
                                <?= e($stat['label']) ?>
                            </small>

                            <span
                                class="badge <?= e($stat['bg']) ?> px-3 py-2"
                            >
                                <?= number_format(
                                    (int) $stat['value']
                                ) ?>
                            </span>

                        </div>

                    </div>

                <?php endforeach; ?>

            </div>

        </div>

    </div>


    <div class="row g-4">


        <!-- =====================================================
             LEFT COLUMN
        ====================================================== -->

        <div class="col-lg-4">


            <!-- ADMIN PROFILE -->

            <div class="card shadow-sm mb-4">

                <div class="card-body text-center p-4">

                    <div
                        class="rounded-circle bg-primary d-flex align-items-center
                               justify-content-center mx-auto mb-3"
                        style="width:90px;height:90px;"
                    >

                        <i
                            class="bi bi-shield-lock-fill
                                   fs-1 text-white"
                        ></i>

                    </div>


                    <h5 class="fw-bold mb-1">

                        <?= e($currentAdminName) ?>

                    </h5>


                    <p class="text-muted small mb-3">
                        Super Administrator
                    </p>


                    <div
                        class="row g-0 border rounded overflow-hidden my-3"
                    >

                        <div class="col-4 py-3 border-end">

                            <div class="fw-bold fs-5 text-primary">
                                <?= $totalFaculties ?>
                            </div>

                            <small class="text-muted">
                                Faculties
                            </small>

                        </div>


                        <div class="col-4 py-3 border-end">

                            <div class="fw-bold fs-5 text-success">
                                <?= $totalDepts ?>
                            </div>

                            <small class="text-muted">
                                Departments
                            </small>

                        </div>


                        <div class="col-4 py-3">

                            <div class="fw-bold fs-5 text-warning">
                                <?= number_format($totalImported) ?>
                            </div>

                            <small class="text-muted">
                                Candidates
                            </small>

                        </div>

                    </div>


                    <span
                        class="badge <?= e($stat['bg']) ?> px-3 py-2"
                    >

                        <i
                            class="bi bi-shield-fill-check me-1"
                        ></i>

                        Super Admin

                    </span>

                </div>

            </div>


            <!-- ACTIVE ADMIN ACCOUNTS -->

            <div class="card shadow-sm mb-4">

                <div class="card-header bg-dark py-2 fw-semibold">

                    <i class="bi bi-people-fill text-primary me-1"></i>
                    Active Admin Accounts

                </div>


                <div class="card-body p-0">

                    <?php
                    $roles = [
                        [
                            'key' => 'dept_admin',
                            'label' => 'Dept Admin',
                            'icon' => 'bi-building',
                            'color' => 'text-primary'
                        ],
                        [
                            'key' => 'hall_admin',
                            'label' => 'Hall Admin',
                            'icon' => 'bi-house-fill',
                            'color' => 'text-success'
                        ],
                        [
                            'key' => 'registrar',
                            'label' => 'Registrar',
                            'icon' => 'bi-person-badge-fill',
                            'color' => 'text-info'
                        ],
                        [
                            'key' => 'super_admin',
                            'label' => 'Super Admin',
                            'icon' => 'bi-shield-lock-fill',
                            'color' => 'text-warning'
                        ],
                    ];
                    ?>


                    <div class="row g-0 text-center">

                        <?php foreach ($roles as $role): ?>

                            <div
                                class="col-6 py-3 border-bottom border-end"
                            >

                                <i
                                    class="bi <?= e($role['icon']) ?> fs-4 <?= e($role['color']) ?>"
                                ></i>

                                <div
                                    class="fw-bold fs-5"
                                >
                                    <?= $adminCounts[
                                        $role['key']
                                    ] ?? 0 ?>
                                </div>

                                <small class="text-muted">
                                    <?= e($role['label']) ?>
                                </small>

                            </div>

                        <?php endforeach; ?>

                    </div>

                </div>

            </div>


            <!-- QUICK ACTIONS -->

            <div class="card shadow-sm">

                <div class="card-header bg-dark py-2 fw-semibold">

                    <i class="bi bi-lightning-fill text-warning me-1"></i>
                    Quick Actions

                </div>


                <div class="list-group list-group-flush">


                    <a
                        href="manageusers.php"
                        class="list-group-item list-group-item-action py-2"
                    >

                        <i
                            class="bi bi-people-fill text-primary me-2"
                        ></i>

                        Manage Users

                        <?php if ($pendingAdmins > 0): ?>

                            <span
                                class="badge bg-danger float-end"
                            >
                                <?= $pendingAdmins ?> Pending
                            </span>

                        <?php endif; ?>

                    </a>


                    <a
                        href="managefaculties.php"
                        class="list-group-item list-group-item-action py-2"
                    >

                        <i
                            class="bi bi-diagram-3-fill text-success me-2"
                        ></i>

                        Manage Faculties

                    </a>


                    <a
                        href="managedepartments.php"
                        class="list-group-item list-group-item-action py-2"
                    >

                        <i
                            class="bi bi-grid-fill text-info me-2"
                        ></i>

                        Manage Departments

                    </a>


                    <a
                        href="reports.php"
                        class="list-group-item list-group-item-action py-2"
                    >

                        <i
                            class="bi bi-file-earmark-bar-graph-fill text-warning me-2"
                        ></i>

                        View Reports

                    </a>


                    <a
                        href="manageusers.php?role=dept_admin"
                        class="list-group-item list-group-item-action py-2"
                    >

                        <i
                            class="bi bi-building text-secondary me-2"
                        ></i>

                        Department Admins

                    </a>


                    <a
                        href="manageusers.php?role=hall_admin"
                        class="list-group-item list-group-item-action py-2"
                    >

                        <i
                            class="bi bi-house-fill text-secondary me-2"
                        ></i>

                        Hall Admins

                    </a>


                    <a
                        href="manageusers.php?role=registrar"
                        class="list-group-item list-group-item-action py-2"
                    >

                        <i
                            class="bi bi-person-badge-fill text-secondary me-2"
                        ></i>

                        Registrar Accounts

                    </a>


                    <a
                        href="logout.php"
                        class="list-group-item list-group-item-action
                               py-2 text-danger"
                    >

                        <i
                            class="bi bi-box-arrow-right me-2"
                        ></i>

                        Logout

                    </a>

                </div>

            </div>

        </div>


        <!-- =====================================================
             RIGHT COLUMN
        ====================================================== -->

        <div class="col-lg-8">


            <!-- FACULTY-WISE PROGRESS -->

            <div class="card shadow-sm mb-4">

                <div class="card-header bg-dark py-2 fw-semibold">

                    <i class="bi bi-bar-chart-fill text-primary me-1"></i>
                    Faculty-wise Admission Progress

                </div>


                <div class="card-body p-0">

                    <div class="table-responsive">

                        <table
                            class="table table-sm table-bordered
                                   mb-0 align-middle faculty-progress"
                        >

                            <thead class="table-light">

                                <tr>

                                    <th>Faculty</th>

                                    <th class="text-center">
                                        Candidates
                                    </th>

                                    <th class="text-center">
                                        Viva Selected
                                    </th>

                                    <th class="text-center">
                                        Allocated
                                    </th>

                                    <th class="text-center">
                                        Admitted
                                    </th>

                                    <th class="text-center">
                                        Progress
                                    </th>

                                </tr>

                            </thead>


                            <tbody>

                                <?php foreach (
                                    $facultyStats as $row
                                ): ?>

                                    <?php

                                    $total =
                                        (int)
                                        $row[
                                            'total_candidates'
                                        ];

                                    $admitted =
                                        (int)
                                        $row['admitted'];

                                    $percentage =
                                        $total > 0
                                            ? (int) round(
                                                (
                                                    $admitted /
                                                    $total
                                                ) * 100
                                            )
                                            : 0;

                                    ?>

                                    <tr>

                                        <td class="fw-semibold small">

                                            <?= e(
                                                $row[
                                                    'faculty_name'
                                                ]
                                            ) ?>

                                        </td>


                                        <td class="text-center">

                                            <?= number_format(
                                                $total
                                            ) ?>

                                        </td>


                                        <td class="text-center">

                                            <span class="badge bg-success">
                                                <?= number_format(
                                                    (int)
                                                    $row[
                                                        'viva_selected'
                                                    ]
                                                ) ?>
                                            </span>

                                        </td>


                                        <td class="text-center">

                                            <span class="badge bg-info text-dark">
                                                <?= number_format(
                                                    (int)
                                                    $row[
                                                        'allocated'
                                                    ]
                                                ) ?>
                                            </span>

                                        </td>


                                        <td class="text-center">

                                            <span class="badge bg-warning text-dark">
                                                <?= number_format(
                                                    $admitted
                                                ) ?>
                                            </span>

                                        </td>


                                        <td
                                            style="min-width:110px;"
                                        >

                                            <div
                                                class="progress"
                                                style="height:7px;"
                                            >

                                                <div
                                                    class="progress-bar bg-success"
                                                    style="width:<?= $percentage ?>%;"
                                                ></div>

                                            </div>

                                            <small
                                                class="text-muted"
                                            >
                                                <?= $percentage ?>%
                                            </small>

                                        </td>

                                    </tr>

                                <?php endforeach; ?>

                            </tbody>

                        </table>

                    </div>

                </div>

            </div>


            <!-- PENDING ADMIN REQUESTS -->

            <div class="card shadow-sm mb-4">

                <div
                    class="card-header bg-dark py-2
                           d-flex justify-content-between
                           align-items-center"
                >

                    <span class="fw-semibold">

                        <i
                            class="bi bi-person-exclamation text-danger me-1"
                        ></i>

                        Pending Admin Approval Requests

                    </span>


                    <?php if ($pendingAdmins > 0): ?>

                        <span
                            class="badge bg-warning text-dark"
                        >
                            <?= $pendingAdmins ?> Pending
                        </span>

                    <?php endif; ?>

                </div>


                <div
                    class="card-body <?= $pendingList
                        ? 'p-0'
                        : ''
                    ?>"
                >

                    <?php if (!$pendingList): ?>

                        <div
                            class="text-center py-5 text-muted"
                        >

                            <i
                                class="bi bi-check-circle-fill
                                       text-success fs-1
                                       d-block mb-2"
                            ></i>

                            No pending approval requests.

                        </div>


                    <?php else: ?>


                        <div class="table-responsive">

                            <table
                                class="table table-sm table-bordered
                                       mb-0 align-middle"
                            >

                                <thead class="table-light">

                                    <tr>

                                        <th>#</th>
                                        <th>Full Name</th>
                                        <th>Username</th>
                                        <th>Role</th>
                                        <th>Department</th>
                                        <th>Requested</th>

                                        <th class="text-center">
                                            Action
                                        </th>

                                    </tr>

                                </thead>


                                <tbody>

                                    <?php foreach (
                                        $pendingList as
                                        $index => $request
                                    ): ?>

                                        <tr>

                                            <td>
                                                <?= $index + 1 ?>
                                            </td>


                                            <td
                                                class="fw-semibold small"
                                            >
                                                <?= e(
                                                    $request[
                                                        'full_name'
                                                    ]
                                                ) ?>
                                            </td>


                                            <td>

                                                <small>
                                                    @<?= e(
                                                        $request[
                                                            'username'
                                                        ]
                                                    ) ?>
                                                </small>

                                            </td>


                                            <td>

                                                <span
                                                    class="badge bg-secondary"
                                                >
                                                    <?= e(
                                                        $request[
                                                            'role'
                                                        ]
                                                    ) ?>
                                                </span>

                                            </td>


                                            <td>

                                                <small>
                                                    <?= e(
                                                        $request[
                                                            'dept_name'
                                                        ]
                                                        ?? '—'
                                                    ) ?>
                                                </small>

                                            </td>


                                            <td>

                                                <small>

                                                    <?= !empty(
                                                        $request[
                                                            'requested_at'
                                                        ]
                                                    )
                                                        ? date(
                                                            'd M Y',
                                                            strtotime(
                                                                (string)
                                                                $request[
                                                                    'requested_at'
                                                                ]
                                                            )
                                                        )
                                                        : '—'
                                                    ?>

                                                </small>

                                            </td>


                                            <td class="text-center">

                                                <div
                                                    class="d-flex gap-1
                                                           justify-content-center"
                                                >

                                                    <form
                                                        method="POST"
                                                        class="d-inline"
                                                    >

                                                        <input
                                                            type="hidden"
                                                            name="csrf_token"
                                                            value="<?= e(
                                                                $_SESSION[
                                                                    'csrf_token'
                                                                ]
                                                            ) ?>"
                                                        >

                                                        <input
                                                            type="hidden"
                                                            name="admin_id"
                                                            value="<?= (int)
                                                                $request[
                                                                    'admin_id'
                                                                ]
                                                            ?>"
                                                        >

                                                        <input
                                                            type="hidden"
                                                            name="action"
                                                            value="approve"
                                                        >

                                                        <button
                                                            type="submit"
                                                            class="btn btn-success
                                                                   btn-sm py-0 px-2"
                                                            title="Approve"
                                                        >

                                                            <i
                                                                class="bi bi-check-lg"
                                                            ></i>

                                                        </button>

                                                    </form>


                                                    <form
                                                        method="POST"
                                                        class="d-inline"
                                                        onsubmit="
                                                            return confirm(
                                                                'Reject and suspend this administrator request?'
                                                            );
                                                        "
                                                    >

                                                        <input
                                                            type="hidden"
                                                            name="csrf_token"
                                                            value="<?= e(
                                                                $_SESSION[
                                                                    'csrf_token'
                                                                ]
                                                            ) ?>"
                                                        >

                                                        <input
                                                            type="hidden"
                                                            name="admin_id"
                                                            value="<?= (int)
                                                                $request[
                                                                    'admin_id'
                                                                ]
                                                            ?>"
                                                        >

                                                        <input
                                                            type="hidden"
                                                            name="action"
                                                            value="reject"
                                                        >

                                                        <button
                                                            type="submit"
                                                            class="btn btn-outline-danger
                                                                   btn-sm py-0 px-2"
                                                            title="Reject"
                                                        >

                                                            <i
                                                                class="bi bi-x-lg"
                                                            ></i>

                                                        </button>

                                                    </form>

                                                </div>

                                            </td>

                                        </tr>

                                    <?php endforeach; ?>

                                </tbody>

                            </table>

                        </div>


                        <div class="p-3 text-end">

                            <a
                                href="manageusers.php"
                                class="btn btn-sm btn-outline-primary"
                            >

                                <i
                                    class="bi bi-people-fill me-1"
                                ></i>

                                View All Users

                            </a>

                        </div>

                    <?php endif; ?>

                </div>

            </div>

        </div>

    </div>

</div>


<?php require_once '../includes/footer.php'; ?>
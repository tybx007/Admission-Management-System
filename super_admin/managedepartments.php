<?php

declare(strict_types=1);

$pageTitle = 'Manage Departments';

require_once '../includes/auth.php';
require_once '../config/db.php';

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

function cleanDeptCode(mixed $value): string
{
    $value = strtoupper(
        trim((string) $value)
    );

    $value = preg_replace(
        '/[^A-Z0-9\-]/',
        '',
        $value
    ) ?? '';

    if ($value === '') {
        throw new RuntimeException(
            'Department code is required.'
        );
    }

    if (strlen($value) > 20) {
        throw new RuntimeException(
            'Department code cannot exceed 20 characters.'
        );
    }

    return $value;
}

function cleanDeptName(mixed $value): string
{
    $value = trim((string) $value);

    if ($value === '') {
        throw new RuntimeException(
            'Department name is required.'
        );
    }

    if (
        mb_strlen(
            $value,
            'UTF-8'
        ) > 150
    ) {
        throw new RuntimeException(
            'Department name is too long.'
        );
    }

    return $value;
}

function redirectToDepartmentPage(
    int $facultyId = 0
): never {
    $url = 'managedepartments.php';

    if ($facultyId > 0) {
        $url .= '?faculty_id=' .
            $facultyId;
    }

    header('Location: ' . $url);
    exit;
}


/*
|--------------------------------------------------------------------------
| Faculty filter
|--------------------------------------------------------------------------
*/

$filterFacultyId = (int) (
    $_GET['faculty_id']
    ?? $_POST['filter_faculty_id']
    ?? 0
);


/*
|--------------------------------------------------------------------------
| POST actions
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    try {

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

        $deptId = (int) (
            $_POST['dept_id'] ?? 0
        );

        /*
        |--------------------------------------------------------------------------
        | CREATE
        |--------------------------------------------------------------------------
        */

        if ($action === 'create') {

            $facultyId = (int) (
                $_POST['faculty_id'] ?? 0
            );

            $deptCode = cleanDeptCode(
                $_POST['dept_code'] ?? ''
            );

            $deptName = cleanDeptName(
                $_POST['dept_name'] ?? ''
            );

            $totalSeats = (int) (
                $_POST['total_seats'] ?? 45
            );

            if ($facultyId <= 0) {
                throw new RuntimeException(
                    'Faculty is required.'
                );
            }

            if (
                $totalSeats < 1 ||
                $totalSeats > 500
            ) {
                throw new RuntimeException(
                    'Total seats must be between 1 and 500.'
                );
            }


            /*
            | Confirm faculty exists.
            */

            $facultyCheck = $pdo->prepare("
                SELECT faculty_id
                FROM faculties
                WHERE faculty_id = ?
                LIMIT 1
            ");

            $facultyCheck->execute([
                $facultyId
            ]);

            if (!$facultyCheck->fetchColumn()) {
                throw new RuntimeException(
                    'Selected faculty does not exist.'
                );
            }


            /*
            | Department code is globally unique.
            */

            $codeCheck = $pdo->prepare("
                SELECT dept_id
                FROM departments
                WHERE dept_code = ?
                LIMIT 1
            ");

            $codeCheck->execute([
                $deptCode
            ]);

            if ($codeCheck->fetchColumn()) {
                throw new RuntimeException(
                    'This department code already exists.'
                );
            }


            /*
            | Name must not be duplicated inside the same faculty.
            */

            $nameCheck = $pdo->prepare("
                SELECT dept_id
                FROM departments
                WHERE dept_name = ?
                  AND faculty_id = ?
                LIMIT 1
            ");

            $nameCheck->execute([
                $deptName,
                $facultyId
            ]);

            if ($nameCheck->fetchColumn()) {
                throw new RuntimeException(
                    'A department with this name already exists in the selected faculty.'
                );
            }


            $insert = $pdo->prepare("
                INSERT INTO departments (
                    faculty_id,
                    dept_code,
                    dept_name,
                    total_seats
                )
                VALUES (?, ?, ?, ?)
            ");

            $insert->execute([
                $facultyId,
                $deptCode,
                $deptName,
                $totalSeats
            ]);


            $_SESSION['flash'] = [
                'type' => 'success',
                'msg' =>
                    "Department {$deptCode} — {$deptName} created successfully."
            ];


            redirectToDepartmentPage(
                $filterFacultyId
                    ?: $facultyId
            );
        }


        /*
        |--------------------------------------------------------------------------
        | EDIT
        |--------------------------------------------------------------------------
        */

        if ($action === 'edit') {

            if ($deptId <= 0) {
                throw new RuntimeException(
                    'Invalid department.'
                );
            }

            $facultyId = (int) (
                $_POST['faculty_id'] ?? 0
            );

            $deptCode = cleanDeptCode(
                $_POST['dept_code'] ?? ''
            );

            $deptName = cleanDeptName(
                $_POST['dept_name'] ?? ''
            );

            $totalSeats = (int) (
                $_POST['total_seats'] ?? 0
            );

            if ($facultyId <= 0) {
                throw new RuntimeException(
                    'Faculty is required.'
                );
            }

            if (
                $totalSeats < 1 ||
                $totalSeats > 500
            ) {
                throw new RuntimeException(
                    'Total seats must be between 1 and 500.'
                );
            }


            /*
            | Check that the department exists.
            */

            $currentStmt = $pdo->prepare("
                SELECT
                    dept_id,
                    total_seats
                FROM departments
                WHERE dept_id = ?
                LIMIT 1
            ");

            $currentStmt->execute([
                $deptId
            ]);

            $currentDepartment =
                $currentStmt->fetch(
                    PDO::FETCH_ASSOC
                );

            if (!$currentDepartment) {
                throw new RuntimeException(
                    'Department was not found.'
                );
            }


            /*
            | Do not reduce seats below current active allocations.
            */

            $activeAllocationStmt =
                $pdo->prepare("
                    SELECT COUNT(*)
                    FROM merit_allocations
                    WHERE dept_id = ?
                      AND allocation_status IN (
                          'Allocated',
                          'Admitted'
                      )
                ");

            $activeAllocationStmt->execute([
                $deptId
            ]);

            $activeAllocationCount =
                (int)
                $activeAllocationStmt
                    ->fetchColumn();

            if (
                $totalSeats <
                $activeAllocationCount
            ) {
                throw new RuntimeException(
                    'Total seats cannot be reduced below the current active allocation count (' .
                    $activeAllocationCount .
                    ').'
                );
            }


            $codeCheck = $pdo->prepare("
                SELECT dept_id
                FROM departments
                WHERE dept_code = ?
                  AND dept_id <> ?
                LIMIT 1
            ");

            $codeCheck->execute([
                $deptCode,
                $deptId
            ]);

            if ($codeCheck->fetchColumn()) {
                throw new RuntimeException(
                    'Another department already uses this department code.'
                );
            }


            $nameCheck = $pdo->prepare("
                SELECT dept_id
                FROM departments
                WHERE dept_name = ?
                  AND faculty_id = ?
                  AND dept_id <> ?
                LIMIT 1
            ");

            $nameCheck->execute([
                $deptName,
                $facultyId,
                $deptId
            ]);

            if ($nameCheck->fetchColumn()) {
                throw new RuntimeException(
                    'Another department with this name already exists in the selected faculty.'
                );
            }


            $update = $pdo->prepare("
                UPDATE departments
                SET
                    faculty_id = ?,
                    dept_code = ?,
                    dept_name = ?,
                    total_seats = ?
                WHERE dept_id = ?
            ");

            $update->execute([
                $facultyId,
                $deptCode,
                $deptName,
                $totalSeats,
                $deptId
            ]);


            $_SESSION['flash'] = [
                'type' => 'success',
                'msg' =>
                    'Department updated successfully.'
            ];


            redirectToDepartmentPage(
                $filterFacultyId
            );
        }


        /*
        |--------------------------------------------------------------------------
        | DELETE
        |--------------------------------------------------------------------------
        */

        if ($action === 'delete') {

            if ($deptId <= 0) {
                throw new RuntimeException(
                    'Invalid department.'
                );
            }


            /*
            |--------------------------------------------------------------------------
            | Check every CURRENT relationship
            |--------------------------------------------------------------------------
            */

            $checks = [

                'GST candidate preferences' => "
                    SELECT COUNT(*)
                    FROM gst_candidate_choices
                    WHERE dept_id = ?
                ",

                'merit allocations' => "
                    SELECT COUNT(*)
                    FROM merit_allocations
                    WHERE dept_id = ?
                ",

                'waiting-list records' => "
                    SELECT COUNT(*)
                    FROM waiting_list
                    WHERE dept_id = ?
                ",

                'department admin accounts' => "
                    SELECT COUNT(*)
                    FROM admin_users
                    WHERE dept_id = ?
                ",
            ];


            foreach (
                $checks as
                $label => $sql
            ) {

                $check = $pdo->prepare($sql);

                $check->execute([
                    $deptId
                ]);

                $count = (int) $check
                    ->fetchColumn();

                if ($count > 0) {
                    throw new RuntimeException(
                        'Cannot delete this department because it has ' .
                        $label .
                        ' (' .
                        $count .
                        ').'
                    );
                }
            }


            $delete = $pdo->prepare("
                DELETE FROM departments
                WHERE dept_id = ?
            ");

            $delete->execute([
                $deptId
            ]);

            if ($delete->rowCount() !== 1) {
                throw new RuntimeException(
                    'Department could not be deleted.'
                );
            }


            $_SESSION['flash'] = [
                'type' => 'success',
                'msg' =>
                    'Department deleted successfully.'
            ];


            redirectToDepartmentPage(
                $filterFacultyId
            );
        }


        throw new RuntimeException(
            'Invalid department action.'
        );


    } catch (Throwable $exception) {

        $_SESSION['flash'] = [
            'type' => 'danger',
            'msg' =>
                $exception->getMessage()
        ];

        redirectToDepartmentPage(
            $filterFacultyId
        );
    }
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
| Faculties + department counts in ONE query
|--------------------------------------------------------------------------
*/

$facultyStmt = $pdo->query("
    SELECT
        f.faculty_id,
        f.faculty_name,
        COUNT(d.dept_id) AS department_count

    FROM faculties f

    LEFT JOIN departments d
        ON d.faculty_id = f.faculty_id

    GROUP BY
        f.faculty_id,
        f.faculty_name

    ORDER BY
        f.faculty_id
");

$allFaculties =
    $facultyStmt->fetchAll(
        PDO::FETCH_ASSOC
    );


/*
|--------------------------------------------------------------------------
| Validate faculty filter
|--------------------------------------------------------------------------
*/

$currentFaculty = null;

if ($filterFacultyId > 0) {

    foreach ($allFaculties as $faculty) {

        if (
            (int) $faculty['faculty_id']
            ===
            $filterFacultyId
        ) {
            $currentFaculty = $faculty;
            break;
        }
    }

    if ($currentFaculty === null) {
        $filterFacultyId = 0;
    }
}


/*
|--------------------------------------------------------------------------
| Department stats
|--------------------------------------------------------------------------
|
| IMPORTANT:
| - choices = gst_candidate_choices.import_candidate_id
| - allocations = merit_allocations.import_candidate_id
| - no old dept_choices dependency
|--------------------------------------------------------------------------
*/

$whereSql = '';
$params = [];

if ($filterFacultyId > 0) {
    $whereSql =
        'WHERE d.faculty_id = ?';

    $params[] =
        $filterFacultyId;
}


$departmentStmt = $pdo->prepare("
    SELECT
        d.dept_id,
        d.faculty_id,
        d.dept_code,
        d.dept_name,
        d.total_seats,
        d.created_at,

        f.faculty_name,

        COALESCE(
            choice_stats.choice_count,
            0
        ) AS choice_count,

        COALESCE(
            allocation_stats.allocated_count,
            0
        ) AS allocated_count,

        COALESCE(
            allocation_stats.admitted_count,
            0
        ) AS admitted_count,

        COALESCE(
            waiting_stats.waiting_count,
            0
        ) AS waiting_count,

        COALESCE(
            admin_stats.admin_count,
            0
        ) AS admin_count

    FROM departments d

    INNER JOIN faculties f
        ON f.faculty_id =
           d.faculty_id


    LEFT JOIN (
        SELECT
            dept_id,
            COUNT(
                DISTINCT import_candidate_id
            ) AS choice_count
        FROM gst_candidate_choices
        GROUP BY dept_id
    ) choice_stats
        ON choice_stats.dept_id =
           d.dept_id


    LEFT JOIN (
        SELECT
            dept_id,

            COUNT(
                DISTINCT CASE
                    WHEN allocation_status IN (
                        'Allocated',
                        'Admitted'
                    )
                    THEN import_candidate_id
                END
            ) AS allocated_count,

            COUNT(
                DISTINCT CASE
                    WHEN allocation_status =
                         'Admitted'
                    THEN import_candidate_id
                END
            ) AS admitted_count

        FROM merit_allocations

        GROUP BY dept_id
    ) allocation_stats
        ON allocation_stats.dept_id =
           d.dept_id


    LEFT JOIN (
        SELECT
            dept_id,
            COUNT(*) AS waiting_count
        FROM waiting_list
        WHERE waiting_status = 'Waiting'
        GROUP BY dept_id
    ) waiting_stats
        ON waiting_stats.dept_id =
           d.dept_id


    LEFT JOIN (
        SELECT
            dept_id,
            COUNT(*) AS admin_count
        FROM admin_users
        WHERE role = 'dept_admin'
          AND status = 'Active'
        GROUP BY dept_id
    ) admin_stats
        ON admin_stats.dept_id =
           d.dept_id


    {$whereSql}

    ORDER BY
        f.faculty_id,
        d.dept_name
");

$departmentStmt->execute(
    $params
);

$departments =
    $departmentStmt->fetchAll(
        PDO::FETCH_ASSOC
    );


/*
|--------------------------------------------------------------------------
| Summary values
|--------------------------------------------------------------------------
*/

$shownDepartments =
    count($departments);

$totalSeatsShown = 0;
$totalChoicesShown = 0;
$totalAllocatedShown = 0;
$totalAdmittedShown = 0;
$totalWaitingShown = 0;

foreach ($departments as $department) {

    $totalSeatsShown +=
        (int)
        $department['total_seats'];

    $totalChoicesShown +=
        (int)
        $department['choice_count'];

    $totalAllocatedShown +=
        (int)
        $department['allocated_count'];

    $totalAdmittedShown +=
        (int)
        $department['admitted_count'];

    $totalWaitingShown +=
        (int)
        $department['waiting_count'];
}


/*
|--------------------------------------------------------------------------
| HTML layout AFTER redirects / DB work
|--------------------------------------------------------------------------
*/

require_once '../includes/header.php';
require_once '../includes/navbar.php';

?>




<div class="container py-4">


    <!-- =========================================================
         BANNER
    ========================================================== -->

    <div
        class="alert alert-primary d-flex align-items-center
               justify-content-between flex-wrap gap-2 mb-4"
    >

        <div
            class="d-flex align-items-center gap-3"
        >

            <div
                class="d-flex align-items-center justify-content-center"
                style="width:46px;height:46px;"
            >

                <i
                    class="bi bi-grid-fill fs-4"
                ></i>

            </div>


            <div>

                <div class="fw-bold fs-5">
                    Manage Departments
                </div>


                <small>

                    <?php if ($currentFaculty): ?>

                        <?= e(
                            $currentFaculty[
                                'faculty_name'
                            ]
                        ) ?>

                    <?php else: ?>

                        All Faculties

                    <?php endif; ?>

                </small>

            </div>

        </div>


        <div class="d-flex gap-2">

            <a
                href="managefaculties.php"
                class="btn btn-sm btn-light"
            >

                <i
                    class="bi bi-diagram-3-fill me-1"
                ></i>

                Faculties

            </a>


            <a
                href="dashboard.php"
                class="btn btn-sm btn-light"
            >

                <i
                    class="bi bi-arrow-left me-1"
                ></i>

                Dashboard

            </a>

        </div>

    </div>


    <!-- =========================================================
         FLASH
    ========================================================== -->

    <?php if ($flash): ?>

        <div
            class="alert alert-<?= e(
                $flash['type']
            ) ?> alert-dismissible fade show
            d-flex align-items-center gap-2"
        >

            <i
                class="bi bi-<?= $flash['type'] ===
                    'success'
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


    <div class="row g-4">


        <!-- =====================================================
             LEFT
        ====================================================== -->

        <div class="col-lg-4">


            <!-- ADD DEPARTMENT -->

            <div class="card shadow-sm mb-4">

                <div
                    class="card-header bg-dark py-2 fw-semibold"
                >

                    <strong>

                        <i
                            class="bi bi-plus-circle-fill text-success me-1"
                        ></i>

                        Add New Department

                    </strong>

                </div>


                <div class="card-body">

                    <form method="POST">

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
                            name="action"
                            value="create"
                        >

                        <input
                            type="hidden"
                            name="filter_faculty_id"
                            value="<?= $filterFacultyId ?>"
                        >


                        <div class="mb-3">

                            <label
                                class="form-label fw-semibold"
                            >

                                Faculty

                                <span
                                    class="text-danger"
                                >
                                    *
                                </span>

                            </label>


                            <select
                                name="faculty_id"
                                class="form-select"
                                required
                            >

                                <option value="">
                                    -- Select Faculty --
                                </option>


                                <?php foreach (
                                    $allFaculties as
                                    $faculty
                                ): ?>

                                    <option
                                        value="<?= (int)
                                            $faculty[
                                                'faculty_id'
                                            ]
                                        ?>"
                                        <?= $filterFacultyId ===
                                            (int)
                                            $faculty[
                                                'faculty_id'
                                            ]
                                            ? 'selected'
                                            : ''
                                        ?>
                                    >

                                        <?= e(
                                            $faculty[
                                                'faculty_name'
                                            ]
                                        ) ?>

                                    </option>

                                <?php endforeach; ?>

                            </select>

                        </div>


                        <div class="row g-3">


                            <div class="col-4">

                                <label
                                    class="form-label fw-semibold"
                                >

                                    Code

                                    <span class="text-danger">
                                        *
                                    </span>

                                </label>


                                <input
                                    type="text"
                                    name="dept_code"
                                    class="form-control text-uppercase"
                                    maxlength="20"
                                    placeholder="CSE"
                                    required
                                >

                            </div>


                            <div class="col-8">

                                <label
                                    class="form-label fw-semibold"
                                >

                                    Department Name

                                    <span class="text-danger">
                                        *
                                    </span>

                                </label>


                                <input
                                    type="text"
                                    name="dept_name"
                                    class="form-control"
                                    maxlength="150"
                                    placeholder="Computer Science & Engineering"
                                    required
                                >

                            </div>

                        </div>


                        <div class="mt-3 mb-3">

                            <label
                                class="form-label fw-semibold"
                            >
                                Total Seats
                            </label>


                            <input
                                type="number"
                                name="total_seats"
                                class="form-control"
                                value="45"
                                min="1"
                                max="500"
                                required
                            >

                            <div class="form-text">
                                Default seat count: 45
                            </div>

                        </div>


                        <button
                            type="submit"
                            class="btn btn-success w-100 fw-bold"
                        >

                            <i
                                class="bi bi-plus-circle-fill me-1"
                            ></i>

                            Add Department

                        </button>

                    </form>

                </div>

            </div>


            <!-- FACULTY FILTER -->

            <div
                class="card shadow-sm mb-4
                       faculty-filter"
            >

                <div
                    class="card-header bg-dark py-2 fw-semibold"
                >

                    <strong>

                        <i
                            class="bi bi-funnel-fill text-warning me-1"
                        ></i>

                        Filter by Faculty

                    </strong>

                </div>


                <div
                    class="list-group
                           list-group-flush"
                >

                    <a
                        href="managedepartments.php"
                        class="list-group-item
                               list-group-item-action
                               d-flex justify-content-between
                               align-items-center
                               <?= $filterFacultyId === 0
                                   ? 'active'
                                   : ''
                               ?>"
                    >

                        <span>

                            <i
                                class="bi bi-grid-fill me-2"
                            ></i>

                            All Faculties

                        </span>

                        <span
                            class="badge
                                   <?= $filterFacultyId === 0
                                       ? 'bg-light text-dark'
                                       : 'bg-primary'
                                   ?>"
                        >
                            <?= array_sum(
                                array_map(
                                    static fn(
                                        array $faculty
                                    ): int =>
                                        (int)
                                        $faculty[
                                            'department_count'
                                        ],
                                    $allFaculties
                                )
                            ) ?>
                        </span>

                    </a>


                    <?php foreach (
                        $allFaculties as
                        $faculty
                    ): ?>

                        <?php

                        $isActive =
                            $filterFacultyId ===
                            (int)
                            $faculty[
                                'faculty_id'
                            ];

                        ?>

                        <a
                            href="managedepartments.php?faculty_id=<?= (int)
                                $faculty[
                                    'faculty_id'
                                ]
                            ?>"
                            class="list-group-item
                                   list-group-item-action
                                   d-flex justify-content-between
                                   align-items-center
                                   <?= $isActive
                                       ? 'active'
                                       : ''
                                   ?>"
                        >

                            <span>

                                <i
                                    class="bi bi-diagram-3-fill me-2"
                                ></i>

                                <?= e(
                                    $faculty[
                                        'faculty_name'
                                    ]
                                ) ?>

                            </span>


                            <span
                                class="badge
                                       <?= $isActive
                                           ? 'bg-light text-dark'
                                           : 'bg-primary'
                                       ?>"
                            >

                                <?= (int)
                                    $faculty[
                                        'department_count'
                                    ]
                                ?>

                            </span>

                        </a>

                    <?php endforeach; ?>

                </div>

            </div>


            <!-- SUMMARY -->

            <div class="card shadow-sm">

                <div
                    class="card-header bg-dark py-2 fw-semibold"
                >

                    <strong>

                        <i
                            class="bi bi-bar-chart-fill text-info me-1"
                        ></i>

                        Summary

                    </strong>

                </div>


                <div class="card-body p-0">

                    <?php

                    $summaryRows = [
                        [
                            'label' => 'Departments Shown',
                            'value' => $shownDepartments,
                            'icon' => 'bi-grid-fill',
                            'icon_color' => 'text-primary',
                            'badge' => 'bg-primary',
                        ],
                        [
                            'label' => 'Total Seats',
                            'value' => $totalSeatsShown,
                            'icon' => 'bi-person-fill',
                            'icon_color' => 'text-warning',
                            'badge' => 'bg-warning text-dark',
                        ],
                        [
                            'label' => 'Imported Choices',
                            'value' => $totalChoicesShown,
                            'icon' => 'bi-list-ol',
                            'icon_color' => 'text-info',
                            'badge' => 'bg-info text-dark',
                        ],
                        [
                            'label' => 'Active Allocations',
                            'value' => $totalAllocatedShown,
                            'icon' => 'bi-award-fill',
                            'icon_color' => 'text-primary',
                            'badge' => 'bg-primary',
                        ],
                        [
                            'label' => 'Admitted',
                            'value' => $totalAdmittedShown,
                            'icon' => 'bi-mortarboard-fill',
                            'icon_color' => 'text-success',
                            'badge' => 'bg-success',
                        ],
                        [
                            'label' => 'Waiting',
                            'value' => $totalWaitingShown,
                            'icon' => 'bi-hourglass-split',
                            'icon_color' => 'text-secondary',
                            'badge' => 'bg-secondary',
                        ],
                    ];

                    foreach (
                        $summaryRows as
                        $index => $summary
                    ):

                    ?>

                        <div
                            class="d-flex
                                   align-items-center
                                   justify-content-between
                                   px-3 py-2
                                   <?= $index <
                                       count(
                                           $summaryRows
                                       ) - 1
                                       ? 'border-bottom'
                                       : ''
                                   ?>"
                        >

                            <span class="small">

                                <i
                                    class="bi <?= e($summary['icon']) ?> <?= e($summary['icon_color']) ?> me-2"
                                ></i>

                                <?= e(
                                    $summary[
                                        'label'
                                    ]
                                ) ?>

                            </span>


                            <span
                                class="badge <?= e($summary['badge']) ?>"
                            >

                                <?= number_format(
                                    (int)
                                    $summary[
                                        'value'
                                    ]
                                ) ?>

                            </span>

                        </div>

                    <?php endforeach; ?>

                </div>

            </div>

        </div>


        <!-- =====================================================
             RIGHT
        ====================================================== -->

        <div class="col-lg-8">

            <div class="card shadow-sm">

                <div
                    class="card-header bg-dark py-2 fw-semibold
                           d-flex align-items-center
                           justify-content-between gap-2"
                >

                    <span class="fw-semibold">

                        <i
                            class="bi bi-list-ul text-warning me-1"
                        ></i>

                        <?= $currentFaculty
                            ? e(
                                $currentFaculty[
                                    'faculty_name'
                                ]
                            ) .
                                ' — Departments'
                            : 'All Departments'
                        ?>

                    </span>


                    <span
                        class="badge
                               bg-light text-dark"
                    >
                        <?= $shownDepartments ?>
                        total
                    </span>

                </div>


                <div class="card-body p-0">


                    <?php if (
                        !$departments
                    ): ?>

                        <div
                            class="text-center
                                   py-5 text-muted"
                        >

                            <i
                                class="bi bi-grid fs-1 d-block mb-2 text-primary"
                            ></i>

                            No departments found.

                        </div>


                    <?php else: ?>


                        <div class="table-responsive">

                            <table
                                class="table table-sm
                                       table-bordered
                                       table-hover mb-0
                                       align-middle"
                            >

                                <thead class="table-light">

                                    <tr>

                                        <th>#</th>
                                        <th>Department</th>
                                        <th>Faculty</th>

                                        <th
                                            class="text-center"
                                        >
                                            Seats
                                        </th>

                                        <th
                                            class="text-center"
                                        >
                                            Choices
                                        </th>

                                        <th
                                            class="text-center"
                                        >
                                            Allocated
                                        </th>

                                        <th
                                            class="text-center"
                                        >
                                            Admitted
                                        </th>

                                        <th
                                            class="text-center"
                                        >
                                            Waiting
                                        </th>

                                        <th
                                            class="text-center"
                                        >
                                            Admin
                                        </th>

                                        <th
                                            class="text-center"
                                        >
                                            Actions
                                        </th>

                                    </tr>

                                </thead>


                                <tbody>


                                    <?php foreach (
                                        $departments as
                                        $index =>
                                        $department
                                    ): ?>

                                        <?php

                                        $seats =
                                            max(
                                                1,
                                                (int)
                                                $department[
                                                    'total_seats'
                                                ]
                                            );

                                        $allocated =
                                            (int)
                                            $department[
                                                'allocated_count'
                                            ];

                                        $occupancy =
                                            min(
                                                100,
                                                (int)
                                                round(
                                                    (
                                                        $allocated /
                                                        $seats
                                                    ) * 100
                                                )
                                            );

                                        $canDelete =
                                            (int)
                                            $department[
                                                'choice_count'
                                            ] === 0
                                            &&
                                            (int)
                                            $department[
                                                'allocated_count'
                                            ] === 0
                                            &&
                                            (int)
                                            $department[
                                                'waiting_count'
                                            ] === 0
                                            &&
                                            (int)
                                            $department[
                                                'admin_count'
                                            ] === 0;

                                        ?>

                                        <tr>

                                            <td>
                                                <?= $index + 1 ?>
                                            </td>


                                            <td>

                                                <div
                                                    class="d-flex
                                                           align-items-center
                                                           gap-2"
                                                >

                                                    <span
                                                        class="badge bg-light text-dark border"
                                                    >
                                                        <?= e(
                                                            $department[
                                                                'dept_code'
                                                            ]
                                                        ) ?>
                                                    </span>

                                                    <span
                                                        class="fw-semibold
                                                               small"
                                                    >
                                                        <?= e(
                                                            $department[
                                                                'dept_name'
                                                            ]
                                                        ) ?>
                                                    </span>

                                                </div>


                                                <div
                                                    class="progress
                                                           mt-2
                                                           "
                                                    style="height:5px;"
                                                >

                                                    <div
                                                        class="progress-bar <?= $occupancy >= 90
                                                            ? 'bg-danger'
                                                            : ($occupancy >= 60
                                                                ? 'bg-warning'
                                                                : 'bg-success') ?>"
                                                        style="width:<?= $occupancy ?>%;"
                                                    ></div>

                                                </div>


                                                <small
                                                    class="text-muted"
                                                >
                                                    <?= $occupancy ?>%
                                                    occupied
                                                </small>

                                            </td>


                                            <td>

                                                <small
                                                    class="badge
                                                           bg-light
                                                           text-dark
                                                           border"
                                                >
                                                    <?= e(
                                                        $department[
                                                            'faculty_name'
                                                        ]
                                                    ) ?>
                                                </small>

                                            </td>


                                            <td
                                                class="text-center
                                                       fw-bold"
                                            >
                                                <?= (int)
                                                    $department[
                                                        'total_seats'
                                                    ]
                                                ?>
                                            </td>


                                            <td class="text-center">

                                                <span class="badge bg-info text-dark">
                                                    <?= (int)
                                                        $department[
                                                            'choice_count'
                                                        ]
                                                    ?>
                                                </span>

                                            </td>


                                            <td class="text-center">

                                                <span class="badge bg-primary">
                                                    <?= $allocated ?>
                                                </span>

                                            </td>


                                            <td class="text-center">

                                                <span class="badge bg-success">
                                                    <?= (int)
                                                        $department[
                                                            'admitted_count'
                                                        ]
                                                    ?>
                                                </span>

                                            </td>


                                            <td class="text-center">

                                                <span
                                                    class="badge
                                                           bg-secondary"
                                                >
                                                    <?= (int)
                                                        $department[
                                                            'waiting_count'
                                                        ]
                                                    ?>
                                                </span>

                                            </td>


                                            <td class="text-center">

                                                <?php if (
                                                    (int)
                                                    $department[
                                                        'admin_count'
                                                    ] > 0
                                                ): ?>

                                                    <span
                                                        class="badge bg-success"
                                                        title="Has active department admin"
                                                    >

                                                        <i
                                                            class="bi bi-check-lg"
                                                        ></i>

                                                    </span>

                                                <?php else: ?>

                                                    <span
                                                        class="badge
                                                               bg-danger"
                                                        title="No active department admin"
                                                    >

                                                        <i
                                                            class="bi bi-x-lg"
                                                        ></i>

                                                    </span>

                                                <?php endif; ?>

                                            </td>


                                            <td class="text-center">

                                                <div
                                                    class="d-flex
                                                           gap-1
                                                           justify-content-center"
                                                >


                                                    <button
                                                        type="button"
                                                        class="btn btn-outline-primary btn-sm py-0 px-2"
                                                        title="Edit"
                                                        data-dept-id="<?= (int)
                                                            $department[
                                                                'dept_id'
                                                            ]
                                                        ?>"
                                                        data-dept-code="<?= e(
                                                            $department[
                                                                'dept_code'
                                                            ]
                                                        ) ?>"
                                                        data-dept-name="<?= e(
                                                            $department[
                                                                'dept_name'
                                                            ]
                                                        ) ?>"
                                                        data-faculty-id="<?= (int)
                                                            $department[
                                                                'faculty_id'
                                                            ]
                                                        ?>"
                                                        data-total-seats="<?= (int)
                                                            $department[
                                                                'total_seats'
                                                            ]
                                                        ?>"
                                                        onclick="openDepartmentEdit(this)"
                                                    >

                                                        <i
                                                            class="bi bi-pencil-fill"
                                                        ></i>

                                                    </button>


                                                    <?php if (
                                                        $canDelete
                                                    ): ?>

                                                        <form
                                                            method="POST"
                                                            class="d-inline"
                                                            onsubmit="
                                                                return confirm(
                                                                    'Delete this department permanently?'
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
                                                                name="action"
                                                                value="delete"
                                                            >

                                                            <input
                                                                type="hidden"
                                                                name="dept_id"
                                                                value="<?= (int)
                                                                    $department[
                                                                        'dept_id'
                                                                    ]
                                                                ?>"
                                                            >

                                                            <input
                                                                type="hidden"
                                                                name="filter_faculty_id"
                                                                value="<?= $filterFacultyId ?>"
                                                            >


                                                            <button
                                                                type="submit"
                                                                class="btn
                                                                       btn-danger
                                                                       btn-sm
                                                                       py-0 px-2"
                                                                title="Delete"
                                                            >

                                                                <i
                                                                    class="bi bi-trash-fill"
                                                                ></i>

                                                            </button>

                                                        </form>


                                                    <?php else: ?>

                                                        <button
                                                            type="button"
                                                            class="btn
                                                                   btn-outline-danger
                                                                   btn-sm
                                                                   py-0 px-2"
                                                            disabled
                                                            title="Cannot delete: linked choices, allocations, waiting records, or admin accounts exist"
                                                        >

                                                            <i
                                                                class="bi bi-trash-fill"
                                                            ></i>

                                                        </button>

                                                    <?php endif; ?>

                                                </div>

                                            </td>

                                        </tr>

                                    <?php endforeach; ?>

                                </tbody>

                            </table>

                        </div>


                        <div
                            class="p-3
                                   text-muted
                                   small text-end"
                        >

                            <?= $shownDepartments ?>
                            department<?= $shownDepartments !== 1
                                ? 's'
                                : ''
                            ?>
                            shown

                        </div>

                    <?php endif; ?>

                </div>

            </div>

        </div>

    </div>

</div>


<!-- =============================================================
     EDIT MODAL
============================================================== -->

<div
    class="modal fade"
    id="editModal"
    tabindex="-1"
    aria-hidden="true"
>

    <div class="modal-dialog">

        <div class="modal-content">

            <div
                class="modal-header bg-dark text-white"
            >

                <h5
                    class="modal-title fw-bold"
                >

                    <i
                        class="bi bi-pencil-fill me-2"
                    ></i>

                    Edit Department

                </h5>

                <button
                    type="button"
                    class="btn-close
                           btn-close-white"
                    data-bs-dismiss="modal"
                ></button>

            </div>


            <form method="POST">

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
                    name="action"
                    value="edit"
                >

                <input
                    type="hidden"
                    name="dept_id"
                    id="editDeptId"
                >

                <input
                    type="hidden"
                    name="filter_faculty_id"
                    value="<?= $filterFacultyId ?>"
                >


                <div class="modal-body">


                    <div class="mb-3">

                        <label
                            class="form-label fw-semibold"
                        >

                            Faculty

                            <span class="text-danger">
                                *
                            </span>

                        </label>


                        <select
                            name="faculty_id"
                            id="editFacultyId"
                            class="form-select"
                            required
                        >

                            <?php foreach (
                                $allFaculties as
                                $faculty
                            ): ?>

                                <option
                                    value="<?= (int)
                                        $faculty[
                                            'faculty_id'
                                        ]
                                    ?>"
                                >

                                    <?= e(
                                        $faculty[
                                            'faculty_name'
                                        ]
                                    ) ?>

                                </option>

                            <?php endforeach; ?>

                        </select>

                    </div>


                    <div class="row g-3">


                        <div class="col-4">

                            <label
                                class="form-label
                                       fw-semibold"
                            >
                                Code
                            </label>


                            <input
                                type="text"
                                name="dept_code"
                                id="editDeptCode"
                                class="form-control
                                       text-uppercase"
                                maxlength="20"
                                required
                            >

                        </div>


                        <div class="col-8">

                            <label
                                class="form-label
                                       fw-semibold"
                            >
                                Department Name
                            </label>


                            <input
                                type="text"
                                name="dept_name"
                                id="editDeptName"
                                class="form-control"
                                maxlength="150"
                                required
                            >

                        </div>

                    </div>


                    <div class="mt-3">

                        <label
                            class="form-label fw-semibold"
                        >
                            Total Seats
                        </label>


                        <input
                            type="number"
                            name="total_seats"
                            id="editTotalSeats"
                            class="form-control"
                            min="1"
                            max="500"
                            required
                        >


                        <div class="form-text">

                            Seats cannot be reduced below
                            the current active allocation count.

                        </div>

                    </div>

                </div>


                <div class="modal-footer">

                    <button
                        type="button"
                        class="btn
                               btn-outline-secondary"
                        data-bs-dismiss="modal"
                    >
                        Cancel
                    </button>


                    <button
                        type="submit"
                        class="btn btn-primary fw-bold"
                    >

                        <i
                            class="bi bi-save-fill me-1"
                        ></i>

                        Save Changes

                    </button>

                </div>

            </form>

        </div>

    </div>

</div>


<script>

function openDepartmentEdit(button) {

    document
        .getElementById(
            'editDeptId'
        )
        .value =
            button.dataset.deptId;


    document
        .getElementById(
            'editDeptCode'
        )
        .value =
            button.dataset.deptCode;


    document
        .getElementById(
            'editDeptName'
        )
        .value =
            button.dataset.deptName;


    document
        .getElementById(
            'editFacultyId'
        )
        .value =
            button.dataset.facultyId;


    document
        .getElementById(
            'editTotalSeats'
        )
        .value =
            button.dataset.totalSeats;


    const modal =
        bootstrap.Modal.getOrCreateInstance(
            document.getElementById(
                'editModal'
            )
        );


    modal.show();
}

</script>


<?php require_once '../includes/footer.php'; ?>
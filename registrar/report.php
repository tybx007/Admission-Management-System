<?php

declare(strict_types=1);

$pageTitle = 'Registrar Reports';

require_once '../includes/auth.php';
require_once '../config/db.php';
require_once '../includes/admission_session.php';

requireLogin('registrar');

$pdo->setAttribute(
    PDO::ATTR_ERRMODE,
    PDO::ERRMODE_EXCEPTION
);

date_default_timezone_set('Asia/Dhaka');


/*
|--------------------------------------------------------------------------
| Helper
|--------------------------------------------------------------------------
*/

function registrarReportE(mixed $value): string
{
    return htmlspecialchars(
        (string) ($value ?? ''),
        ENT_QUOTES,
        'UTF-8'
    );
}


/*
|--------------------------------------------------------------------------
| Current session
|--------------------------------------------------------------------------
*/

$currentAcademicYear = '';

try {
    $currentAcademicYear =
        admissionSessionCurrentYear($pdo);
} catch (Throwable $e) {
    $currentAcademicYear = '';
}


/*
|--------------------------------------------------------------------------
| Available report sessions
|--------------------------------------------------------------------------
|
| Reports may read historical sessions.
| Operational pages remain current-session only.
|--------------------------------------------------------------------------
*/

$sessionRows = $pdo->query("
    SELECT
        academic_year,
        session_status
    FROM admission_sessions
    ORDER BY academic_year DESC
")->fetchAll(PDO::FETCH_ASSOC);

$knownYears = [];
$sessionStatusMap = [];

foreach ($sessionRows as $row) {
    $year = trim(
        (string) ($row['academic_year'] ?? '')
    );

    if ($year === '') {
        continue;
    }

    $knownYears[$year] = true;
    $sessionStatusMap[$year] =
        (string) ($row['session_status'] ?? '');
}


/*
|--------------------------------------------------------------------------
| Include imported historical years that may predate admission_sessions
|--------------------------------------------------------------------------
*/

$importedYears = $pdo->query("
    SELECT DISTINCT academic_year
    FROM gst_imported_candidates
    WHERE academic_year IS NOT NULL
      AND TRIM(academic_year) <> ''
    ORDER BY academic_year DESC
")->fetchAll(PDO::FETCH_COLUMN);

foreach ($importedYears as $year) {
    $year = trim((string) $year);

    if ($year !== '') {
        $knownYears[$year] = true;
    }
}

$availableYears =
    array_keys($knownYears);

rsort(
    $availableYears,
    SORT_STRING
);


/*
|--------------------------------------------------------------------------
| Selected session
|--------------------------------------------------------------------------
*/

$selectedYear = trim(
    (string) (
        $_GET['academic_year']
        ?? $currentAcademicYear
    )
);

if (
    $selectedYear === ''
    ||
    !isset($knownYears[$selectedYear])
) {
    $selectedYear =
        $currentAcademicYear !== ''
        && isset(
            $knownYears[$currentAcademicYear]
        )
            ? $currentAcademicYear
            : (
                $availableYears[0]
                ?? ''
            );
}


/*
|--------------------------------------------------------------------------
| Filters
|--------------------------------------------------------------------------
*/

$facultyId =
    max(
        0,
        (int) (
            $_GET['faculty_id']
            ?? 0
        )
    );

$deptId =
    max(
        0,
        (int) (
            $_GET['dept_id']
            ?? 0
        )
    );


/*
|--------------------------------------------------------------------------
| Faculty / Department lists
|--------------------------------------------------------------------------
*/

$facultyList = $pdo->query("
    SELECT
        faculty_id,
        faculty_name
    FROM faculties
    ORDER BY faculty_name
")->fetchAll(PDO::FETCH_ASSOC);


if ($facultyId > 0) {

    $deptStmt = $pdo->prepare("
        SELECT
            dept_id,
            faculty_id,
            dept_name,
            total_seats
        FROM departments
        WHERE faculty_id = ?
        ORDER BY dept_name
    ");

    $deptStmt->execute([
        $facultyId
    ]);

} else {

    $deptStmt = $pdo->query("
        SELECT
            dept_id,
            faculty_id,
            dept_name,
            total_seats
        FROM departments
        ORDER BY dept_name
    ");
}

$departmentList =
    $deptStmt->fetchAll(
        PDO::FETCH_ASSOC
    );


/*
|--------------------------------------------------------------------------
| Validate selected department
|--------------------------------------------------------------------------
*/

if ($deptId > 0) {

    $validDepartment = false;

    foreach (
        $departmentList
        as $department
    ) {
        if (
            (int) $department['dept_id']
            === $deptId
        ) {
            $validDepartment = true;
            break;
        }
    }

    if (!$validDepartment) {
        $deptId = 0;
    }
}


/*
|--------------------------------------------------------------------------
| Overall overview
|--------------------------------------------------------------------------
*/

$overview = [
    'imported' => 0,
    'registered' => 0,
    'unregistered' => 0,
    'allocated' => 0,
    'admitted' => 0,
    'waiting' => 0,
    'vacated' => 0,
];


/*
|--------------------------------------------------------------------------
| Imported / Registered
|--------------------------------------------------------------------------
*/

$overviewStmt = $pdo->prepare("
    SELECT
        COUNT(*) AS imported,

        COALESCE(
            SUM(
                CASE
                    WHEN is_registered = 1
                    THEN 1
                    ELSE 0
                END
            ),
            0
        ) AS registered,

        COALESCE(
            SUM(
                CASE
                    WHEN is_registered = 0
                    THEN 1
                    ELSE 0
                END
            ),
            0
        ) AS unregistered

    FROM gst_imported_candidates

    WHERE academic_year = ?
");

$overviewStmt->execute([
    $selectedYear
]);

$importOverview =
    $overviewStmt->fetch(
        PDO::FETCH_ASSOC
    ) ?: [];

$overview['imported'] =
    (int) (
        $importOverview['imported']
        ?? 0
    );

$overview['registered'] =
    (int) (
        $importOverview['registered']
        ?? 0
    );

$overview['unregistered'] =
    (int) (
        $importOverview['unregistered']
        ?? 0
    );


/*
|--------------------------------------------------------------------------
| Current allocation status
|--------------------------------------------------------------------------
|
| Allocated + Admitted are active seat occupancies.
| Vacated rows are history and do not occupy seats.
|--------------------------------------------------------------------------
*/

$allocationStmt = $pdo->prepare("
    SELECT
        COALESCE(
            SUM(
                CASE
                    WHEN ma.allocation_status = 'Allocated'
                    THEN 1
                    ELSE 0
                END
            ),
            0
        ) AS allocated,

        COALESCE(
            SUM(
                CASE
                    WHEN ma.allocation_status = 'Admitted'
                    THEN 1
                    ELSE 0
                END
            ),
            0
        ) AS admitted,

        COALESCE(
            SUM(
                CASE
                    WHEN ma.allocation_status = 'Vacated'
                    THEN 1
                    ELSE 0
                END
            ),
            0
        ) AS vacated

    FROM merit_allocations ma

    INNER JOIN gst_imported_candidates gic
        ON gic.import_candidate_id =
           ma.import_candidate_id

    WHERE gic.academic_year = ?
");

$allocationStmt->execute([
    $selectedYear
]);

$allocationOverview =
    $allocationStmt->fetch(
        PDO::FETCH_ASSOC
    ) ?: [];

$overview['allocated'] =
    (int) (
        $allocationOverview['allocated']
        ?? 0
    );

$overview['admitted'] =
    (int) (
        $allocationOverview['admitted']
        ?? 0
    );

$overview['vacated'] =
    (int) (
        $allocationOverview['vacated']
        ?? 0
    );


/*
|--------------------------------------------------------------------------
| Waiting
|--------------------------------------------------------------------------
*/

$waitingStmt = $pdo->prepare("
    SELECT
        COUNT(*)
    FROM waiting_list wl

    INNER JOIN gst_imported_candidates gic
        ON gic.import_candidate_id =
           wl.import_candidate_id

    WHERE gic.academic_year = ?
      AND wl.waiting_status = 'Waiting'
");

$waitingStmt->execute([
    $selectedYear
]);

$overview['waiting'] =
    (int) (
        $waitingStmt->fetchColumn()
        ?: 0
    );


/*
|--------------------------------------------------------------------------
| Seat totals
|--------------------------------------------------------------------------
*/

$seatTotals = $pdo->query("
    SELECT
        COALESCE(
            SUM(total_seats),
            0
        )
    FROM departments
")->fetchColumn();

$totalSeats =
    (int) (
        $seatTotals
        ?: 0
    );

$totalOccupied =
    $overview['allocated']
    +
    $overview['admitted'];

$totalVacant =
    max(
        0,
        $totalSeats
        -
        $totalOccupied
    );


/*
|--------------------------------------------------------------------------
| Viva overview
|--------------------------------------------------------------------------
|
| DB value "Not Selected" is presented to Registrar as "Rejected".
|--------------------------------------------------------------------------
*/

$viva = [
    'selected' => 0,
    'rejected' => 0,
    'absent' => 0,
    'pending' => 0,
];

$vivaStmt = $pdo->prepare("
    SELECT
        COUNT(
            DISTINCT CASE
                WHEN vr.viva_status = 'Selected'
                THEN gic.import_candidate_id
            END
        ) AS selected_count,

        COUNT(
            DISTINCT CASE
                WHEN vr.viva_status = 'Not Selected'
                THEN gic.import_candidate_id
            END
        ) AS rejected_count,

        COUNT(
            DISTINCT CASE
                WHEN vr.viva_status = 'Absent'
                THEN gic.import_candidate_id
            END
        ) AS absent_count,

        COUNT(
            DISTINCT CASE
                WHEN gic.is_registered = 1
                 AND ma.allocation_status = 'Allocated'
                 AND vr.viva_id IS NULL
                THEN gic.import_candidate_id
            END
        ) AS pending_count

    FROM gst_imported_candidates gic

    LEFT JOIN merit_allocations ma
        ON ma.import_candidate_id =
           gic.import_candidate_id
       AND ma.allocation_status IN (
           'Allocated',
           'Admitted'
       )

    LEFT JOIN viva_results vr
        ON vr.applicant_id =
           gic.applicant_id

    WHERE gic.academic_year = ?
");

$vivaStmt->execute([
    $selectedYear
]);

$vivaRow =
    $vivaStmt->fetch(
        PDO::FETCH_ASSOC
    ) ?: [];

$viva['selected'] =
    (int) (
        $vivaRow['selected_count']
        ?? 0
    );

$viva['rejected'] =
    (int) (
        $vivaRow['rejected_count']
        ?? 0
    );

$viva['absent'] =
    (int) (
        $vivaRow['absent_count']
        ?? 0
    );

$viva['pending'] =
    (int) (
        $vivaRow['pending_count']
        ?? 0
    );


/*
|--------------------------------------------------------------------------
| Latest Merit Call for selected session
|--------------------------------------------------------------------------
*/

$latestCallStmt = $pdo->prepare("
    SELECT
        merit_call_id,
        call_number,
        call_title,
        published_at,
        registration_deadline,
        processed_at,
        viva_status,
        process_closed_at,
        migration_processed_at,
        next_call_prepared_at

    FROM merit_calls

    WHERE academic_year = ?

    ORDER BY
        call_number DESC,
        merit_call_id DESC

    LIMIT 1
");

$latestCallStmt->execute([
    $selectedYear
]);

$latestCall =
    $latestCallStmt->fetch(
        PDO::FETCH_ASSOC
    ) ?: null;


/*
|--------------------------------------------------------------------------
| Department report filters
|--------------------------------------------------------------------------
*/

$departmentWhere = [];
$departmentParams = [
    $selectedYear,
    $selectedYear,
    $selectedYear,
];

if ($facultyId > 0) {
    $departmentWhere[] =
        'd.faculty_id = ?';

    $departmentParams[] =
        $facultyId;
}

if ($deptId > 0) {
    $departmentWhere[] =
        'd.dept_id = ?';

    $departmentParams[] =
        $deptId;
}

$departmentWhereSql =
    $departmentWhere
        ? 'WHERE '
          . implode(
              ' AND ',
              $departmentWhere
          )
        : '';


/*
|--------------------------------------------------------------------------
| Department-wise seat/admission report
|--------------------------------------------------------------------------
|
| a = active allocations by session
| w = current waiting candidates by session
| r = registered active candidates by session
|--------------------------------------------------------------------------
*/

$departmentReportStmt =
    $pdo->prepare("
        SELECT
            d.dept_id,
            d.dept_name,
            d.total_seats,

            f.faculty_name,

            COALESCE(
                a.allocated_count,
                0
            ) AS allocated_count,

            COALESCE(
                a.admitted_count,
                0
            ) AS admitted_count,

            COALESCE(
                a.occupied_count,
                0
            ) AS occupied_count,

            GREATEST(
                d.total_seats
                -
                COALESCE(
                    a.occupied_count,
                    0
                ),
                0
            ) AS vacant_count,

            COALESCE(
                r.registered_count,
                0
            ) AS registered_count,

            COALESCE(
                w.waiting_count,
                0
            ) AS waiting_count

        FROM departments d

        INNER JOIN faculties f
            ON f.faculty_id =
               d.faculty_id


        LEFT JOIN (
            SELECT
                ma.dept_id,

                COUNT(
                    DISTINCT CASE
                        WHEN ma.allocation_status = 'Allocated'
                        THEN ma.import_candidate_id
                    END
                ) AS allocated_count,

                COUNT(
                    DISTINCT CASE
                        WHEN ma.allocation_status = 'Admitted'
                        THEN ma.import_candidate_id
                    END
                ) AS admitted_count,

                COUNT(
                    DISTINCT CASE
                        WHEN ma.allocation_status IN (
                            'Allocated',
                            'Admitted'
                        )
                        THEN ma.import_candidate_id
                    END
                ) AS occupied_count

            FROM merit_allocations ma

            INNER JOIN gst_imported_candidates gic
                ON gic.import_candidate_id =
                   ma.import_candidate_id

            WHERE gic.academic_year = ?

            GROUP BY ma.dept_id
        ) a
            ON a.dept_id =
               d.dept_id


        LEFT JOIN (
            SELECT
                ma.dept_id,

                COUNT(
                    DISTINCT ma.import_candidate_id
                ) AS registered_count

            FROM merit_allocations ma

            INNER JOIN gst_imported_candidates gic
                ON gic.import_candidate_id =
                   ma.import_candidate_id

            WHERE gic.academic_year = ?
              AND gic.is_registered = 1
              AND ma.allocation_status IN (
                  'Allocated',
                  'Admitted'
              )

            GROUP BY ma.dept_id
        ) r
            ON r.dept_id =
               d.dept_id


        LEFT JOIN (
            SELECT
                wl.dept_id,

                COUNT(
                    DISTINCT wl.import_candidate_id
                ) AS waiting_count

            FROM waiting_list wl

            INNER JOIN gst_imported_candidates gic
                ON gic.import_candidate_id =
                   wl.import_candidate_id

            WHERE gic.academic_year = ?
              AND wl.waiting_status = 'Waiting'

            GROUP BY wl.dept_id
        ) w
            ON w.dept_id =
               d.dept_id


        {$departmentWhereSql}

        ORDER BY
            f.faculty_name,
            d.dept_name
    ");

$departmentReportStmt->execute(
    $departmentParams
);

$departmentRows =
    $departmentReportStmt->fetchAll(
        PDO::FETCH_ASSOC
    );


/*
|--------------------------------------------------------------------------
| Filtered department totals
|--------------------------------------------------------------------------
*/

$departmentTotals = [
    'seats' => 0,
    'allocated' => 0,
    'registered' => 0,
    'admitted' => 0,
    'occupied' => 0,
    'vacant' => 0,
    'waiting' => 0,
];

foreach ($departmentRows as $row) {

    $departmentTotals['seats'] +=
        (int) $row['total_seats'];

    $departmentTotals['allocated'] +=
        (int) $row['allocated_count'];

    $departmentTotals['registered'] +=
        (int) $row['registered_count'];

    $departmentTotals['admitted'] +=
        (int) $row['admitted_count'];

    $departmentTotals['occupied'] +=
        (int) $row['occupied_count'];

    $departmentTotals['vacant'] +=
        (int) $row['vacant_count'];

    $departmentTotals['waiting'] +=
        (int) $row['waiting_count'];
}


/*
|--------------------------------------------------------------------------
| CSV Export — Department report
|--------------------------------------------------------------------------
*/

if (
    (string) (
        $_GET['export']
        ?? ''
    )
    === 'departments'
) {

    $safeYear =
        preg_replace(
            '/[^0-9A-Za-z_-]/',
            '_',
            $selectedYear
        );

    header(
        'Content-Type: text/csv; charset=UTF-8'
    );

    header(
        'Content-Disposition: attachment; filename="jkkniu_department_report_'
        . $safeYear
        . '.csv"'
    );

    $output =
        fopen(
            'php://output',
            'w'
        );

    fputcsv(
        $output,
        [
            'Admission Session',
            'Faculty',
            'Department',
            'Seats',
            'Allocated',
            'Registered',
            'Final Admitted',
            'Occupied',
            'Vacant',
            'Waiting',
        ]
    );

    foreach (
        $departmentRows
        as $row
    ) {
        fputcsv(
            $output,
            [
                $selectedYear,
                $row['faculty_name'],
                $row['dept_name'],
                (int) $row['total_seats'],
                (int) $row['allocated_count'],
                (int) $row['registered_count'],
                (int) $row['admitted_count'],
                (int) $row['occupied_count'],
                (int) $row['vacant_count'],
                (int) $row['waiting_count'],
            ]
        );
    }

    fclose($output);
    exit;
}


/*
|--------------------------------------------------------------------------
| Preserve filter query for export
|--------------------------------------------------------------------------
*/

$exportQuery = http_build_query([
    'academic_year' =>
        $selectedYear,

    'faculty_id' =>
        $facultyId,

    'dept_id' =>
        $deptId,

    'export' =>
        'departments',
]);


/*
|--------------------------------------------------------------------------
| UI
|--------------------------------------------------------------------------
*/

require_once '../includes/header.php';
require_once '../includes/navbar.php';

?>


<div class="container py-4">


    <!-- ================================================================
         HEADER
    ================================================================= -->

    <div
        class="d-flex
               flex-wrap
               justify-content-between
               align-items-center
               gap-3
               mb-3
               report-no-print"
    >

        <div>

            <h3 class="fw-bold mb-1">

                <i
                    class="bi
                           bi-file-earmark-bar-graph
                           text-primary
                           me-2"
                ></i>

                Registrar Reports

            </h3>

            <div class="text-muted small">
                Admission overview and department seat status
            </div>

        </div>


        <div class="d-flex gap-2">

            <button
                type="button"
                class="btn btn-outline-secondary"
                onclick="window.print();"
            >
                <i class="bi bi-printer me-1"></i>
                Print
            </button>

            <a
                href="report.php?<?= registrarReportE(
                    $exportQuery
                ) ?>"
                class="btn btn-outline-success"
            >
                <i class="bi bi-filetype-csv me-1"></i>
                Export CSV
            </a>

        </div>

    </div>


    <!-- ================================================================
         SESSION SELECTOR
    ================================================================= -->

    <div class="card shadow-sm mb-3 report-no-print">

        <div class="card-body py-3">

            <form
                method="GET"
                class="row g-2 align-items-end"
            >

                <div class="col-lg-3 col-md-4">

                    <label class="form-label small fw-semibold">
                        Admission Session
                    </label>

                    <select
                        name="academic_year"
                        class="form-select"
                    >

                        <?php foreach (
                            $availableYears
                            as $year
                        ): ?>

                            <option
                                value="<?= registrarReportE(
                                    $year
                                ) ?>"
                                <?= $selectedYear === $year
                                    ? 'selected'
                                    : ''
                                ?>
                            >
                                <?= registrarReportE(
                                    $year
                                ) ?>

                                <?php if (
                                    (
                                        $sessionStatusMap[$year]
                                        ?? ''
                                    )
                                    === 'Open'
                                ): ?>
                                    — Current
                                <?php endif; ?>
                            </option>

                        <?php endforeach; ?>

                    </select>

                </div>


                <div class="col-lg-3 col-md-4">

                    <label class="form-label small fw-semibold">
                        Faculty
                    </label>

                    <select
                        name="faculty_id"
                        class="form-select"
                        onchange="
                            this.form.dept_id.value='0';
                        "
                    >

                        <option value="0">
                            All Faculties
                        </option>

                        <?php foreach (
                            $facultyList
                            as $faculty
                        ): ?>

                            <option
                                value="<?= (int)
                                    $faculty[
                                        'faculty_id'
                                    ]
                                ?>"
                                <?= $facultyId ===
                                    (int)
                                    $faculty[
                                        'faculty_id'
                                    ]
                                    ? 'selected'
                                    : ''
                                ?>
                            >
                                <?= registrarReportE(
                                    $faculty[
                                        'faculty_name'
                                    ]
                                ) ?>
                            </option>

                        <?php endforeach; ?>

                    </select>

                </div>


                <div class="col-lg-3 col-md-4">

                    <label class="form-label small fw-semibold">
                        Department
                    </label>

                    <select
                        name="dept_id"
                        class="form-select"
                    >

                        <option value="0">
                            All Departments
                        </option>

                        <?php foreach (
                            $departmentList
                            as $department
                        ): ?>

                            <option
                                value="<?= (int)
                                    $department[
                                        'dept_id'
                                    ]
                                ?>"
                                <?= $deptId ===
                                    (int)
                                    $department[
                                        'dept_id'
                                    ]
                                    ? 'selected'
                                    : ''
                                ?>
                            >
                                <?= registrarReportE(
                                    $department[
                                        'dept_name'
                                    ]
                                ) ?>
                            </option>

                        <?php endforeach; ?>

                    </select>

                </div>


                <div class="col-lg-3">

                    <div class="d-flex gap-2">

                        <button
                            type="submit"
                            class="btn btn-primary flex-grow-1"
                        >
                            <i class="bi bi-funnel me-1"></i>
                            Apply
                        </button>

                        <a
                            href="report.php?academic_year=<?= urlencode(
                                $selectedYear
                            ) ?>"
                            class="btn btn-outline-secondary"
                        >
                            Reset
                        </a>

                    </div>

                </div>

            </form>

        </div>

    </div>


    <!-- ================================================================
         PRINT TITLE
    ================================================================= -->

    <div class="d-none d-print-block mb-3">

        <h4 class="mb-1">
            JKKNIU Registrar Admission Report
        </h4>

        <div>
            Admission Session:
            <strong>
                <?= registrarReportE(
                    $selectedYear
                ) ?>
            </strong>
        </div>

    </div>


    <!-- ================================================================
         OVERVIEW STRIP
    ================================================================= -->

    <div class="card shadow-sm mb-3 report-summary-print-hide">

        <div
            class="card-header
                   bg-primary
                   text-white
                   d-flex
                   justify-content-between
                   align-items-center"
        >

            <span>
                <i class="bi bi-bar-chart-fill me-2"></i>
                Admission Overview
            </span>

            <span class="badge bg-light text-primary">
                <?= registrarReportE(
                    $selectedYear
                ) ?>
            </span>

        </div>


        <div class="card-body py-3">

            <div class="row g-0 text-center">

                <?php
                $overviewCards = [
                    [
                        'Imported',
                        $overview['imported'],
                        'primary'
                    ],
                    [
                        'Registered',
                        $overview['registered'],
                        'success'
                    ],
                    [
                        'Allocated',
                        $overview['allocated'],
                        'warning'
                    ],
                    [
                        'Final Admitted',
                        $overview['admitted'],
                        'success'
                    ],
                    [
                        'Waiting',
                        $overview['waiting'],
                        'info'
                    ],
                    [
                        'Vacant Seats',
                        $totalVacant,
                        'danger'
                    ],
                ];
                ?>


                <?php foreach (
                    $overviewCards
                    as $index => $card
                ): ?>

                    <div
                        class="col-6
                               col-md-4
                               col-xl-2
                               <?= $index > 0
                                   ? 'border-start'
                                   : ''
                               ?>"
                    >

                        <div class="px-2 py-2">

                            <div class="small text-muted">
                                <?= registrarReportE(
                                    $card[0]
                                ) ?>
                            </div>

                            <div
                                class="h4
                                       fw-bold
                                       mb-0
                                       text-<?= registrarReportE(
                                           $card[2]
                                       ) ?>"
                            >
                                <?= (int) $card[1] ?>
                            </div>

                        </div>

                    </div>

                <?php endforeach; ?>

            </div>

        </div>

    </div>


    <!-- ================================================================
         VIVA + CURRENT MERIT CALL
    ================================================================= -->

    <div class="row g-3 mb-3 report-summary-print-hide">


        <div class="col-lg-7">

            <div class="card shadow-sm h-100">

                <div class="card-header bg-light fw-semibold">
                    <i class="bi bi-mic-fill me-2"></i>
                    Viva Summary
                </div>

                <div class="card-body">

                    <div class="row text-center g-2">

                        <div class="col-6 col-md-3">
                            <div class="border rounded py-2">
                                <div class="small text-muted">
                                    Selected
                                </div>
                                <div class="h5 mb-0 text-success fw-bold">
                                    <?= $viva['selected'] ?>
                                </div>
                            </div>
                        </div>

                        <div class="col-6 col-md-3">
                            <div class="border rounded py-2">
                                <div class="small text-muted">
                                    Rejected
                                </div>
                                <div class="h5 mb-0 text-danger fw-bold">
                                    <?= $viva['rejected'] ?>
                                </div>
                            </div>
                        </div>

                        <div class="col-6 col-md-3">
                            <div class="border rounded py-2">
                                <div class="small text-muted">
                                    Absent
                                </div>
                                <div class="h5 mb-0 text-warning fw-bold">
                                    <?= $viva['absent'] ?>
                                </div>
                            </div>
                        </div>

                        <div class="col-6 col-md-3">
                            <div class="border rounded py-2">
                                <div class="small text-muted">
                                    Pending
                                </div>
                                <div class="h5 mb-0 text-secondary fw-bold">
                                    <?= $viva['pending'] ?>
                                </div>
                            </div>
                        </div>

                    </div>

                </div>

            </div>

        </div>


        <div class="col-lg-5">

            <div class="card shadow-sm h-100">

                <div class="card-header bg-light fw-semibold">
                    <i class="bi bi-megaphone-fill me-2"></i>
                    Latest Merit Call
                </div>

                <div class="card-body">

                    <?php if ($latestCall): ?>

                        <div class="d-flex justify-content-between gap-3 mb-2">

                            <div>
                                <div class="small text-muted">
                                    Merit Call
                                </div>

                                <strong>
                                    <?= registrarReportE(
                                        $latestCall[
                                            'call_title'
                                        ]
                                    ) ?>
                                </strong>
                            </div>

                            <div class="text-end">
                                <div class="small text-muted">
                                    Viva
                                </div>

                                <?php
                                $vivaStatus =
                                    (string) (
                                        $latestCall[
                                            'viva_status'
                                        ]
                                        ?? 'Locked'
                                    );

                                $vivaBadge =
                                    $vivaStatus === 'Open'
                                        ? 'success'
                                        : (
                                            $vivaStatus === 'Closed'
                                                ? 'dark'
                                                : 'warning text-dark'
                                        );
                                ?>

                                <span class="badge bg-<?= registrarReportE(
                                    $vivaBadge
                                ) ?>">
                                    <?= registrarReportE(
                                        $vivaStatus
                                    ) ?>
                                </span>
                            </div>

                        </div>


                        <div class="small text-muted">

                            Registration deadline:

                            <strong class="text-dark">
                                <?= !empty(
                                    $latestCall[
                                        'registration_deadline'
                                    ]
                                )
                                    ? date(
                                        'd M Y, h:i A',
                                        strtotime(
                                            (string)
                                            $latestCall[
                                                'registration_deadline'
                                            ]
                                        )
                                    )
                                    : '—'
                                ?>
                            </strong>

                        </div>


                        <div class="mt-2 d-flex flex-wrap gap-1">

                            <span
                                class="badge bg-<?=
                                    !empty(
                                        $latestCall[
                                            'processed_at'
                                        ]
                                    )
                                        ? 'success'
                                        : 'secondary'
                                ?>"
                            >
                                Deadline
                                <?= !empty(
                                    $latestCall[
                                        'processed_at'
                                    ]
                                )
                                    ? 'Processed'
                                    : 'Pending'
                                ?>
                            </span>

                            <span
                                class="badge bg-<?=
                                    !empty(
                                        $latestCall[
                                            'process_closed_at'
                                        ]
                                    )
                                        ? 'success'
                                        : 'secondary'
                                ?>"
                            >
                                Process
                                <?= !empty(
                                    $latestCall[
                                        'process_closed_at'
                                    ]
                                )
                                    ? 'Closed'
                                    : 'Open'
                                ?>
                            </span>

                            <span
                                class="badge bg-<?=
                                    !empty(
                                        $latestCall[
                                            'migration_processed_at'
                                        ]
                                    )
                                        ? 'success'
                                        : 'secondary'
                                ?>"
                            >
                                Migration
                                <?= !empty(
                                    $latestCall[
                                        'migration_processed_at'
                                    ]
                                )
                                    ? 'Done'
                                    : 'Pending'
                                ?>
                            </span>

                        </div>

                    <?php else: ?>

                        <div class="text-muted text-center py-3">
                            No Merit Call found for this session.
                        </div>

                    <?php endif; ?>

                </div>

            </div>

        </div>

    </div>


    <!-- ================================================================
         DEPARTMENT REPORT
    ================================================================= -->

    <div class="card shadow-sm">

        <div
            class="card-header
                   bg-dark
                   text-white
                   d-flex
                   flex-wrap
                   justify-content-between
                   align-items-center
                   gap-2"
        >

            <span>
                <i class="bi bi-building me-2"></i>
                Department-wise Admission Status
            </span>

            <span class="small">
                <?= count(
                    $departmentRows
                ) ?>
                department(s)
            </span>

        </div>


        <div class="table-responsive">

            <table
                class="table
                       table-hover
                       align-middle
                       mb-0
                       department-report-table"
            >

                <thead class="table-light">

                    <tr>
                        <th>#</th>
                        <th>Faculty</th>
                        <th>Department</th>
                        <th class="text-center">Seats</th>
                        <th class="text-center">Allocated</th>
                        <th class="text-center">Registered</th>
                        <th class="text-center">Final Admitted</th>
                        <th class="text-center">Occupied</th>
                        <th class="text-center">Vacant</th>
                        <th class="text-center">Waiting</th>
                    </tr>

                </thead>


                <tbody>

                <?php if (!$departmentRows): ?>

                    <tr>
                        <td
                            colspan="10"
                            class="text-center
                                   text-muted
                                   py-5"
                        >
                            No department data found.
                        </td>
                    </tr>

                <?php else: ?>


                    <?php foreach (
                        $departmentRows
                        as $index => $row
                    ): ?>

                        <?php
                        $totalSeat =
                            max(
                                1,
                                (int)
                                $row[
                                    'total_seats'
                                ]
                            );

                        $fillPercent =
                            min(
                                100,
                                (
                                    (int)
                                    $row[
                                        'occupied_count'
                                    ]
                                    /
                                    $totalSeat
                                )
                                *
                                100
                            );
                        ?>


                        <tr>

                            <td>
                                <?= $index + 1 ?>
                            </td>


                            <td class="small">
                                <?= registrarReportE(
                                    $row[
                                        'faculty_name'
                                    ]
                                ) ?>
                            </td>


                            <td>

                                <strong>
                                    <?= registrarReportE(
                                        $row[
                                            'dept_name'
                                        ]
                                    ) ?>
                                </strong>

                                <div
                                    class="progress mt-1"
                                    style="height:4px;"
                                    title="<?= number_format(
                                        $fillPercent,
                                        1
                                    ) ?>% occupied"
                                >
                                    <div
                                        class="progress-bar"
                                        style="width:<?= number_format(
                                            $fillPercent,
                                            2,
                                            '.',
                                            ''
                                        ) ?>%;"
                                    ></div>
                                </div>

                            </td>


                            <td class="text-center fw-semibold">
                                <?= (int)
                                    $row[
                                        'total_seats'
                                    ]
                                ?>
                            </td>


                            <td class="text-center">
                                <?= (int)
                                    $row[
                                        'allocated_count'
                                    ]
                                ?>
                            </td>


                            <td class="text-center">
                                <?= (int)
                                    $row[
                                        'registered_count'
                                    ]
                                ?>
                            </td>


                            <td class="text-center">
                                <span class="text-success fw-semibold">
                                    <?= (int)
                                        $row[
                                            'admitted_count'
                                        ]
                                    ?>
                                </span>
                            </td>


                            <td class="text-center">
                                <?= (int)
                                    $row[
                                        'occupied_count'
                                    ]
                                ?>
                            </td>


                            <td class="text-center">

                                <span
                                    class="badge bg-<?=
                                        (int)
                                        $row[
                                            'vacant_count'
                                        ]
                                        > 0
                                            ? 'danger'
                                            : 'success'
                                    ?>"
                                >
                                    <?= (int)
                                        $row[
                                            'vacant_count'
                                        ]
                                    ?>
                                </span>

                            </td>


                            <td class="text-center">

                                <span class="badge bg-info text-dark">
                                    <?= (int)
                                        $row[
                                            'waiting_count'
                                        ]
                                    ?>
                                </span>

                            </td>

                        </tr>

                    <?php endforeach; ?>


                <?php endif; ?>

                </tbody>


                <?php if ($departmentRows): ?>

                    <tfoot class="table-light fw-semibold">

                        <tr>
                            <td colspan="3">
                                Filtered Total
                            </td>

                            <td class="text-center">
                                <?= $departmentTotals[
                                    'seats'
                                ] ?>
                            </td>

                            <td class="text-center">
                                <?= $departmentTotals[
                                    'allocated'
                                ] ?>
                            </td>

                            <td class="text-center">
                                <?= $departmentTotals[
                                    'registered'
                                ] ?>
                            </td>

                            <td class="text-center text-success">
                                <?= $departmentTotals[
                                    'admitted'
                                ] ?>
                            </td>

                            <td class="text-center">
                                <?= $departmentTotals[
                                    'occupied'
                                ] ?>
                            </td>

                            <td class="text-center text-danger">
                                <?= $departmentTotals[
                                    'vacant'
                                ] ?>
                            </td>

                            <td class="text-center">
                                <?= $departmentTotals[
                                    'waiting'
                                ] ?>
                            </td>
                        </tr>

                    </tfoot>

                <?php endif; ?>

            </table>

        </div>

    </div>


</div>


<style>
@page {
    size: A4 portrait;
    margin: 6mm;
}

@media print {

    .report-no-print,
    .report-summary-print-hide,
    nav,
    footer,
    .navbar {
        display: none !important;
    }

    html,
    body {
        width: 100% !important;
        margin: 0 !important;
        padding: 0 !important;
        background: #fff !important;
        font-size: 8px !important;
    }

    .container {
        width: 100% !important;
        max-width: none !important;
        margin: 0 !important;
        padding: 0 !important;
    }

    .card {
        width: 100% !important;
        margin: 0 !important;
        box-shadow: none !important;
        border: 1px solid #dee2e6 !important;
        border-radius: 0 !important;
        overflow: visible !important;
    }

    .card-header {
        padding: 4px 6px !important;
        font-size: 8px !important;
    }

    .table-responsive {
        overflow: visible !important;
        width: 100% !important;
    }

    .department-report-table {
        width: 100% !important;
        max-width: 100% !important;
        table-layout: fixed !important;
        border-collapse: collapse !important;
        font-size: 7px !important;
        line-height: 1.08 !important;
        margin: 0 !important;
    }

    .department-report-table th,
    .department-report-table td {
        padding: 2px 2px !important;
        vertical-align: middle !important;
        white-space: normal !important;
        overflow-wrap: anywhere !important;
        word-break: normal !important;
    }

    .department-report-table th {
        font-size: 6.8px !important;
        line-height: 1.05 !important;
    }

    /*
     * Portrait A4 column widths.
     * More room is reserved for Faculty and Department;
     * numeric columns stay narrow.
     */
    .department-report-table th:nth-child(1),
    .department-report-table td:nth-child(1) { width: 4% !important; }

    .department-report-table th:nth-child(2),
    .department-report-table td:nth-child(2) { width: 12% !important; }

    .department-report-table th:nth-child(3),
    .department-report-table td:nth-child(3) { width: 26% !important; }

    .department-report-table th:nth-child(4),
    .department-report-table td:nth-child(4) { width: 6% !important; }

    .department-report-table th:nth-child(5),
    .department-report-table td:nth-child(5) { width: 8% !important; }

    .department-report-table th:nth-child(6),
    .department-report-table td:nth-child(6) { width: 8% !important; }

    .department-report-table th:nth-child(7),
    .department-report-table td:nth-child(7) { width: 9% !important; }

    .department-report-table th:nth-child(8),
    .department-report-table td:nth-child(8) { width: 9% !important; }

    .department-report-table th:nth-child(9),
    .department-report-table td:nth-child(9) { width: 9% !important; }

    .department-report-table th:nth-child(10),
    .department-report-table td:nth-child(10) { width: 9% !important; }

    /* Short print-only headings so every column fits in portrait. */
    .department-report-table thead th:nth-child(5),
    .department-report-table thead th:nth-child(6),
    .department-report-table thead th:nth-child(7),
    .department-report-table thead th:nth-child(8),
    .department-report-table thead th:nth-child(9),
    .department-report-table thead th:nth-child(10) {
        font-size: 0 !important;
    }

    .department-report-table thead th:nth-child(5)::after {
        content: "Alloc.";
        font-size: 6.8px;
    }

    .department-report-table thead th:nth-child(6)::after {
        content: "Reg.";
        font-size: 6.8px;
    }

    .department-report-table thead th:nth-child(7)::after {
        content: "Final";
        font-size: 6.8px;
    }

    .department-report-table thead th:nth-child(8)::after {
        content: "Occupied";
        font-size: 6.8px;
    }

    .department-report-table thead th:nth-child(9)::after {
        content: "Vacant";
        font-size: 6.8px;
    }

    .department-report-table thead th:nth-child(10)::after {
        content: "Waiting";
        font-size: 6.8px;
    }

    .department-report-table thead {
        display: table-header-group !important;
    }

    /* Keep the total only once, at the end of the report. */
    .department-report-table tfoot {
        display: table-row-group !important;
    }

    .department-report-table tr {
        break-inside: avoid !important;
        page-break-inside: avoid !important;
    }

    .department-report-table .badge {
        font-size: 6.8px !important;
        padding: 1px 2px !important;
    }

    .progress {
        display: none !important;
    }

    .d-print-block {
        margin-bottom: 5px !important;
    }

    .d-print-block h4 {
        font-size: 13px !important;
        margin-bottom: 2px !important;
    }

    .d-print-block > div {
        font-size: 8px !important;
    }
}
</style>


<?php require_once '../includes/footer.php'; ?>
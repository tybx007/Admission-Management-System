<?php

declare(strict_types=1);

$pageTitle = 'Merit List & Waiting List Management';

require_once '../includes/auth.php';
require_once '../config/db.php';
require_once '../includes/admission_engine.php';
require_once '../includes/admission_session.php';
require_once '../includes/session_vacancy_engine.php';

/*
|--------------------------------------------------------------------------
| Authentication BEFORE HTML output
|--------------------------------------------------------------------------
*/

requireLogin('dept_admin');

$deptId = (int) ($_SESSION['dept_id'] ?? 0);

if ($deptId <= 0) {
    http_response_code(403);
    exit('Department information is missing from your session.');
}

$pdo->setAttribute(
    PDO::ATTR_ERRMODE,
    PDO::ERRMODE_EXCEPTION
);


$currentAcademicYear =
    admissionSessionCurrentYear(
        $pdo
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
| Handle vacancy action BEFORE rendering HTML
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

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
                'Invalid request token. Refresh the page and try again.'
            );
        }


        if (!isset($_POST['vacate_seat'])) {
            throw new RuntimeException(
                'Unknown action.'
            );
        }


        $allocationId = filter_input(
            INPUT_POST,
            'allocation_id',
            FILTER_VALIDATE_INT
        );


        if (
            !$allocationId ||
            $allocationId <= 0
        ) {
            throw new RuntimeException(
                'Invalid allocation selected.'
            );
        }


        /*
        |--------------------------------------------------------------------------
        | Confirm the allocation belongs to THIS department and is still active
        |--------------------------------------------------------------------------
        */

        $allocationCheck = $pdo->prepare("
            SELECT
                ma.allocation_id,
                ma.allocation_status

            FROM merit_allocations ma

            INNER JOIN gst_imported_candidates gic
                ON gic.import_candidate_id =
                   ma.import_candidate_id

            WHERE ma.allocation_id = ?
              AND ma.dept_id = ?
              AND gic.academic_year = ?

            LIMIT 1
        ");

        $allocationCheck->execute([
            (int) $allocationId,
            $deptId,
            $currentAcademicYear
        ]);

        $allocation =
            $allocationCheck->fetch(
                PDO::FETCH_ASSOC
            );


        if (!$allocation) {
            throw new RuntimeException(
                'This allocation does not belong to your department.'
            );
        }


        if (
            $allocation['allocation_status']
            !== 'Allocated'
        ) {
            throw new RuntimeException(
                'Only a provisional Allocated seat can be vacated.'
            );
        }


        /*
        |--------------------------------------------------------------------------
        | Required reason for permanent removal
        |--------------------------------------------------------------------------
        */

        $vacateReason = trim(
            (string) (
                $_POST['vacate_reason']
                ?? ''
            )
        );

        if ($vacateReason === '') {
            throw new RuntimeException(
                'A proper reason for vacating this candidate is required.'
            );
        }

        $reasonLength = function_exists('mb_strlen')
            ? mb_strlen(
                $vacateReason,
                'UTF-8'
            )
            : strlen($vacateReason);

        if ($reasonLength < 5) {
            throw new RuntimeException(
                'Please enter a clearer vacate reason.'
            );
        }

        if ($reasonLength > 500) {
            throw new RuntimeException(
                'Vacate reason cannot exceed 500 characters.'
            );
        }


        /*
        |--------------------------------------------------------------------------
        | Use the admission engine and pass the EXACT reason
        |--------------------------------------------------------------------------
        */

        $result =
            vacateAndCallNextCandidateForSession(
                $pdo,
                (int) $allocationId,
                $deptId,
                $currentAcademicYear,
                true,
                $vacateReason,
                true
            );


        $notification =
            $result['notification']
            ?? null;

        $notificationText = '';

        if (is_array($notification)) {

            $mailText =
                !empty(
                    $notification['email_sent']
                )
                    ? 'Email sent'
                    : (
                        !empty(
                            $notification['email_available']
                        )
                            ? 'Email failed'
                            : 'Email unavailable'
                      );

            $smsText =
                !empty(
                    $notification['sms_sent']
                )
                    ? 'SMS sent'
                    : (
                        !empty(
                            $notification['phone_available']
                        )
                            ? 'SMS failed'
                            : 'Phone unavailable'
                      );

            $notificationText =
                ' Notification: '
                . $mailText
                . '; '
                . $smsText
                . '.';
        }


        $_SESSION['flash_message'] =
            '✅ '
            . (
                $result['message']
                ?? 'Candidate vacated successfully.'
              )
            . ' Reason: '
            . $vacateReason
            . '.'
            . $notificationText;

        $_SESSION['flash_type'] =
            'success';


    } catch (Throwable $exception) {

        $_SESSION['flash_message'] =
            '❌ ' . $exception->getMessage();

        $_SESSION['flash_type'] =
            'danger';
    }


    header(
        'Location: ' .
        basename($_SERVER['PHP_SELF'])
    );

    exit;
}


/*
|--------------------------------------------------------------------------
| Flash
|--------------------------------------------------------------------------
*/

$message =
    (string) (
        $_SESSION['flash_message']
        ?? ''
    );

$alertType =
    (string) (
        $_SESSION['flash_type']
        ?? 'info'
    );

unset(
    $_SESSION['flash_message'],
    $_SESSION['flash_type']
);


/*
|--------------------------------------------------------------------------
| Department information
|--------------------------------------------------------------------------
*/

$stmtDept = $pdo->prepare("
    SELECT
        d.dept_id,
        d.dept_code,
        d.dept_name,
        d.total_seats,
        f.faculty_name

    FROM departments d

    LEFT JOIN faculties f
        ON f.faculty_id =
           d.faculty_id

    WHERE d.dept_id = ?

    LIMIT 1
");

$stmtDept->execute([
    $deptId
]);

$deptInfo = $stmtDept->fetch(
    PDO::FETCH_ASSOC
);

if (!$deptInfo) {
    http_response_code(404);
    exit('Department not found.');
}

$deptName =
    (string) $deptInfo['dept_name'];

$totalSeats =
    max(
        0,
        (int) $deptInfo['total_seats']
    );

$facultyName =
    (string) (
        $deptInfo['faculty_name']
        ?? 'N/A'
    );


/*
|--------------------------------------------------------------------------
| Active merit list
|--------------------------------------------------------------------------
|
| IMPORTANT:
| merit_allocations is linked to the GST import source by import_candidate_id.
| applicant_id may still be NULL for pre-registration allocations.
|--------------------------------------------------------------------------
*/

$stmtMerit = $pdo->prepare("
    SELECT
        ma.allocation_id,
        ma.import_candidate_id,
        ma.applicant_id,
        ma.dept_id,
        ma.merit_rank,
        ma.allocation_status,
        ma.allocated_at,

        COALESCE(
            NULLIF(TRIM(ga.full_name), ''),
            gic.full_name
        ) AS full_name,

        gic.gst_roll,
        gic.gst_score,

        COALESCE(
            NULLIF(TRIM(ga.phone), ''),
            gic.phone
        ) AS phone,

        ds.submission_status AS dept_status,
        ap.final_status

    FROM merit_allocations ma

    INNER JOIN gst_imported_candidates gic
        ON gic.import_candidate_id =
           ma.import_candidate_id

    LEFT JOIN gst_applicants ga
        ON ga.applicant_id =
           gic.applicant_id

    LEFT JOIN dept_submissions ds
        ON ds.applicant_id =
           gic.applicant_id
       AND ds.dept_id =
           ma.dept_id

    LEFT JOIN admission_progress ap
        ON ap.applicant_id =
           gic.applicant_id

    WHERE ma.dept_id = ?
      AND gic.academic_year = ?
      AND ma.allocation_status IN (
          'Allocated',
          'Admitted'
      )

    ORDER BY
        ma.merit_rank ASC,
        ma.allocation_id ASC
");

$stmtMerit->execute([
    $deptId,
    $currentAcademicYear
]);

$meritList = $stmtMerit->fetchAll(
    PDO::FETCH_ASSOC
);


/*
|--------------------------------------------------------------------------
| Canonical waiting list
|--------------------------------------------------------------------------
|
| FINAL WAITING RULE:
|
| - Candidate appears only ONCE.
| - A candidate who failed to obtain ANY preferred department enters waiting.
| - The waiting department is the candidate's FIRST preference.
| - Old Choice 2 / Choice 3 / Choice 4 waiting rows are NOT displayed.
| - Candidate data comes from gst_imported_candidates because registration
|   is not required to be in the waiting list.
|--------------------------------------------------------------------------
*/

$stmtWaiting = $pdo->prepare("
    SELECT
        wl.waiting_id,
        wl.import_candidate_id,
        wl.applicant_id,
        wl.dept_id,
        wl.waiting_rank,
        wl.waiting_status,
        wl.called_at,

        gic.full_name,
        gic.gst_roll,
        gic.gst_score,
        gic.phone,

        gcc.preference_order

    FROM waiting_list wl

    INNER JOIN gst_imported_candidates gic
        ON gic.import_candidate_id =
           wl.import_candidate_id

    INNER JOIN gst_candidate_choices gcc
        ON gcc.import_candidate_id =
           wl.import_candidate_id
       AND gcc.dept_id =
           wl.dept_id

    WHERE wl.dept_id = ?
      AND gic.academic_year = ?
      AND wl.waiting_status = 'Waiting'

      AND gcc.preference_order = (
          SELECT MIN(gcc2.preference_order)
          FROM gst_candidate_choices gcc2
          WHERE gcc2.import_candidate_id =
                wl.import_candidate_id
      )

      /*
      | Protect the UI from old duplicate rows for the same candidate
      | and canonical waiting department.
      */
      AND wl.waiting_id = (
          SELECT MIN(wl2.waiting_id)
          FROM waiting_list wl2
          WHERE wl2.import_candidate_id =
                wl.import_candidate_id
            AND wl2.dept_id =
                wl.dept_id
      )

    ORDER BY
        wl.waiting_rank ASC,
        gic.merit_position ASC,
        gic.gst_score DESC,
        wl.waiting_id ASC
");

$stmtWaiting->execute([
    $deptId,
    $currentAcademicYear
]);

$waitingList =
    $stmtWaiting->fetchAll(
        PDO::FETCH_ASSOC
    );


/*
|--------------------------------------------------------------------------
| Dashboard counts
|--------------------------------------------------------------------------
|
| Allocation history can contain Vacated rows. Active seat counts are based
| only on the candidate's current active allocation for this department.
|--------------------------------------------------------------------------
*/

$allocationStatsStmt = $pdo->prepare("
    SELECT

        COUNT(
            DISTINCT CASE
                WHEN ma.allocation_status = 'Admitted'
                THEN ma.import_candidate_id
            END
        ) AS admitted_count,

        COUNT(
            DISTINCT CASE
                WHEN ma.allocation_status = 'Allocated'
                THEN ma.import_candidate_id
            END
        ) AS provisional_count,

        COUNT(
            CASE
                WHEN ma.allocation_status = 'Vacated'
                THEN 1
            END
        ) AS vacated_count

    FROM merit_allocations ma

    INNER JOIN gst_imported_candidates gic
        ON gic.import_candidate_id =
           ma.import_candidate_id

    WHERE ma.dept_id = ?
      AND gic.academic_year = ?
");

$allocationStatsStmt->execute([
    $deptId,
    $currentAcademicYear
]);

$allocationStats =
    $allocationStatsStmt->fetch(
        PDO::FETCH_ASSOC
    ) ?: [];


$admittedCount =
    (int) (
        $allocationStats['admitted_count']
        ?? 0
    );

$provisionalCount =
    (int) (
        $allocationStats['provisional_count']
        ?? 0
    );

$vacatedCount =
    (int) (
        $allocationStats['vacated_count']
        ?? 0
    );


$waitingCount = 0;
$calledCount = 0;
$expiredCount = 0;

foreach ($waitingList as $wait) {

    switch ($wait['waiting_status']) {

        case 'Waiting':
            $waitingCount++;
            break;

        case 'Called':
            $calledCount++;
            break;

        case 'Expired':
            $expiredCount++;
            break;
    }
}


$activeSeatCount =
    $admittedCount +
    $provisionalCount;


$finalFillPercentage =
    $totalSeats > 0
        ? min(
            100,
            (int) round(
                (
                    $admittedCount /
                    $totalSeats
                ) * 100
            )
        )
        : 0;


$activeOccupancyPercentage =
    $totalSeats > 0
        ? min(
            100,
            (int) round(
                (
                    $activeSeatCount /
                    $totalSeats
                ) * 100
            )
        )
        : 0;


/*
|--------------------------------------------------------------------------
| Layout
|--------------------------------------------------------------------------
*/

require_once '../includes/header.php';
require_once '../includes/navbar.php';

?>

<div class="container py-4">

    <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">

        <div>

            <h2 class="mb-1">

                <i class="bi bi-trophy text-warning me-2"></i>

                <?= e($deptName) ?>

            </h2>


            <small class="text-muted">

                <?= e($facultyName) ?>

                Faculty

                <?php if (
                    !empty($deptInfo['dept_code'])
                ): ?>

                    · <?= e($deptInfo['dept_code']) ?>

                <?php endif; ?>

            </small>

        </div>


        <div>

            <a
                href="dashboard.php"
                class="btn btn-outline-secondary me-2"
            >

                <i class="bi bi-house-door me-1"></i>
                Dashboard

            </a>


            <a
                href="viva_list.php?dept_id=<?= $deptId ?>"
                class="btn btn-outline-primary"
            >

                <i class="bi bi-people me-1"></i>
                Viva Management

            </a>

        </div>

    </div>


    <?php if ($message !== ''): ?>

        <div
            class="alert alert-<?= e($alertType) ?> alert-dismissible fade show"
            role="alert"
        >

            <?= e($message) ?>


            <button
                type="button"
                class="btn-close"
                data-bs-dismiss="alert"
                aria-label="Close"
            ></button>

        </div>

    <?php endif; ?>


    <!-- =========================================================
         SUMMARY
    ========================================================== -->

    <div class="row g-3 mb-4">


        <div class="col-md-3">

            <div class="card shadow-sm h-100 border-success">

                <div class="card-body">

                    <div class="text-muted small">
                        Finally admitted
                    </div>

                    <div class="display-6 fw-bold text-success">
                        <?= $admittedCount ?>
                    </div>

                    <div class="small">
                        Out of <?= $totalSeats ?> seats
                    </div>

                </div>

            </div>

        </div>


        <div class="col-md-3">

            <div class="card shadow-sm h-100 border-warning">

                <div class="card-body">

                    <div class="text-muted small">
                        Provisional allocations
                    </div>

                    <div class="display-6 fw-bold text-warning">
                        <?= $provisionalCount ?>
                    </div>

                    <div class="small">
                        Admission not yet finalized
                    </div>

                </div>

            </div>

        </div>


        <div class="col-md-3">

            <div class="card shadow-sm h-100 border-info">

                <div class="card-body">

                    <div class="text-muted small">
                        Waiting candidates
                    </div>

                    <div class="display-6 fw-bold text-info">
                        <?= $waitingCount ?>
                    </div>

                    <div class="small">
                        <?= $calledCount ?> currently called
                    </div>

                </div>

            </div>

        </div>


        <div class="col-md-3">

            <div class="card shadow-sm h-100 border-danger">

                <div class="card-body">

                    <div class="text-muted small">
                        Vacated history
                    </div>

                    <div class="display-6 fw-bold text-danger">
                        <?= $vacatedCount ?>
                    </div>

                    <div class="small">
                        Previous provisional seats
                    </div>

                </div>

            </div>

        </div>

    </div>


    <!-- =========================================================
         SEAT PROGRESS
    ========================================================== -->

    <div class="card shadow-sm mb-4">

        <div class="card-body">


            <div class="d-flex justify-content-between mb-2">

                <strong>
                    Final seat completion
                </strong>

                <span>

                    <?= $admittedCount ?>
                    /
                    <?= $totalSeats ?>

                    (<?= $finalFillPercentage ?>%)

                </span>

            </div>


            <div
                class="progress mb-4"
                style="height:24px;"
            >

                <div
                    class="progress-bar bg-success"
                    role="progressbar"
                    style="width:<?= $finalFillPercentage ?>%;"
                    aria-valuenow="<?= $finalFillPercentage ?>"
                    aria-valuemin="0"
                    aria-valuemax="100"
                >

                    <?= $finalFillPercentage ?>%

                </div>

            </div>


            <div class="d-flex justify-content-between mb-2">

                <strong>
                    Current provisional occupancy
                </strong>

                <span>

                    <?= $activeSeatCount ?>
                    /
                    <?= $totalSeats ?>

                    (<?= $activeOccupancyPercentage ?>%)

                </span>

            </div>


            <div
                class="progress"
                style="height:24px;"
            >

                <div
                    class="progress-bar bg-warning text-dark"
                    role="progressbar"
                    style="width:<?= $activeOccupancyPercentage ?>%;"
                    aria-valuenow="<?= $activeOccupancyPercentage ?>"
                    aria-valuemin="0"
                    aria-valuemax="100"
                >

                    <?= $activeOccupancyPercentage ?>%

                </div>

            </div>

        </div>

    </div>


    <!-- =========================================================
         TABLES
    ========================================================== -->

    <div class="row g-4">


        <!-- MERIT HISTORY -->

        <div class="col-xl-8">

            <div class="card shadow-sm">


                <div
                    class="card-header bg-success text-white
                           d-flex justify-content-between
                           align-items-center"
                >

                    <h6 class="mb-0">

                        <i class="bi bi-award me-2"></i>

                        Merit List

                    </h6>


                    <span class="badge bg-light text-success fs-6">

                        <?= count($meritList) ?> active

                    </span>

                </div>


                <div class="table-responsive">

                    <table class="table table-hover align-middle mb-0">


                        <thead class="table-light">

                            <tr>

                                <th>Rank</th>
                                <th>GST Roll</th>
                                <th>Candidate</th>
                                <th>Score</th>
                                <th>Submission</th>
                                <th>Status</th>
                                <th>Action</th>

                            </tr>

                        </thead>


                        <tbody>


                        <?php if ($meritList): ?>


                            <?php foreach (
                                $meritList as $merit
                            ): ?>

                                <?php

                                $rowClass = '';

                                if (
                                    $merit['allocation_status']
                                    === 'Admitted'
                                ) {
                                    $rowClass =
                                        'table-success';
                                }

                                ?>


                                <tr class="<?= $rowClass ?>">


                                    <td>

                                        <span class="badge bg-dark">

                                            #<?= (int)
                                                $merit[
                                                    'merit_rank'
                                                ]
                                            ?>

                                        </span>

                                    </td>


                                    <td>

                                        <strong>
                                            <?= e(
                                                $merit[
                                                    'gst_roll'
                                                ]
                                            ) ?>
                                        </strong>

                                    </td>


                                    <td>

                                        <?= e(
                                            $merit[
                                                'full_name'
                                            ]
                                        ) ?>


                                        <?php if (
                                            !empty(
                                                $merit['phone']
                                            )
                                        ): ?>

                                            <br>

                                            <small class="text-muted">

                                                <i
                                                    class="bi bi-telephone"
                                                ></i>

                                                <?= e(
                                                    $merit[
                                                        'phone'
                                                    ]
                                                ) ?>

                                            </small>

                                        <?php endif; ?>

                                    </td>


                                    <td>

                                        <strong>

                                            <?= number_format(
                                                (float)
                                                $merit[
                                                    'gst_score'
                                                ],
                                                2
                                            ) ?>

                                        </strong>

                                    </td>


                                    <td>

                                        <?php if (
                                            !empty(
                                                $merit[
                                                    'dept_status'
                                                ]
                                            )
                                        ): ?>

                                            <?php

                                            $submissionBadge =
                                                match (
                                                    $merit[
                                                        'dept_status'
                                                    ]
                                                ) {
                                                    'Verified' =>
                                                        'success',

                                                    'Rejected' =>
                                                        'danger',

                                                    default =>
                                                        'warning text-dark'
                                                };

                                            ?>

                                            <span
                                                class="badge bg-<?= $submissionBadge ?>"
                                            >

                                                <?= e(
                                                    ucwords(
                                                        str_replace(
                                                            '_',
                                                            ' ',
                                                            (string)
                                                            $merit[
                                                                'dept_status'
                                                            ]
                                                        )
                                                    )
                                                ) ?>

                                            </span>


                                        <?php else: ?>

                                            <span
                                                class="badge bg-secondary"
                                            >
                                                Not Submitted
                                            </span>

                                        <?php endif; ?>

                                    </td>


                                    <td>

                                        <?php

                                        $allocationBadge =
                                            match (
                                                $merit[
                                                    'allocation_status'
                                                ]
                                            ) {
                                                'Admitted' =>
                                                    'success',

                                                'Allocated' =>
                                                    'warning text-dark',

                                                'Vacated' =>
                                                    'danger',

                                                default =>
                                                    'secondary'
                                            };

                                        ?>

                                        <span
                                            class="badge bg-<?= $allocationBadge ?>"
                                        >

                                            <?= e(
                                                $merit[
                                                    'allocation_status'
                                                ]
                                            ) ?>

                                        </span>


                                        <?php if (
                                            !empty(
                                                $merit[
                                                    'final_status'
                                                ]
                                            )
                                        ): ?>

                                            <br>

                                            <small class="text-muted">

                                                <?= e(
                                                    $merit[
                                                        'final_status'
                                                    ]
                                                ) ?>

                                            </small>

                                        <?php endif; ?>

                                    </td>


                                    <td>


                                        <?php if (
                                            $merit[
                                                'allocation_status'
                                            ] === 'Allocated'
                                        ): ?>


                                            <form
                                                method="POST"
                                                class="d-inline"
                                                onsubmit="return confirmVacate(
                                                    this,
                                                    <?= (int)
                                                        $merit[
                                                            'merit_rank'
                                                        ]
                                                    ?>
                                                );"
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
                                                    name="allocation_id"
                                                    value="<?= (int)
                                                        $merit[
                                                            'allocation_id'
                                                        ]
                                                    ?>"
                                                >

                                                <input
                                                    type="hidden"
                                                    name="vacate_reason"
                                                    value=""
                                                    class="vacate-reason"
                                                >

                                                <input
                                                    type="hidden"
                                                    name="vacate_seat"
                                                    value="1"
                                                >


                                                <button
                                                    type="submit"
                                                    class="btn btn-sm
                                                           btn-outline-danger"
                                                    title="Permanently vacate this candidate, notify by email/SMS, and promote the next waiting candidate"
                                                >

                                                    <i
                                                        class="bi bi-x-circle"
                                                    ></i>

                                                    Vacate

                                                </button>

                                            </form>


                                        <?php elseif (
                                            $merit[
                                                'allocation_status'
                                            ] === 'Admitted'
                                        ): ?>


                                            <span
                                                class="badge bg-success"
                                            >

                                                <i
                                                    class="bi bi-check-lg"
                                                ></i>

                                                Finalized

                                            </span>


                                        <?php endif; ?>

                                    </td>

                                </tr>


                            <?php endforeach; ?>


                        <?php else: ?>


                            <tr>

                                <td
                                    colspan="7"
                                    class="text-center py-5"
                                >

                                    <i
                                        class="bi bi-inbox
                                               fs-1 text-muted
                                               d-block mb-3"
                                    ></i>

                                    <h6 class="text-muted">
                                        No merit allocations found
                                    </h6>

                                </td>

                            </tr>


                        <?php endif; ?>


                        </tbody>

                    </table>

                </div>

            </div>

        </div>


        <!-- WAITING LIST -->

        <div class="col-xl-4">

            <div class="card shadow-sm">


                <div
                    class="card-header bg-warning
                           d-flex justify-content-between
                           align-items-center"
                >

                    <h6 class="mb-0">

                        <i class="bi bi-list-nested me-2"></i>

                        Waiting List

                    </h6>


                    <span class="badge bg-dark">

                        <?= count($waitingList) ?> waiting

                    </span>

                </div>


                <div class="list-group list-group-flush">


                    <?php if ($waitingList): ?>


                        <?php foreach (
                            $waitingList as $wait
                        ): ?>

                            <?php

                            $waitingBadge =
                                match (
                                    $wait[
                                        'waiting_status'
                                    ]
                                ) {
                                    'Waiting' =>
                                        'secondary',

                                    'Called' =>
                                        'warning text-dark',

                                    'Admitted' =>
                                        'success',

                                    'Expired' =>
                                        'danger',

                                    default =>
                                        'info'
                                };

                            ?>


                            <div
                                class="list-group-item
                                       px-3 py-3"
                            >


                                <div
                                    class="d-flex
                                           justify-content-between
                                           align-items-start
                                           gap-3"
                                >


                                    <div>


                                        <div class="fw-bold">

                                            #<?= (int)
                                                $wait[
                                                    'waiting_rank'
                                                ]
                                            ?>

                                            <?= e(
                                                $wait[
                                                    'gst_roll'
                                                ]
                                            ) ?>

                                        </div>


                                        <div
                                            class="text-muted small"
                                        >

                                            <?= e(
                                                $wait[
                                                    'full_name'
                                                ]
                                            ) ?>

                                        </div>


                                        <div class="small mt-1">

                                            Score:

                                            <strong>

                                                <?= number_format(
                                                    (float)
                                                    $wait[
                                                        'gst_score'
                                                    ],
                                                    2
                                                ) ?>

                                            </strong>

                                        </div>


                                        <?php if (
                                            !empty(
                                                $wait['phone']
                                            )
                                        ): ?>

                                            <div class="small mt-1">

                                                <i
                                                    class="bi
                                                           bi-telephone-fill
                                                           me-1"
                                                ></i>

                                                <?= e(
                                                    $wait[
                                                        'phone'
                                                    ]
                                                ) ?>

                                            </div>

                                        <?php endif; ?>

                                    </div>


                                    <div class="text-end">


                                        <span
                                            class="badge bg-<?= $waitingBadge ?>"
                                        >

                                            <?= e(
                                                $wait[
                                                    'waiting_status'
                                                ]
                                            ) ?>

                                        </span>


                                        <?php if (
                                            !empty(
                                                $wait[
                                                    'called_at'
                                                ]
                                            )
                                        ): ?>

                                            <div
                                                class="small
                                                       text-muted
                                                       mt-2"
                                            >

                                                <?= date(
                                                    'M j, Y',
                                                    strtotime(
                                                        (string)
                                                        $wait[
                                                            'called_at'
                                                        ]
                                                    )
                                                ) ?>

                                            </div>

                                        <?php endif; ?>

                                    </div>

                                </div>

                            </div>


                        <?php endforeach; ?>


                    <?php else: ?>


                        <div
                            class="text-center
                                   py-5 text-muted"
                        >

                            <i
                                class="bi bi-clipboard-x
                                       fs-1 d-block mb-3"
                            ></i>

                            <h6>
                                No waiting candidates
                            </h6>

                        </div>


                    <?php endif; ?>

                </div>


                <div class="card-footer small text-muted">

                    Only active Waiting candidates are shown here.
                    When rank #1 is promoted, they leave this list and
                    the remaining candidates are re-ranked automatically.

                </div>

            </div>

        </div>

    </div>


    <!-- =========================================================
         WORKFLOW NOTE
    ========================================================== -->

    <div class="alert alert-info mt-4">

        <div class="d-flex align-items-start">

            <i
                class="bi bi-info-circle-fill
                       fs-3 me-3"
            ></i>

            <div>

                <strong>
                    Vacancy workflow
                </strong>


                <div class="mt-2">

                    Department admin clicks
                    <strong>Vacate</strong>

                    → the selected provisional allocation
                    becomes <strong>Vacated</strong>

                    → the vacated candidate disappears from the active merit list

                    → the remaining merit candidates move up one position

                    → waiting rank <strong>#1</strong> leaves the waiting list
                    and enters the <strong>last active merit position</strong>

                    → the remaining waiting candidates are re-ranked
                    automatically.

                </div>


                <div class="mt-2">

                    Existing viva results and final admissions
                    are not reset. The full merit list is never
                    regenerated.

                </div>

            </div>

        </div>

    </div>

</div>


<script>
function confirmVacate(form, rank) {

    const reason = prompt(
        'Enter the exact reason that will be sent to the candidate.\n\n' +
        'Example: Absent from the scheduled viva on 26 August 2026.'
    );

    if (reason === null) {
        return false;
    }

    const cleanedReason = reason.trim();

    if (cleanedReason.length < 5) {
        alert(
            'Please enter a clear and proper vacate reason.'
        );
        return false;
    }

    if (cleanedReason.length > 500) {
        alert(
            'Vacate reason cannot exceed 500 characters.'
        );
        return false;
    }

    form.querySelector(
        '.vacate-reason'
    ).value = cleanedReason;

    return confirm(
        'Permanently vacate merit #' +
        rank +
        '?\n\n' +
        'Reason: ' +
        cleanedReason +
        '\n\n' +
        '• The candidate will be removed from the active admission process.\n' +
        '• Email and SMS notifications will be attempted with this reason.\n' +
        '• Remaining merit candidates will move up.\n' +
        '• Waiting rank #1 will enter the last merit position.\n' +
        '• The remaining waiting list will be re-ranked.'
    );
}
</script>


<?php require_once '../includes/footer.php'; ?>
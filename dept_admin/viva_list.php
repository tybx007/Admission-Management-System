<?php

declare(strict_types=1);

$pageTitle = 'Viva Voce Management';

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

if (
    !isset($_SESSION['user_id']) ||
    ($_SESSION['role'] ?? '') !== 'dept_admin'
) {
    header(
        'Location: /jkkniu-admission/dept_admin/login.php'
    );
    exit;
}

$deptId = (int) (
    $_SESSION['dept_id'] ?? 0
);

if ($deptId <= 0) {
    http_response_code(403);
    exit(
        'Department information is missing from your session.'
    );
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

function viva_e(mixed $value): string
{
    return htmlspecialchars(
        (string) ($value ?? ''),
        ENT_QUOTES,
        'UTF-8'
    );
}


function vivaCallBadge(string $status): array
{
    return match ($status) {
        'Open' => [
            'label' => 'OPEN',
            'class' => 'bg-success'
        ],
        'Closed' => [
            'label' => 'CLOSED',
            'class' => 'bg-secondary'
        ],
        default => [
            'label' => 'LOCKED',
            'class' => 'bg-warning text-dark'
        ]
    };
}


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
        d.faculty_id,
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

$facultyId =
    (int) $deptInfo['faculty_id'];

$facultyName =
    (string) (
        $deptInfo['faculty_name']
        ?? 'N/A'
    );

$totalSeats =
    (int) $deptInfo['total_seats'];


/*
|--------------------------------------------------------------------------
| Merit Calls available to this department
|--------------------------------------------------------------------------
|
| Viva is opened/closed universally by the Registrar on merit_calls.
| A Department Admin only processes candidates of this department for the
| selected Merit Call.
|--------------------------------------------------------------------------
*/

$stmtCalls = $pdo->prepare("
    SELECT DISTINCT
        mc.merit_call_id,
        mc.call_number,
        mc.call_title,
        mc.registration_start,
        mc.registration_deadline,
        mc.processed_at,
        mc.viva_status,
        mc.viva_date,
        mc.viva_reporting_time,
        mc.viva_instructions,
        mc.viva_started_at,
        mc.viva_closed_at

    FROM merit_calls mc

    WHERE mc.published_at IS NOT NULL

      AND mc.academic_year = ?

      AND EXISTS (
          SELECT 1
          FROM merit_allocations ma
          WHERE ma.merit_call_id = mc.merit_call_id
            AND ma.dept_id = ?
      )

    ORDER BY mc.call_number DESC
");

$stmtCalls->execute([
    $currentAcademicYear,
    $deptId
]);

$meritCalls = $stmtCalls->fetchAll(
    PDO::FETCH_ASSOC
);


$requestedCallId = (int) (
    $_POST['merit_call_id']
    ?? $_GET['merit_call_id']
    ?? 0
);

$selectedCall = null;

if ($requestedCallId > 0) {

    foreach ($meritCalls as $call) {

        if (
            (int) $call['merit_call_id']
            === $requestedCallId
        ) {
            $selectedCall = $call;
            break;
        }
    }
}


/* Default to the currently Open universal Viva, otherwise latest call. */
if (!$selectedCall) {

    foreach ($meritCalls as $call) {

        if ((string) $call['viva_status'] === 'Open') {
            $selectedCall = $call;
            break;
        }
    }
}

if (!$selectedCall && $meritCalls) {
    $selectedCall = $meritCalls[0];
}

$selectedCallId = $selectedCall
    ? (int) $selectedCall['merit_call_id']
    : 0;

$vivaIsOpen =
    $selectedCall
    &&
    (string) $selectedCall['viva_status'] === 'Open';


/*
|--------------------------------------------------------------------------
| Flash
|--------------------------------------------------------------------------
*/

$flash = $_SESSION['viva_flash'] ?? null;
unset($_SESSION['viva_flash']);


/*
|--------------------------------------------------------------------------
| POST — Record viva result
|--------------------------------------------------------------------------
|
| IMPORTANT:
| There is intentionally NO "Auto Process Viva" anymore.
|
| Merit allocation has already determined the seat holder.
| Viva decides:
|
| Selected      -> continue admission.
| Not Selected  -> rejected permanently + allocation vacated + next waiting.
| Absent        -> Call Later + allocation vacated + next waiting.
|
| Rejected / Absent candidates are NEVER inserted into waiting_list.
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


        if (
            !$selectedCall
            ||
            $selectedCallId <= 0
        ) {
            throw new RuntimeException(
                'Select a valid Merit Call.'
            );
        }


        /* Re-check the universal Viva state from the database. */
        $liveCallStmt = $pdo->prepare("
            SELECT
                merit_call_id,
                academic_year,
                call_title,
                processed_at,
                viva_status
            FROM merit_calls
            WHERE merit_call_id = ?
              AND academic_year = ?
            LIMIT 1
        ");

        $liveCallStmt->execute([
            $selectedCallId,
            $currentAcademicYear
        ]);

        $liveCall = $liveCallStmt->fetch(
            PDO::FETCH_ASSOC
        );

        if (!$liveCall) {
            throw new RuntimeException(
                'Merit Call was not found.'
            );
        }

        if (
            empty($liveCall['processed_at'])
            ||
            (string) $liveCall['viva_status'] !== 'Open'
        ) {
            throw new RuntimeException(
                'Viva is not Open for this Merit Call. The Registrar controls Viva universally.'
            );
        }


        $vivaStatus = trim(
            (string) (
                $_POST['viva_status'] ?? ''
            )
        );


        if (
            !in_array(
                $vivaStatus,
                [
                    'Selected',
                    'Not Selected',
                    'Absent'
                ],
                true
            )
        ) {
            throw new RuntimeException(
                'Invalid viva status.'
            );
        }


        $rawIds =
            $_POST['applicant_ids']
            ?? [];

        if (!is_array($rawIds)) {
            $rawIds = [];
        }


        $applicantIds = [];

        foreach ($rawIds as $rawId) {

            $id = (int) $rawId;

            if ($id > 0) {
                $applicantIds[$id] = $id;
            }
        }

        $applicantIds =
            array_values($applicantIds);


        if (!$applicantIds) {
            throw new RuntimeException(
                'Select at least one candidate.'
            );
        }


        $pdo->beginTransaction();


        /*
        |--------------------------------------------------------------------------
        | Lock all selected active allocations
        |--------------------------------------------------------------------------
        */

        $placeholders =
            implode(
                ',',
                array_fill(
                    0,
                    count($applicantIds),
                    '?'
                )
            );

        $candidateSql = "
            SELECT
                ma.allocation_id,
                ma.import_candidate_id,
                ma.merit_rank,
                ma.allocation_status,

                gic.applicant_id,
                gic.gst_roll,
                gic.full_name

            FROM merit_allocations ma

            INNER JOIN gst_imported_candidates gic
                ON gic.import_candidate_id =
                   ma.import_candidate_id

            WHERE ma.dept_id = ?
              AND ma.merit_call_id = ?
              AND gic.academic_year = ?
              AND ma.allocation_status = 'Allocated'
              AND gic.is_registered = 1
              AND gic.applicant_id IS NOT NULL
              AND gic.applicant_id IN (
                  {$placeholders}
              )

              AND ma.allocation_id = (
                  SELECT MAX(ma2.allocation_id)
                  FROM merit_allocations ma2
                  WHERE ma2.import_candidate_id =
                        ma.import_candidate_id
                    AND ma2.allocation_status IN (
                        'Allocated',
                        'Admitted'
                    )
              )

            ORDER BY ma.merit_rank ASC

            FOR UPDATE
        ";

        $candidateStmt =
            $pdo->prepare($candidateSql);

        $candidateStmt->execute(
            array_merge(
                [
                    $deptId,
                    $selectedCallId,
                    $currentAcademicYear
                ],
                $applicantIds
            )
        );

        $selectedCandidates =
            $candidateStmt->fetchAll(
                PDO::FETCH_ASSOC
            );


        if (
            count($selectedCandidates)
            !== count($applicantIds)
        ) {
            throw new RuntimeException(
                'One or more selected candidates no longer have an active provisional allocation in this department.'
            );
        }


        /*
        |--------------------------------------------------------------------------
        | Prevent overwriting an already recorded viva result
        |--------------------------------------------------------------------------
        */

        $existingStmt = $pdo->prepare("
            SELECT
                applicant_id,
                viva_status

            FROM viva_results

            WHERE applicant_id IN (
                {$placeholders}
            )
        ");

        $existingStmt->execute(
            $applicantIds
        );

        $existingResults =
            $existingStmt->fetchAll(
                PDO::FETCH_ASSOC
            );


        if ($existingResults) {
            throw new RuntimeException(
                'One or more selected candidates already have a viva result. This page does not overwrite completed viva decisions.'
            );
        }


        /*
        |--------------------------------------------------------------------------
        | Prepared statements
        |--------------------------------------------------------------------------
        */

        $insertViva = $pdo->prepare("
            INSERT INTO viva_results (
                applicant_id,
                faculty_id,
                viva_status,
                conducted_at
            )
            VALUES (
                ?,
                ?,
                ?,
                NOW()
            )

            ON DUPLICATE KEY UPDATE
                faculty_id =
                    VALUES(faculty_id),
                viva_status =
                    VALUES(viva_status),
                conducted_at =
                    NOW()
        ");


        $selectedProgress = $pdo->prepare("
            INSERT INTO admission_progress (
                applicant_id
            )
            VALUES (?)

            ON DUPLICATE KEY UPDATE
                applicant_id =
                    VALUES(applicant_id)
        ");


        $rejectedProgress = $pdo->prepare("
            INSERT INTO admission_progress (
                applicant_id,
                final_status
            )
            VALUES (
                ?,
                'Rejected'
            )

            ON DUPLICATE KEY UPDATE
                final_status =
                    'Rejected'
        ");


        $promotedCount = 0;


        /*
        |--------------------------------------------------------------------------
        | Process candidates
        |--------------------------------------------------------------------------
        */

        foreach (
            $selectedCandidates as
            $candidate
        ) {

            $applicantId =
                (int) $candidate[
                    'applicant_id'
                ];

            $allocationId =
                (int) $candidate[
                    'allocation_id'
                ];


            if ($applicantId <= 0) {
                throw new RuntimeException(
                    'Viva can only be recorded after the allocated candidate has registered.'
                );
            }


            /*
            | Save viva result first.
            */

            $insertViva->execute([
                $applicantId,
                $facultyId,
                $vivaStatus
            ]);


            /*
            |--------------------------------------------------------------------------
            | SELECTED
            |--------------------------------------------------------------------------
            */

            if ($vivaStatus === 'Selected') {

                $selectedProgress->execute([
                    $applicantId
                ]);

                continue;
            }


            /*
            |--------------------------------------------------------------------------
            | NOT SELECTED
            |--------------------------------------------------------------------------
            |
            | Permanent rejection.
            |--------------------------------------------------------------------------
            */

            if (
                $vivaStatus ===
                'Not Selected'
            ) {

                $rejectedProgress->execute([
                    $applicantId
                ]);
            }


            /*
            |--------------------------------------------------------------------------
            | ABSENT
            |--------------------------------------------------------------------------
            |
            | The viva_results row with status Absent is the current Call Later
            | record in this implementation.
            |
            | It is deliberately NOT inserted into waiting_list.
            |--------------------------------------------------------------------------
            */


            /*
            |--------------------------------------------------------------------------
            | Release seat and promote next real waiting candidate
            |--------------------------------------------------------------------------
            |
            | The fixed admission engine accepts false as the 4th argument so
            | this whole bulk viva action remains one atomic transaction.
            |--------------------------------------------------------------------------
            */

            $automaticVacateReason =
                $vivaStatus === 'Absent'
                    ? 'Absent from the scheduled viva.'
                    : 'Not selected in the viva voce.';


            $promotion =
                vacateAndCallNextCandidateForSession(
                    $pdo,
                    $allocationId,
                    $deptId,
                    $currentAcademicYear,
                    false,
                    $automaticVacateReason,
                    $vivaStatus !== 'Absent'
                );


            if (
                !empty(
                    $promotion[
                        'candidate_called'
                    ]
                )
            ) {
                $promotedCount++;
            }
        }


        $pdo->commit();


        if ($vivaStatus === 'Selected') {

            $message =
                count($selectedCandidates) .
                ' candidate(s) marked Selected. They may continue to department submission.';

        } elseif (
            $vivaStatus ===
            'Not Selected'
        ) {

            $message =
                count($selectedCandidates) .
                ' candidate(s) rejected permanently. ' .
                $promotedCount .
                ' waiting candidate(s) were promoted for the released seat(s).';

        } else {

            $message =
                count($selectedCandidates) .
                ' candidate(s) marked Absent and moved to Call Later. ' .
                $promotedCount .
                ' waiting candidate(s) were promoted for the released seat(s).';
        }


        $_SESSION['viva_flash'] = [
            'type' => 'success',
            'msg' => $message
        ];


    } catch (Throwable $exception) {

        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }


        $_SESSION['viva_flash'] = [
            'type' => 'danger',
            'msg' =>
                $exception->getMessage()
        ];
    }


    $redirect =
        basename($_SERVER['PHP_SELF']);

    if ($selectedCallId > 0) {
        $redirect .=
            '?merit_call_id=' .
            $selectedCallId;
    }

    header(
        'Location: ' . $redirect
    );

    exit;
}


/*
|--------------------------------------------------------------------------
| Active allocation / viva list
|--------------------------------------------------------------------------
|
| Pre-registration allocations remain visible.
| Checkbox is enabled only after applicant registration and before viva result.
|--------------------------------------------------------------------------
*/

$stmtCandidates = $pdo->prepare("
    SELECT
        ma.allocation_id,
        ma.import_candidate_id,
        ma.merit_rank,
        ma.allocation_status,

        gic.applicant_id,
        gic.gst_roll,
        gic.full_name,
        gic.gst_score,
        gic.phone,
        gic.is_registered,

        ga.email,

        vr.viva_status,
        vr.conducted_at

    FROM merit_allocations ma

    INNER JOIN gst_imported_candidates gic
        ON gic.import_candidate_id =
           ma.import_candidate_id

    LEFT JOIN gst_applicants ga
        ON ga.applicant_id =
           gic.applicant_id

    LEFT JOIN viva_results vr
        ON vr.applicant_id =
           gic.applicant_id

    WHERE ma.dept_id = ?
      AND ma.merit_call_id = ?
      AND gic.academic_year = ?
      AND ma.allocation_status IN (
          'Allocated',
          'Admitted'
      )

      AND ma.allocation_id = (
          SELECT MAX(ma2.allocation_id)
          FROM merit_allocations ma2
          WHERE ma2.import_candidate_id =
                ma.import_candidate_id
            AND ma2.allocation_status IN (
                'Allocated',
                'Admitted'
            )
      )

    ORDER BY
        ma.merit_rank ASC,
        gic.merit_position ASC
");

$stmtCandidates->execute([
    $deptId,
    $selectedCallId,
    $currentAcademicYear
]);

$meritList =
    $stmtCandidates->fetchAll(
        PDO::FETCH_ASSOC
    );


/*
|--------------------------------------------------------------------------
| Viva statistics for this department
|--------------------------------------------------------------------------
|
| Rejected / Absent candidates may already have Vacated allocation history,
| so statistics are based on ANY historical allocation in this department.
|--------------------------------------------------------------------------
*/

$stmtStats = $pdo->prepare("
    SELECT

        COUNT(
            DISTINCT CASE
                WHEN vr.viva_status = 'Selected'
                THEN vr.applicant_id
            END
        ) AS selected_count,

        COUNT(
            DISTINCT CASE
                WHEN vr.viva_status = 'Not Selected'
                THEN vr.applicant_id
            END
        ) AS rejected_count,

        COUNT(
            DISTINCT CASE
                WHEN vr.viva_status = 'Absent'
                THEN vr.applicant_id
            END
        ) AS absent_count

    FROM viva_results vr

    INNER JOIN gst_imported_candidates gic
        ON gic.applicant_id =
           vr.applicant_id

    WHERE gic.academic_year = ?

      AND EXISTS (
        SELECT 1
        FROM merit_allocations ma
        WHERE ma.import_candidate_id =
              gic.import_candidate_id
          AND ma.dept_id = ?
          AND ma.merit_call_id = ?
    )
");

$stmtStats->execute([
    $currentAcademicYear,
    $deptId,
    $selectedCallId
]);

$vivaStats =
    $stmtStats->fetch(
        PDO::FETCH_ASSOC
    ) ?: [
        'selected_count' => 0,
        'rejected_count' => 0,
        'absent_count' => 0
    ];


$pendingCount = 0;
$unregisteredCount = 0;

foreach ($meritList as $candidate) {

    if (
        empty(
            $candidate[
                'applicant_id'
            ]
        )
        ||
        (int) $candidate['is_registered'] !== 1
    ) {
        $unregisteredCount++;
        continue;
    }


    if (
        empty(
            $candidate[
                'viva_status'
            ]
        ) &&
        $candidate[
            'allocation_status'
        ] === 'Allocated'
    ) {
        $pendingCount++;
    }
}


/*
|--------------------------------------------------------------------------
| Call Later list
|--------------------------------------------------------------------------
|
| Until a dedicated call_later table is introduced, Absent viva records are
| displayed separately here and are NOT mixed with waiting_list.
|--------------------------------------------------------------------------
*/

$stmtCallLater = $pdo->prepare("
    SELECT DISTINCT
        vr.applicant_id,
        vr.conducted_at,

        gic.gst_roll,
        gic.full_name,
        gic.gst_score,
        gic.phone

    FROM viva_results vr

    INNER JOIN gst_imported_candidates gic
        ON gic.applicant_id =
           vr.applicant_id

    WHERE vr.viva_status = 'Absent'

      AND gic.academic_year = ?

      AND EXISTS (
          SELECT 1
          FROM merit_allocations ma
          WHERE ma.import_candidate_id =
                gic.import_candidate_id
            AND ma.dept_id = ?
            AND ma.merit_call_id = ?
      )

    ORDER BY
        vr.conducted_at ASC,
        gic.merit_position ASC
");

$stmtCallLater->execute([
    $currentAcademicYear,
    $deptId,
    $selectedCallId
]);

$callLaterList =
    $stmtCallLater->fetchAll(
        PDO::FETCH_ASSOC
    );


/*
|--------------------------------------------------------------------------
| Layout
|--------------------------------------------------------------------------
*/

require_once '../includes/header.php';
require_once '../includes/navbar.php';

?>

<div class="container py-4">


    <!-- =========================================================
         HEADING
    ========================================================== -->

    <div
        class="d-flex
               justify-content-between
               align-items-start
               flex-wrap gap-3
               mb-4"
    >

        <div>

            <h3 class="mb-1">

                <i
                    class="bi bi-person-video3
                           text-primary me-2"
                ></i>

                Viva Voce Management

            </h3>

            <span class="badge bg-primary mb-2">
                Admission Session:
                <?= viva_e(
                    $currentAcademicYear
                ) ?>
            </span>


            <div class="text-muted">

                <?= viva_e($deptName) ?>

                <?php if (
                    !empty(
                        $deptInfo[
                            'dept_code'
                        ]
                    )
                ): ?>

                    (<?= viva_e(
                        $deptInfo[
                            'dept_code'
                        ]
                    ) ?>)

                <?php endif; ?>

                —

                <?= viva_e($facultyName) ?>

                Faculty

            </div>

        </div>


        <a
            href="dashboard.php"
            class="btn btn-outline-secondary"
        >

            <i
                class="bi bi-house-door me-1"
            ></i>

            Dashboard

        </a>

    </div>


    <?php if ($flash): ?>

        <div
            class="alert
                   alert-<?= viva_e(
                       $flash['type']
                   ) ?>
                   alert-dismissible
                   fade show"
        >

            <?= viva_e(
                $flash['msg']
            ) ?>


            <button
                type="button"
                class="btn-close"
                data-bs-dismiss="alert"
            ></button>

        </div>

    <?php endif; ?>


    <!-- =========================================================
         MERIT CALL + UNIVERSAL VIVA STATUS
    ========================================================== -->

    <div class="card shadow-sm mb-4">

        <div class="card-header bg-dark text-white">
            <i class="bi bi-megaphone text-warning me-2"></i>
            Merit Call
        </div>

        <div class="card-body">

            <?php if (!$meritCalls): ?>

                <div class="alert alert-info mb-0">
                    No Merit Call is available for this department yet.
                </div>

            <?php else: ?>

                <form method="GET">

                    <div class="row g-3 align-items-end">

                        <div class="col-md-8">

                            <label class="form-label fw-semibold">
                                Select Merit Call
                            </label>

                            <select
                                name="merit_call_id"
                                class="form-select"
                                onchange="this.form.submit()"
                            >

                                <?php foreach ($meritCalls as $call): ?>

                                    <option
                                        value="<?= (int) $call['merit_call_id'] ?>"
                                        <?= (int) $call['merit_call_id'] === $selectedCallId
                                            ? 'selected'
                                            : ''
                                        ?>
                                    >
                                        <?= viva_e($call['call_title']) ?>
                                        — Viva <?= viva_e($call['viva_status']) ?>
                                    </option>

                                <?php endforeach; ?>

                            </select>

                        </div>


                        <div class="col-md-4">

                            <?php
                            $callBadge = $selectedCall
                                ? vivaCallBadge(
                                    (string) $selectedCall['viva_status']
                                )
                                : [
                                    'label' => 'N/A',
                                    'class' => 'bg-secondary'
                                ];
                            ?>

                            <div class="small text-muted mb-1">
                                Universal Viva Status
                            </div>

                            <span
                                class="badge <?= $callBadge['class'] ?> fs-6 px-3 py-2"
                            >
                                <?= viva_e($callBadge['label']) ?>
                            </span>

                        </div>

                    </div>

                </form>

            <?php endif; ?>

        </div>

    </div>


    <?php if ($selectedCall): ?>

        <?php if ((string) $selectedCall['viva_status'] === 'Locked'): ?>

            <div class="alert alert-warning">
                <i class="bi bi-lock-fill me-1"></i>
                <strong>Viva is Locked.</strong>
                The Registrar must process the registration deadline and
                start Viva. The Viva call is universal for all departments.
            </div>

        <?php elseif ((string) $selectedCall['viva_status'] === 'Open'): ?>

            <div class="alert alert-success">
                <i class="bi bi-unlock-fill me-1"></i>
                <strong>
                    <?= viva_e($selectedCall['call_title']) ?> Viva is Open.
                </strong>
                You can now record Selected, Rejected or Absent decisions
                for candidates of this department.


                <?php if (
                    !empty($selectedCall['viva_date'])
                    &&
                    !empty($selectedCall['viva_reporting_time'])
                ): ?>

                    <div class="card border-success mt-3">

                        <div class="card-body">

                            <div class="row g-3">

                                <div class="col-md-4">
                                    <div class="small text-muted">
                                        Viva Date
                                    </div>
                                    <div class="fw-bold">
                                        <?= date(
                                            'd M Y',
                                            strtotime(
                                                (string)
                                                $selectedCall['viva_date']
                                            )
                                        ) ?>
                                    </div>
                                </div>


                                <div class="col-md-4">
                                    <div class="small text-muted">
                                        Reporting Start Time
                                    </div>
                                    <div class="fw-bold">
                                        <?= date(
                                            'h:i A',
                                            strtotime(
                                                (string)
                                                $selectedCall[
                                                    'viva_reporting_time'
                                                ]
                                            )
                                        ) ?>
                                    </div>
                                </div>


                                <div class="col-md-4">
                                    <div class="small text-muted">
                                        Merit Call
                                    </div>
                                    <div class="fw-bold">
                                        <?= viva_e(
                                            $selectedCall['call_title']
                                        ) ?>
                                    </div>
                                </div>


                                <?php if (
                                    !empty(
                                        $selectedCall[
                                            'viva_instructions'
                                        ]
                                    )
                                ): ?>

                                    <div class="col-12">

                                        <div
                                            class="small
                                                   text-muted
                                                   mb-1"
                                        >
                                            Instructions
                                        </div>

                                        <div
                                            class="bg-white
                                                   border
                                                   rounded
                                                   p-3"
                                        >
                                            <?= nl2br(
                                                viva_e(
                                                    $selectedCall[
                                                        'viva_instructions'
                                                    ]
                                                )
                                            ) ?>
                                        </div>

                                    </div>

                                <?php endif; ?>

                            </div>

                        </div>

                    </div>

                <?php endif; ?>


                <?php if (!empty($selectedCall['viva_started_at'])): ?>
                    <div class="small mt-1">
                        Started universally:
                        <?= date(
                            'd M Y, h:i A',
                            strtotime(
                                (string) $selectedCall['viva_started_at']
                            )
                        ) ?>
                    </div>
                <?php endif; ?>
            </div>

        <?php else: ?>

            <div class="alert alert-secondary">
                <i class="bi bi-lock-fill me-1"></i>
                <strong>Viva is Closed.</strong>
                Results are read-only for this Merit Call.


                <?php if (
                    !empty($selectedCall['viva_date'])
                    &&
                    !empty($selectedCall['viva_reporting_time'])
                ): ?>

                    <div class="small mt-2">

                        Viva Date:
                        <strong>
                            <?= date(
                                'd M Y',
                                strtotime(
                                    (string)
                                    $selectedCall['viva_date']
                                )
                            ) ?>
                        </strong>

                        &nbsp;|&nbsp;

                        Reporting Time:
                        <strong>
                            <?= date(
                                'h:i A',
                                strtotime(
                                    (string)
                                    $selectedCall[
                                        'viva_reporting_time'
                                    ]
                                )
                            ) ?>
                        </strong>

                    </div>

                <?php endif; ?>


                <?php if (!empty($selectedCall['viva_closed_at'])): ?>
                    <div class="small mt-1">
                        Closed universally:
                        <?= date(
                            'd M Y, h:i A',
                            strtotime(
                                (string) $selectedCall['viva_closed_at']
                            )
                        ) ?>
                    </div>
                <?php endif; ?>
            </div>

        <?php endif; ?>

    <?php endif; ?>


    <!-- =========================================================
         SUMMARY
    ========================================================== -->

    <div class="row g-3 mb-4">


        <div class="col-6 col-md">

            <div
                class="card h-100
                       border-start
                       border-4
                       border-primary"
            >

                <div
                    class="card-body
                           text-center"
                >

                    <div
                        class="h3
                               fw-bold
                               text-primary
                               mb-1"
                    >
                        <?= $pendingCount ?>
                    </div>

                    <small class="text-muted">
                        Pending Viva
                    </small>

                </div>

            </div>

        </div>


        <div class="col-6 col-md">

            <div
                class="card h-100
                       border-start
                       border-4
                       border-success"
            >

                <div
                    class="card-body
                           text-center"
                >

                    <div
                        class="h3
                               fw-bold
                               text-success
                               mb-1"
                    >
                        <?= (int)
                            $vivaStats[
                                'selected_count'
                            ]
                        ?>
                    </div>

                    <small class="text-muted">
                        Selected
                    </small>

                </div>

            </div>

        </div>


        <div class="col-6 col-md">

            <div
                class="card h-100
                       border-start
                       border-4
                       border-danger"
            >

                <div
                    class="card-body
                           text-center"
                >

                    <div
                        class="h3
                               fw-bold
                               text-danger
                               mb-1"
                    >
                        <?= (int)
                            $vivaStats[
                                'rejected_count'
                            ]
                        ?>
                    </div>

                    <small class="text-muted">
                        Rejected
                    </small>

                </div>

            </div>

        </div>


        <div class="col-6 col-md">

            <div
                class="card h-100
                       border-start
                       border-4
                       border-secondary"
            >

                <div
                    class="card-body
                           text-center"
                >

                    <div
                        class="h3
                               fw-bold
                               text-secondary
                               mb-1"
                    >
                        <?= (int)
                            $vivaStats[
                                'absent_count'
                            ]
                        ?>
                    </div>

                    <small class="text-muted">
                        Call Later
                    </small>

                </div>

            </div>

        </div>


        <div class="col-6 col-md">

            <div
                class="card h-100
                       border-start
                       border-4
                       border-warning"
            >

                <div
                    class="card-body
                           text-center"
                >

                    <div
                        class="h3
                               fw-bold
                               text-warning
                               mb-1"
                    >
                        <?= $unregisteredCount ?>
                    </div>

                    <small class="text-muted">
                        Not Registered
                    </small>

                </div>

            </div>

        </div>

    </div>


    <!-- =========================================================
         RULE NOTICE
    ========================================================== -->

    <div class="alert alert-info">

        <strong>
            Viva rules:
        </strong>

        <ul class="mb-0 mt-2">

            <li>
                <strong>Selected</strong>
                candidates continue to department submission.
            </li>

            <li>
                <strong>Rejected</strong>
                candidates are permanently removed and never enter the
                waiting list.
            </li>

            <li>
                <strong>Absent</strong>
                candidates are moved to the separate
                <strong>Call Later</strong> group.
            </li>

            <li>
                Rejected or Absent allocations release their seats and the
                next real waiting candidate is called automatically.
            </li>

        </ul>

    </div>


    <!-- =========================================================
         VIVA FORM
    ========================================================== -->

    <form
        method="POST"
        id="vivaForm"
    >

        <input
            type="hidden"
            name="csrf_token"
            value="<?= viva_e(
                $_SESSION[
                    'csrf_token'
                ]
            ) ?>"
        >

        <input
            type="hidden"
            name="merit_call_id"
            value="<?= $selectedCallId ?>"
        >

        <input
            type="hidden"
            name="viva_status"
            id="bulkVivaStatus"
        >


        <div class="card shadow-sm mb-4">


            <div
                class="card-header
                       bg-dark text-white
                       d-flex
                       justify-content-between
                       align-items-center"
            >

                <span>

                    <i
                        class="bi bi-list-check
                               text-warning
                               me-2"
                    ></i>

                    <?= $selectedCall
                        ? viva_e($selectedCall['call_title'])
                        : 'Merit Call'
                    ?> Candidates

                </span>


                <span class="badge bg-light text-dark">

                    <?= count($meritList) ?>
                    active

                </span>

            </div>


            <div class="table-responsive">

                <table
                    class="table
                           table-bordered
                           table-hover
                           align-middle
                           mb-0"
                >

                    <thead class="table-light">

                        <tr>

                            <th style="width:65px;">
                                Select
                            </th>

                            <th>Rank</th>
                            <th>GST Roll</th>
                            <th>Candidate</th>
                            <th>GST Score</th>
                            <th>Registration</th>
                            <th>Viva Status</th>

                        </tr>

                    </thead>


                    <tbody>


                    <?php if (!$meritList): ?>

                        <tr>

                            <td
                                colspan="7"
                                class="text-center
                                       py-5
                                       text-muted"
                            >

                                <i
                                    class="bi bi-inbox
                                           fs-1
                                           d-block
                                           mb-2"
                                ></i>

                                No active allocated candidates.

                            </td>

                        </tr>


                    <?php else: ?>


                        <?php foreach (
                            $meritList as
                            $candidate
                        ): ?>

                            <?php

                            $registered =
                                !empty(
                                    $candidate[
                                        'applicant_id'
                                    ]
                                )
                                &&
                                (int) $candidate['is_registered'] === 1;

                            $vivaPending =
                                $vivaIsOpen
                                &&
                                $registered
                                &&
                                empty(
                                    $candidate[
                                        'viva_status'
                                    ]
                                )
                                &&
                                $candidate[
                                    'allocation_status'
                                ] === 'Allocated';

                            ?>


                            <tr>


                                <td class="text-center">

                                    <?php if (
                                        $vivaPending
                                    ): ?>

                                        <input
                                            type="checkbox"
                                            class="form-check-input"
                                            name="applicant_ids[]"
                                            value="<?= (int)
                                                $candidate[
                                                    'applicant_id'
                                                ]
                                            ?>"
                                        >

                                    <?php else: ?>

                                        <i
                                            class="bi bi-lock-fill
                                                   text-muted"
                                        ></i>

                                    <?php endif; ?>

                                </td>


                                <td>

                                    <span
                                        class="badge bg-dark"
                                    >

                                        #<?= (int)
                                            $candidate[
                                                'merit_rank'
                                            ]
                                        ?>

                                    </span>

                                </td>


                                <td>

                                    <strong>

                                        <?= viva_e(
                                            $candidate[
                                                'gst_roll'
                                            ]
                                        ) ?>

                                    </strong>

                                </td>


                                <td>

                                    <div class="fw-semibold">

                                        <?= viva_e(
                                            $candidate[
                                                'full_name'
                                            ]
                                        ) ?>

                                    </div>


                                    <?php if (
                                        !empty(
                                            $candidate[
                                                'phone'
                                            ]
                                        )
                                    ): ?>

                                        <small
                                            class="text-muted"
                                        >

                                            <?= viva_e(
                                                $candidate[
                                                    'phone'
                                                ]
                                            ) ?>

                                        </small>

                                    <?php endif; ?>

                                </td>


                                <td>

                                    <?= number_format(
                                        (float)
                                        $candidate[
                                            'gst_score'
                                        ],
                                        2
                                    ) ?>

                                </td>


                                <td>

                                    <?php if (
                                        $registered
                                    ): ?>

                                        <span
                                            class="badge
                                                   bg-success"
                                        >
                                            Registered
                                        </span>

                                    <?php else: ?>

                                        <span
                                            class="badge
                                                   bg-warning
                                                   text-dark"
                                        >
                                            Not Registered
                                        </span>

                                    <?php endif; ?>

                                </td>


                                <td>

                                    <?php if (
                                        empty(
                                            $candidate[
                                                'viva_status'
                                            ]
                                        )
                                    ): ?>

                                        <span
                                            class="badge
                                                   bg-secondary"
                                        >
                                            Pending
                                        </span>


                                    <?php else: ?>

                                        <?php

                                        $badge =
                                            match (
                                                $candidate[
                                                    'viva_status'
                                                ]
                                            ) {
                                                'Selected' =>
                                                    'bg-success',

                                                'Not Selected' =>
                                                    'bg-danger',

                                                'Absent' =>
                                                    'bg-secondary',

                                                default =>
                                                    'bg-info'
                                            };

                                        ?>

                                        <span
                                            class="badge
                                                   <?= $badge ?>"
                                        >

                                            <?= $candidate[
                                                'viva_status'
                                            ] === 'Not Selected'
                                                ? 'Rejected'
                                                : viva_e(
                                                    $candidate[
                                                        'viva_status'
                                                    ]
                                                )
                                            ?>

                                        </span>


                                        <?php if (
                                            !empty(
                                                $candidate[
                                                    'conducted_at'
                                                ]
                                            )
                                        ): ?>

                                            <div
                                                class="small
                                                       text-muted
                                                       mt-1"
                                            >

                                                <?= date(
                                                    'd M Y',
                                                    strtotime(
                                                        (string)
                                                        $candidate[
                                                            'conducted_at'
                                                        ]
                                                    )
                                                ) ?>

                                            </div>

                                        <?php endif; ?>


                                    <?php endif; ?>

                                </td>

                            </tr>


                        <?php endforeach; ?>


                    <?php endif; ?>


                    </tbody>

                </table>

            </div>


            <div class="card-footer">

                <?php if ($vivaIsOpen): ?>

                    <div class="d-flex flex-wrap gap-2">

                        <button
                            type="button"
                            class="btn btn-success"
                            onclick="submitViva('Selected')"
                        >
                            <i class="bi bi-check-circle me-1"></i>
                            Select
                        </button>

                        <button
                            type="button"
                            class="btn btn-danger"
                            onclick="submitViva('Not Selected')"
                        >
                            <i class="bi bi-x-circle me-1"></i>
                            Reject
                        </button>

                        <button
                            type="button"
                            class="btn btn-secondary"
                            onclick="submitViva('Absent')"
                        >
                            <i class="bi bi-person-dash me-1"></i>
                            Absent / Call Later
                        </button>

                    </div>

                <?php else: ?>

                    <div class="text-muted small">
                        <i class="bi bi-lock-fill me-1"></i>
                        Viva decisions are available only while the Registrar
                        has this Merit Call Viva <strong>Open</strong>.
                    </div>

                <?php endif; ?>

            </div>

        </div>

    </form>


    <!-- =========================================================
         CALL LATER
    ========================================================== -->

    <div class="card shadow-sm">

        <div
            class="card-header
                   bg-secondary
                   text-white
                   d-flex
                   justify-content-between
                   align-items-center"
        >

            <span>

                <i
                    class="bi bi-clock-history
                           me-2"
                ></i>

                Call Later — Absent Candidates

            </span>


            <span class="badge bg-light text-dark">

                <?= count(
                    $callLaterList
                ) ?>

            </span>

        </div>


        <div class="table-responsive">

            <table
                class="table
                       table-hover
                       mb-0
                       align-middle"
            >

                <thead class="table-light">

                    <tr>

                        <th>GST Roll</th>
                        <th>Candidate</th>
                        <th>GST Score</th>
                        <th>Absent At</th>
                        <th>Status</th>

                    </tr>

                </thead>


                <tbody>


                <?php if (
                    !$callLaterList
                ): ?>

                    <tr>

                        <td
                            colspan="5"
                            class="text-center
                                   py-4
                                   text-muted"
                        >
                            No Call Later candidates.
                        </td>

                    </tr>


                <?php else: ?>


                    <?php foreach (
                        $callLaterList as
                        $candidate
                    ): ?>

                        <tr>

                            <td>

                                <strong>

                                    <?= viva_e(
                                        $candidate[
                                            'gst_roll'
                                        ]
                                    ) ?>

                                </strong>

                            </td>


                            <td>

                                <?= viva_e(
                                    $candidate[
                                        'full_name'
                                    ]
                                ) ?>

                            </td>


                            <td>

                                <?= number_format(
                                    (float)
                                    $candidate[
                                        'gst_score'
                                    ],
                                    2
                                ) ?>

                            </td>


                            <td>

                                <?= !empty(
                                    $candidate[
                                        'conducted_at'
                                    ]
                                )
                                    ? date(
                                        'd M Y, h:i A',
                                        strtotime(
                                            (string)
                                            $candidate[
                                                'conducted_at'
                                            ]
                                        )
                                    )
                                    : '—'
                                ?>

                            </td>


                            <td>

                                <span
                                    class="badge
                                           bg-secondary"
                                >
                                    Call Later
                                </span>

                            </td>

                        </tr>


                    <?php endforeach; ?>


                <?php endif; ?>


                </tbody>

            </table>

        </div>

    </div>

</div>


<script>
function submitViva(status) {

    const checked =
        document.querySelectorAll(
            'input[name="applicant_ids[]"]:checked'
        );

    if (checked.length === 0) {

        alert(
            'Select at least one candidate.'
        );

        return;
    }


    const label =
        status === 'Not Selected'
            ? 'Reject'
            : (
                status === 'Absent'
                    ? 'mark Absent / Call Later'
                    : 'Select'
            );


    let warning =
        'Apply "' +
        label +
        '" to ' +
        checked.length +
        ' candidate(s)?';


    if (
        status === 'Not Selected' ||
        status === 'Absent'
    ) {

        warning +=
            '\n\nTheir current allocation will be vacated and the next waiting candidate will be promoted automatically for the next Merit Call.';
    }


    if (!confirm(warning)) {
        return;
    }


    document
        .getElementById(
            'bulkVivaStatus'
        )
        .value = status;


    document
        .getElementById(
            'vivaForm'
        )
        .submit();
}
</script>


<?php require_once '../includes/footer.php'; ?>
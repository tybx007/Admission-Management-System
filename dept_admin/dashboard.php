<?php

declare(strict_types=1);

$pageTitle = 'Department Admin Dashboard';

require_once '../includes/auth.php';
require_once '../config/db.php';

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
    header('Location: /jkkniu-admission/dept_admin/login.php');
    exit;
}

$pdo->setAttribute(
    PDO::ATTR_ERRMODE,
    PDO::ERRMODE_EXCEPTION
);


/*
|--------------------------------------------------------------------------
| Department Information
|--------------------------------------------------------------------------
*/

$deptId = (int) ($_SESSION['dept_id'] ?? 0);

if ($deptId <= 0) {
    http_response_code(403);
    exit('Invalid department information.');
}


/*
|--------------------------------------------------------------------------
| Department Statistics
|--------------------------------------------------------------------------
|
| IMPORTANT:
| - merit_allocations is counted through import_candidate_id
| - Waiting is ONE candidate / ONE canonical waiting department
| - Candidate choices are NOT counted as waiting-list records
| - All metrics are isolated so joins cannot multiply counts
|--------------------------------------------------------------------------
*/

$statsQuery = "
    SELECT
        d.dept_id,
        d.dept_code,
        d.dept_name,
        d.total_seats,
        f.faculty_name,

        COALESCE((
            SELECT COUNT(DISTINCT ds.applicant_id)
            FROM dept_submissions ds
            WHERE ds.dept_id = d.dept_id
              AND ds.submission_status = 'Pending'
        ), 0) AS pending_submissions,

        COALESCE((
            SELECT COUNT(DISTINCT ds.applicant_id)
            FROM dept_submissions ds
            WHERE ds.dept_id = d.dept_id
              AND ds.submission_status = 'Verified'
        ), 0) AS verified_submissions,

        COALESCE((
            SELECT COUNT(DISTINCT ds.applicant_id)
            FROM dept_submissions ds
            WHERE ds.dept_id = d.dept_id
              AND ds.submission_status = 'Rejected'
        ), 0) AS rejected_submissions,

        COALESCE((
            SELECT COUNT(
                DISTINCT ma.import_candidate_id
            )
            FROM merit_allocations ma
            WHERE ma.dept_id = d.dept_id
              AND ma.allocation_status IN (
                  'Allocated',
                  'Admitted'
              )
        ), 0) AS allocated_count,

        COALESCE((
            SELECT COUNT(
                DISTINCT ma.import_candidate_id
            )
            FROM merit_allocations ma
            WHERE ma.dept_id = d.dept_id
              AND ma.allocation_status = 'Admitted'
        ), 0) AS admitted_count,

        COALESCE((
            SELECT COUNT(
                DISTINCT wl.import_candidate_id
            )
            FROM waiting_list wl

            INNER JOIN gst_candidate_choices gcc
                ON gcc.import_candidate_id =
                   wl.import_candidate_id
               AND gcc.dept_id =
                   wl.dept_id

            WHERE wl.dept_id = d.dept_id
              AND wl.waiting_status = 'Waiting'

              AND gcc.preference_order = (
                  SELECT MIN(gcc2.preference_order)
                  FROM gst_candidate_choices gcc2
                  WHERE gcc2.import_candidate_id =
                        wl.import_candidate_id
              )
        ), 0) AS waiting_count,

        COALESCE((
            SELECT COUNT(
                DISTINCT ap.applicant_id
            )
            FROM admission_progress ap

            INNER JOIN gst_imported_candidates gic
                ON gic.applicant_id =
                   ap.applicant_id

            WHERE ap.final_status = 'Admitted'

              AND EXISTS (
                  SELECT 1
                  FROM merit_allocations ma2
                  WHERE ma2.import_candidate_id =
                        gic.import_candidate_id
                    AND ma2.dept_id =
                        d.dept_id
                    AND ma2.allocation_status =
                        'Admitted'
              )
        ), 0) AS final_admitted

    FROM departments d

    LEFT JOIN faculties f
        ON f.faculty_id =
           d.faculty_id

    WHERE d.dept_id = ?

    LIMIT 1
";

$stmt = $pdo->prepare($statsQuery);
$stmt->execute([$deptId]);

$stats = $stmt->fetch(
    PDO::FETCH_ASSOC
);

if (!$stats) {
    http_response_code(404);
    exit('Department not found.');
}

$deptName = (string) (
    $stats['dept_name']
    ?? $_SESSION['dept_name']
    ?? 'Your Department'
);


/*
|--------------------------------------------------------------------------
| Recent Department Submissions
|--------------------------------------------------------------------------
|
| Department submissions only exist after registration, so applicant_id is
| appropriate here. Imported data is used as a fallback for GST identity.
|--------------------------------------------------------------------------
*/

$recentQuery = "
    SELECT
        ds.dept_sub_id,
        ds.applicant_id,
        ds.dept_id,
        ds.submission_status,
        ds.submitted_at,

        COALESCE(
            NULLIF(TRIM(ga.full_name), ''),
            gic.full_name
        ) AS full_name,

        COALESCE(
            NULLIF(TRIM(ga.gst_roll), ''),
            gic.gst_roll
        ) AS gst_roll,

        COALESCE(
            ga.gst_score,
            gic.gst_score
        ) AS gst_score,

        COALESCE(
            NULLIF(TRIM(ga.phone), ''),
            gic.phone
        ) AS phone

    FROM dept_submissions ds

    INNER JOIN gst_applicants ga
        ON ga.applicant_id =
           ds.applicant_id

    LEFT JOIN gst_imported_candidates gic
        ON gic.applicant_id =
           ga.applicant_id

    WHERE ds.dept_id = ?

    ORDER BY
        ds.submitted_at DESC,
        ds.dept_sub_id DESC

    LIMIT 10
";

$stmtRecent = $pdo->prepare(
    $recentQuery
);

$stmtRecent->execute([
    $deptId
]);

$recent = $stmtRecent->fetchAll(
    PDO::FETCH_ASSOC
);


/*
|--------------------------------------------------------------------------
| Department Admission Progress
|--------------------------------------------------------------------------
|
| Only candidates whose CURRENT active allocation belongs to this department
| are counted. Old Vacated allocations cannot duplicate this result.
|--------------------------------------------------------------------------
*/

$progressQuery = "
    SELECT

        COUNT(
            DISTINCT CASE
                WHEN ap.dept_step = 'Pending'
                THEN ap.applicant_id
            END
        ) AS dept_pending,

        COUNT(
            DISTINCT CASE
                WHEN ap.dept_step = 'Done'
                THEN ap.applicant_id
            END
        ) AS dept_done

    FROM admission_progress ap

    INNER JOIN gst_imported_candidates gic
        ON gic.applicant_id =
           ap.applicant_id

    INNER JOIN merit_allocations ma
        ON ma.import_candidate_id =
           gic.import_candidate_id

       AND ma.dept_id = ?

       AND ma.allocation_status IN (
           'Allocated',
           'Admitted'
       )

       AND ma.allocation_id = (
           SELECT MAX(ma2.allocation_id)
           FROM merit_allocations ma2
           WHERE ma2.import_candidate_id =
                 gic.import_candidate_id
             AND ma2.allocation_status IN (
                 'Allocated',
                 'Admitted'
             )
       )
";

$stmtProgress = $pdo->prepare(
    $progressQuery
);

$stmtProgress->execute([
    $deptId
]);

$progress = $stmtProgress->fetch(
    PDO::FETCH_ASSOC
);

if (!$progress) {
    $progress = [
        'dept_pending' => 0,
        'dept_done' => 0
    ];
}


/*
|--------------------------------------------------------------------------
| Page Header
|--------------------------------------------------------------------------
*/

require_once '../includes/header.php';
require_once '../includes/navbar.php';

?>

<div class="container py-4">

    <!-- Department Header -->
    <div class="alert alert-primary d-flex align-items-center gap-3 mb-4">

        <i class="bi bi-building fs-2"></i>

        <div>

            <h5 class="mb-2">
                <?= htmlspecialchars(
                    $deptName,
                    ENT_QUOTES,
                    'UTF-8'
                ) ?>
                Dashboard
            </h5>

            <small>
                Faculty:
                <?= htmlspecialchars(
                    $stats['faculty_name'] ?? 'N/A',
                    ENT_QUOTES,
                    'UTF-8'
                ) ?>

                |

                Seats:
                <?= (int) $stats['final_admitted'] ?>
                /
                <?= (int) $stats['total_seats'] ?>

                |

                Final Admitted:
                <?= (int) $stats['final_admitted'] ?>
            </small>

        </div>

    </div>


    <!-- Statistics Cards -->
    <div class="row g-4 mb-4">

        <!-- Pending -->
        <div class="col-md-3">

            <div class="card border-start border-4 border-warning border-top-0 h-100">

                <div class="card-body text-center py-4">

                    <i class="bi bi-hourglass-split fs-2 text-warning mb-2"></i>

                    <div class="h3 fw-bold text-warning">
                        <?= (int) $stats['pending_submissions'] ?>
                    </div>

                    <small>Pending Review</small>

                </div>

            </div>

        </div>


        <!-- Verified -->
        <div class="col-md-3">

            <div class="card border-start border-4 border-success border-top-0 h-100">

                <div class="card-body text-center py-4">

                    <i class="bi bi-check-circle fs-2 text-success mb-2"></i>

                    <div class="h3 fw-bold text-success">
                        <?= (int) $stats['verified_submissions'] ?>
                    </div>

                    <small>Verified</small>

                </div>

            </div>

        </div>


        <!-- Rejected -->
        <div class="col-md-3">

            <div class="card border-start border-4 border-danger border-top-0 h-100">

                <div class="card-body text-center py-4">

                    <i class="bi bi-x-circle fs-2 text-danger mb-2"></i>

                    <div class="h3 fw-bold text-danger">
                        <?= (int) $stats['rejected_submissions'] ?>
                    </div>

                    <small>Rejected</small>

                </div>

            </div>

        </div>


        <!-- Final Admitted -->
        <div class="col-md-3">

            <div class="card border-start border-4 border-info border-top-0 h-100">

                <div class="card-body text-center py-4">

                    <i class="bi bi-people fs-2 text-info mb-2"></i>

                    <div class="h3 fw-bold text-info">
                        <?= (int) $stats['final_admitted'] ?>
                    </div>

                    <small>Admitted</small>

                </div>

            </div>

        </div>

    </div>


    <!-- Quick Actions -->
    <div class="row g-3 mb-4">

        <!-- Review Submissions -->
        <div class="col-md-4">

            <a
                href="submissions.php?dept_id=<?= $deptId ?>"
                class="btn btn-primary w-100 h-100 d-flex align-items-center justify-content-center text-decoration-none"
            >

                <i class="bi bi-upload fs-3 me-2"></i>

                <span class="fw-bold text-center">

                    Review Submissions

                    <br>

                    <small class="text-white-50">
                        <?= (int) $stats['pending_submissions'] ?> pending
                    </small>

                </span>

            </a>

        </div>


        <!-- Merit List -->
        <div class="col-md-4">

            <a
                href="merit_list.php?dept_id=<?= $deptId ?>"
                class="btn btn-success w-100 h-100 d-flex align-items-center justify-content-center text-decoration-none"
            >

                <i class="bi bi-trophy fs-3 me-2"></i>

                <span class="fw-bold text-center">

                    Merit List

                    <br>

                    <small class="text-white-50">
                        <?= (int) $stats['allocated_count'] ?> allocated
                    </small>

                </span>

            </a>

        </div>


        <!-- Waiting List -->
        <div class="col-md-4">

            <a
                href="waiting_list.php?dept_id=<?= $deptId ?>"
                class="btn btn-info w-100 h-100 d-flex align-items-center justify-content-center text-white text-decoration-none"
            >

                <i class="bi bi-list-nested fs-3 me-2"></i>

                <span class="fw-bold text-center">

                    Waiting List

                    <br>

                    <small>
                        <?= (int) $stats['waiting_count'] ?> waiting
                    </small>

                </span>

            </a>

        </div>

    </div>


    <!-- Progress and Seat Status -->
    <div class="row g-4 mb-4">

        <!-- Admission Progress -->
        <div class="col-md-6">

            <div class="card h-100">

                <div class="card-header bg-primary text-white">

                    <i class="bi bi-graph-up me-2"></i>

                    Admission Progress

                </div>

                <div class="card-body">

                    <div class="row text-center">

                        <div class="col-6 border-end">

                            <div class="h4 fw-bold text-warning">
                                <?= (int) (
                                    $progress['dept_pending']
                                    ?? 0
                                ) ?>
                            </div>

                            <small>Dept Pending</small>

                        </div>

                        <div class="col-6">

                            <div class="h4 fw-bold text-success">
                                <?= (int) (
                                    $progress['dept_done']
                                    ?? 0
                                ) ?>
                            </div>

                            <small>Dept Completed</small>

                        </div>

                    </div>

                </div>

            </div>

        </div>


        <!-- Seat Status -->
        <div class="col-md-6">

            <div class="card h-100">

                <div class="card-header bg-success text-white">

                    <i class="bi bi-people-fill me-2"></i>

                    Seat Status

                </div>

                <div class="card-body text-center py-4">

                    <div class="display-4 fw-bold text-info">

                        <?= (int) $stats['final_admitted'] ?>
                        /
                        <?= (int) $stats['total_seats'] ?>

                    </div>

                    <small class="text-muted">
                        Seats Filled / Total
                    </small>

                    <?php if (
                        (int) $stats['total_seats'] > 0 &&
                        (int) $stats['final_admitted'] >=
                        (int) $stats['total_seats']
                    ): ?>

                        <div class="mt-2">

                            <span class="badge bg-danger fs-6">
                                FULL
                            </span>

                        </div>

                    <?php endif; ?>

                </div>

            </div>

        </div>

    </div>


    <!-- Recent Submissions -->
    <div class="card shadow-sm">

        <div class="card-header bg-secondary text-white d-flex justify-content-between align-items-center">

            <span>

                <i class="bi bi-clock-history me-2"></i>

                Recent Step 1 Submissions

            </span>

            <a
                href="submissions.php?dept_id=<?= $deptId ?>"
                class="btn btn-sm btn-light"
            >
                View All
            </a>

        </div>


        <div class="table-responsive">

            <table class="table table-hover mb-0">

                <thead class="table-light">

                    <tr>

                        <th>GST Roll</th>
                        <th>Student</th>
                        <th>Phone</th>
                        <th>GST Score</th>
                        <th>Submitted</th>
                        <th>Status</th>
                        <th>Action</th>

                    </tr>

                </thead>


                <tbody>

                    <?php foreach ($recent as $sub): ?>

                        <?php

                        $submissionStatus =
                            $sub['submission_status']
                            ?? 'Pending';

                        if ($submissionStatus === 'Verified') {
                            $badgeClass = 'success';
                        } elseif ($submissionStatus === 'Rejected') {
                            $badgeClass = 'danger';
                        } else {
                            $badgeClass = 'warning text-dark';
                        }

                        $submittedDate = 'N/A';

                        if (!empty($sub['submitted_at'])) {

                            $timestamp = strtotime(
                                (string) $sub['submitted_at']
                            );

                            if ($timestamp !== false) {

                                $submittedDate = date(
                                    'M j, H:i',
                                    $timestamp
                                );
                            }
                        }

                        ?>

                        <tr>

                            <td>

                                <strong>
                                    <?= htmlspecialchars(
                                        $sub['gst_roll']
                                        ?? 'N/A',
                                        ENT_QUOTES,
                                        'UTF-8'
                                    ) ?>
                                </strong>

                            </td>


                            <td>
                                <?= htmlspecialchars(
                                    $sub['full_name']
                                    ?? 'N/A',
                                    ENT_QUOTES,
                                    'UTF-8'
                                ) ?>
                            </td>


                            <td>
                                <?= htmlspecialchars(
                                    $sub['phone']
                                    ?? 'N/A',
                                    ENT_QUOTES,
                                    'UTF-8'
                                ) ?>
                            </td>


                            <td>

                                <strong>
                                    <?= number_format(
                                        (float) (
                                            $sub['gst_score']
                                            ?? 0
                                        ),
                                        2
                                    ) ?>
                                </strong>

                            </td>


                            <td>
                                <?= htmlspecialchars(
                                    $submittedDate,
                                    ENT_QUOTES,
                                    'UTF-8'
                                ) ?>
                            </td>


                            <td>

                                <span class="badge bg-<?= $badgeClass ?> fs-6">

                                    <?= htmlspecialchars(
                                        ucwords(
                                            str_replace(
                                                '_',
                                                ' ',
                                                $submissionStatus
                                            )
                                        ),
                                        ENT_QUOTES,
                                        'UTF-8'
                                    ) ?>

                                </span>

                            </td>


                            <td>

                                <a
                                    href="submissions.php?dept_id=<?= $deptId ?>&submission_id=<?= (int) ($sub['dept_sub_id'] ?? 0) ?>"
                                    class="btn btn-sm btn-outline-primary"
                                >

                                    <i class="bi bi-eye"></i>

                                    Review

                                </a>

                            </td>

                        </tr>

                    <?php endforeach; ?>


                    <?php if (empty($recent)): ?>

                        <tr>

                            <td
                                colspan="7"
                                class="text-center py-4 text-muted"
                            >
                                No recent submissions
                            </td>

                        </tr>

                    <?php endif; ?>

                </tbody>

            </table>

        </div>

    </div>

</div>


<?php require_once '../includes/footer.php'; ?>
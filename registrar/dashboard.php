<?php
declare(strict_types=1);

$pageTitle = 'Registrar Dashboard';

require_once '../includes/auth.php';
require_once '../config/db.php';
require_once '../includes/admission_session.php';

requireLogin('registrar');

$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
date_default_timezone_set('Asia/Dhaka');

$currentAcademicYear = admissionSessionCurrentYear($pdo);

function rdE(mixed $v): string {
    return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8');
}

/* -------------------- Session-safe statistics -------------------- */

$stmt = $pdo->prepare("
    SELECT
        COUNT(*) total_imported,
        COALESCE(SUM(is_registered=1),0) registered,
        COALESCE(SUM(is_registered=0),0) unregistered
    FROM gst_imported_candidates
    WHERE academic_year=?
");
$stmt->execute([$currentAcademicYear]);
$import = $stmt->fetch(PDO::FETCH_ASSOC) ?: ['total_imported'=>0,'registered'=>0,'unregistered'=>0];

$stmt = $pdo->prepare("
    SELECT
        COUNT(*) total_merit,
        SUM(ma.allocation_status='Allocated') allocated,
        SUM(ma.allocation_status='Admitted') admitted,
        SUM(ma.allocation_status='Vacated') vacated
    FROM merit_allocations ma
    INNER JOIN gst_imported_candidates gic
        ON gic.import_candidate_id=ma.import_candidate_id
    WHERE gic.academic_year=?
");
$stmt->execute([$currentAcademicYear]);
$merit = $stmt->fetch(PDO::FETCH_ASSOC) ?: ['total_merit'=>0,'allocated'=>0,'admitted'=>0,'vacated'=>0];

$stmt = $pdo->prepare("
    SELECT
        SUM(wl.waiting_status='Waiting') waiting,
        SUM(wl.waiting_status='Called') called
    FROM waiting_list wl
    INNER JOIN gst_imported_candidates gic
        ON gic.import_candidate_id=wl.import_candidate_id
    WHERE gic.academic_year=?
");
$stmt->execute([$currentAcademicYear]);
$waiting = $stmt->fetch(PDO::FETCH_ASSOC) ?: ['waiting'=>0,'called'=>0];

$stmt = $pdo->prepare("
    SELECT
        SUM(ra.approval_status IN ('Submitted','Under Review')) pending,
        SUM(ra.approval_status='Approved') approved,
        SUM(ra.approval_status='Rejected') rejected
    FROM registrar_approvals ra
    INNER JOIN gst_imported_candidates gic
        ON gic.applicant_id=ra.applicant_id
    WHERE gic.academic_year=?
");
$stmt->execute([$currentAcademicYear]);
$approval = $stmt->fetch(PDO::FETCH_ASSOC) ?: ['pending'=>0,'approved'=>0,'rejected'=>0];

$stmt = $pdo->prepare("
    SELECT COUNT(DISTINCT CASE
        WHEN ap.final_status='Admitted'
         AND ma.allocation_status='Admitted'
         AND ap.migration_status='Enabled'
         AND gcc.preference_order>1
        THEN gic.import_candidate_id END)
    FROM gst_imported_candidates gic
    INNER JOIN admission_progress ap ON ap.applicant_id=gic.applicant_id
    INNER JOIN merit_allocations ma ON ma.import_candidate_id=gic.import_candidate_id
    LEFT JOIN gst_candidate_choices gcc
        ON gcc.import_candidate_id=gic.import_candidate_id
       AND gcc.dept_id=ma.dept_id
    WHERE gic.academic_year=?
");
$stmt->execute([$currentAcademicYear]);
$migrationPool = (int)($stmt->fetchColumn() ?: 0);

/* -------------------- Current Merit Call -------------------- */

$stmt = $pdo->prepare("
    SELECT
        merit_call_id, call_number, call_title,
        registration_start, registration_deadline,
        process_end_at, published_at, processed_at,
        process_closed_at, migration_processed_at,
        next_call_prepared_at, viva_status,
        viva_date, viva_reporting_time
    FROM merit_calls
    WHERE academic_year=?
    ORDER BY call_number DESC, merit_call_id DESC
    LIMIT 1
");
$stmt->execute([$currentAcademicYear]);
$currentCall = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

/* -------------------- Decide current stage + one next action -------------------- */

$totalImported = (int)$import['total_imported'];
$totalMerit    = (int)$merit['total_merit'];

$stage = 'Not Started';
$stageColor = 'secondary';
$stageIcon = 'bi-circle';
$nextText = 'Import GST Candidates';
$nextUrl = 'import_candidates.php';
$nextClass = 'btn-primary';

if ($totalImported <= 0) {
    $stage = 'GST Import Required';
    $stageColor = 'warning';
    $stageIcon = 'bi-file-earmark-arrow-up';
} elseif ($totalMerit <= 0) {
    $stage = 'Ready for Initial Merit';
    $stageColor = 'info';
    $stageIcon = 'bi-award';
    $nextText = 'Generate Merit';
    $nextUrl = 'generate_merit.php';
} elseif (!$currentCall) {
    $stage = 'Merit Generated';
    $stageColor = 'primary';
    $stageIcon = 'bi-megaphone';
    $nextText = 'Publish Merit Call';
    $nextUrl = 'merit_calls.php';
} else {
    $deadline = new DateTimeImmutable(
        (string)$currentCall['registration_deadline'],
        new DateTimeZone('Asia/Dhaka')
    );
    $now = new DateTimeImmutable('now', new DateTimeZone('Asia/Dhaka'));

    if (empty($currentCall['processed_at'])) {
        if ($now < $deadline) {
            $stage = 'Registration Running';
            $stageColor = 'primary';
            $stageIcon = 'bi-person-check';
            $nextText = 'View Merit Call';
            $nextUrl = 'merit_calls.php';
            $nextClass = 'btn-outline-primary';
        } else {
            $stage = 'Deadline Ready to Process';
            $stageColor = 'warning';
            $stageIcon = 'bi-hourglass-bottom';
            $nextText = 'Process Deadline';
            $nextUrl = 'merit_calls.php';
            $nextClass = 'btn-warning';
        }
    } elseif (($currentCall['viva_status'] ?? 'Locked') === 'Locked') {
        $stage = 'Ready to Start Viva';
        $stageColor = 'warning';
        $stageIcon = 'bi-mic';
        $nextText = 'Start Viva';
        $nextUrl = 'merit_calls.php';
        $nextClass = 'btn-warning';
    } elseif (($currentCall['viva_status'] ?? '') === 'Open') {
        $stage = 'Viva Running';
        $stageColor = 'success';
        $stageIcon = 'bi-mic-fill';
        $nextText = 'Manage / Close Viva';
        $nextUrl = 'merit_calls.php';
        $nextClass = 'btn-success';
    } elseif (($currentCall['viva_status'] ?? '') === 'Closed' && empty($currentCall['process_closed_at'])) {
        $stage = 'Ready to Close Merit Process';
        $stageColor = 'danger';
        $stageIcon = 'bi-lock';
        $nextText = 'Close Current Process';
        $nextUrl = 'merit_round_process.php';
        $nextClass = 'btn-danger';
    } elseif (!empty($currentCall['process_closed_at']) && empty($currentCall['migration_processed_at'])) {
        $stage = 'Ready for Migration';
        $stageColor = 'warning';
        $stageIcon = 'bi-arrow-up-right-circle-fill';
        $nextText = 'Run Migration';
        $nextUrl = 'migration.php';
        $nextClass = 'btn-success';
    } elseif (!empty($currentCall['migration_processed_at']) && empty($currentCall['next_call_prepared_at'])) {
        $stage = 'Ready for Next Merit Call';
        $stageColor = 'info';
        $stageIcon = 'bi-megaphone-fill';
        $nextText = 'Prepare Next Merit Call';
        $nextUrl = 'merit_round_process.php';
        $nextClass = 'btn-primary';
    } else {
        $stage = 'Merit Round Completed';
        $stageColor = 'success';
        $stageIcon = 'bi-check-circle-fill';
        $nextText = 'View Merit Calls';
        $nextUrl = 'merit_calls.php';
        $nextClass = 'btn-outline-success';
    }
}

$registrationStep = !$currentCall ? 'Waiting' : (!empty($currentCall['processed_at']) ? 'Done' : 'Active');
$vivaStep = !$currentCall ? 'Waiting' : (string)($currentCall['viva_status'] ?? 'Locked');
$closeStep = !$currentCall ? 'Waiting' : (!empty($currentCall['process_closed_at']) ? 'Done' : 'Waiting');
$migrationStep = !$currentCall ? 'Waiting' : (!empty($currentCall['migration_processed_at']) ? 'Done' : 'Waiting');
$nextStep = !$currentCall ? 'Waiting' : (!empty($currentCall['next_call_prepared_at']) ? 'Done' : 'Waiting');

/* -------------------- Latest 5 pending final reviews -------------------- */

$stmt = $pdo->prepare("
    SELECT
        ra.applicant_id, ra.submitted_at,
        ga.gst_roll, ga.full_name,
        d.dept_name, ma.merit_rank
    FROM registrar_approvals ra
    INNER JOIN gst_applicants ga ON ga.applicant_id=ra.applicant_id
    INNER JOIN gst_imported_candidates gic ON gic.applicant_id=ga.applicant_id
    INNER JOIN merit_allocations ma
        ON ma.import_candidate_id=gic.import_candidate_id
       AND ma.allocation_status IN ('Allocated','Admitted')
    INNER JOIN departments d ON d.dept_id=ma.dept_id
    WHERE ra.approval_status IN ('Submitted','Under Review')
      AND gic.academic_year=?
    ORDER BY ra.submitted_at DESC
    LIMIT 3
");
$stmt->execute([$currentAcademicYear]);
$pending = $stmt->fetchAll(PDO::FETCH_ASSOC);

require_once '../includes/header.php';
require_once '../includes/navbar.php';

$registrationDone =
    $registrationStep === 'Done';

$vivaDone =
    $vivaStep === 'Closed';

$processCloseDone =
    $closeStep === 'Done';

$migrationDone =
    $migrationStep === 'Done';

$nextCallDone =
    $nextStep === 'Done';

$registrationActive =
    $registrationStep === 'Active';

$vivaActive =
    $vivaStep === 'Open'
    || (
        $vivaStep === 'Locked'
        && $registrationDone
    );

$processCloseActive =
    $vivaDone
    && !$processCloseDone;

$migrationActive =
    $processCloseDone
    && !$migrationDone;

$nextCallActive =
    $migrationDone
    && !$nextCallDone;

?>

<div class="container py-4">

    <!-- ================================================================
         HEADER
    ================================================================= -->
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-3">
        <div>
            <h3 class="fw-bold mb-1">
                <i class="bi bi-speedometer2 text-primary me-2"></i>
                Registrar Dashboard
            </h3>

            <div class="d-flex flex-wrap gap-2">
                <span class="badge bg-primary">
                    Session: <?= rdE($currentAcademicYear) ?>
                </span>

                <?php if ($currentCall): ?>
                    <span class="badge bg-dark">
                        <?= rdE($currentCall['call_title']) ?>
                    </span>
                <?php endif; ?>
            </div>
        </div>

        <a
            href="<?= rdE($nextUrl) ?>"
            class="btn <?= rdE($nextClass) ?>"
        >
            <i class="bi <?= rdE($stageIcon) ?> me-1"></i>
            <?= rdE($nextText) ?>
        </a>
    </div>


    <!-- ================================================================
         CURRENT STAGE + WORKFLOW TRACKER
    ================================================================= -->
    <div class="card shadow-sm mb-3 border-0">
        <div class="card-body p-3 p-lg-4">

            <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">

                <div>
                    <div class="small text-muted mb-1">
                        Current Admission Stage
                    </div>

                    <div class="fs-4 fw-bold text-<?= rdE($stageColor) ?>">
                        <i class="bi <?= rdE($stageIcon) ?> me-2"></i>
                        <?= rdE($stage) ?>
                    </div>
                </div>

                <div class="d-flex flex-wrap gap-3 align-items-center">

                    <?php if ($currentCall && !empty($currentCall['registration_deadline'])): ?>
                        <div class="text-end">
                            <div class="small text-muted">
                                Registration Deadline
                            </div>

                            <div class="fw-semibold">
                                <?= date(
                                    'd M Y, h:i A',
                                    strtotime((string)$currentCall['registration_deadline'])
                                ) ?>
                            </div>
                        </div>
                    <?php endif; ?>

                    <a
                        href="<?= rdE($nextUrl) ?>"
                        class="btn <?= rdE($nextClass) ?>"
                    >
                        <?= rdE($nextText) ?>
                        <i class="bi bi-arrow-right ms-1"></i>
                    </a>
                </div>

            </div>


            <!-- Thin Workflow -->
            <div class="row g-2 align-items-stretch text-center">

                <?php
                $flow = [
                    [
                        'label' => 'Registration',
                        'done' => $registrationDone,
                        'active' => $registrationActive,
                        'value' => $registrationStep,
                        'icon' => 'bi-person-check'
                    ],
                    [
                        'label' => 'Viva',
                        'done' => $vivaDone,
                        'active' => $vivaActive,
                        'value' => $vivaStep,
                        'icon' => 'bi-mic'
                    ],
                    [
                        'label' => 'Process Close',
                        'done' => $processCloseDone,
                        'active' => $processCloseActive,
                        'value' => $closeStep,
                        'icon' => 'bi-lock'
                    ],
                    [
                        'label' => 'Migration',
                        'done' => $migrationDone,
                        'active' => $migrationActive,
                        'value' => $migrationStep,
                        'icon' => 'bi-arrow-up-right-circle'
                    ],
                    [
                        'label' => 'Next Call',
                        'done' => $nextCallDone,
                        'active' => $nextCallActive,
                        'value' => $nextStep,
                        'icon' => 'bi-megaphone'
                    ],
                ];
                ?>

                <?php foreach ($flow as $step): ?>

                    <?php
                    if ($step['done']) {
                        $boxClass = 'border-success bg-success-subtle';
                        $iconClass = 'text-success';
                        $stateText = 'Done';
                    } elseif ($step['active']) {
                        $boxClass = 'border-primary bg-primary-subtle';
                        $iconClass = 'text-primary';
                        $stateText = $step['value'] === 'Locked'
                            ? 'Ready'
                            : $step['value'];
                    } else {
                        $boxClass = 'border-light bg-light';
                        $iconClass = 'text-secondary';
                        $stateText = 'Locked';
                    }
                    ?>

                    <div class="col">
                        <div class="border rounded-3 py-2 px-2 h-100 <?= rdE($boxClass) ?>">
                            <div class="small fw-semibold">
                                <i class="bi <?= rdE($step['icon']) ?> <?= rdE($iconClass) ?> me-1"></i>
                                <?= rdE($step['label']) ?>
                            </div>

                            <div class="small text-muted mt-1">
                                <?= rdE($stateText) ?>
                            </div>
                        </div>
                    </div>

                <?php endforeach; ?>

            </div>

        </div>
    </div>


    <!-- ================================================================
         ONE COMPACT STATS STRIP
    ================================================================= -->
    <div class="card shadow-sm mb-3">
        <div class="card-body py-3">

            <div class="row g-0 text-center">

                <?php
                $compactStats = [
                    ['Imported', $totalImported, 'primary'],
                    ['Registered', (int)$import['registered'], 'success'],
                    ['Allocated', (int)$merit['allocated'], 'warning'],
                    ['Final Admitted', (int)$merit['admitted'], 'success'],
                    ['Waiting', (int)$waiting['waiting'], 'info'],
                    ['Pending Review', (int)$approval['pending'], 'danger'],
                ];
                ?>

                <?php foreach ($compactStats as $i => [$label, $value, $color]): ?>

                    <div class="col-6 col-md-4 col-xl-2 <?= $i > 0 ? 'border-start' : '' ?>">
                        <div class="px-2 py-1">
                            <div class="small text-muted">
                                <?= rdE($label) ?>
                            </div>

                            <div class="h4 fw-bold mb-0 text-<?= rdE($color) ?>">
                                <?= (int)$value ?>
                            </div>
                        </div>
                    </div>

                <?php endforeach; ?>

            </div>

        </div>
    </div>


    <!-- ================================================================
         ACTIONS + PENDING
    ================================================================= -->
    <div class="row g-3">

        <!-- LEFT: GROUPED ACTIONS -->
        <div class="col-lg-4">

            <div class="card shadow-sm h-100">

                <div class="card-header bg-dark text-white">
                    <i class="bi bi-grid-fill me-2"></i>
                    <strong>Control Center</strong>
                </div>

                <div class="card-body">

                    <div class="small text-uppercase fw-semibold text-muted mb-2">
                        Admission Process
                    </div>

                    <div class="row g-2 mb-3">

                        <div class="col-4">
                            <a href="import_candidates.php" class="btn btn-outline-primary w-100 py-2">
                                <i class="bi bi-file-earmark-arrow-up d-block"></i>
                                <span class="small">Import</span>
                            </a>
                        </div>

                        <div class="col-4">
                            <a href="generate_merit.php" class="btn btn-outline-primary w-100 py-2">
                                <i class="bi bi-award d-block"></i>
                                <span class="small">Merit</span>
                            </a>
                        </div>

                        <div class="col-4">
                            <a href="merit_calls.php" class="btn btn-outline-warning w-100 py-2">
                                <i class="bi bi-megaphone d-block"></i>
                                <span class="small">Calls</span>
                            </a>
                        </div>

                    </div>


                    <div class="small text-uppercase fw-semibold text-muted mb-2">
                        Candidate Processing
                    </div>

                    <div class="row g-2 mb-3">

                        <div class="col-4">
                            <a href="approvals.php" class="btn btn-outline-success w-100 py-2 position-relative">
                                <i class="bi bi-clipboard-check d-block"></i>
                                <span class="small">Approvals</span>

                                <?php if ((int)$approval['pending'] > 0): ?>
                                    <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger">
                                        <?= (int)$approval['pending'] ?>
                                    </span>
                                <?php endif; ?>
                            </a>
                        </div>

                        <div class="col-4">
                            <a href="waiting_list.php" class="btn btn-outline-info w-100 py-2">
                                <i class="bi bi-list-ol d-block"></i>
                                <span class="small">Waiting</span>
                            </a>
                        </div>

                        <div class="col-4">

                            <?php if ($processCloseDone || $migrationDone): ?>

                                <a href="migration.php" class="btn btn-outline-success w-100 py-2">
                                    <i class="bi bi-arrow-up-right-circle d-block"></i>
                                    <span class="small">Migration</span>
                                </a>

                            <?php else: ?>

                                <button
                                    type="button"
                                    class="btn btn-outline-secondary w-100 py-2"
                                    disabled
                                    title="Available after current Merit Process closes"
                                >
                                    <i class="bi bi-lock d-block"></i>
                                    <span class="small">Migration</span>
                                </button>

                            <?php endif; ?>

                        </div>

                    </div>


                    <div class="small text-uppercase fw-semibold text-muted mb-2">
                        Management
                    </div>

                    <div class="row g-2">

                        <div class="col-6">
                            <a href="view_notices.php" class="btn btn-outline-secondary w-100 py-2">
                                <i class="bi bi-megaphone-fill me-1"></i>
                                Notices
                            </a>
                        </div>

                        <div class="col-6">
                            <a href="report.php" class="btn btn-outline-dark w-100 py-2">
                                <i class="bi bi-file-earmark-bar-graph me-1"></i>
                                Reports
                            </a>
                        </div>

                    </div>


                    <!-- collapsed extra stats -->
                    <div class="accordion accordion-flush mt-3" id="extraRegistrarStats">

                        <div class="accordion-item">

                            <h2 class="accordion-header">

                                <button
                                    class="accordion-button collapsed px-0 py-2 small"
                                    type="button"
                                    data-bs-toggle="collapse"
                                    data-bs-target="#extraStatsBody"
                                >
                                    More admission stats
                                </button>

                            </h2>

                            <div
                                id="extraStatsBody"
                                class="accordion-collapse collapse"
                                data-bs-parent="#extraRegistrarStats"
                            >

                                <div class="accordion-body px-0 pt-2 pb-0">

                                    <div class="d-flex justify-content-between small mb-2">
                                        <span class="text-muted">Migration pool</span>
                                        <strong><?= $migrationPool ?></strong>
                                    </div>

                                    <div class="d-flex justify-content-between small mb-2">
                                        <span class="text-muted">Called from waiting</span>
                                        <strong><?= (int)$waiting['called'] ?></strong>
                                    </div>

                                    <div class="d-flex justify-content-between small">
                                        <span class="text-muted">Vacated allocations</span>
                                        <strong><?= (int)$merit['vacated'] ?></strong>
                                    </div>

                                </div>

                            </div>

                        </div>

                    </div>

                </div>

            </div>

        </div>


        <!-- RIGHT: LATEST 3 PENDING REVIEWS -->
        <div class="col-lg-8">

            <div class="card shadow-sm h-100">

                <div class="card-header bg-dark text-white d-flex justify-content-between align-items-center">

                    <div>
                        <i class="bi bi-clock-history me-2"></i>
                        <strong>Pending Final Reviews</strong>
                    </div>

                    <a
                        href="approvals.php"
                        class="btn btn-sm btn-outline-light"
                    >
                        View All
                    </a>

                </div>


                <div class="table-responsive">

                    <table class="table table-hover align-middle mb-0">

                        <thead class="table-light">
                            <tr>
                                <th>GST Roll</th>
                                <th>Candidate</th>
                                <th>Department</th>
                                <th>Rank</th>
                                <th>Submitted</th>
                                <th></th>
                            </tr>
                        </thead>

                        <tbody>

                        <?php if ($pending): ?>

                            <?php foreach ($pending as $item): ?>

                                <tr>

                                    <td>
                                        <strong><?= rdE($item['gst_roll']) ?></strong>
                                    </td>

                                    <td>
                                        <?= rdE($item['full_name']) ?>
                                    </td>

                                    <td>
                                        <?= rdE($item['dept_name']) ?>
                                    </td>

                                    <td>
                                        <span class="badge bg-primary">
                                            #<?= (int)$item['merit_rank'] ?>
                                        </span>
                                    </td>

                                    <td class="small">
                                        <?= !empty($item['submitted_at'])
                                            ? date(
                                                'd M, H:i',
                                                strtotime((string)$item['submitted_at'])
                                            )
                                            : '—'
                                        ?>
                                    </td>

                                    <td>
                                        <a
                                            href="approvals.php?applicant_id=<?= (int)$item['applicant_id'] ?>"
                                            class="btn btn-sm btn-outline-primary"
                                        >
                                            Review
                                        </a>
                                    </td>

                                </tr>

                            <?php endforeach; ?>

                        <?php else: ?>

                            <tr>
                                <td colspan="6" class="text-center text-muted py-5">
                                    <i class="bi bi-check-circle fs-2 d-block mb-2"></i>
                                    No pending final reviews
                                </td>
                            </tr>

                        <?php endif; ?>

                        </tbody>

                    </table>

                </div>

            </div>

        </div>

    </div>

</div>

<?php require_once '../includes/footer.php'; ?>
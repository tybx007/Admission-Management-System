<?php

declare(strict_types=1);

$pageTitle = 'Merit Round Process';

require_once '../includes/auth.php';
require_once '../config/db.php';
require_once '../includes/admission_session.php';
require_once '../includes/merit_round_engine.php';

$notificationHelper =
    __DIR__
    . '/../includes/sms/merit_call_notifications.php';

if (is_file($notificationHelper)) {
    require_once $notificationHelper;
}

requireLogin('registrar');

date_default_timezone_set('Asia/Dhaka');

$pdo->setAttribute(
    PDO::ATTR_ERRMODE,
    PDO::ERRMODE_EXCEPTION
);

$currentAcademicYear =
    admissionSessionCurrentYear($pdo);

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] =
        bin2hex(random_bytes(32));
}

function roundPageE(mixed $value): string
{
    return htmlspecialchars(
        (string) ($value ?? ''),
        ENT_QUOTES,
        'UTF-8'
    );
}


function roundUserId(): ?int
{
    if (!empty($_SESSION['user_id'])) {
        return (int) $_SESSION['user_id'];
    }

    if (!empty($_SESSION['admin_id'])) {
        return (int) $_SESSION['admin_id'];
    }

    return null;
}


if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $token =
            (string) ($_POST['csrf_token'] ?? '');

        if (
            $token === ''
            || !hash_equals(
                (string) $_SESSION['csrf_token'],
                $token
            )
        ) {
            throw new RuntimeException(
                'Invalid request token. Refresh the page and try again.'
            );
        }

        $action =
            trim((string) ($_POST['action'] ?? ''));

        $currentCall =
            meritRoundLatestCall(
                $pdo,
                $currentAcademicYear
            );

        if (!$currentCall) {
            throw new RuntimeException(
                'Generate and publish the 1st Merit Call first.'
            );
        }

        $callId =
            (int) $currentCall['merit_call_id'];

        if ($action === 'set_process_end') {
            $processEnd =
                meritRoundParseDateTime(
                    (string) ($_POST['process_end_at'] ?? '')
                );

            $result =
                meritRoundSetProcessEnd(
                    $pdo,
                    $callId,
                    $currentAcademicYear,
                    $processEnd,
                    (int) (roundUserId() ?? 0)
                );

            $_SESSION['round_flash'] =
                'Process End set to '
                . date(
                    'd M Y, h:i A',
                    strtotime(
                        (string) $result['process_end_at']
                    )
                )
                . '. A Notice Board notice was published automatically.';

            $_SESSION['round_flash_type'] =
                'success';
        }


        elseif ($action === 'close_process') {
            $result =
                meritRoundCloseProcess(
                    $pdo,
                    $callId,
                    $currentAcademicYear
                );

            $_SESSION['round_flash'] =
                $currentCall['call_title']
                . ' has been CLOSED. '
                . (int) $result['unfinished_removed']
                . ' unfinished candidate(s) were removed. '
                . (int) $result['vacant_seats']
                . ' seat(s) are now available for Migration. '
                . 'No waiting candidate has been called yet.';

            $_SESSION['round_flash_type'] =
                'success';
        }


        elseif ($action === 'run_migration') {
            $result =
                meritRoundExecuteMigration(
                    $pdo,
                    $callId,
                    $currentAcademicYear,
                    roundUserId()
                );

            $notifications =
                $result[
                    'notifications'
                ]
                ?? [];


            $_SESSION['round_flash'] =
                'Migration completed. '
                . (int) (
                    $result[
                        'migrated_candidate_count'
                    ]
                    ?? $result[
                        'migration_count'
                    ]
                    ?? 0
                )
                . ' candidate(s) migrated. '
                . (int) (
                    $notifications[
                        'email_sent'
                    ]
                    ?? 0
                )
                . ' email(s) sent, '
                . (int) (
                    $notifications[
                        'sms_sent'
                    ]
                    ?? 0
                )
                . ' SMS sent. '
                . (int) $result['remaining_vacancies']
                . ' vacancy/vacancies remain for the next Merit Call.';


            if (
                (int) (
                    $notifications[
                        'email_failed'
                    ]
                    ?? 0
                )
                > 0
                ||
                (int) (
                    $notifications[
                        'sms_failed'
                    ]
                    ?? 0
                )
                > 0
            ) {

                $_SESSION['round_flash'] .=
                    ' Some notifications failed, but Migration was saved successfully.';
            }

            $_SESSION['round_flash_type'] =
                'success';
        }


        elseif ($action === 'publish_next') {
            $registrationStart =
                meritRoundParseDateTime(
                    (string) ($_POST['registration_start'] ?? '')
                );

            $registrationDeadline =
                meritRoundParseDateTime(
                    (string) ($_POST['registration_deadline'] ?? '')
                );

            $processEnd =
                meritRoundParseDateTime(
                    (string) ($_POST['next_process_end_at'] ?? '')
                );

            $result =
                meritRoundPublishNextCall(
                    $pdo,
                    $callId,
                    $currentAcademicYear,
                    $registrationStart,
                    $registrationDeadline,
                    $processEnd,
                    roundUserId()
                );

            $notificationText = '';

            if (
                !empty($result['published'])
                && !empty($result['next_call_id'])
                && function_exists('sendMeritCallNotifications')
            ) {
                try {
                    $notification =
                        call_user_func(
                            'sendMeritCallNotifications',
                            $pdo,
                            (int) $result['next_call_id']
                        );

                    $notificationText =
                        ' Notifications: '
                        . (int) ($notification['sms_sent'] ?? 0)
                        . ' SMS and '
                        . (int) ($notification['email_sent'] ?? 0)
                        . ' email sent.';

                } catch (Throwable $notificationError) {
                    error_log(
                        'Next Merit Call notification error: '
                        . $notificationError->getMessage()
                    );

                    $notificationText =
                        ' Merit Call was published, but some notifications failed.';
                }
            }

            if (!empty($result['published'])) {
                $_SESSION['round_flash'] =
                    $result['call_title']
                    . ' published for '
                    . (int) $result['candidate_count']
                    . ' waiting candidate(s). '
                    . 'The system allocated them globally by GST merit and highest available preference.'
                    . $notificationText;
            } else {
                $_SESSION['round_flash'] =
                    'Migration was completed, but no waiting candidate can fill any remaining vacancy. '
                    . 'No next Merit Call was published.';
            }

            $_SESSION['round_flash_type'] =
                'success';
        }


        else {
            throw new RuntimeException(
                'Invalid Merit Round action.'
            );
        }

    } catch (Throwable $e) {
        $_SESSION['round_flash'] =
            $e->getMessage();

        $_SESSION['round_flash_type'] =
            'danger';
    }

    header(
        'Location: '
        . basename($_SERVER['PHP_SELF'])
    );
    exit;
}


$message =
    (string) ($_SESSION['round_flash'] ?? '');

$messageType =
    (string) ($_SESSION['round_flash_type'] ?? 'info');

unset(
    $_SESSION['round_flash'],
    $_SESSION['round_flash_type']
);


$currentCall =
    meritRoundLatestCall(
        $pdo,
        $currentAcademicYear
    );

$now =
    new DateTimeImmutable(
        'now',
        new DateTimeZone('Asia/Dhaka')
    );

$processEndReached = false;
$registrationProcessed = false;
$vivaClosed = false;
$processClosed = false;
$migrationProcessed = false;
$nextPrepared = false;

$migrationPreview = [];
$departmentMap = [];
$totalVacancies = 0;
$waitingCount = 0;

if ($currentCall) {
    $registrationProcessed =
        !empty($currentCall['processed_at']);

    $vivaClosed =
        (string) $currentCall['viva_status']
        === 'Closed';

    $processClosed =
        !empty($currentCall['process_closed_at']);

    $migrationProcessed =
        !empty($currentCall['migration_processed_at']);

    $nextPrepared =
        !empty($currentCall['next_call_prepared_at']);

    if (!empty($currentCall['process_end_at'])) {
        $processEnd =
            new DateTimeImmutable(
                (string) $currentCall['process_end_at'],
                new DateTimeZone('Asia/Dhaka')
            );

        $processEndReached =
            $now >= $processEnd;
    }

    $departmentMap =
        migrationDepartmentMap(
            $pdo,
            $currentAcademicYear
        );

    foreach ($departmentMap as $department) {
        $totalVacancies +=
            (int) $department['vacant_seats'];
    }

    $waitingStmt = $pdo->prepare("
        SELECT COUNT(DISTINCT wl.import_candidate_id)
        FROM waiting_list wl
        INNER JOIN gst_imported_candidates gic
            ON gic.import_candidate_id = wl.import_candidate_id
        WHERE gic.academic_year = ?
          AND wl.waiting_status = 'Waiting'
    ");

    $waitingStmt->execute([
        $currentAcademicYear
    ]);

    $waitingCount =
        (int) $waitingStmt->fetchColumn();

    if ($processClosed && !$migrationProcessed) {
        $migrationPreview =
            previewMigration(
                $pdo,
                $currentAcademicYear
            );
    }
}


require_once '../includes/header.php';
require_once '../includes/navbar.php';
?>

<div class="container py-4">

    <div class="d-flex justify-content-between align-items-center flex-wrap gap-3 mb-4">
        <div>
            <h2 class="fw-bold mb-1">
                <i class="bi bi-diagram-3-fill text-primary me-2"></i>
                Merit Round Process
            </h2>

            <div class="text-muted">
                Admission Session:
                <strong>
                    <?= roundPageE($currentAcademicYear) ?>
                </strong>
            </div>
        </div>

        <div class="d-flex gap-2">
            <a
                href="merit_calls.php"
                class="btn btn-outline-primary"
            >
                <i class="bi bi-calendar-event me-1"></i>
                Registration & Viva
            </a>

            <a
                href="dashboard.php"
                class="btn btn-outline-secondary"
            >
                Dashboard
            </a>
        </div>
    </div>


    <?php if ($message !== ''): ?>
        <div class="alert alert-<?= roundPageE($messageType) ?>">
            <?= roundPageE($message) ?>
        </div>
    <?php endif; ?>


    <?php if (!$currentCall): ?>

        <div class="alert alert-warning">
            Generate the 1st Merit Call before using the Merit Round Process.
        </div>

    <?php else: ?>

        <div class="card shadow-sm mb-4">
            <div class="card-header bg-dark text-white d-flex justify-content-between align-items-center">
                <div>
                    <i class="bi bi-megaphone-fill me-2"></i>
                    <strong><?= roundPageE($currentCall['call_title']) ?></strong>
                </div>

                <span class="badge bg-primary">
                    <?= roundPageE($currentAcademicYear) ?>
                </span>
            </div>

            <div class="card-body">

                <div class="row g-3">

                    <div class="col-md-3">
                        <div class="small text-muted">Registration Deadline</div>
                        <div class="fw-semibold">
                            <?= roundPageE(
                                date(
                                    'd M Y, h:i A',
                                    strtotime(
                                        (string) $currentCall['registration_deadline']
                                    )
                                )
                            ) ?>
                        </div>
                    </div>

                    <div class="col-md-3">
                        <div class="small text-muted">Merit Process Ends</div>

                        <?php if (!empty($currentCall['process_end_at'])): ?>
                            <div class="fw-semibold text-danger">
                                <?= roundPageE(
                                    date(
                                        'd M Y, h:i A',
                                        strtotime(
                                            (string) $currentCall['process_end_at']
                                        )
                                    )
                                ) ?>
                            </div>
                        <?php else: ?>
                            <span class="badge bg-warning text-dark">
                                Not Set
                            </span>
                        <?php endif; ?>
                    </div>

                    <div class="col-md-2">
                        <div class="small text-muted">Vacant Seats</div>
                        <div class="h4 fw-bold mb-0">
                            <?= $processClosed
                                ? $totalVacancies
                                : '—'
                            ?>
                        </div>
                    </div>

                    <div class="col-md-2">
                        <div class="small text-muted">Waiting Pool</div>
                        <div class="h4 fw-bold text-info mb-0">
                            <?= $waitingCount ?>
                        </div>
                    </div>

                    <div class="col-md-2">
                        <div class="small text-muted">Round Status</div>

                        <?php if ($migrationProcessed): ?>
                            <span class="badge bg-success">
                                Migration Done
                            </span>
                        <?php elseif ($processClosed): ?>
                            <span class="badge bg-warning text-dark">
                                Closed
                            </span>
                        <?php else: ?>
                            <span class="badge bg-primary">
                                Running
                            </span>
                        <?php endif; ?>
                    </div>

                </div>

            </div>
        </div>


        <?php if (empty($currentCall['process_end_at'])): ?>

            <div class="card shadow-sm border-warning mb-4">

                <div class="card-header bg-warning">
                    <strong>
                        <i class="bi bi-megaphone me-1"></i>
                        Announce Current Merit Process End
                    </strong>
                </div>

                <div class="card-body">

                    <div class="alert alert-info">
                        Set when this Merit Call admission process will end.
                        The system will automatically publish the date/time
                        to the Notice Board.
                    </div>

                    <form method="POST">

                        <input
                            type="hidden"
                            name="csrf_token"
                            value="<?= roundPageE($_SESSION['csrf_token']) ?>"
                        >

                        <input
                            type="hidden"
                            name="action"
                            value="set_process_end"
                        >

                        <div class="row g-3 align-items-end">

                            <div class="col-md-8">
                                <label class="form-label fw-semibold">
                                    Merit Process End Date & Time
                                </label>

                                <input
                                    type="datetime-local"
                                    name="process_end_at"
                                    class="form-control"
                                    required
                                >
                            </div>

                            <div class="col-md-4">
                                <button
                                    type="submit"
                                    class="btn btn-warning w-100"
                                >
                                    Set End Time & Publish Notice
                                </button>
                            </div>

                        </div>

                    </form>

                </div>
            </div>

        <?php endif; ?>


        <div class="row g-4 mb-4">

            <!-- STEP 1 -->
            <div class="col-lg-4">
                <div class="card shadow-sm h-100 border-danger">

                    <div class="card-header bg-danger text-white">
                        <strong>
                            1. Close Current Process
                        </strong>
                    </div>

                    <div class="card-body">

                        <?php if ($processClosed): ?>

                            <div class="alert alert-success">
                                <strong>Completed ✓</strong>
                                <div class="small mt-1">
                                    Seats from unfinished candidates are now
                                    officially available for Migration.
                                </div>
                            </div>

                        <?php else: ?>

                            <div class="small mb-3">
                                Closing finalizes vacancies. No waiting candidate
                                is called at this stage.
                            </div>

                            <div class="mb-2">
                                Registration Deadline Processed:
                                <span class="badge bg-<?=
                                    $registrationProcessed
                                        ? 'success'
                                        : 'secondary'
                                ?>">
                                    <?= $registrationProcessed
                                        ? 'Yes'
                                        : 'No'
                                    ?>
                                </span>
                            </div>

                            <div class="mb-2">
                                Viva Closed:
                                <span class="badge bg-<?=
                                    $vivaClosed
                                        ? 'success'
                                        : 'secondary'
                                ?>">
                                    <?= $vivaClosed
                                        ? 'Yes'
                                        : 'No'
                                    ?>
                                </span>
                            </div>

                            <div class="mb-3">
                                Announced End Reached:
                                <span class="badge bg-<?=
                                    $processEndReached
                                        ? 'success'
                                        : 'secondary'
                                ?>">
                                    <?= $processEndReached
                                        ? 'Yes'
                                        : 'No'
                                    ?>
                                </span>
                            </div>

                            <form
                                method="POST"
                                onsubmit="return confirm(
                                    'Close this Merit Process?\n\nUnfinished provisional allocations will be removed. Vacancies will be released for Migration. No waiting candidate will be called yet.'
                                );"
                            >

                                <input
                                    type="hidden"
                                    name="csrf_token"
                                    value="<?= roundPageE($_SESSION['csrf_token']) ?>"
                                >

                                <input
                                    type="hidden"
                                    name="action"
                                    value="close_process"
                                >

                                <button
                                    class="btn btn-danger w-100"
                                    <?= (
                                        !$registrationProcessed
                                        || !$vivaClosed
                                        || !$processEndReached
                                    )
                                        ? 'disabled'
                                        : ''
                                    ?>
                                >
                                    <i class="bi bi-lock-fill me-1"></i>
                                    Close Current Process
                                </button>

                            </form>

                        <?php endif; ?>

                    </div>
                </div>
            </div>


            <!-- STEP 2 -->
            <div class="col-lg-4">
                <div class="card shadow-sm h-100 border-success">

                    <div class="card-header bg-success text-white">
                        <strong>
                            2. Execute Migration
                        </strong>
                    </div>

                    <div class="card-body">

                        <?php if ($migrationProcessed): ?>

                            <div class="alert alert-success">
                                <strong>Completed ✓</strong>
                                <div class="small mt-1">
                                    Migration seekers already received first
                                    priority over vacancies.
                                </div>
                            </div>

                        <?php elseif (!$processClosed): ?>

                            <div class="alert alert-secondary">
                                <i class="bi bi-lock-fill me-1"></i>
                                Close the current process first.
                            </div>

                        <?php else: ?>

                            <div class="mb-3">
                                Migration Preview:
                                <strong>
                                    <?= count($migrationPreview) ?>
                                    move(s)
                                </strong>
                            </div>

                            <?php if ($migrationPreview): ?>
                                <div
                                    class="border rounded p-2 mb-3 small"
                                    style="max-height:180px;overflow:auto;"
                                >
                                    <?php foreach (
                                        $migrationPreview as $move
                                    ): ?>
                                        <div class="mb-2">
                                            <strong>
                                                <?= roundPageE($move['full_name']) ?>
                                            </strong>
                                            :
                                            <?= roundPageE($move['from_department']) ?>
                                            (#<?= (int) $move['from_preference_order'] ?>)
                                            →
                                            <span class="text-success fw-semibold">
                                                <?= roundPageE($move['to_department']) ?>
                                                (#<?= (int) $move['to_preference_order'] ?>)
                                            </span>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>

                            <form
                                method="POST"
                                onsubmit="return confirm(
                                    'Execute Migration now?\n\nOnly finally admitted candidates with Migration Enabled will be considered. GST merit and preference order control every move.'
                                );"
                            >

                                <input
                                    type="hidden"
                                    name="csrf_token"
                                    value="<?= roundPageE($_SESSION['csrf_token']) ?>"
                                >

                                <input
                                    type="hidden"
                                    name="action"
                                    value="run_migration"
                                >

                                <button
                                    class="btn btn-success w-100"
                                >
                                    <i class="bi bi-arrow-up-right-circle-fill me-1"></i>
                                    Execute Migration
                                </button>

                            </form>

                        <?php endif; ?>

                    </div>
                </div>
            </div>


            <!-- STEP 3 -->
            <div class="col-lg-4">
                <div class="card shadow-sm h-100 border-primary">

                    <div class="card-header bg-primary text-white">
                        <strong>
                            3. Call for Next Merit List
                        </strong>
                    </div>

                    <div class="card-body">

                        <?php if ($nextPrepared): ?>

                            <div class="alert alert-success">
                                <strong>Completed ✓</strong>
                            </div>

                        <?php elseif (!$migrationProcessed): ?>

                            <div class="alert alert-secondary">
                                <i class="bi bi-lock-fill me-1"></i>
                                Execute Migration first.
                            </div>

                        <?php else: ?>

                            <div class="alert alert-info small">
                                Remaining vacancies will now be filled from the
                                waiting pool globally by GST merit. For each
                                candidate the system checks Choice 1 → Choice 2
                                → later choices.
                            </div>

                            <form
                                method="POST"
                                onsubmit="return confirm(
                                    'Prepare and publish the next Merit Call?\n\nMigration has priority. Only remaining vacancies will now be offered to waiting candidates.'
                                );"
                            >

                                <input
                                    type="hidden"
                                    name="csrf_token"
                                    value="<?= roundPageE($_SESSION['csrf_token']) ?>"
                                >

                                <input
                                    type="hidden"
                                    name="action"
                                    value="publish_next"
                                >

                                <div class="mb-2">
                                    <label class="form-label small fw-semibold">
                                        Registration Starts
                                    </label>
                                    <input
                                        type="datetime-local"
                                        name="registration_start"
                                        class="form-control"
                                        required
                                    >
                                </div>

                                <div class="mb-2">
                                    <label class="form-label small fw-semibold">
                                        Registration Deadline
                                    </label>
                                    <input
                                        type="datetime-local"
                                        name="registration_deadline"
                                        class="form-control"
                                        required
                                    >
                                </div>

                                <div class="mb-3">
                                    <label class="form-label small fw-semibold">
                                        Next Merit Process Ends
                                    </label>
                                    <input
                                        type="datetime-local"
                                        name="next_process_end_at"
                                        class="form-control"
                                        required
                                    >
                                </div>

                                <button
                                    class="btn btn-primary w-100"
                                >
                                    <i class="bi bi-megaphone-fill me-1"></i>
                                    Prepare & Publish Next Merit Call
                                </button>

                            </form>

                        <?php endif; ?>

                    </div>
                </div>
            </div>

        </div>


        <?php if ($processClosed): ?>

            <div class="card shadow-sm">

                <div class="card-header">
                    <strong>
                        <i class="bi bi-building me-1"></i>
                        Department Vacancy Status
                    </strong>
                </div>

                <div class="table-responsive">

                    <table class="table table-hover align-middle mb-0">

                        <thead class="table-light">
                            <tr>
                                <th>Department</th>
                                <th>Seats</th>
                                <th>Occupied</th>
                                <th>Vacant</th>
                            </tr>
                        </thead>

                        <tbody>
                        <?php foreach (
                            $departmentMap as $department
                        ): ?>
                            <tr>
                                <td>
                                    <?= roundPageE($department['dept_name']) ?>
                                </td>

                                <td>
                                    <?= (int) $department['total_seats'] ?>
                                </td>

                                <td>
                                    <?= (int) $department['occupied_seats'] ?>
                                </td>

                                <td>
                                    <span class="badge bg-<?=
                                        (int) $department['vacant_seats'] > 0
                                            ? 'warning text-dark'
                                            : 'success'
                                    ?>">
                                        <?= (int) $department['vacant_seats'] ?>
                                    </span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>

                    </table>

                </div>
            </div>

        <?php endif; ?>

    <?php endif; ?>

</div>

<?php require_once '../includes/footer.php'; ?>

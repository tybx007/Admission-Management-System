<?php

declare(strict_types=1);

$pageTitle = 'Migration';

require_once '../includes/auth.php';
require_once '../config/db.php';
require_once '../includes/admission_session.php';

requireLogin('student');

$pdo->setAttribute(
    PDO::ATTR_ERRMODE,
    PDO::ERRMODE_EXCEPTION
);

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

function migrationPageE(mixed $value): string
{
    return htmlspecialchars(
        (string) ($value ?? ''),
        ENT_QUOTES,
        'UTF-8'
    );
}

$applicantId =
    (int) (
        $_SESSION['user_id']
        ?? 0
    );

$message = '';
$messageType = 'info';
$setupError = '';
$currentAcademicYear = '';
$profile = null;


/*
|--------------------------------------------------------------------------
| Load Migration Engine safely
|--------------------------------------------------------------------------
*/

$migrationEnginePath =
    __DIR__
    . '/../includes/migration_engine.php';

if (!is_file($migrationEnginePath)) {

    $setupError =
        'Migration engine is missing. Put migration_engine.php inside the includes folder.';

} else {

    try {

        require_once $migrationEnginePath;


        /*
        |--------------------------------------------------------------------------
        | Current Admission Session
        |--------------------------------------------------------------------------
        */

        $currentAcademicYear =
            admissionSessionCurrentYear(
                $pdo
            );


        if ($applicantId <= 0) {
            throw new RuntimeException(
                'Student login session is invalid.'
            );
        }


        /*
        |--------------------------------------------------------------------------
        | Process permanent Migration Opt Out
        |--------------------------------------------------------------------------
        */

        if (
            $_SERVER['REQUEST_METHOD']
            === 'POST'
        ) {

            $token =
                (string) (
                    $_POST['csrf_token']
                    ?? ''
                );


            if (
                $token === ''
                ||
                !hash_equals(
                    (string)
                    $_SESSION['csrf_token'],
                    $token
                )
            ) {
                throw new RuntimeException(
                    'Invalid request token. Refresh the page and try again.'
                );
            }


            if (
                (string) (
                    $_POST['action']
                    ?? ''
                )
                !== 'opt_out'
            ) {
                throw new RuntimeException(
                    'Invalid Migration action.'
                );
            }


            if (
                (string) (
                    $_POST['confirmation']
                    ?? ''
                )
                !== 'OPT OUT'
            ) {
                throw new RuntimeException(
                    'Migration Opt Out confirmation failed.'
                );
            }


            optOutMigration(
                $pdo,
                $applicantId,
                $currentAcademicYear
            );


            $messageType =
                'success';

            $message =
                'Migration has been permanently turned OFF for this admission session.';
        }


        /*
        |--------------------------------------------------------------------------
        | Candidate Migration Profile
        |--------------------------------------------------------------------------
        */

        $profile =
            getCandidateMigrationProfile(
                $pdo,
                $applicantId,
                $currentAcademicYear
            );


    } catch (PDOException $exception) {

        $databaseMessage =
            $exception->getMessage();


        if (
            stripos(
                $databaseMessage,
                'migration_status'
            ) !== false
            ||
            stripos(
                $databaseMessage,
                'migration_opted_out_at'
            ) !== false
            ||
            stripos(
                $databaseMessage,
                'migration_history'
            ) !== false
        ) {

            $setupError =
                'Migration database setup has not been installed yet. Run migration_system_setup.sql once in phpMyAdmin.';

        } else {

            $setupError =
                'Database error while loading Migration: '
                . $databaseMessage;
        }


    } catch (Throwable $exception) {

        $setupError =
            $exception->getMessage();
    }
}


require_once '../includes/header.php';
require_once '../includes/navbar.php';

?>

<div class="container py-4">

    <?php if ($setupError !== ''): ?>

        <div class="alert alert-danger shadow-sm mb-4">

            <h5 class="fw-bold mb-2">
                <i class="bi bi-exclamation-triangle-fill me-1"></i>
                Migration Setup Error
            </h5>

            <div>
                <?= migrationPageE($setupError) ?>
            </div>

            <hr>

            <div class="small">
                Required:
                <code>includes/migration_engine.php</code>
                and the database migration from
                <code>migration_system_setup.sql</code>.
            </div>

        </div>

    <?php endif; ?>

    <div class="d-flex justify-content-between align-items-center flex-wrap gap-3 mb-4">
        <div>
            <h3 class="fw-bold mb-1">
                <i class="bi bi-arrow-up-circle text-primary me-2"></i>
                Department Migration
            </h3>

            <div class="text-muted">
                Admission Session:
                <strong><?= migrationPageE(
        $currentAcademicYear !== ''
            ? $currentAcademicYear
            : 'Unavailable'
    ) ?></strong>
            </div>
        </div>

        <a
            href="/jkkniu-admission/student/dashboard.php"
            class="btn btn-outline-secondary"
        >
            <i class="bi bi-arrow-left me-1"></i>
            Dashboard
        </a>
    </div>

    <?php if ($message !== ''): ?>
        <div class="alert alert-<?= migrationPageE($messageType) ?>">
            <?= migrationPageE($message) ?>
        </div>
    <?php endif; ?>

    <?php if ($setupError !== ''): ?>

        <div class="card shadow-sm">
            <div class="card-body text-center py-5">

                <i class="bi bi-tools fs-1 text-danger d-block mb-3"></i>

                <h5 class="fw-bold">
                    Migration module needs setup
                </h5>

                <p class="text-muted mb-0">
                    Complete the database/file setup shown above,
                    then refresh this page.
                </p>

            </div>
        </div>

    <?php elseif (!$profile): ?>

        <div class="alert alert-info">
            <h5 class="fw-bold">Migration is not available yet</h5>
            Migration becomes available only after your
            <strong>Final Admission</strong> is completed.
        </div>

    <?php else: ?>

        <?php
        $currentPreference =
            (int) ($profile['current_preference_order'] ?? 0);

        $migrationStatus =
            (string) $profile['migration_status'];
        ?>

        <div class="row g-4">

            <div class="col-lg-7">
                <div class="card shadow-sm h-100">

                    <div class="card-header bg-primary text-white">
                        <h5 class="mb-0">
                            <i class="bi bi-mortarboard-fill me-2"></i>
                            Current Admission
                        </h5>
                    </div>

                    <div class="card-body">

                        <div class="row g-3 mb-4">
                            <div class="col-md-6">
                                <div class="small text-muted">Candidate</div>
                                <div class="fw-bold">
                                    <?= migrationPageE($profile['full_name']) ?>
                                </div>
                                <div class="small text-muted">
                                    GST Roll:
                                    <?= migrationPageE($profile['gst_roll']) ?>
                                </div>
                            </div>

                            <div class="col-md-6">
                                <div class="small text-muted">Current Department</div>
                                <div class="fw-bold text-primary">
                                    <?= migrationPageE($profile['dept_name']) ?>
                                </div>
                                <div class="small text-muted">
                                    Current Preference:
                                    #<?= $currentPreference ?>
                                </div>
                            </div>
                        </div>

                        <div class="fw-semibold mb-2">
                            Original Department Preferences
                        </div>

                        <div class="list-group">
                            <?php foreach ($profile['choices'] as $choice): ?>
                                <?php
                                $choiceOrder = (int) $choice['preference_order'];
                                $isCurrent =
                                    (int) $choice['dept_id']
                                    ===
                                    (int) $profile['dept_id'];
                                ?>

                                <div class="list-group-item d-flex justify-content-between align-items-center">
                                    <div>
                                        <span class="badge bg-secondary me-2">
                                            #<?= $choiceOrder ?>
                                        </span>

                                        <?= migrationPageE($choice['dept_name']) ?>
                                    </div>

                                    <?php if ($isCurrent): ?>
                                        <span class="badge bg-primary">Current</span>
                                    <?php elseif ($choiceOrder < $currentPreference): ?>
                                        <span class="badge bg-light text-dark border">
                                            Higher Preference
                                        </span>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>

                    </div>
                </div>
            </div>

            <div class="col-lg-5">
                <div class="card shadow-sm h-100">

                    <div class="card-header">
                        <h5 class="mb-0">
                            <i class="bi bi-toggle-on me-2"></i>
                            Migration Status
                        </h5>
                    </div>

                    <div class="card-body">

                        <?php if ($currentPreference <= 1): ?>

                            <div class="alert alert-success mb-0">
                                <h6 class="fw-bold">First Choice Achieved</h6>
                                You are already admitted to your first-choice
                                department. No higher Migration is possible.
                            </div>

                        <?php elseif ($migrationStatus === 'Enabled'): ?>

                            <div class="text-center mb-4">
                                <span class="badge bg-success fs-6 px-3 py-2">
                                    <i class="bi bi-check-circle-fill me-1"></i>
                                    MIGRATION ENABLED
                                </span>
                            </div>

                            <div class="alert alert-info">
                                You will automatically be considered for a
                                vacant seat in a higher-preference department
                                according to GST merit order.
                            </div>

                            <div class="alert alert-warning">
                                <strong>Warning:</strong>
                                turning Migration OFF is permanent for this
                                admission session. You will remain in your
                                current department and cannot re-enter the
                                Migration system.
                            </div>

                            <form
                                method="POST"
                                onsubmit="return confirm(
                                    'Turn Migration OFF permanently?\n\nYou will remain in your current department and cannot re-enter the Migration system for this admission session.'
                                );"
                            >
                                <input
                                    type="hidden"
                                    name="csrf_token"
                                    value="<?= migrationPageE($_SESSION['csrf_token']) ?>"
                                >

                                <input
                                    type="hidden"
                                    name="action"
                                    value="opt_out"
                                >

                                <input
                                    type="hidden"
                                    name="confirmation"
                                    value="OPT OUT"
                                >

                                <button
                                    type="submit"
                                    class="btn btn-danger w-100"
                                >
                                    <i class="bi bi-toggle-off me-1"></i>
                                    Turn Off Migration Permanently
                                </button>
                            </form>

                        <?php else: ?>

                            <div class="text-center mb-4">
                                <span class="badge bg-danger fs-6 px-3 py-2">
                                    <i class="bi bi-x-circle-fill me-1"></i>
                                    MIGRATION OPTED OUT
                                </span>
                            </div>

                            <div class="alert alert-danger">
                                You are permanently outside the Migration
                                system for this admission session.

                                <br><br>

                                Current Department:
                                <strong>
                                    <?= migrationPageE($profile['dept_name']) ?>
                                </strong>
                            </div>

                            <?php if (!empty($profile['migration_opted_out_at'])): ?>
                                <div class="small text-muted text-center">
                                    Opted out:
                                    <?= migrationPageE(
                                        date(
                                            'd M Y, h:i A',
                                            strtotime(
                                                (string)
                                                $profile['migration_opted_out_at']
                                            )
                                        )
                                    ) ?>
                                </div>
                            <?php endif; ?>

                        <?php endif; ?>

                    </div>
                </div>
            </div>

        </div>

    <?php endif; ?>

</div>

<?php require_once '../includes/footer.php'; ?>
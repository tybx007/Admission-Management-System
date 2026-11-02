<?php

declare(strict_types=1);

$pageTitle = 'Migration Management';

require_once '../includes/auth.php';
require_once '../config/db.php';
require_once '../includes/admission_session.php';
require_once '../includes/migration_engine.php';
require_once '../includes/merit_round_engine.php';

requireLogin('registrar');

$pdo->setAttribute(
    PDO::ATTR_ERRMODE,
    PDO::ERRMODE_EXCEPTION
);

date_default_timezone_set('Asia/Dhaka');

$currentAcademicYear =
    admissionSessionCurrentYear($pdo);

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] =
        bin2hex(random_bytes(32));
}


/*
|--------------------------------------------------------------------------
| Helpers
|--------------------------------------------------------------------------
*/

function registrarMigrationE(
    mixed $value
): string {
    return htmlspecialchars(
        (string) ($value ?? ''),
        ENT_QUOTES,
        'UTF-8'
    );
}


function registrarMigrationUserId(): ?int
{
    if (!empty($_SESSION['user_id'])) {
        return (int) $_SESSION['user_id'];
    }

    if (!empty($_SESSION['admin_id'])) {
        return (int) $_SESSION['admin_id'];
    }

    return null;
}


/*
|--------------------------------------------------------------------------
| Safety check: correct engine must be installed
|--------------------------------------------------------------------------
*/

$engineReady =
    function_exists('previewMigration')
    &&
    function_exists('migrationDepartmentMap')
    &&
    function_exists('meritRoundExecuteMigration');


/*
|--------------------------------------------------------------------------
| Current Merit Call
|--------------------------------------------------------------------------
*/

$currentCall = null;

if ($engineReady) {

    $callStmt =
        $pdo->prepare("
            SELECT
                merit_call_id,
                call_number,
                call_title,
                academic_year,
                process_end_at,
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

    $callStmt->execute([
        $currentAcademicYear
    ]);

    $currentCall =
        $callStmt->fetch(
            PDO::FETCH_ASSOC
        ) ?: null;
}


$processClosed =
    $currentCall
    &&
    !empty(
        $currentCall['process_closed_at']
    );


$migrationProcessed =
    $currentCall
    &&
    !empty(
        $currentCall['migration_processed_at']
    );


$canRunMigration =
    $engineReady
    &&
    $currentCall
    &&
    $processClosed
    &&
    !$migrationProcessed;


/*
|--------------------------------------------------------------------------
| Messages
|--------------------------------------------------------------------------
*/

$message = '';
$messageType = 'info';


/*
|--------------------------------------------------------------------------
| Execute Migration
|--------------------------------------------------------------------------
*/

if (
    $_SERVER['REQUEST_METHOD']
    === 'POST'
) {

    try {

        if (!$engineReady) {
            throw new RuntimeException(
                'Migration engine is incomplete. Replace includes/migration_engine.php and includes/merit_round_engine.php with the current versions.'
            );
        }


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
            !== 'run_migration'
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
            !== 'RUN MIGRATION'
        ) {
            throw new RuntimeException(
                'Migration confirmation failed.'
            );
        }


        /*
        |--------------------------------------------------------------------------
        | Re-read and lock workflow state through Merit Round engine
        |--------------------------------------------------------------------------
        */

        if (!$currentCall) {
            throw new RuntimeException(
                'No Merit Call exists for the current admission session.'
            );
        }


        if (!$processClosed) {
            throw new RuntimeException(
                'Close the current Merit Process before running Migration.'
            );
        }


        if ($migrationProcessed) {
            throw new RuntimeException(
                'Migration has already been processed for this Merit Call.'
            );
        }


        $result =
            meritRoundExecuteMigration(
                $pdo,
                (int)
                $currentCall[
                    'merit_call_id'
                ],
                $currentAcademicYear,
                registrarMigrationUserId()
            );


        $notifications =
            $result['notifications']
            ?? [];


        $messageType =
            'success';


        $message =
            'Migration completed successfully. '
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
            . ' email(s) sent and '
            . (int) (
                $notifications[
                    'sms_sent'
                ]
                ?? 0
            )
            . ' SMS sent. '
            . (int) (
                $result[
                    'remaining_vacancies'
                ]
                ?? 0
            )
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
            $message .=
                ' Some notifications failed, but Migration was saved successfully.';
        }


        /*
        |--------------------------------------------------------------------------
        | Refresh workflow state after successful Migration
        |--------------------------------------------------------------------------
        */

        $callStmt->execute([
            $currentAcademicYear
        ]);

        $currentCall =
            $callStmt->fetch(
                PDO::FETCH_ASSOC
            ) ?: null;


        $processClosed =
            $currentCall
            &&
            !empty(
                $currentCall[
                    'process_closed_at'
                ]
            );


        $migrationProcessed =
            $currentCall
            &&
            !empty(
                $currentCall[
                    'migration_processed_at'
                ]
            );


        $canRunMigration =
            $currentCall
            &&
            $processClosed
            &&
            !$migrationProcessed;


    } catch (Throwable $e) {

        $messageType =
            'danger';

        $message =
            $e->getMessage();
    }
}


/*
|--------------------------------------------------------------------------
| Preview
|--------------------------------------------------------------------------
|
| IMPORTANT:
| Before Process Close, vacancies are NOT official for Migration.
| Therefore no migration preview is shown before Close.
|--------------------------------------------------------------------------
*/

$previewMoves = [];

if ($canRunMigration) {

    $previewMoves =
        previewMigration(
            $pdo,
            $currentAcademicYear
        );
}


/*
|--------------------------------------------------------------------------
| Department map
|--------------------------------------------------------------------------
*/

$departmentMap = [];

if (
    $engineReady
    &&
    $processClosed
) {

    $departmentMap =
        migrationDepartmentMap(
            $pdo,
            $currentAcademicYear
        );
}


/*
|--------------------------------------------------------------------------
| Migration statistics
|--------------------------------------------------------------------------
*/

$stats = [
    'admitted_count' => 0,
    'enabled_count' => 0,
    'opted_out_count' => 0,
];


$statsStmt =
    $pdo->prepare("
        SELECT
            COUNT(
                DISTINCT CASE

                    WHEN ap.final_status =
                         'Admitted'

                     AND ma.allocation_status =
                         'Admitted'

                    THEN gic.import_candidate_id

                END
            ) AS admitted_count,


            COUNT(
                DISTINCT CASE

                    WHEN ap.final_status =
                         'Admitted'

                     AND ma.allocation_status =
                         'Admitted'

                     AND ap.migration_status =
                         'Enabled'

                    THEN gic.import_candidate_id

                END
            ) AS enabled_count,


            COUNT(
                DISTINCT CASE

                    WHEN ap.final_status =
                         'Admitted'

                     AND ma.allocation_status =
                         'Admitted'

                     AND ap.migration_status =
                         'Opted Out'

                    THEN gic.import_candidate_id

                END
            ) AS opted_out_count


        FROM gst_imported_candidates gic


        INNER JOIN admission_progress ap
            ON ap.applicant_id =
               gic.applicant_id


        INNER JOIN merit_allocations ma
            ON ma.import_candidate_id =
               gic.import_candidate_id


        WHERE gic.academic_year = ?
    ");


$statsStmt->execute([
    $currentAcademicYear
]);


$stats =
    $statsStmt->fetch(
        PDO::FETCH_ASSOC
    ) ?: $stats;


/*
|--------------------------------------------------------------------------
| Migration History
|--------------------------------------------------------------------------
*/

$historyStmt =
    $pdo->prepare("
        SELECT
            mh.migration_id,
            mh.from_preference_order,
            mh.to_preference_order,
            mh.migrated_at,

            gic.gst_roll,
            gic.full_name,
            gic.merit_position,

            d1.dept_name
                AS from_department,

            d2.dept_name
                AS to_department

        FROM migration_history mh

        INNER JOIN gst_imported_candidates gic
            ON gic.import_candidate_id =
               mh.import_candidate_id

        INNER JOIN departments d1
            ON d1.dept_id =
               mh.from_dept_id

        INNER JOIN departments d2
            ON d2.dept_id =
               mh.to_dept_id

        WHERE mh.academic_year = ?

        ORDER BY
            mh.migration_id DESC

        LIMIT 100
    ");


$historyStmt->execute([
    $currentAcademicYear
]);


$history =
    $historyStmt->fetchAll(
        PDO::FETCH_ASSOC
    );


require_once '../includes/header.php';
require_once '../includes/navbar.php';

?>


<div class="container py-4">


    <div
        class="d-flex
               justify-content-between
               align-items-center
               flex-wrap
               gap-3
               mb-4"
    >

        <div>

            <h3 class="fw-bold mb-1">

                <i
                    class="bi
                           bi-arrow-up-right-circle-fill
                           text-primary
                           me-2"
                ></i>

                Migration Management

            </h3>


            <div class="text-muted">

                Admission Session:

                <strong>
                    <?= registrarMigrationE(
                        $currentAcademicYear
                    ) ?>
                </strong>

            </div>

        </div>


        <a
            href="/jkkniu-admission/registrar/dashboard.php"
            class="btn btn-outline-secondary"
        >

            <i class="bi bi-arrow-left me-1"></i>

            Registrar Dashboard

        </a>

    </div>


    <?php if ($message !== ''): ?>

        <div
            class="alert
                   alert-<?= registrarMigrationE(
                       $messageType
                   ) ?>"
        >

            <?= registrarMigrationE(
                $message
            ) ?>

        </div>

    <?php endif; ?>


    <?php if (!$engineReady): ?>

        <div class="alert alert-danger">

            <strong>
                Migration engine is incomplete.
            </strong>

            <br>

            The required functions
            <code>previewMigration()</code>,
            <code>migrationDepartmentMap()</code>,
            and
            <code>meritRoundExecuteMigration()</code>
            are not all available.

        </div>

    <?php elseif (!$currentCall): ?>

        <div class="alert alert-warning">

            No Merit Call exists for
            <?= registrarMigrationE(
                $currentAcademicYear
            ) ?>.

        </div>

    <?php else: ?>


        <!-- ============================================================
             WORKFLOW STATUS
        ============================================================= -->

        <div class="card shadow-sm mb-4">

            <div
                class="card-body
                       d-flex
                       justify-content-between
                       align-items-center
                       flex-wrap
                       gap-3"
            >

                <div>

                    <div class="small text-muted">
                        Current Merit Call
                    </div>

                    <div class="fw-bold fs-5">

                        <?= registrarMigrationE(
                            $currentCall[
                                'call_title'
                            ]
                        ) ?>

                    </div>

                </div>


                <div>

                    <?php if ($migrationProcessed): ?>

                        <span
                            class="badge
                                   bg-success
                                   fs-6"
                        >

                            <i
                                class="bi
                                       bi-check-circle-fill
                                       me-1"
                            ></i>

                            Migration Completed

                        </span>


                    <?php elseif ($processClosed): ?>

                        <span
                            class="badge
                                   bg-warning
                                   text-dark
                                   fs-6"
                        >

                            <i
                                class="bi
                                       bi-unlock-fill
                                       me-1"
                            ></i>

                            Ready for Migration

                        </span>


                    <?php else: ?>

                        <span
                            class="badge
                                   bg-secondary
                                   fs-6"
                        >

                            <i
                                class="bi
                                       bi-lock-fill
                                       me-1"
                            ></i>

                            Locked Until Process Close

                        </span>

                    <?php endif; ?>

                </div>

            </div>

        </div>


        <!-- ============================================================
             STATS
        ============================================================= -->

        <div class="row g-3 mb-4">


            <div class="col-md-3">

                <div
                    class="card
                           shadow-sm
                           h-100
                           border-primary"
                >

                    <div class="card-body">

                        <div class="small text-muted">
                            Finally Admitted
                        </div>

                        <div
                            class="display-6
                                   fw-bold
                                   text-primary"
                        >

                            <?= (int) (
                                $stats[
                                    'admitted_count'
                                ]
                                ?? 0
                            ) ?>

                        </div>

                    </div>

                </div>

            </div>


            <div class="col-md-3">

                <div
                    class="card
                           shadow-sm
                           h-100
                           border-success"
                >

                    <div class="card-body">

                        <div class="small text-muted">
                            Migration Enabled
                        </div>

                        <div
                            class="display-6
                                   fw-bold
                                   text-success"
                        >

                            <?= (int) (
                                $stats[
                                    'enabled_count'
                                ]
                                ?? 0
                            ) ?>

                        </div>

                    </div>

                </div>

            </div>


            <div class="col-md-3">

                <div
                    class="card
                           shadow-sm
                           h-100
                           border-danger"
                >

                    <div class="card-body">

                        <div class="small text-muted">
                            Opted Out
                        </div>

                        <div
                            class="display-6
                                   fw-bold
                                   text-danger"
                        >

                            <?= (int) (
                                $stats[
                                    'opted_out_count'
                                ]
                                ?? 0
                            ) ?>

                        </div>

                    </div>

                </div>

            </div>


            <div class="col-md-3">

                <div
                    class="card
                           shadow-sm
                           h-100
                           border-warning"
                >

                    <div class="card-body">

                        <div class="small text-muted">
                            Preview Moves
                        </div>

                        <div
                            class="display-6
                                   fw-bold
                                   text-warning"
                        >

                            <?= $canRunMigration
                                ? count(
                                    $previewMoves
                                )
                                : '—'
                            ?>

                        </div>

                    </div>

                </div>

            </div>


        </div>


        <!-- ============================================================
             MIGRATION PREVIEW
        ============================================================= -->

        <div class="card shadow-sm mb-4">


            <div
                class="card-header
                       bg-primary
                       text-white
                       d-flex
                       justify-content-between
                       align-items-center"
            >

                <h5 class="mb-0">

                    <i class="bi bi-eye-fill me-2"></i>

                    Migration Preview

                </h5>


                <?php if ($canRunMigration): ?>

                    <span
                        class="badge
                               bg-light
                               text-primary"
                    >

                        <?= count(
                            $previewMoves
                        ) ?>
                        move(s)

                    </span>

                <?php endif; ?>

            </div>


            <div class="card-body">


                <?php if (!$processClosed): ?>

                    <div class="alert alert-secondary mb-0">

                        <i class="bi bi-lock-fill me-1"></i>

                        Migration is locked.

                        Close the current Merit Process first.
                        Only after closure do vacancies become
                        officially available for Migration.

                    </div>


                <?php elseif ($migrationProcessed): ?>

                    <div class="alert alert-success mb-0">

                        <i
                            class="bi
                                   bi-check-circle-fill
                                   me-1"
                        ></i>

                        Migration has already been completed for
                        <?= registrarMigrationE(
                            $currentCall[
                                'call_title'
                            ]
                        ) ?>.

                    </div>


                <?php else: ?>


                    <div class="alert alert-info">

                        Preview does not change the database.

                        Only
                        <strong>
                            finally admitted + Migration Enabled
                        </strong>
                        candidates are considered.

                        Candidates are processed by GST merit,
                        and only a higher preference can be assigned.

                    </div>


                    <?php if ($previewMoves): ?>


                        <div class="table-responsive">

                            <table
                                class="table
                                       table-hover
                                       align-middle"
                            >


                                <thead class="table-light">

                                    <tr>

                                        <th>#</th>

                                        <th>Candidate</th>

                                        <th>GST Merit</th>

                                        <th>From</th>

                                        <th>To</th>

                                        <th>Preference</th>

                                    </tr>

                                </thead>


                                <tbody>


                                <?php foreach (
                                    $previewMoves
                                    as $index => $move
                                ): ?>

                                    <tr>


                                        <td>
                                            <?= $index + 1 ?>
                                        </td>


                                        <td>

                                            <strong>
                                                <?= registrarMigrationE(
                                                    $move[
                                                        'full_name'
                                                    ]
                                                ) ?>
                                            </strong>


                                            <div
                                                class="small
                                                       text-muted"
                                            >

                                                GST Roll:

                                                <?= registrarMigrationE(
                                                    $move[
                                                        'gst_roll'
                                                    ]
                                                ) ?>

                                            </div>

                                        </td>


                                        <td>

                                            <?= $move[
                                                'merit_position'
                                            ] !== null
                                                ? (int)
                                                  $move[
                                                      'merit_position'
                                                  ]
                                                : 'N/A'
                                            ?>

                                        </td>


                                        <td>

                                            <?= registrarMigrationE(
                                                $move[
                                                    'from_department'
                                                ]
                                            ) ?>

                                            <div
                                                class="small
                                                       text-muted"
                                            >

                                                Choice #

                                                <?= (int)
                                                    $move[
                                                        'from_preference_order'
                                                    ]
                                                ?>

                                            </div>

                                        </td>


                                        <td>

                                            <strong
                                                class="text-success"
                                            >

                                                <?= registrarMigrationE(
                                                    $move[
                                                        'to_department'
                                                    ]
                                                ) ?>

                                            </strong>


                                            <div
                                                class="small
                                                       text-success"
                                            >

                                                Choice #

                                                <?= (int)
                                                    $move[
                                                        'to_preference_order'
                                                    ]
                                                ?>

                                            </div>

                                        </td>


                                        <td>

                                            #<?= (int)
                                                $move[
                                                    'from_preference_order'
                                                ]
                                            ?>

                                            →

                                            <strong>

                                                #<?= (int)
                                                    $move[
                                                        'to_preference_order'
                                                    ]
                                                ?>

                                            </strong>

                                        </td>


                                    </tr>

                                <?php endforeach; ?>


                                </tbody>


                            </table>

                        </div>


                        <form
                            method="POST"
                            onsubmit="
                                return confirm(
                                    'Run this Migration now?\n\nMigration will be saved permanently, the Merit Call will be marked Migration Completed, and successful candidates will receive email/SMS notifications.'
                                );
                            "
                        >

                            <input
                                type="hidden"
                                name="csrf_token"
                                value="<?= registrarMigrationE(
                                    $_SESSION[
                                        'csrf_token'
                                    ]
                                ) ?>"
                            >


                            <input
                                type="hidden"
                                name="action"
                                value="run_migration"
                            >


                            <input
                                type="hidden"
                                name="confirmation"
                                value="RUN MIGRATION"
                            >


                            <button
                                type="submit"
                                class="btn
                                       btn-success
                                       btn-lg"
                            >

                                <i class="bi bi-play-fill me-1"></i>

                                Run Migration

                            </button>

                        </form>


                    <?php else: ?>


                        <div
                            class="text-center
                                   text-muted
                                   py-4"
                        >

                            <i
                                class="bi
                                       bi-check-circle
                                       fs-1
                                       d-block
                                       mb-2"
                            ></i>

                            No valid migration move is available.

                            <div class="small mt-1">
                                You can still complete this Migration round
                                from the Merit Round Process page so the next
                                Merit Call can be unlocked.
                            </div>

                        </div>


                    <?php endif; ?>


                <?php endif; ?>


            </div>

        </div>


        <!-- ============================================================
             SEAT STATUS + HISTORY
        ============================================================= -->

        <div class="row g-4">


            <div class="col-lg-5">

                <div class="card shadow-sm">


                    <div class="card-header">

                        <h5 class="mb-0">

                            <i class="bi bi-building me-2"></i>

                            Department Seat Status

                        </h5>

                    </div>


                    <?php if (!$processClosed): ?>

                        <div
                            class="card-body
                                   text-muted"
                        >

                            Official Migration vacancies will be shown
                            after the current Merit Process closes.

                        </div>


                    <?php else: ?>


                        <div
                            class="table-responsive"
                            style="max-height:520px;"
                        >

                            <table
                                class="table
                                       table-sm
                                       table-hover
                                       align-middle
                                       mb-0"
                            >


                                <thead
                                    class="table-light
                                           sticky-top"
                                >

                                    <tr>

                                        <th>Department</th>

                                        <th>Seats</th>

                                        <th>Occupied</th>

                                        <th>Vacant</th>

                                    </tr>

                                </thead>


                                <tbody>


                                <?php foreach (
                                    $departmentMap
                                    as $department
                                ): ?>

                                    <tr>


                                        <td>

                                            <?= registrarMigrationE(
                                                $department[
                                                    'dept_name'
                                                ]
                                            ) ?>

                                        </td>


                                        <td>

                                            <?= (int)
                                                $department[
                                                    'total_seats'
                                                ]
                                            ?>

                                        </td>


                                        <td>

                                            <?= (int)
                                                $department[
                                                    'occupied_seats'
                                                ]
                                            ?>

                                        </td>


                                        <td>

                                            <span
                                                class="badge
                                                       bg-<?=
                                                    (int)
                                                    $department[
                                                        'vacant_seats'
                                                    ]
                                                    > 0
                                                        ? 'success'
                                                        : 'secondary'
                                                ?>"
                                            >

                                                <?= (int)
                                                    $department[
                                                        'vacant_seats'
                                                    ]
                                                ?>

                                            </span>

                                        </td>


                                    </tr>

                                <?php endforeach; ?>


                                </tbody>


                            </table>

                        </div>


                    <?php endif; ?>


                </div>

            </div>


            <div class="col-lg-7">

                <div class="card shadow-sm">


                    <div class="card-header">

                        <h5 class="mb-0">

                            <i
                                class="bi
                                       bi-clock-history
                                       me-2"
                            ></i>

                            Migration History

                        </h5>

                    </div>


                    <div class="table-responsive">

                        <table
                            class="table
                                   table-hover
                                   align-middle
                                   mb-0"
                        >


                            <thead class="table-light">

                                <tr>

                                    <th>Candidate</th>

                                    <th>From</th>

                                    <th>To</th>

                                    <th>Preference</th>

                                    <th>Date</th>

                                </tr>

                            </thead>


                            <tbody>


                            <?php if ($history): ?>


                                <?php foreach (
                                    $history as $row
                                ): ?>

                                    <tr>


                                        <td>

                                            <strong>
                                                <?= registrarMigrationE(
                                                    $row[
                                                        'full_name'
                                                    ]
                                                ) ?>
                                            </strong>


                                            <div
                                                class="small
                                                       text-muted"
                                            >

                                                <?= registrarMigrationE(
                                                    $row[
                                                        'gst_roll'
                                                    ]
                                                ) ?>

                                            </div>

                                        </td>


                                        <td>

                                            <?= registrarMigrationE(
                                                $row[
                                                    'from_department'
                                                ]
                                            ) ?>

                                        </td>


                                        <td>

                                            <strong
                                                class="text-success"
                                            >

                                                <?= registrarMigrationE(
                                                    $row[
                                                        'to_department'
                                                    ]
                                                ) ?>

                                            </strong>

                                        </td>


                                        <td>

                                            #<?= (int)
                                                $row[
                                                    'from_preference_order'
                                                ]
                                            ?>

                                            →

                                            #<?= (int)
                                                $row[
                                                    'to_preference_order'
                                                ]
                                            ?>

                                        </td>


                                        <td>

                                            <?= registrarMigrationE(
                                                date(
                                                    'd M Y, h:i A',
                                                    strtotime(
                                                        (string)
                                                        $row[
                                                            'migrated_at'
                                                        ]
                                                    )
                                                )
                                            ) ?>

                                        </td>


                                    </tr>

                                <?php endforeach; ?>


                            <?php else: ?>


                                <tr>

                                    <td
                                        colspan="5"
                                        class="text-center
                                               text-muted
                                               py-4"
                                    >

                                        No Migration has been processed yet.

                                    </td>

                                </tr>


                            <?php endif; ?>


                            </tbody>


                        </table>

                    </div>


                </div>

            </div>


        </div>


    <?php endif; ?>


</div>


<?php require_once '../includes/footer.php'; ?>
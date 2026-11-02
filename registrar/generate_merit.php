<?php

declare(strict_types=1);

$pageTitle = 'Generate Merit and Waiting Lists';

require_once '../includes/auth.php';
require_once '../config/db.php';
require_once '../includes/admission_engine.php';
require_once '../includes/admission_session.php';
require_once '../includes/session_merit_engine.php';
require_once '../includes/sms/merit_call_notifications.php';

requireLogin('registrar');

date_default_timezone_set(
    'Asia/Dhaka'
);

$pdo->setAttribute(
    PDO::ATTR_ERRMODE,
    PDO::ERRMODE_EXCEPTION
);


$currentAcademicYear =
    admissionSessionCurrentYear(
        $pdo
    );

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] =
        bin2hex(
            random_bytes(32)
        );
}


function meritGenerateE(mixed $value): string
{
    return htmlspecialchars(
        (string) ($value ?? ''),
        ENT_QUOTES,
        'UTF-8'
    );
}


function meritCallOrdinal(int $number): string
{
    $mod100 = $number % 100;

    if ($mod100 >= 11 && $mod100 <= 13) {
        return $number . 'th';
    }

    return $number . match ($number % 10) {
        1 => 'st',
        2 => 'nd',
        3 => 'rd',
        default => 'th',
    };
}


function parseLocalDateTime(
    string $value
): DateTimeImmutable {

    $date = DateTimeImmutable::createFromFormat(
        'Y-m-d\TH:i',
        $value,
        new DateTimeZone(
            'Asia/Dhaka'
        )
    );

    if (!$date) {
        throw new RuntimeException(
            'Enter a valid registration date and time.'
        );
    }

    return $date;
}


$message = '';
$messageType = 'info';
$result = null;
$notificationResult = null;

$startInput =
    trim(
        (string) (
            $_POST['registration_start']
            ?? ''
        )
    );

$deadlineInput =
    trim(
        (string) (
            $_POST['registration_deadline']
            ?? ''
        )
    );


/*
|--------------------------------------------------------------------------
| DEVELOPMENT RESET
|--------------------------------------------------------------------------
|
| This is intentionally separate from merit generation so it remains
| available even after the 1st Merit Call has already been published.
|
| Reset:
|   - merit_calls
|   - merit_allocations
|   - waiting_list
|
| Keep:
|   - gst_imported_candidates
|   - gst_candidate_choices
|   - gst_applicants
|   - viva_results
|   - department / faculty configuration
|--------------------------------------------------------------------------
*/

if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    &&
    isset($_POST['development_reset'])
) {

    try {

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


        $confirmation =
            strtoupper(
                trim(
                    (string) (
                        $_POST['reset_confirmation']
                        ?? ''
                    )
                )
            );


        if ($confirmation !== 'RESET') {
            throw new RuntimeException(
                'Type RESET exactly to confirm the development reset.'
            );
        }


        $pdo->beginTransaction();

        try {

            $stmtReset =
                $pdo->prepare("
                    DELETE wl
                    FROM waiting_list wl

                    INNER JOIN gst_imported_candidates gic
                        ON gic.import_candidate_id =
                           wl.import_candidate_id

                    WHERE gic.academic_year = ?
                ");

            $stmtReset->execute([
                $currentAcademicYear
            ]);

            $waitingDeleted =
                $stmtReset->rowCount();


            $stmtReset =
                $pdo->prepare("
                    DELETE ma
                    FROM merit_allocations ma

                    INNER JOIN gst_imported_candidates gic
                        ON gic.import_candidate_id =
                           ma.import_candidate_id

                    WHERE gic.academic_year = ?
                ");

            $stmtReset->execute([
                $currentAcademicYear
            ]);

            $allocationsDeleted =
                $stmtReset->rowCount();


            $stmtReset =
                $pdo->prepare("
                    DELETE FROM merit_calls
                    WHERE academic_year = ?
                ");

            $stmtReset->execute([
                $currentAcademicYear
            ]);

            $callsDeleted =
                $stmtReset->rowCount();


            $pdo->commit();


        } catch (Throwable $exception) {

            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            throw $exception;
        }


        $messageType =
            'warning';

        $message =
            'Development reset completed. '
            . (int) $allocationsDeleted
            . ' merit allocation(s), '
            . (int) $waitingDeleted
            . ' waiting-list record(s), and '
            . (int) $callsDeleted
            . ' Merit Call record(s) were removed for session '
            . $currentAcademicYear
            . '. Previous-year records were preserved. '
            . 'Imported GST candidates and department choices were preserved. '
            . 'You can now generate and publish the 1st Merit Call again.';


        /*
        | Clear previously posted registration dates after reset.
        */
        $startInput = '';
        $deadlineInput = '';


    } catch (Throwable $exception) {

        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        $messageType =
            'danger';

        $message =
            $exception->getMessage();
    }
}



/*
|--------------------------------------------------------------------------
| Existing merit-call information — CURRENT SESSION ONLY
|--------------------------------------------------------------------------
*/

$stmtSession =
    $pdo->prepare("
        SELECT COUNT(*)
        FROM merit_calls
        WHERE academic_year = ?
    ");

$stmtSession->execute([
    $currentAcademicYear
]);

$existingCallCount =
    (int)
    $stmtSession->fetchColumn();


$stmtSession =
    $pdo->prepare("
        SELECT
            merit_call_id,
            academic_year,
            call_number,
            call_title,
            registration_start,
            registration_deadline,
            published_at,
            processed_at

        FROM merit_calls

        WHERE academic_year = ?

        ORDER BY call_number DESC

        LIMIT 1
    ");

$stmtSession->execute([
    $currentAcademicYear
]);

$latestCall =
    $stmtSession->fetch(
        PDO::FETCH_ASSOC
    );


$stmtSession =
    $pdo->prepare("
        SELECT COUNT(*)

        FROM merit_allocations ma

        INNER JOIN gst_imported_candidates gic
            ON gic.import_candidate_id =
               ma.import_candidate_id

        WHERE gic.academic_year = ?
    ");

$stmtSession->execute([
    $currentAcademicYear
]);

$generatedMeritCount =
    (int)
    $stmtSession->fetchColumn();


$stmtSession =
    $pdo->prepare("
        SELECT COUNT(*)

        FROM waiting_list wl

        INNER JOIN gst_imported_candidates gic
            ON gic.import_candidate_id =
               wl.import_candidate_id

        WHERE gic.academic_year = ?
    ");

$stmtSession->execute([
    $currentAcademicYear
]);

$generatedWaitingCount =
    (int)
    $stmtSession->fetchColumn();


/*
|--------------------------------------------------------------------------
| Generate initial merit + publish 1st merit call
|--------------------------------------------------------------------------
*/

if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    &&
    isset(
        $_POST['generate_merit']
    )
) {

    try {

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
                $_SESSION[
                    'csrf_token'
                ],
                $token
            )
        ) {
            throw new RuntimeException(
                'Invalid request token. Refresh the page and try again.'
            );
        }


        $forceReset = false;

        if (
            $startInput === ''
            ||
            $deadlineInput === ''
        ) {
            throw new RuntimeException(
                'Registration start and deadline are required.'
            );
        }


        $registrationStart =
            parseLocalDateTime(
                $startInput
            );

        $registrationDeadline =
            parseLocalDateTime(
                $deadlineInput
            );


        if (
            $registrationDeadline
            <=
            $registrationStart
        ) {
            throw new RuntimeException(
                'Registration deadline must be after the registration start time.'
            );
        }


        if (
            $existingCallCount > 0
        ) {
            throw new RuntimeException(
                'The initial Merit Call has already been published. Use Merit Call Management for later calls.'
            );
        }


        /*
        |--------------------------------------------------------------------------
        | 1. Generate merit and waiting lists
        |--------------------------------------------------------------------------
        */

        $result =
            generateMeritAndWaitingForSession(
                $pdo,
                $currentAcademicYear,
                $forceReset
            );


        /*
        |--------------------------------------------------------------------------
        | 2. Publish 1st Merit Call and attach fresh allocations
        |--------------------------------------------------------------------------
        */

        $pdo->beginTransaction();

        try {

            $callNumber = 1;

            $callTitle =
                meritCallOrdinal(
                    $callNumber
                )
                . ' Merit Call';


            $createdBy =
                isset(
                    $_SESSION[
                        'user_id'
                    ]
                )
                    ? (int)
                      $_SESSION[
                          'user_id'
                      ]
                    : (
                        isset(
                            $_SESSION[
                                'admin_id'
                            ]
                        )
                            ? (int)
                              $_SESSION[
                                  'admin_id'
                              ]
                            : null
                      );


            $callStmt =
                $pdo->prepare("
                    INSERT INTO merit_calls (
                        academic_year,
                        call_number,
                        call_title,
                        registration_start,
                        registration_deadline,
                        published_at,
                        created_by
                    )
                    VALUES (
                        ?,
                        ?,
                        ?,
                        ?,
                        ?,
                        NOW(),
                        ?
                    )
                ");

            $callStmt->execute([
                $currentAcademicYear,
                $callNumber,
                $callTitle,
                $registrationStart
                    ->format(
                        'Y-m-d H:i:s'
                    ),
                $registrationDeadline
                    ->format(
                        'Y-m-d H:i:s'
                    ),
                $createdBy
            ]);


            $meritCallId =
                (int)
                $pdo->lastInsertId();


            /*
            | Every fresh active allocation from initial merit generation
            | belongs to the 1st Merit Call.
            */

            $assignStmt =
                $pdo->prepare("
                    UPDATE merit_allocations ma

                    INNER JOIN gst_imported_candidates gic
                        ON gic.import_candidate_id =
                           ma.import_candidate_id

                    SET ma.merit_call_id = ?

                    WHERE ma.allocation_status =
                          'Allocated'

                      AND ma.merit_call_id IS NULL

                      AND gic.academic_year = ?
                ");

            $assignStmt->execute([
                $meritCallId,
                $currentAcademicYear
            ]);

            $assignedCount =
                $assignStmt->rowCount();


            $pdo->commit();


        } catch (Throwable $exception) {

            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            throw new RuntimeException(
                'Merit was generated, but the 1st Merit Call could not be published: '
                . $exception->getMessage()
            );
        }


        /*
        |--------------------------------------------------------------------------
        | 3. Notify candidates AFTER the 1st Merit Call is published
        |--------------------------------------------------------------------------
        |
        | Notification includes:
        | - 1st Merit Call
        | - allocated department
        | - department merit rank
        | - registration start
        | - registration deadline
        |--------------------------------------------------------------------------
        */

        $notificationResult =
            sendMeritCallNotifications(
                $pdo,
                $meritCallId
            );


        $messageType =
            'success';


        $message =
            sprintf(
                '%s published successfully. '
                . '%d candidates allocated, '
                . '%d candidates placed on waiting lists. '
                . '%d allocations attached to this call. '
                . 'Registration deadline: %s. '
                . 'Notifications — SMS: %d sent, %d failed, %d skipped; '
                . 'Email: %d sent, %d failed, %d skipped.',
                $callTitle,
                (int)
                $result[
                    'allocated_count'
                ],
                (int)
                $result[
                    'waiting_count'
                ],
                (int)
                $assignedCount,
                $registrationDeadline
                    ->format(
                        'd M Y, h:i A'
                    ),
                (int)
                (
                    $notificationResult[
                        'sms_sent'
                    ]
                    ?? 0
                ),
                (int)
                (
                    $notificationResult[
                        'sms_failed'
                    ]
                    ?? 0
                ),
                (int)
                (
                    $notificationResult[
                        'sms_skipped'
                    ]
                    ?? 0
                ),
                (int)
                (
                    $notificationResult[
                        'email_sent'
                    ]
                    ?? 0
                ),
                (int)
                (
                    $notificationResult[
                        'email_failed'
                    ]
                    ?? 0
                ),
                (int)
                (
                    $notificationResult[
                        'email_skipped'
                    ]
                    ?? 0
                )
            );


        $existingCallCount = 1;

        $latestCall = [
            'merit_call_id' =>
                $meritCallId,
            'academic_year' =>
                $currentAcademicYear,
            'call_number' =>
                1,
            'call_title' =>
                $callTitle,
            'registration_start' =>
                $registrationStart
                    ->format(
                        'Y-m-d H:i:s'
                    ),
            'registration_deadline' =>
                $registrationDeadline
                    ->format(
                        'Y-m-d H:i:s'
                    ),
            'published_at' =>
                date(
                    'Y-m-d H:i:s'
                ),
            'processed_at' =>
                null
        ];


    } catch (Throwable $exception) {

        $messageType =
            'danger';

        $message =
            $exception
                ->getMessage();
    }
}


/*
|--------------------------------------------------------------------------
| Department statistics — CURRENT SESSION ONLY
|--------------------------------------------------------------------------
*/

$stmtDepartmentStats =
    $pdo->prepare("
        SELECT
            d.dept_id,
            d.dept_name,
            d.total_seats,

            (
                SELECT COUNT(
                    DISTINCT
                    ma.import_candidate_id
                )

                FROM merit_allocations ma

                INNER JOIN gst_imported_candidates gic
                    ON gic.import_candidate_id =
                       ma.import_candidate_id

                WHERE ma.dept_id =
                      d.dept_id

                  AND gic.academic_year = ?

                  AND ma.allocation_status
                      IN (
                          'Allocated',
                          'Admitted'
                      )
            ) AS active_allocations,

            (
                SELECT COUNT(
                    DISTINCT
                    ma.import_candidate_id
                )

                FROM merit_allocations ma

                INNER JOIN gst_imported_candidates gic
                    ON gic.import_candidate_id =
                       ma.import_candidate_id

                WHERE ma.dept_id =
                      d.dept_id

                  AND gic.academic_year = ?

                  AND ma.allocation_status =
                      'Admitted'
            ) AS admitted_count,

            (
                SELECT COUNT(
                    DISTINCT
                    wl.import_candidate_id
                )

                FROM waiting_list wl

                INNER JOIN gst_imported_candidates gic
                    ON gic.import_candidate_id =
                       wl.import_candidate_id

                INNER JOIN gst_candidate_choices gcc
                    ON gcc.import_candidate_id =
                       wl.import_candidate_id
                   AND gcc.dept_id =
                       wl.dept_id

                WHERE wl.dept_id =
                      d.dept_id

                  AND gic.academic_year = ?

                  AND wl.waiting_status =
                      'Waiting'

                  AND gcc.preference_order = (
                      SELECT MIN(
                          gcc2.preference_order
                      )

                      FROM gst_candidate_choices gcc2

                      WHERE gcc2.import_candidate_id =
                            wl.import_candidate_id
                  )
            ) AS waiting_count,

            (
                SELECT COUNT(
                    DISTINCT
                    wl.import_candidate_id
                )

                FROM waiting_list wl

                INNER JOIN gst_imported_candidates gic
                    ON gic.import_candidate_id =
                       wl.import_candidate_id

                WHERE wl.dept_id =
                      d.dept_id

                  AND gic.academic_year = ?

                  AND wl.waiting_status =
                      'Called'
            ) AS called_count

        FROM departments d

        ORDER BY
            d.faculty_id,
            d.dept_name
    ");

$stmtDepartmentStats->execute([
    $currentAcademicYear,
    $currentAcademicYear,
    $currentAcademicYear,
    $currentAcademicYear
]);

$departmentStats =
    $stmtDepartmentStats->fetchAll(
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
               mb-4"
    >

        <div>

            <h3 class="fw-bold mb-1">
                Generate Merit and Waiting Lists
            </h3>

            <span class="badge bg-primary mb-2">
                Admission Session:
                <?= meritGenerateE(
                    $currentAcademicYear
                ) ?>
            </span>

            <p class="text-muted mb-0">

                Initial merit generation publishes the
                <strong>1st Merit Call</strong>
                and its public registration deadline.

            </p>

        </div>


        <div class="d-flex gap-2">

            <a
                href="merit_calls.php"
                class="btn btn-outline-primary"
            >

                <i
                    class="bi bi-megaphone me-1"
                ></i>

                Merit Calls

            </a>

            <a
                href="dashboard.php"
                class="btn btn-outline-secondary"
            >
                Back
            </a>

        </div>

    </div>


    <?php if ($message !== ''): ?>

        <div
            class="alert
                   alert-<?= meritGenerateE(
                       $messageType
                   ) ?>"
        >

            <?= meritGenerateE(
                $message
            ) ?>

        </div>

    <?php endif; ?>


    <?php if ($latestCall): ?>

        <?php

        $now =
            new DateTimeImmutable(
                'now',
                new DateTimeZone(
                    'Asia/Dhaka'
                )
            );

        $callStart =
            new DateTimeImmutable(
                $latestCall[
                    'registration_start'
                ],
                new DateTimeZone(
                    'Asia/Dhaka'
                )
            );

        $callDeadline =
            new DateTimeImmutable(
                $latestCall[
                    'registration_deadline'
                ],
                new DateTimeZone(
                    'Asia/Dhaka'
                )
            );

        if ($now < $callStart) {
            $currentStatus =
                'Scheduled';
            $statusClass =
                'bg-info text-dark';
        } elseif (
            $now <= $callDeadline
        ) {
            $currentStatus =
                'Registration Open';
            $statusClass =
                'bg-success';
        } else {
            $currentStatus =
                'Registration Closed';
            $statusClass =
                'bg-secondary';
        }

        ?>

        <div class="card shadow-sm mb-4">

            <div
                class="card-header
                       bg-dark
                       text-white"
            >

                <i
                    class="bi bi-calendar-event
                           text-warning
                           me-1"
                ></i>

                Current Merit Call

            </div>

            <div class="card-body">

                <div
                    class="d-flex
                           justify-content-between
                           flex-wrap
                           gap-3"
                >

                    <div>

                        <h5 class="fw-bold mb-1">

                            <?= meritGenerateE(
                                $latestCall[
                                    'call_title'
                                ]
                            ) ?>

                        </h5>

                        <div class="text-muted small">

                            Registration:
                            <?= meritGenerateE(
                                $callStart
                                    ->format(
                                        'd M Y, h:i A'
                                    )
                            ) ?>

                            —

                            <?= meritGenerateE(
                                $callDeadline
                                    ->format(
                                        'd M Y, h:i A'
                                    )
                            ) ?>

                        </div>

                    </div>


                    <div>

                        <span
                            class="badge
                                   <?= $statusClass ?>
                                   fs-6"
                        >

                            <?= meritGenerateE(
                                $currentStatus
                            ) ?>

                        </span>

                    </div>

                </div>

            </div>

        </div>

    <?php endif; ?>


    <div class="card shadow-sm mb-4">

        <div class="card-body">

            <?php if (
                $existingCallCount > 0
            ): ?>

                <div class="alert alert-warning mb-0">

                    <strong>
                        Initial merit has already been published.
                    </strong>

                    <div class="small mt-1">

                        Use
                        <a
                            href="merit_calls.php"
                            class="alert-link"
                        >
                            Merit Call Management
                        </a>
                        to publish the 2nd, 3rd and later
                        calls after vacancies are processed.

                    </div>

                </div>


            <?php else: ?>

                <form
                    method="post"
                    onsubmit="
                        return confirmMeritGeneration(
                            this
                        );
                    "
                >

                    <input
                        type="hidden"
                        name="csrf_token"
                        value="<?= meritGenerateE(
                            $_SESSION[
                                'csrf_token'
                            ]
                        ) ?>"
                    >


                    <div class="alert alert-info">

                        <strong>
                            Allocation Rule:
                        </strong>

                        Candidates are processed globally
                        in GST merit order.

                        For every candidate, the system
                        checks:

                        <strong>
                            Choice 1 → Choice 2 → Choice 3 → ...
                        </strong>

                        until an available department seat
                        is found.

                    </div>


                    <div class="row g-3 mb-3">

                        <div class="col-md-6">

                            <label
                                class="form-label
                                       fw-semibold"
                            >
                                1st Merit Call Registration Starts
                            </label>

                            <input
                                type="datetime-local"
                                name="registration_start"
                                class="form-control"
                                value="<?= meritGenerateE(
                                    $startInput
                                ) ?>"
                                required
                            >

                        </div>


                        <div class="col-md-6">

                            <label
                                class="form-label
                                       fw-semibold"
                            >
                                Registration Deadline
                            </label>

                            <input
                                type="datetime-local"
                                name="registration_deadline"
                                class="form-control"
                                value="<?= meritGenerateE(
                                    $deadlineInput
                                ) ?>"
                                required
                            >

                        </div>

                    </div>


                    <div
                        class="alert
                               alert-light
                               border"
                    >

                        <i
                            class="bi bi-globe2
                                   text-primary
                                   me-1"
                        ></i>

                        This deadline will be shown publicly
                        on the homepage with a live countdown.

                    </div>

                    <button
                        type="submit"
                        name="generate_merit"
                        value="1"
                        class="btn btn-primary"
                    >

                        <i
                            class="bi bi-list-ol
                                   me-1"
                        ></i>

                        Generate & Publish 1st Merit Call

                    </button>

                </form>

            <?php endif; ?>

        </div>

    </div>


    <!-- =========================================================
         DEVELOPMENT RESET
    ========================================================== -->

    <div class="card shadow-sm border-danger mb-4">

        <div
            class="card-header
                   bg-danger
                   text-white
                   d-flex
                   justify-content-between
                   align-items-center
                   flex-wrap gap-2"
        >

            <span>

                <i
                    class="bi bi-arrow-counterclockwise
                           me-1"
                ></i>

                Development Reset

            </span>


            <span class="badge bg-light text-danger">

                Testing Only

            </span>

        </div>


        <div class="card-body">

            <div class="alert alert-warning">

                <strong>
                    Use only during development/testing.
                </strong>

                <div class="small mt-1">

                    This removes all generated
                    <strong>Merit Calls</strong>,
                    <strong>merit allocations</strong>,
                    and <strong>waiting-list records</strong>.

                    Imported GST candidates, their department choices,
                    registered accounts, and viva-result records are not deleted.

                </div>

            </div>


            <div class="row g-3 mb-3">

                <div class="col-md-4">

                    <div class="border rounded p-3 text-center">

                        <div class="h4 fw-bold text-primary mb-1">

                            <?= $existingCallCount ?>

                        </div>

                        <small class="text-muted">
                            Merit Calls
                        </small>

                    </div>

                </div>


                <div class="col-md-4">

                    <div class="border rounded p-3 text-center">

                        <div class="h4 fw-bold text-success mb-1">

                            <?= $generatedMeritCount ?>

                        </div>

                        <small class="text-muted">
                            Merit Allocations
                        </small>

                    </div>

                </div>


                <div class="col-md-4">

                    <div class="border rounded p-3 text-center">

                        <div class="h4 fw-bold text-warning mb-1">

                            <?= $generatedWaitingCount ?>

                        </div>

                        <small class="text-muted">
                            Waiting Records
                        </small>

                    </div>

                </div>

            </div>


            <form
                method="POST"
                onsubmit="
                    return confirmDevelopmentReset();
                "
            >

                <input
                    type="hidden"
                    name="csrf_token"
                    value="<?= meritGenerateE(
                        $_SESSION[
                            'csrf_token'
                        ]
                    ) ?>"
                >

                <input
                    type="hidden"
                    name="development_reset"
                    value="1"
                >


                <div class="row g-2 align-items-end">

                    <div class="col-md-8">

                        <label class="form-label fw-semibold">

                            Type
                            <code>RESET</code>
                            to confirm

                        </label>

                        <input
                            type="text"
                            name="reset_confirmation"
                            id="resetConfirmation"
                            class="form-control"
                            placeholder="RESET"
                            autocomplete="off"
                            required
                        >

                    </div>


                    <div class="col-md-4">

                        <button
                            type="submit"
                            class="btn btn-danger w-100 fw-bold"
                            <?= (
                                $existingCallCount <= 0
                                &&
                                $generatedMeritCount <= 0
                                &&
                                $generatedWaitingCount <= 0
                            )
                                ? 'disabled'
                                : ''
                            ?>
                        >

                            <i
                                class="bi bi-trash3-fill
                                       me-1"
                            ></i>

                            Reset Merit Development Data

                        </button>

                    </div>

                </div>

            </form>

        </div>

    </div>


    <div class="card shadow-sm">

        <div
            class="card-header
                   bg-dark
                   text-white"
        >

            <i
                class="bi bi-building
                       me-1"
            ></i>

            Department Seat Status

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
                        <th>Department</th>
                        <th class="text-center">Seats</th>
                        <th class="text-center">
                            Provisional / Admitted
                        </th>
                        <th class="text-center">
                            Finally Admitted
                        </th>
                        <th class="text-center">Waiting</th>
                        <th class="text-center">Called</th>
                    </tr>

                </thead>

                <tbody>

                <?php foreach (
                    $departmentStats as
                    $department
                ): ?>

                    <tr>

                        <td>
                            <?= meritGenerateE(
                                $department[
                                    'dept_name'
                                ]
                            ) ?>
                        </td>

                        <td class="text-center">
                            <?= (int)
                                $department[
                                    'total_seats'
                                ]
                            ?>
                        </td>

                        <td class="text-center">
                            <?= (int)
                                $department[
                                    'active_allocations'
                                ]
                            ?>
                        </td>

                        <td class="text-center">
                            <?= (int)
                                $department[
                                    'admitted_count'
                                ]
                            ?>
                        </td>

                        <td class="text-center">
                            <?= (int)
                                $department[
                                    'waiting_count'
                                ]
                            ?>
                        </td>

                        <td class="text-center">
                            <?= (int)
                                $department[
                                    'called_count'
                                ]
                            ?>
                        </td>

                    </tr>

                <?php endforeach; ?>

                </tbody>

            </table>

        </div>

    </div>

</div>


<script>
function confirmMeritGeneration(form) {

    const start =
        form.querySelector(
            '[name="registration_start"]'
        ).value;

    const deadline =
        form.querySelector(
            '[name="registration_deadline"]'
        ).value;

    if (!start || !deadline) {
        return false;
    }

    const message =
        'Generate the merit and waiting lists and publish the 1st Merit Call?\n\n'
        + 'Registration starts: '
        + start
        + '\nDeadline: '
        + deadline;

    return confirm(message);
}


function confirmDevelopmentReset() {

    const confirmation =
        document.getElementById(
            'resetConfirmation'
        ).value.trim();

    if (confirmation !== 'RESET') {

        alert(
            'Type RESET exactly to continue.'
        );

        return false;
    }


    return confirm(
        'DEVELOPMENT RESET\n\n'
        + 'This will permanently remove:\n'
        + '• All Merit Call records and deadlines\n'
        + '• All merit allocations\n'
        + '• All waiting-list records\n\n'
        + 'Imported GST candidates and department choices will remain.\n\n'
        + 'Continue?'
    );
}
</script>


<?php require_once '../includes/footer.php'; ?>
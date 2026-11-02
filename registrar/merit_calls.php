<?php

declare(strict_types=1);

$pageTitle = 'Merit Call Management';

require_once '../includes/auth.php';
require_once '../config/db.php';
require_once '../includes/admission_engine.php';
require_once '../includes/admission_session.php';
require_once '../includes/session_vacancy_engine.php';
require_once '../includes/sms/merit_call_notifications.php';
require_once '../includes/sms/viva_call_notifications.php';

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


function meritCallE(mixed $value): string
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


function meritCallParseDateTime(
    string $value
): DateTimeImmutable {

    $date =
        DateTimeImmutable::createFromFormat(
            'Y-m-d\TH:i',
            $value,
            new DateTimeZone(
                'Asia/Dhaka'
            )
        );

    if (!$date) {
        throw new RuntimeException(
            'Enter a valid date and time.'
        );
    }

    return $date;
}


function meritCallStatus(
    array $call
): array {

    $timezone =
        new DateTimeZone(
            'Asia/Dhaka'
        );

    $now =
        new DateTimeImmutable(
            'now',
            $timezone
        );

    $start =
        new DateTimeImmutable(
            $call[
                'registration_start'
            ],
            $timezone
        );

    $deadline =
        new DateTimeImmutable(
            $call[
                'registration_deadline'
            ],
            $timezone
        );


    if (
        !empty(
            $call[
                'processed_at'
            ]
        )
    ) {
        return [
            'label' => 'Processed',
            'class' => 'bg-dark'
        ];
    }


    if ($now < $start) {
        return [
            'label' => 'Scheduled',
            'class' => 'bg-info text-dark'
        ];
    }


    if ($now <= $deadline) {
        return [
            'label' => 'Registration Open',
            'class' => 'bg-success'
        ];
    }


    return [
        'label' => 'Deadline Ended',
        'class' => 'bg-warning text-dark'
    ];
}


function meritVivaBadge(string $status): array
{
    return match ($status) {
        'Open' => [
            'label' => 'Viva Open',
            'class' => 'bg-success'
        ],
        'Closed' => [
            'label' => 'Viva Closed',
            'class' => 'bg-secondary'
        ],
        default => [
            'label' => 'Viva Locked',
            'class' => 'bg-warning text-dark'
        ]
    };
}


$message = '';
$messageType = 'info';


/*
|--------------------------------------------------------------------------
| POST actions
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    try {

        $token =
            (string) (
                $_POST[
                    'csrf_token'
                ]
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


        $action =
            trim(
                (string) (
                    $_POST[
                        'action'
                    ]
                    ?? ''
                )
            );


        /*
        |--------------------------------------------------------------------------
        | Publish next Merit Call
        |--------------------------------------------------------------------------
        */

        if ($action === 'publish_next') {

            $startInput =
                trim(
                    (string) (
                        $_POST[
                            'registration_start'
                        ]
                        ?? ''
                    )
                );

            $deadlineInput =
                trim(
                    (string) (
                        $_POST[
                            'registration_deadline'
                        ]
                        ?? ''
                    )
                );


            $start =
                meritCallParseDateTime(
                    $startInput
                );

            $deadline =
                meritCallParseDateTime(
                    $deadlineInput
                );


            if ($deadline <= $start) {
                throw new RuntimeException(
                    'Registration deadline must be after the start time.'
                );
            }


            /*
            | Only candidates promoted from waiting and not yet assigned
            | to a Merit Call are published in the next call.
            */

            $readyStmt =
                $pdo->prepare("
                    SELECT COUNT(
                        DISTINCT
                        ma.import_candidate_id
                    )

                    FROM merit_allocations ma

                    INNER JOIN gst_imported_candidates gic
                        ON gic.import_candidate_id =
                           ma.import_candidate_id

                    WHERE ma.allocation_status =
                          'Allocated'

                      AND ma.merit_call_id
                          IS NULL

                      AND gic.academic_year = ?
                ");

            $readyStmt->execute([
                $currentAcademicYear
            ]);

            $readyCount =
                (int)
                $readyStmt->fetchColumn();


            if ($readyCount <= 0) {
                throw new RuntimeException(
                    'There are no newly promoted candidates ready for another Merit Call.'
                );
            }


            $latestCallStmt =
                $pdo->prepare("
                    SELECT
                        merit_call_id,
                        academic_year,
                        call_number,
                        processed_at,
                        registration_deadline,
                        viva_status

                    FROM merit_calls

                    WHERE academic_year = ?

                    ORDER BY
                        call_number DESC

                    LIMIT 1
                ");

            $latestCallStmt->execute([
                $currentAcademicYear
            ]);

            $latestCall =
                $latestCallStmt->fetch(
                    PDO::FETCH_ASSOC
                );


            if (!$latestCall) {
                throw new RuntimeException(
                    'Publish the 1st Merit Call from Generate Merit first.'
                );
            }


            if (
                empty(
                    $latestCall[
                        'processed_at'
                    ]
                )
            ) {
                throw new RuntimeException(
                    'Process the previous Merit Call deadline before publishing the next call.'
                );
            }


            if (
                (string) $latestCall['viva_status'] !== 'Closed'
            ) {
                throw new RuntimeException(
                    'Close the previous Merit Call Viva before publishing the next Merit Call.'
                );
            }


            $nextCallNumber =
                (int)
                $latestCall[
                    'call_number'
                ]
                + 1;


            $callTitle =
                meritCallOrdinal(
                    $nextCallNumber
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


            $pdo->beginTransaction();

            try {

                $stmt =
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

                $stmt->execute([
                    $currentAcademicYear,
                    $nextCallNumber,
                    $callTitle,
                    $start->format(
                        'Y-m-d H:i:s'
                    ),
                    $deadline->format(
                        'Y-m-d H:i:s'
                    ),
                    $createdBy
                ]);


                $newCallId =
                    (int)
                    $pdo
                        ->lastInsertId();


                $stmt =
                    $pdo->prepare("
                        UPDATE merit_allocations ma

                        INNER JOIN gst_imported_candidates gic
                            ON gic.import_candidate_id =
                               ma.import_candidate_id

                        SET ma.merit_call_id = ?

                        WHERE ma.allocation_status =
                              'Allocated'

                          AND ma.merit_call_id
                              IS NULL

                          AND gic.academic_year = ?
                    ");

                $stmt->execute([
                    $newCallId,
                    $currentAcademicYear
                ]);


                $assignedCount =
                    $stmt->rowCount();


                $pdo->commit();


            } catch (Throwable $exception) {

                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }

                throw $exception;
            }


            /*
            |--------------------------------------------------------------------------
            | Notify every candidate in this newly published Merit Call
            |--------------------------------------------------------------------------
            */

            $notificationResult =
                sendMeritCallNotifications(
                    $pdo,
                    $newCallId
                );


            $messageType =
                'success';

            $message =
                "{$callTitle} published for {$assignedCount} promoted candidate(s). Registration deadline: "
                . $deadline->format(
                    'd M Y, h:i A'
                )
                . '. Notifications — SMS: '
                . (int) (
                    $notificationResult[
                        'sms_sent'
                    ]
                    ?? 0
                )
                . ' sent, '
                . (int) (
                    $notificationResult[
                        'sms_failed'
                    ]
                    ?? 0
                )
                . ' failed, '
                . (int) (
                    $notificationResult[
                        'sms_skipped'
                    ]
                    ?? 0
                )
                . ' skipped; Email: '
                . (int) (
                    $notificationResult[
                        'email_sent'
                    ]
                    ?? 0
                )
                . ' sent, '
                . (int) (
                    $notificationResult[
                        'email_failed'
                    ]
                    ?? 0
                )
                . ' failed, '
                . (int) (
                    $notificationResult[
                        'email_skipped'
                    ]
                    ?? 0
                )
                . ' skipped.';
        }


        /*
        |--------------------------------------------------------------------------
        | Process a Merit Call after deadline
        |--------------------------------------------------------------------------
        */

        elseif ($action === 'process_deadline') {

            $callId =
                (int) (
                    $_POST[
                        'merit_call_id'
                    ]
                    ?? 0
                );


            if ($callId <= 0) {
                throw new RuntimeException(
                    'Invalid Merit Call.'
                );
            }


            $callStmt =
                $pdo->prepare("
                    SELECT
                        merit_call_id,
                        call_number,
                        call_title,
                        registration_start,
                        registration_deadline,
                        processed_at
                    FROM merit_calls
                    WHERE merit_call_id = ?
                      AND academic_year = ?
                    LIMIT 1
                ");

            $callStmt->execute([
                $callId,
                $currentAcademicYear
            ]);

            $call =
                $callStmt->fetch(
                    PDO::FETCH_ASSOC
                );


            if (!$call) {
                throw new RuntimeException(
                    'Merit Call was not found.'
                );
            }


            if (
                !empty(
                    $call[
                        'processed_at'
                    ]
                )
            ) {
                throw new RuntimeException(
                    'This Merit Call has already been processed.'
                );
            }


            $deadline =
                new DateTimeImmutable(
                    $call[
                        'registration_deadline'
                    ],
                    new DateTimeZone(
                        'Asia/Dhaka'
                    )
                );

            $now =
                new DateTimeImmutable(
                    'now',
                    new DateTimeZone(
                        'Asia/Dhaka'
                    )
                );


            if ($now <= $deadline) {
                throw new RuntimeException(
                    'The registration deadline has not ended yet.'
                );
            }


            /*
            | Candidates still Allocated in this call but never registered
            | are permanently removed.
            */

            $expiredStmt =
                $pdo->prepare("
                    SELECT
                        ma.allocation_id,
                        ma.import_candidate_id,

                        gic.gst_roll,
                        gic.full_name,
                        gic.is_registered,
                        gic.applicant_id

                    FROM merit_allocations ma

                    INNER JOIN
                        gst_imported_candidates gic
                        ON gic.import_candidate_id =
                           ma.import_candidate_id

                    WHERE ma.merit_call_id = ?
                      AND gic.academic_year = ?
                      AND ma.allocation_status =
                          'Allocated'

                      AND (
                          gic.is_registered = 0
                          OR
                          gic.applicant_id IS NULL
                      )

                    ORDER BY
                        ma.dept_id,
                        ma.merit_rank
                ");

            $expiredStmt->execute([
                $callId,
                $currentAcademicYear
            ]);

            $expiredCandidates =
                $expiredStmt->fetchAll(
                    PDO::FETCH_ASSOC
                );


            $removedCount = 0;
            $promotedCount = 0;
            $notificationFailures = 0;


            $reason =
                'Registration was not completed within the deadline for '
                . $call['call_title']
                . ' ('
                . $deadline->format(
                    'd M Y, h:i A'
                )
                . ').';


            foreach (
                $expiredCandidates as
                $candidate
            ) {

                $allocationId =
                    (int)
                    $candidate[
                        'allocation_id'
                    ];


                /*
                | Department is validated inside engine; fetch it from allocation.
                */

                $deptStmt =
                    $pdo->prepare("
                        SELECT dept_id
                        FROM merit_allocations
                        WHERE allocation_id = ?
                        LIMIT 1
                    ");

                $deptStmt->execute([
                    $allocationId
                ]);

                $deptId =
                    (int) (
                        $deptStmt
                            ->fetchColumn()
                        ?: 0
                    );


                if ($deptId <= 0) {
                    continue;
                }


                $vacateResult =
                    vacateAndCallNextCandidateForSession(
                        $pdo,
                        $allocationId,
                        $deptId,
                        $currentAcademicYear,
                        true,
                        $reason,
                        true
                    );


                $removedCount++;


                if (
                    !empty(
                        $vacateResult[
                            'candidate_called'
                        ]
                    )
                ) {
                    $promotedCount++;
                }


                if (
                    isset(
                        $vacateResult[
                            'notification'
                        ]
                    )
                    &&
                    is_array(
                        $vacateResult[
                            'notification'
                        ]
                    )
                ) {

                    $notification =
                        $vacateResult[
                            'notification'
                        ];

                    if (
                        !empty(
                            $notification[
                                'phone_available'
                            ]
                        )
                        &&
                        empty(
                            $notification[
                                'sms_sent'
                            ]
                        )
                    ) {
                        $notificationFailures++;
                    }
                }
            }


            /*
            | Mark processing complete only after all expired allocations
            | were handled.
            */

            $stmt =
                $pdo->prepare("
                    UPDATE merit_calls
                    SET processed_at = NOW()
                    WHERE merit_call_id = ?
                      AND academic_year = ?
                      AND processed_at IS NULL
                ");

            $stmt->execute([
                $callId,
                $currentAcademicYear
            ]);


            $messageType =
                'success';

            $message =
                $call[
                    'call_title'
                ]
                . ' deadline processed. '
                . $removedCount
                . ' unregistered candidate(s) permanently removed; '
                . $promotedCount
                . ' waiting candidate(s) promoted. '
                . 'The Merit Call is now ready for universal Viva.';

            if (
                $notificationFailures > 0
            ) {
                $message .=
                    ' Warning: '
                    . $notificationFailures
                    . ' SMS notification(s) failed.';
            }
        }


        /*
        |--------------------------------------------------------------------------
        | Start Universal Viva
        |--------------------------------------------------------------------------
        */

        elseif ($action === 'start_viva') {

            $callId = (int) (
                $_POST['merit_call_id'] ?? 0
            );

            if ($callId <= 0) {
                throw new RuntimeException(
                    'Invalid Merit Call.'
                );
            }


            $vivaDateInput = trim(
                (string) (
                    $_POST['viva_date'] ?? ''
                )
            );

            $vivaTimeInput = trim(
                (string) (
                    $_POST['viva_reporting_time'] ?? ''
                )
            );

            $vivaInstructions = trim(
                (string) (
                    $_POST['viva_instructions'] ?? ''
                )
            );


            if (
                $vivaDateInput === ''
                ||
                $vivaTimeInput === ''
            ) {
                throw new RuntimeException(
                    'Viva Date and Reporting Start Time are required.'
                );
            }


            if ($vivaInstructions === '') {
                throw new RuntimeException(
                    'Viva Instructions are required.'
                );
            }


            if (mb_strlen($vivaInstructions) > 2000) {
                throw new RuntimeException(
                    'Viva Instructions cannot exceed 2000 characters.'
                );
            }


            $vivaSchedule =
                DateTimeImmutable::createFromFormat(
                    'Y-m-d H:i',
                    $vivaDateInput . ' ' . $vivaTimeInput,
                    new DateTimeZone('Asia/Dhaka')
                );


            if (!$vivaSchedule) {
                throw new RuntimeException(
                    'Enter a valid Viva Date and Reporting Start Time.'
                );
            }


            $now = new DateTimeImmutable(
                'now',
                new DateTimeZone('Asia/Dhaka')
            );


            if ($vivaSchedule < $now) {
                throw new RuntimeException(
                    'The Viva schedule cannot be in the past.'
                );
            }


            $pdo->beginTransaction();

            try {

                $stmt = $pdo->prepare("
                    SELECT
                        merit_call_id,
                        call_title,
                        processed_at,
                        viva_status
                    FROM merit_calls
                    WHERE merit_call_id = ?
                      AND academic_year = ?
                    LIMIT 1
                    FOR UPDATE
                ");

                $stmt->execute([
                    $callId,
                    $currentAcademicYear
                ]);

                $call = $stmt->fetch(
                    PDO::FETCH_ASSOC
                );

                if (!$call) {
                    throw new RuntimeException(
                        'Merit Call was not found.'
                    );
                }

                if (empty($call['processed_at'])) {
                    throw new RuntimeException(
                        'Process the registration deadline before starting Viva.'
                    );
                }

                if ((string) $call['viva_status'] !== 'Locked') {
                    throw new RuntimeException(
                        'This Merit Call Viva is not in Locked status.'
                    );
                }

                $candidateStmt = $pdo->prepare("
                    SELECT COUNT(
                        DISTINCT ma.import_candidate_id
                    )
                    FROM merit_allocations ma
                    INNER JOIN gst_imported_candidates gic
                        ON gic.import_candidate_id = ma.import_candidate_id
                    WHERE ma.merit_call_id = ?
                      AND gic.academic_year = ?
                      AND ma.allocation_status = 'Allocated'
                      AND gic.is_registered = 1
                      AND gic.applicant_id IS NOT NULL
                ");

                $candidateStmt->execute([
                    $callId,
                    $currentAcademicYear
                ]);

                $registeredCount =
                    (int) $candidateStmt->fetchColumn();

                if ($registeredCount <= 0) {
                    throw new RuntimeException(
                        'There are no registered active candidates for Viva in this Merit Call.'
                    );
                }

                $update = $pdo->prepare("
                    UPDATE merit_calls
                    SET
                        viva_status = 'Open',
                        viva_date = ?,
                        viva_reporting_time = ?,
                        viva_instructions = ?,
                        viva_started_at = NOW(),
                        viva_closed_at = NULL
                    WHERE merit_call_id = ?
                      AND academic_year = ?
                      AND processed_at IS NOT NULL
                      AND viva_status = 'Locked'
                ");

                $update->execute([
                    $vivaSchedule->format('Y-m-d'),
                    $vivaSchedule->format('H:i:s'),
                    $vivaInstructions,
                    $callId,
                    $currentAcademicYear
                ]);

                if ($update->rowCount() !== 1) {
                    throw new RuntimeException(
                        'Unable to start Viva. Refresh the page and try again.'
                    );
                }

                $pdo->commit();


                $notificationResult = [
                    'sms_sent' => 0,
                    'sms_failed' => 0,
                    'sms_skipped' => 0,
                    'email_sent' => 0,
                    'email_failed' => 0,
                    'email_skipped' => 0
                ];

                $notificationWarning = '';

                try {

                    $notificationResult =
                        sendVivaCallNotifications(
                            $pdo,
                            $callId
                        );

                } catch (Throwable $notificationException) {

                    $notificationWarning =
                        ' Viva opened successfully, but notification sending had an error: '
                        . $notificationException->getMessage();

                    error_log(
                        'Viva notification error for Merit Call '
                        . $callId
                        . ': '
                        . $notificationException->getMessage()
                    );
                }


                $messageType = 'success';

                $message =
                    $call['call_title']
                    . ' Viva is now OPEN universally for all departments. '
                    . 'Viva Date: '
                    . $vivaSchedule->format('d M Y')
                    . ', Reporting Time: '
                    . $vivaSchedule->format('h:i A')
                    . '. Notifications — SMS: '
                    . (int) (
                        $notificationResult['sms_sent'] ?? 0
                    )
                    . ' sent, '
                    . (int) (
                        $notificationResult['sms_failed'] ?? 0
                    )
                    . ' failed, '
                    . (int) (
                        $notificationResult['sms_skipped'] ?? 0
                    )
                    . ' skipped; Email: '
                    . (int) (
                        $notificationResult['email_sent'] ?? 0
                    )
                    . ' sent, '
                    . (int) (
                        $notificationResult['email_failed'] ?? 0
                    )
                    . ' failed, '
                    . (int) (
                        $notificationResult['email_skipped'] ?? 0
                    )
                    . ' skipped.'
                    . $notificationWarning;

            } catch (Throwable $exception) {

                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }

                throw $exception;
            }
        }


        /*
        |--------------------------------------------------------------------------
        | Close Universal Viva
        |--------------------------------------------------------------------------
        |
        | The Registrar cannot close the call while any registered active
        | candidate still has no Viva result.
        |--------------------------------------------------------------------------
        */

        elseif ($action === 'close_viva') {

            $callId = (int) (
                $_POST['merit_call_id'] ?? 0
            );

            if ($callId <= 0) {
                throw new RuntimeException(
                    'Invalid Merit Call.'
                );
            }

            $pdo->beginTransaction();

            try {

                $stmt = $pdo->prepare("
                    SELECT
                        merit_call_id,
                        call_title,
                        viva_status
                    FROM merit_calls
                    WHERE merit_call_id = ?
                      AND academic_year = ?
                    LIMIT 1
                    FOR UPDATE
                ");

                $stmt->execute([
                    $callId,
                    $currentAcademicYear
                ]);

                $call = $stmt->fetch(
                    PDO::FETCH_ASSOC
                );

                if (!$call) {
                    throw new RuntimeException(
                        'Merit Call was not found.'
                    );
                }

                if ((string) $call['viva_status'] !== 'Open') {
                    throw new RuntimeException(
                        'Only an Open Viva can be closed.'
                    );
                }

                $pendingStmt = $pdo->prepare("
                    SELECT COUNT(
                        DISTINCT ma.import_candidate_id
                    )
                    FROM merit_allocations ma
                    INNER JOIN gst_imported_candidates gic
                        ON gic.import_candidate_id = ma.import_candidate_id
                    LEFT JOIN viva_results vr
                        ON vr.applicant_id = gic.applicant_id
                    WHERE ma.merit_call_id = ?
                      AND gic.academic_year = ?
                      AND ma.allocation_status = 'Allocated'
                      AND gic.is_registered = 1
                      AND gic.applicant_id IS NOT NULL
                      AND vr.applicant_id IS NULL
                ");

                $pendingStmt->execute([
                    $callId,
                    $currentAcademicYear
                ]);

                $pendingViva =
                    (int) $pendingStmt->fetchColumn();

                if ($pendingViva > 0) {
                    throw new RuntimeException(
                        'Viva cannot be closed. '
                        . $pendingViva
                        . ' registered candidate(s) still have Pending Viva.'
                    );
                }

                $update = $pdo->prepare("
                    UPDATE merit_calls
                    SET
                        viva_status = 'Closed',
                        viva_closed_at = NOW()
                    WHERE merit_call_id = ?
                      AND academic_year = ?
                      AND viva_status = 'Open'
                ");

                $update->execute([
                    $callId,
                    $currentAcademicYear
                ]);

                if ($update->rowCount() !== 1) {
                    throw new RuntimeException(
                        'Unable to close Viva. Refresh the page and try again.'
                    );
                }

                $pdo->commit();

                $messageType = 'success';
                $message =
                    $call['call_title']
                    . ' Viva has been CLOSED universally. '
                    . 'The Merit Call Viva is complete. The current Merit Process can now continue toward Close → Migration → Next Merit Call.';

            } catch (Throwable $exception) {

                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }

                throw $exception;
            }
        }


        else {
            throw new RuntimeException(
                'Invalid Merit Call action.'
            );
        }


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
| Current counts
|--------------------------------------------------------------------------
*/

$readyForNextCallStmt =
    $pdo->prepare("
        SELECT COUNT(
            DISTINCT
            ma.import_candidate_id
        )

        FROM merit_allocations ma

        INNER JOIN gst_imported_candidates gic
            ON gic.import_candidate_id =
               ma.import_candidate_id

        WHERE ma.allocation_status =
              'Allocated'

          AND ma.merit_call_id
              IS NULL

          AND gic.academic_year = ?
    ");

$readyForNextCallStmt->execute([
    $currentAcademicYear
]);

$readyForNextCall =
    (int)
    $readyForNextCallStmt
        ->fetchColumn();


$callsStmt =
    $pdo->prepare("
        SELECT
            mc.merit_call_id,
            mc.academic_year,
            mc.call_number,
            mc.call_title,
            mc.registration_start,
            mc.registration_deadline,
            mc.published_at,
            mc.processed_at,
            mc.viva_status,
            mc.viva_date,
            mc.viva_reporting_time,
            mc.viva_instructions,
            mc.viva_started_at,
            mc.viva_closed_at,

            COUNT(
                DISTINCT
                ma.import_candidate_id
            ) AS allocation_count,

            COUNT(
                DISTINCT
                CASE
                    WHEN
                        gic.is_registered = 1
                    THEN
                        ma.import_candidate_id
                END
            ) AS registered_count,

            COUNT(
                DISTINCT
                CASE
                    WHEN
                        ma.allocation_status =
                        'Allocated'
                        AND
                        (
                            gic.is_registered = 0
                            OR
                            gic.applicant_id
                            IS NULL
                        )
                    THEN
                        ma.import_candidate_id
                END
            ) AS unregistered_count

        FROM merit_calls mc

        LEFT JOIN
            merit_allocations ma
            ON ma.merit_call_id =
               mc.merit_call_id

        LEFT JOIN
            gst_imported_candidates gic
            ON gic.import_candidate_id =
               ma.import_candidate_id

        WHERE mc.academic_year = ?

        GROUP BY
            mc.merit_call_id

        ORDER BY
            mc.call_number DESC
    ");

$callsStmt->execute([
    $currentAcademicYear
]);

$calls =
    $callsStmt->fetchAll(
        PDO::FETCH_ASSOC
    );


$nextCallNumber =
    $calls
        ? (
            (int)
            $calls[0][
                'call_number'
            ]
            + 1
          )
        : 1;


$latestCallForPublish =
    $calls[0] ?? null;

$canPublishNextCall =
    $readyForNextCall > 0
    &&
    $latestCallForPublish
    &&
    !empty(
        $latestCallForPublish['processed_at']
    )
    &&
    (string)
    $latestCallForPublish['viva_status']
        === 'Closed';


require_once '../includes/header.php';
require_once '../includes/navbar.php';

?>

<div class="container py-4">


    <div
        class="d-flex
               justify-content-between
               align-items-center
               flex-wrap gap-2
               mb-4"
    >

        <div>

            <h3 class="fw-bold mb-1">

                <i
                    class="bi bi-megaphone-fill
                           text-warning
                           me-1"
                ></i>

                Merit Call Management

            </h3>

            <span class="badge bg-primary mb-2">
                Admission Session:
                <?= meritCallE(
                    $currentAcademicYear
                ) ?>
            </span>

            <div class="text-muted">

                Manage registration deadlines and universal Viva
                for the 1st, 2nd, 3rd and later Merit Calls.

            </div>

        </div>


        <a
            href="generate_merit.php"
            class="btn btn-outline-secondary"
        >

            <i
                class="bi bi-arrow-left
                       me-1"
            ></i>

            Generate Merit

        </a>

    </div>


    <?php if ($message !== ''): ?>

        <div
            class="alert
                   alert-<?= meritCallE(
                       $messageType
                   ) ?>"
        >

            <?= meritCallE(
                $message
            ) ?>

        </div>

    <?php endif; ?>


    <div class="row g-4 mb-4">


        <div class="col-lg-4">

            <div class="card shadow-sm h-100">

                <div
                    class="card-header
                           bg-dark
                           text-white"
                >

                    <i
                        class="bi bi-person-plus-fill
                               text-success
                               me-1"
                    ></i>

                    Ready for Next Call

                </div>


                <div
                    class="card-body
                           text-center
                           py-4"
                >

                    <div
                        class="display-5
                               fw-bold
                               text-primary"
                    >

                        <?= $readyForNextCall ?>

                    </div>

                    <div class="text-muted">
                        promoted candidate(s)
                    </div>

                </div>

            </div>

        </div>


        <div class="col-lg-8">

            <div class="card shadow-sm">

                <div
                    class="card-header
                           bg-dark
                           text-white"
                >

                    <i
                        class="bi bi-calendar-plus
                               text-warning
                               me-1"
                    ></i>

                    Publish
                    <?= meritCallOrdinal(
                        $nextCallNumber
                    ) ?>
                    Merit Call

                </div>


                <div class="card-body">

                    <form method="POST">

                        <input
                            type="hidden"
                            name="csrf_token"
                            value="<?= meritCallE(
                                $_SESSION[
                                    'csrf_token'
                                ]
                            ) ?>"
                        >

                        <input
                            type="hidden"
                            name="action"
                            value="publish_next"
                        >


                        <div class="row g-3">

                            <div class="col-md-5">

                                <label
                                    class="form-label
                                           fw-semibold"
                                >
                                    Registration Starts
                                </label>

                                <input
                                    type="datetime-local"
                                    name="registration_start"
                                    class="form-control"
                                    required
                                >

                            </div>


                            <div class="col-md-5">

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
                                    required
                                >

                            </div>


                            <div
                                class="col-md-2
                                       d-flex
                                       align-items-end"
                            >

                                <button
                                    type="submit"
                                    class="btn
                                           btn-primary
                                           w-100"
                                    <?= !$canPublishNextCall
                                        ? 'disabled'
                                        : ''
                                    ?>
                                >

                                    Publish

                                </button>

                            </div>

                        </div>

                    </form>

                </div>

            </div>

        </div>

    </div>


    <div class="card shadow-sm">

        <div
            class="card-header
                   bg-dark
                   text-white"
        >

            <i
                class="bi bi-clock-history
                       text-info
                       me-1"
            ></i>

            Merit Call History

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
                        <th>Call</th>
                        <th>Registration Window</th>
                        <th>Registration</th>
                        <th>Viva</th>
                        <th class="text-center">
                            Allocated
                        </th>
                        <th class="text-center">
                            Registered
                        </th>
                        <th class="text-center">
                            Not Registered
                        </th>
                        <th class="text-end">
                            Action
                        </th>
                    </tr>

                </thead>


                <tbody>

                <?php if (!$calls): ?>

                    <tr>

                        <td
                            colspan="8"
                            class="text-center
                                   py-5
                                   text-muted"
                        >

                            No Merit Call has been
                            published yet.

                        </td>

                    </tr>


                <?php else: ?>


                    <?php foreach (
                        $calls as $call
                    ): ?>

                        <?php

                        $status =
                            meritCallStatus(
                                $call
                            );

                        $vivaBadge =
                            meritVivaBadge(
                                (string)
                                $call['viva_status']
                            );

                        $deadlineEnded =
                            strtotime(
                                (string)
                                $call[
                                    'registration_deadline'
                                ]
                            )
                            <
                            time();

                        ?>


                        <tr>

                            <td>

                                <strong>
                                    <?= meritCallE(
                                        $call[
                                            'call_title'
                                        ]
                                    ) ?>
                                </strong>

                            </td>


                            <td class="small">

                                <?= date(
                                    'd M Y, h:i A',
                                    strtotime(
                                        (string)
                                        $call[
                                            'registration_start'
                                        ]
                                    )
                                ) ?>

                                <br>

                                <span class="text-muted">
                                    to
                                </span>

                                <?= date(
                                    'd M Y, h:i A',
                                    strtotime(
                                        (string)
                                        $call[
                                            'registration_deadline'
                                        ]
                                    )
                                ) ?>

                            </td>


                            <td>

                                <span
                                    class="badge
                                           <?= $status[
                                               'class'
                                           ] ?>"
                                >

                                    <?= meritCallE(
                                        $status[
                                            'label'
                                        ]
                                    ) ?>

                                </span>

                            </td>


                            <td>

                                <span
                                    class="badge
                                           <?= $vivaBadge['class'] ?>"
                                >
                                    <?= meritCallE(
                                        $vivaBadge['label']
                                    ) ?>
                                </span>

                                <?php if (
                                    !empty(
                                        $call['viva_date']
                                    )
                                    &&
                                    !empty(
                                        $call['viva_reporting_time']
                                    )
                                ): ?>

                                    <div class="small text-muted mt-1">
                                        <?= date(
                                            'd M Y',
                                            strtotime(
                                                (string) $call['viva_date']
                                            )
                                        ) ?>
                                        ·
                                        <?= date(
                                            'h:i A',
                                            strtotime(
                                                (string)
                                                $call['viva_reporting_time']
                                            )
                                        ) ?>
                                    </div>

                                <?php endif; ?>


                                <?php if (
                                    !empty(
                                        $call['viva_started_at']
                                    )
                                ): ?>

                                    <div class="small text-muted mt-1">
                                        Started:
                                        <?= date(
                                            'd M, h:i A',
                                            strtotime(
                                                (string)
                                                $call['viva_started_at']
                                            )
                                        ) ?>
                                    </div>

                                <?php endif; ?>

                                <?php if (
                                    !empty(
                                        $call['viva_closed_at']
                                    )
                                ): ?>

                                    <div class="small text-muted">
                                        Closed:
                                        <?= date(
                                            'd M, h:i A',
                                            strtotime(
                                                (string)
                                                $call['viva_closed_at']
                                            )
                                        ) ?>
                                    </div>

                                <?php endif; ?>

                            </td>


                            <td class="text-center">

                                <?= (int)
                                    $call[
                                        'allocation_count'
                                    ]
                                ?>

                            </td>


                            <td class="text-center">

                                <span
                                    class="text-success
                                           fw-semibold"
                                >

                                    <?= (int)
                                        $call[
                                            'registered_count'
                                        ]
                                    ?>

                                </span>

                            </td>


                            <td class="text-center">

                                <span
                                    class="text-danger
                                           fw-semibold"
                                >

                                    <?= (int)
                                        $call[
                                            'unregistered_count'
                                        ]
                                    ?>

                                </span>

                            </td>


                            <td class="text-end">

                                <?php if (
                                    empty(
                                        $call[
                                            'processed_at'
                                        ]
                                    )
                                    &&
                                    $deadlineEnded
                                ): ?>

                                    <form
                                        method="POST"
                                        class="d-inline"
                                        onsubmit="
                                            return confirm(
                                                'Process this expired Merit Call? Unregistered allocated candidates will be permanently removed. Released seats will be held for Migration after the Merit Process closes.'
                                            );
                                        "
                                    >

                                        <input
                                            type="hidden"
                                            name="csrf_token"
                                            value="<?= meritCallE(
                                                $_SESSION[
                                                    'csrf_token'
                                                ]
                                            ) ?>"
                                        >

                                        <input
                                            type="hidden"
                                            name="action"
                                            value="process_deadline"
                                        >

                                        <input
                                            type="hidden"
                                            name="merit_call_id"
                                            value="<?= (int)
                                                $call[
                                                    'merit_call_id'
                                                ]
                                            ?>"
                                        >

                                        <button
                                            type="submit"
                                            class="btn
                                                   btn-sm
                                                   btn-danger"
                                        >

                                            <i
                                                class="bi bi-hourglass-bottom
                                                       me-1"
                                            ></i>

                                            Process Deadline

                                        </button>

                                    </form>


                                <?php elseif (
                                    empty(
                                        $call['processed_at']
                                    )
                                ): ?>

                                    <span class="text-muted small">
                                        Waiting for deadline
                                    </span>


                                <?php elseif (
                                    (string)
                                    $call['viva_status']
                                    === 'Locked'
                                ): ?>

                                    <button
                                        type="button"
                                        class="btn btn-sm btn-success"
                                        data-bs-toggle="modal"
                                        data-bs-target="#startVivaModal<?= (int)
                                            $call['merit_call_id']
                                        ?>"
                                    >
                                        <i class="bi bi-play-circle-fill me-1"></i>
                                        Start Viva
                                    </button>


                                <?php elseif (
                                    (string)
                                    $call['viva_status']
                                    === 'Open'
                                ): ?>

                                    <form
                                        method="POST"
                                        class="d-inline"
                                        onsubmit="return confirm('Close Viva for this Merit Call? Viva will close universally for ALL departments.');"
                                    >

                                        <input
                                            type="hidden"
                                            name="csrf_token"
                                            value="<?= meritCallE(
                                                $_SESSION['csrf_token']
                                            ) ?>"
                                        >

                                        <input
                                            type="hidden"
                                            name="action"
                                            value="close_viva"
                                        >

                                        <input
                                            type="hidden"
                                            name="merit_call_id"
                                            value="<?= (int)
                                                $call['merit_call_id']
                                            ?>"
                                        >

                                        <button
                                            type="submit"
                                            class="btn btn-sm btn-secondary"
                                        >
                                            <i class="bi bi-stop-circle-fill me-1"></i>
                                            Close Viva
                                        </button>

                                    </form>


                                <?php else: ?>

                                    <span class="badge bg-success">
                                        <i class="bi bi-check-lg"></i>
                                        Viva Completed
                                    </span>

                                <?php endif; ?>

                            </td>

                        </tr>

                    <?php endforeach; ?>


                <?php endif; ?>

                </tbody>

            </table>

        </div>

    </div>

</div>



<!-- START UNIVERSAL VIVA MODALS -->

<?php foreach ($calls as $call): ?>

    <?php if (
        !empty($call['processed_at'])
        &&
        (string) $call['viva_status'] === 'Locked'
    ): ?>

        <div
            class="modal fade"
            id="startVivaModal<?= (int) $call['merit_call_id'] ?>"
            tabindex="-1"
            aria-hidden="true"
        >

            <div class="modal-dialog modal-lg">

                <div class="modal-content">

                    <form
                        method="POST"
                        onsubmit="
                            return confirm(
                                'Start Viva universally for ALL departments and notify every registered candidate in this Merit Call?'
                            );
                        "
                    >

                        <div class="modal-header bg-dark text-white">

                            <h5 class="modal-title">

                                <i
                                    class="bi bi-person-video3
                                           text-warning me-2"
                                ></i>

                                Start
                                <?= meritCallE(
                                    $call['call_title']
                                ) ?>
                                Viva

                            </h5>

                            <button
                                type="button"
                                class="btn-close btn-close-white"
                                data-bs-dismiss="modal"
                            ></button>

                        </div>


                        <div class="modal-body">

                            <input
                                type="hidden"
                                name="csrf_token"
                                value="<?= meritCallE(
                                    $_SESSION['csrf_token']
                                ) ?>"
                            >

                            <input
                                type="hidden"
                                name="action"
                                value="start_viva"
                            >

                            <input
                                type="hidden"
                                name="merit_call_id"
                                value="<?= (int)
                                    $call['merit_call_id']
                                ?>"
                            >


                            <div class="alert alert-info">

                                <strong>Universal Viva:</strong>

                                Starting this Viva opens it for
                                every department in this Merit Call.

                                Every registered active candidate
                                will receive the schedule by
                                <strong>SMS and email</strong>.

                            </div>


                            <div class="row g-3">

                                <div class="col-md-6">

                                    <label class="form-label fw-semibold">
                                        Viva Date
                                    </label>

                                    <input
                                        type="date"
                                        name="viva_date"
                                        class="form-control"
                                        min="<?= date('Y-m-d') ?>"
                                        required
                                    >

                                </div>


                                <div class="col-md-6">

                                    <label class="form-label fw-semibold">
                                        Viva Reporting Start Time
                                    </label>

                                    <input
                                        type="time"
                                        name="viva_reporting_time"
                                        class="form-control"
                                        required
                                    >

                                </div>


                                <div class="col-12">

                                    <label class="form-label fw-semibold">
                                        Instructions
                                    </label>

                                    <textarea
                                        name="viva_instructions"
                                        class="form-control"
                                        rows="5"
                                        maxlength="2000"
                                        placeholder="Example: Bring original academic documents, GST admit card and registration print copy. Report at least 30 minutes before the Viva."
                                        required
                                    ></textarea>

                                    <div class="form-text">
                                        These instructions will be sent
                                        in the candidate Viva notification.
                                    </div>

                                </div>

                            </div>

                        </div>


                        <div class="modal-footer">

                            <button
                                type="button"
                                class="btn btn-outline-secondary"
                                data-bs-dismiss="modal"
                            >
                                Cancel
                            </button>

                            <button
                                type="submit"
                                class="btn btn-success fw-semibold"
                            >
                                <i
                                    class="bi bi-play-circle-fill me-1"
                                ></i>
                                Start Viva & Notify Candidates
                            </button>

                        </div>

                    </form>

                </div>

            </div>

        </div>

    <?php endif; ?>

<?php endforeach; ?>


<?php require_once '../includes/footer.php'; ?>
<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Session-safe Vacancy Engine — Merit Round / Migration Workflow
|--------------------------------------------------------------------------
|
| Intended path:
|   includes/session_vacancy_engine.php
|
| This prevents cross-session vacancy handling and intentionally DOES NOT
| promote a waiting candidate immediately. Vacancies are held until the current
| Merit Process closes; Migration gets first priority, then the next Merit Call.
|--------------------------------------------------------------------------
*/

$mailHelper =
    __DIR__
    . '/mail/send_mail.php';

$smsHelper =
    __DIR__
    . '/sms/send_sms.php';

if (is_file($mailHelper)) {
    require_once $mailHelper;
}

if (is_file($smsHelper)) {
    require_once $smsHelper;
}


function sessionNotifyVacatedCandidate(
    array $candidate,
    string $departmentName,
    string $reason,
    bool $permanentRemoval
): array {

    $fullName =
        trim(
            (string) (
                $candidate['full_name']
                ?? 'Candidate'
            )
        );

    $gstRoll =
        trim(
            (string) (
                $candidate['gst_roll']
                ?? ''
            )
        );

    $email =
        trim(
            (string) (
                $candidate['email']
                ?? ''
            )
        );

    $phone =
        trim(
            (string) (
                $candidate['phone']
                ?? ''
            )
        );

    $reason =
        trim($reason);

    $emailSent = false;
    $smsSent = false;


    if (
        $email !== ''
        &&
        filter_var(
            $email,
            FILTER_VALIDATE_EMAIL
        )
        &&
        function_exists('sendMail')
    ) {

        $safeName =
            htmlspecialchars(
                $fullName,
                ENT_QUOTES,
                'UTF-8'
            );

        $safeRoll =
            htmlspecialchars(
                $gstRoll,
                ENT_QUOTES,
                'UTF-8'
            );

        $safeDepartment =
            htmlspecialchars(
                $departmentName,
                ENT_QUOTES,
                'UTF-8'
            );

        $safeReason =
            nl2br(
                htmlspecialchars(
                    $reason,
                    ENT_QUOTES,
                    'UTF-8'
                )
            );


        $subject =
            $permanentRemoval
                ? 'JKKNIU Admission Allocation Cancelled'
                : 'JKKNIU Viva Absence / Call Later Notice';


        $statusText =
            $permanentRemoval
                ? 'Your provisional admission allocation has been cancelled and you are removed from this admission allocation.'
                : 'You were marked Absent in Viva. Your current allocation has been released and you have been moved to the Call Later group.';


        $body = "
            <div style=\"font-family:Arial,sans-serif;
                        max-width:650px;
                        margin:auto;
                        border:1px solid #dddddd;
                        border-radius:10px;
                        overflow:hidden;\">

                <div style=\"background:#1a3c5e;
                            color:#ffffff;
                            padding:22px;
                            text-align:center;\">

                    <h2 style=\"margin:0;\">
                        JKKNIU Admission Management System
                    </h2>

                </div>

                <div style=\"padding:25px;\">

                    <p>
                        Dear <strong>{$safeName}</strong>,
                    </p>

                    <p>
                        {$statusText}
                    </p>

                    <div style=\"background:#f5f7fa;
                                padding:15px;
                                margin:18px 0;
                                border-radius:6px;\">

                        <p>
                            <strong>GST Roll:</strong>
                            {$safeRoll}
                        </p>

                        <p>
                            <strong>Department:</strong>
                            {$safeDepartment}
                        </p>

                        <p>
                            <strong>Reason:</strong><br>
                            {$safeReason}
                        </p>

                    </div>

                    <p>
                        Regards,<br>
                        <strong>
                            JKKNIU Admission Authority
                        </strong>
                    </p>

                </div>

            </div>
        ";


        try {

            $emailSent =
                (bool)
                sendMail(
                    $email,
                    $subject,
                    $body
                );

        } catch (Throwable $exception) {

            error_log(
                'Session vacancy email error: '
                . $exception->getMessage()
            );
        }
    }


    if (
        $phone !== ''
        &&
        function_exists('sendSMS')
    ) {

        $smsMessage =
            $permanentRemoval
                ? (
                    'JKKNIU Admission: Your allocation in '
                    . $departmentName
                    . ' has been VACATED. GST Roll: '
                    . $gstRoll
                    . '. Reason: '
                    . $reason
                    . '. You are removed from this admission allocation.'
                )
                : (
                    'JKKNIU Admission: You were marked ABSENT in Viva for '
                    . $departmentName
                    . '. GST Roll: '
                    . $gstRoll
                    . '. Your allocation is released and you are moved to CALL LATER. Reason: '
                    . $reason
                );


        try {

            $smsSent =
                (bool)
                sendSMS(
                    $phone,
                    $smsMessage
                );

        } catch (Throwable $exception) {

            error_log(
                'Session vacancy SMS error: '
                . $exception->getMessage()
            );
        }
    }


    return [
        'email_available' =>
            $email !== '',

        'phone_available' =>
            $phone !== '',

        'email_sent' =>
            $emailSent,

        'sms_sent' =>
            $smsSent
    ];
}


/*
|--------------------------------------------------------------------------
| Re-rank ACTIVE merit list for one department + academic year
|--------------------------------------------------------------------------
*/

function sessionRerankActiveMeritList(
    PDO $pdo,
    int $departmentId,
    string $academicYear
): int {

    if (!$pdo->inTransaction()) {
        throw new RuntimeException(
            'Merit re-ranking requires an active transaction.'
        );
    }


    $stmt =
        $pdo->prepare("
            SELECT
                ma.allocation_id

            FROM merit_allocations ma

            INNER JOIN gst_imported_candidates gic
                ON gic.import_candidate_id =
                   ma.import_candidate_id

            WHERE ma.dept_id = ?

              AND gic.academic_year = ?

              AND ma.allocation_status IN (
                  'Allocated',
                  'Admitted'
              )

            ORDER BY
                ma.merit_rank ASC,
                ma.allocation_id ASC

            FOR UPDATE
        ");

    $stmt->execute([
        $departmentId,
        $academicYear
    ]);


    $allocationIds =
        $stmt->fetchAll(
            PDO::FETCH_COLUMN
        );


    $update =
        $pdo->prepare("
            UPDATE merit_allocations
            SET merit_rank = ?
            WHERE allocation_id = ?
              AND dept_id = ?
        ");


    $rank = 1;


    foreach (
        $allocationIds as
        $allocationId
    ) {

        $update->execute([
            $rank,
            (int) $allocationId,
            $departmentId
        ]);

        $rank++;
    }


    return count(
        $allocationIds
    );
}


/*
|--------------------------------------------------------------------------
| Re-rank ACTIVE waiting list for one department + academic year
|--------------------------------------------------------------------------
*/

function sessionRerankActiveWaitingList(
    PDO $pdo,
    int $departmentId,
    string $academicYear
): void {

    if (!$pdo->inTransaction()) {
        throw new RuntimeException(
            'Waiting-list re-ranking requires an active transaction.'
        );
    }


    $stmt =
        $pdo->prepare("
            SELECT
                wl.waiting_id

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

              AND wl.waiting_id = (
                  SELECT MIN(
                      wl2.waiting_id
                  )

                  FROM waiting_list wl2

                  WHERE wl2.import_candidate_id =
                        wl.import_candidate_id

                    AND wl2.dept_id =
                        wl.dept_id
              )

            ORDER BY
                wl.waiting_rank ASC,
                wl.waiting_id ASC

            FOR UPDATE
        ");

    $stmt->execute([
        $departmentId,
        $academicYear
    ]);


    $waitingIds =
        $stmt->fetchAll(
            PDO::FETCH_COLUMN
        );


    $update =
        $pdo->prepare("
            UPDATE waiting_list

            SET waiting_rank = ?

            WHERE waiting_id = ?
              AND dept_id = ?
              AND waiting_status =
                  'Waiting'
        ");


    $rank = 1;


    foreach (
        $waitingIds as
        $waitingId
    ) {

        $update->execute([
            $rank,
            (int) $waitingId,
            $departmentId
        ]);

        $rank++;
    }
}


/*
|--------------------------------------------------------------------------
| Session-safe vacancy + next waiting promotion
|--------------------------------------------------------------------------
*/

function vacateAndCallNextCandidateForSession(
    PDO $pdo,
    int $allocationId,
    int $departmentId,
    string $academicYear,
    bool $manageTransaction = true,
    string $vacateReason = '',
    bool $permanentRemoval = true
): array {

    if (
        $allocationId <= 0
        ||
        $departmentId <= 0
    ) {
        throw new InvalidArgumentException(
            'Invalid allocation or department.'
        );
    }


    $academicYear =
        trim(
            $academicYear
        );

    $vacateReason =
        trim(
            $vacateReason
        );


    if ($academicYear === '') {
        throw new InvalidArgumentException(
            'Academic year is required.'
        );
    }


    if ($vacateReason === '') {
        throw new InvalidArgumentException(
            'A vacancy reason is required.'
        );
    }


    if ($manageTransaction) {

        if ($pdo->inTransaction()) {
            throw new RuntimeException(
                'A database transaction is already active.'
            );
        }

        $pdo->beginTransaction();

    } elseif (!$pdo->inTransaction()) {

        throw new RuntimeException(
            'An active transaction is required.'
        );
    }


    try {

        /*
        |--------------------------------------------------------------------------
        | Lock allocation and VERIFY SAME ACADEMIC YEAR
        |--------------------------------------------------------------------------
        */

        $stmt =
            $pdo->prepare("
                SELECT
                    ma.allocation_id,
                    ma.import_candidate_id,
                    ma.applicant_id,
                    ma.dept_id,
                    ma.merit_rank,
                    ma.allocation_status,

                    gic.academic_year,
                    gic.gst_roll,
                    gic.full_name,
                    gic.applicant_id
                        AS linked_applicant_id,

                    COALESCE(
                        NULLIF(
                            TRIM(ga.phone),
                            ''
                        ),
                        gic.phone
                    ) AS phone,

                    ga.email,

                    d.dept_name

                FROM merit_allocations ma

                INNER JOIN gst_imported_candidates gic
                    ON gic.import_candidate_id =
                       ma.import_candidate_id

                INNER JOIN departments d
                    ON d.dept_id =
                       ma.dept_id

                LEFT JOIN gst_applicants ga
                    ON ga.applicant_id =
                       gic.applicant_id

                WHERE ma.allocation_id = ?

                  AND ma.dept_id = ?

                  AND gic.academic_year = ?

                LIMIT 1

                FOR UPDATE
            ");

        $stmt->execute([
            $allocationId,
            $departmentId,
            $academicYear
        ]);


        $allocation =
            $stmt->fetch(
                PDO::FETCH_ASSOC
            );


        if (!$allocation) {
            throw new RuntimeException(
                'Allocation was not found in the current admission session.'
            );
        }


        if (
            (string)
            $allocation[
                'allocation_status'
            ]
            !== 'Allocated'
        ) {
            throw new RuntimeException(
                'Only an active provisional allocation can be vacated.'
            );
        }


        $vacatedImportCandidateId =
            (int)
            $allocation[
                'import_candidate_id'
            ];


        /*
        |--------------------------------------------------------------------------
        | Vacate
        |--------------------------------------------------------------------------
        */

        $stmt =
            $pdo->prepare("
                UPDATE merit_allocations

                SET allocation_status =
                    'Vacated'

                WHERE allocation_id = ?
                  AND dept_id = ?
                  AND allocation_status =
                      'Allocated'
            ");

        $stmt->execute([
            $allocationId,
            $departmentId
        ]);


        if (
            $stmt->rowCount()
            !== 1
        ) {
            throw new RuntimeException(
                'The allocation could not be vacated.'
            );
        }


        /*
        |--------------------------------------------------------------------------
        | Close candidate's old waiting state if applicable
        |--------------------------------------------------------------------------
        */

        $stmt =
            $pdo->prepare("
                UPDATE waiting_list wl

                INNER JOIN gst_imported_candidates gic
                    ON gic.import_candidate_id =
                       wl.import_candidate_id

                SET wl.waiting_status =
                    'Expired'

                WHERE wl.import_candidate_id = ?

                  AND wl.waiting_status IN (
                      'Waiting',
                      'Called'
                  )

                  AND gic.academic_year = ?
            ");

        $stmt->execute([
            $vacatedImportCandidateId,
            $academicYear
        ]);


        /*
        |--------------------------------------------------------------------------
        | Permanent removal only for Reject / Deadline failure / Withdrawal
        |--------------------------------------------------------------------------
        */

        $vacatedApplicantId =
            !empty(
                $allocation[
                    'linked_applicant_id'
                ]
            )
                ? (int)
                  $allocation[
                      'linked_applicant_id'
                  ]
                : (
                    !empty(
                        $allocation[
                            'applicant_id'
                        ]
                    )
                        ? (int)
                          $allocation[
                              'applicant_id'
                          ]
                        : 0
                );


        if (
            $permanentRemoval
            &&
            $vacatedApplicantId > 0
        ) {

            $progress =
                $pdo->prepare("
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

            $progress->execute([
                $vacatedApplicantId
            ]);
        }


        /*
        |--------------------------------------------------------------------------
        | Current-session merit re-rank
        |--------------------------------------------------------------------------
        */

        $activeMeritCount =
            sessionRerankActiveMeritList(
                $pdo,
                $departmentId,
                $academicYear
            );


        $replacementMeritRank =
            $activeMeritCount
            + 1;


        /*
        |--------------------------------------------------------------------------
        | Current-session waiting re-rank
        |--------------------------------------------------------------------------
        */

        sessionRerankActiveWaitingList(
            $pdo,
            $departmentId,
            $academicYear
        );


        /*
        |--------------------------------------------------------------------------
        | IMPORTANT: DO NOT PROMOTE WAITING CANDIDATE HERE
        |--------------------------------------------------------------------------
        |
        | New Merit-Round workflow:
        |
        | 1. Candidate allocation is vacated during the current Merit Call.
        | 2. The seat remains vacant / held.
        | 3. When the Registrar closes the current Merit Process, all vacancies
        |    become final for that Merit Call.
        | 4. Migration-Enabled finally admitted candidates get FIRST priority.
        | 5. Only after Migration finishes are remaining vacancies used for the
        |    NEXT Merit Call.
        |
        | Therefore this vacancy engine must never call waiting candidates
        | immediately.
        |--------------------------------------------------------------------------
        */

        $notificationCandidate = [
            'full_name' =>
                $allocation[
                    'full_name'
                ],

            'gst_roll' =>
                $allocation[
                    'gst_roll'
                ],

            'email' =>
                $allocation[
                    'email'
                ],

            'phone' =>
                $allocation[
                    'phone'
                ]
        ];


        if ($manageTransaction) {

            $pdo->commit();

            $notification =
                sessionNotifyVacatedCandidate(
                    $notificationCandidate,
                    (string)
                    $allocation[
                        'dept_name'
                    ],
                    $vacateReason,
                    $permanentRemoval
                );

        } else {

            /*
            | Outer caller owns the transaction.
            | Notification should be sent by the caller only AFTER commit.
            */
            $notification = null;
        }


        return [
            'success' =>
                true,

            'vacated' =>
                true,

            'candidate_called' =>
                false,

            'vacancy_held_for_migration' =>
                true,

            'academic_year' =>
                $academicYear,

            'vacated_allocation_id' =>
                $allocationId,

            'vacated_import_candidate_id' =>
                $vacatedImportCandidateId,

            'department_id' =>
                $departmentId,

            'notification' =>
                $notification,

            'message' =>
                'Seat vacated successfully. No waiting candidate was called. '
                . 'The vacancy will remain available until the current Merit '
                . 'Process closes. Migration will receive first priority, and '
                . 'only remaining vacancies will be used for the next Merit Call.'
        ];


    } catch (Throwable $exception) {

        if (
            $manageTransaction
            &&
            $pdo->inTransaction()
        ) {
            $pdo->rollBack();
        }

        throw $exception;
    }
}
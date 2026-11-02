<?php

declare(strict_types=1);


/*
|--------------------------------------------------------------------------
| Notification helpers
|--------------------------------------------------------------------------
|
| These are loaded when available. A notification failure must never roll
| back an already completed vacancy / seat replacement transaction.
|--------------------------------------------------------------------------
*/

$mailHelper = __DIR__ . '/mail/send_mail.php';
$smsHelper  = __DIR__ . '/sms/send_sms.php';

if (is_file($mailHelper)) {
    require_once $mailHelper;
}

if (is_file($smsHelper)) {
    require_once $smsHelper;
}




/*
|--------------------------------------------------------------------------
| VACATED CANDIDATE NOTIFICATION
|--------------------------------------------------------------------------
|
| Sends the reason through BOTH available channels:
| - Email: registered applicant email
| - SMS: registered phone, falling back to imported GST phone
|
| This function is intentionally best-effort. Database state is authoritative
| and must not be rolled back because an external notification provider fails.
|--------------------------------------------------------------------------
*/

function notifyVacatedCandidate(
    array $candidate,
    string $departmentName,
    string $reason
): array {

    $reason = trim($reason);

    if ($reason === '') {
        throw new InvalidArgumentException(
            'Vacate notification reason is required.'
        );
    }

    $fullName = trim(
        (string) (
            $candidate['full_name']
            ?? 'Candidate'
        )
    );

    $gstRoll = trim(
        (string) (
            $candidate['gst_roll']
            ?? ''
        )
    );

    $email = trim(
        (string) (
            $candidate['email']
            ?? ''
        )
    );

    $phone = trim(
        (string) (
            $candidate['phone']
            ?? ''
        )
    );

    $emailSent = false;
    $smsSent = false;

    /*
    |--------------------------------------------------------------------------
    | Email
    |--------------------------------------------------------------------------
    */

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

        $safeName = htmlspecialchars(
            $fullName,
            ENT_QUOTES,
            'UTF-8'
        );

        $safeRoll = htmlspecialchars(
            $gstRoll,
            ENT_QUOTES,
            'UTF-8'
        );

        $safeDepartment = htmlspecialchars(
            $departmentName,
            ENT_QUOTES,
            'UTF-8'
        );

        $safeReason = nl2br(
            htmlspecialchars(
                $reason,
                ENT_QUOTES,
                'UTF-8'
            )
        );

        $subject =
            'JKKNIU Admission Allocation Cancelled';

        $body = "
            <div style=\"font-family:Arial,sans-serif;
                        max-width:650px;
                        margin:auto;
                        border:1px solid #dddddd;
                        border-radius:10px;
                        overflow:hidden;\">

                <div style=\"background:#dc3545;
                            color:#ffffff;
                            padding:22px;
                            text-align:center;\">

                    <h2 style=\"margin:0;\">
                        JKKNIU Admission Management System
                    </h2>

                    <div style=\"margin-top:6px;\">
                        Admission Allocation Cancelled
                    </div>
                </div>

                <div style=\"padding:25px;\">

                    <p>
                        Dear <strong>{$safeName}</strong>,
                    </p>

                    <p>
                        Your provisional admission allocation has been
                        <strong style=\"color:#dc3545;\">vacated</strong>.
                        You have been removed from the current admission
                        process for this allocation.
                    </p>

                    <div style=\"background:#fff5f5;
                                border-left:4px solid #dc3545;
                                padding:15px;
                                margin:18px 0;\">

                        <p style=\"margin:5px 0;\">
                            <strong>GST Roll:</strong>
                            {$safeRoll}
                        </p>

                        <p style=\"margin:5px 0;\">
                            <strong>Department:</strong>
                            {$safeDepartment}
                        </p>

                        <p style=\"margin:10px 0 5px;\">
                            <strong>Reason for Vacating:</strong>
                        </p>

                        <div>
                            {$safeReason}
                        </div>
                    </div>

                    <p>
                        This allocation will no longer appear in your active
                        merit position. The vacant seat will be filled
                        according to the JKKNIU waiting-list policy.
                    </p>

                    <p style=\"margin-top:25px;\">
                        Regards,<br>
                        <strong>JKKNIU Admission Authority</strong>
                    </p>
                </div>
            </div>
        ";

        try {
            $emailSent = (bool) sendMail(
                $email,
                $subject,
                $body
            );
        } catch (Throwable $exception) {
            error_log(
                'Vacate email notification error: '
                . $exception->getMessage()
            );
            $emailSent = false;
        }
    }


    /*
    |--------------------------------------------------------------------------
    | SMS
    |--------------------------------------------------------------------------
    */

    if (
        $phone !== ''
        &&
        function_exists('sendSMS')
    ) {

        $smsMessage =
            'JKKNIU Admission: Your allocation in '
            . $departmentName
            . ' has been VACATED. GST Roll: '
            . $gstRoll
            . '. Reason: '
            . $reason
            . '. You are removed from this admission allocation.';

        /*
         * Keep gateway messages at a practical length while preserving cause.
         */
        if (
            function_exists('mb_strlen')
            &&
            mb_strlen(
                $smsMessage,
                'UTF-8'
            ) > 480
        ) {
            $smsMessage =
                mb_substr(
                    $smsMessage,
                    0,
                    477,
                    'UTF-8'
                )
                . '...';
        } elseif (
            !function_exists('mb_strlen')
            &&
            strlen($smsMessage) > 480
        ) {
            $smsMessage =
                substr(
                    $smsMessage,
                    0,
                    477
                )
                . '...';
        }

        try {
            $smsSent = (bool) sendSMS(
                $phone,
                $smsMessage
            );
        } catch (Throwable $exception) {
            error_log(
                'Vacate SMS notification error: '
                . $exception->getMessage()
            );
            $smsSent = false;
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
| GENERATE INITIAL MERIT + WAITING LIST
|--------------------------------------------------------------------------
|
| Authoritative candidate source:
|   gst_imported_candidates
|
| Authoritative preference source:
|   gst_candidate_choices
|
| Allocation rule:
|   Global GST merit order
|      -> Choice 1
|      -> Choice 2
|      -> ...
|      -> first department with a vacant seat
|
| Waiting rule:
|   Only candidates who receive NO preferred department enter waiting.
|   One candidate = one waiting record.
|   Waiting department = candidate's first preference.
|--------------------------------------------------------------------------
*/

function generateMeritAndWaiting(
    PDO $pdo,
    bool $forceReset = false
): array {

    if ($pdo->inTransaction()) {
        throw new RuntimeException(
            'Merit generation must start outside another transaction.'
        );
    }

    $pdo->beginTransaction();

    try {

        /*
        |--------------------------------------------------------------------------
        | 1. Existing merit data
        |--------------------------------------------------------------------------
        */

        $existingMeritCount = (int) $pdo->query("
            SELECT
                (SELECT COUNT(*) FROM merit_allocations)
              + (SELECT COUNT(*) FROM waiting_list)
        ")->fetchColumn();


        if (
            $existingMeritCount > 0 &&
            !$forceReset
        ) {
            throw new RuntimeException(
                'Existing merit or waiting-list data was found. '
                . 'Use the development reset option if you intentionally want '
                . 'to rebuild the initial merit data.'
            );
        }


        /*
        |--------------------------------------------------------------------------
        | 2. Development reset
        |--------------------------------------------------------------------------
        |
        | Imported GST candidates and choices are never deleted.
        |--------------------------------------------------------------------------
        */

        if ($forceReset) {
            $pdo->exec(
                "DELETE FROM waiting_list"
            );

            $pdo->exec(
                "DELETE FROM merit_allocations"
            );
        }


        /*
        |--------------------------------------------------------------------------
        | 3. Department capacities
        |--------------------------------------------------------------------------
        */

        $departments = [];

        $departmentRows = $pdo->query("
            SELECT
                dept_id,
                dept_name,
                total_seats
            FROM departments
            ORDER BY dept_id
        ")->fetchAll(PDO::FETCH_ASSOC);


        foreach ($departmentRows as $row) {

            $deptId =
                (int) $row['dept_id'];

            $departments[$deptId] = [
                'dept_name' =>
                    (string) $row['dept_name'],

                'total_seats' =>
                    max(
                        0,
                        (int) $row['total_seats']
                    ),

                'allocated_count' =>
                    0,

                'next_merit_rank' =>
                    1
            ];
        }


        if (!$departments) {
            throw new RuntimeException(
                'No departments were found.'
            );
        }


        /*
        |--------------------------------------------------------------------------
        | 4. GST candidates + choices
        |--------------------------------------------------------------------------
        |
        | Only GST candidates marked Selected are part of the admission pool.
        |--------------------------------------------------------------------------
        */

        $choiceRows = $pdo->query("
            SELECT
                gic.import_candidate_id,
                gic.gst_roll,
                gic.full_name,
                gic.gst_score,
                gic.merit_position,

                gcc.dept_id,
                gcc.preference_order

            FROM gst_imported_candidates gic

            INNER JOIN gst_candidate_choices gcc
                ON gcc.import_candidate_id =
                   gic.import_candidate_id

            INNER JOIN departments d
                ON d.dept_id =
                   gcc.dept_id

            WHERE gic.selection_status = 'Selected'

            ORDER BY
                CASE
                    WHEN gic.merit_position IS NULL
                    THEN 1
                    ELSE 0
                END ASC,

                gic.merit_position ASC,
                gic.gst_score DESC,
                gic.import_candidate_id ASC,
                gcc.preference_order ASC
        ")->fetchAll(PDO::FETCH_ASSOC);


        if (!$choiceRows) {
            throw new RuntimeException(
                'No selected imported GST candidates with department choices were found.'
            );
        }


        /*
        |--------------------------------------------------------------------------
        | 5. Build candidates
        |--------------------------------------------------------------------------
        */

        $candidates = [];
        $candidateOrder = [];


        foreach ($choiceRows as $row) {

            $importCandidateId =
                (int) $row[
                    'import_candidate_id'
                ];

            $deptId =
                (int) $row['dept_id'];

            $preferenceOrder =
                (int) $row[
                    'preference_order'
                ];


            if (
                !isset(
                    $candidates[
                        $importCandidateId
                    ]
                )
            ) {

                $candidates[
                    $importCandidateId
                ] = [
                    'import_candidate_id' =>
                        $importCandidateId,

                    'gst_roll' =>
                        (string) $row[
                            'gst_roll'
                        ],

                    'full_name' =>
                        (string) $row[
                            'full_name'
                        ],

                    'gst_score' =>
                        (float) $row[
                            'gst_score'
                        ],

                    'merit_position' =>
                        $row[
                            'merit_position'
                        ] !== null
                            ? (int) $row[
                                'merit_position'
                              ]
                            : null,

                    'choices' =>
                        [],

                    'allocated' =>
                        false,

                    'allocated_dept_id' =>
                        null
                ];


                $candidateOrder[] =
                    $importCandidateId;
            }


            $candidates[
                $importCandidateId
            ]['choices'][
                $preferenceOrder
            ] = $deptId;
        }


        /*
        |--------------------------------------------------------------------------
        | 6. Allocation insert
        |--------------------------------------------------------------------------
        |
        | applicant_id remains NULL until registration.
        |--------------------------------------------------------------------------
        */

        $insertAllocation =
            $pdo->prepare("
                INSERT INTO merit_allocations (
                    import_candidate_id,
                    applicant_id,
                    dept_id,
                    merit_rank,
                    allocation_status
                )
                VALUES (
                    ?,
                    NULL,
                    ?,
                    ?,
                    'Allocated'
                )
            ");


        $allocationCount = 0;
        $allocationSummary = [];


        /*
        |--------------------------------------------------------------------------
        | 7. Global GST merit allocation
        |--------------------------------------------------------------------------
        */

        foreach (
            $candidateOrder as
            $importCandidateId
        ) {

            $candidate =&
                $candidates[
                    $importCandidateId
                ];


            ksort(
                $candidate['choices']
            );


            foreach (
                $candidate['choices'] as
                $preference => $deptId
            ) {

                $deptId =
                    (int) $deptId;


                if (
                    !isset(
                        $departments[
                            $deptId
                        ]
                    )
                ) {
                    continue;
                }


                if (
                    $departments[
                        $deptId
                    ]['allocated_count']
                    >=
                    $departments[
                        $deptId
                    ]['total_seats']
                ) {
                    continue;
                }


                $departmentMeritRank =
                    $departments[
                        $deptId
                    ]['next_merit_rank'];


                $insertAllocation->execute([
                    $importCandidateId,
                    $deptId,
                    $departmentMeritRank
                ]);


                $candidate['allocated'] =
                    true;

                $candidate[
                    'allocated_dept_id'
                ] = $deptId;


                $departments[
                    $deptId
                ]['allocated_count']++;


                $departments[
                    $deptId
                ]['next_merit_rank']++;


                $allocationCount++;


                $allocationSummary[
                    $deptId
                ] =
                    (
                        $allocationSummary[
                            $deptId
                        ]
                        ?? 0
                    ) + 1;


                break;
            }


            unset($candidate);
        }


        /*
        |--------------------------------------------------------------------------
        | 8. Build canonical waiting lists
        |--------------------------------------------------------------------------
        */

        $waitingCandidatesByDepartment =
            [];


        foreach (
            $candidateOrder as
            $importCandidateId
        ) {

            $candidate =
                $candidates[
                    $importCandidateId
                ];


            if ($candidate['allocated']) {
                continue;
            }


            if (!$candidate['choices']) {
                continue;
            }


            ksort(
                $candidate['choices']
            );


            $firstChoiceDeptId =
                (int) reset(
                    $candidate[
                        'choices'
                    ]
                );


            if (
                !isset(
                    $departments[
                        $firstChoiceDeptId
                    ]
                )
            ) {
                continue;
            }


            $waitingCandidatesByDepartment[
                $firstChoiceDeptId
            ][] = [
                'import_candidate_id' =>
                    $importCandidateId,

                'gst_score' =>
                    $candidate[
                        'gst_score'
                    ],

                'merit_position' =>
                    $candidate[
                        'merit_position'
                    ]
            ];
        }


        /*
        |--------------------------------------------------------------------------
        | 9. Insert one waiting record per candidate
        |--------------------------------------------------------------------------
        */

        $insertWaiting =
            $pdo->prepare("
                INSERT INTO waiting_list (
                    import_candidate_id,
                    applicant_id,
                    dept_id,
                    waiting_rank,
                    waiting_status,
                    called_at
                )
                VALUES (
                    ?,
                    NULL,
                    ?,
                    ?,
                    'Waiting',
                    NULL
                )
            ");


        $waitingCount = 0;
        $waitingSummary = [];


        foreach (
            $waitingCandidatesByDepartment as
            $deptId => $waitingCandidates
        ) {

            usort(
                $waitingCandidates,

                static function (
                    array $a,
                    array $b
                ): int {

                    if (
                        $a[
                            'merit_position'
                        ] !== null
                        &&
                        $b[
                            'merit_position'
                        ] !== null
                    ) {

                        $rankCompare =
                            $a[
                                'merit_position'
                            ]
                            <=>
                            $b[
                                'merit_position'
                            ];


                        if (
                            $rankCompare !== 0
                        ) {
                            return $rankCompare;
                        }
                    }


                    if (
                        $a[
                            'merit_position'
                        ] !== null
                        &&
                        $b[
                            'merit_position'
                        ] === null
                    ) {
                        return -1;
                    }


                    if (
                        $a[
                            'merit_position'
                        ] === null
                        &&
                        $b[
                            'merit_position'
                        ] !== null
                    ) {
                        return 1;
                    }


                    $scoreCompare =
                        $b['gst_score']
                        <=>
                        $a['gst_score'];


                    if (
                        $scoreCompare !== 0
                    ) {
                        return $scoreCompare;
                    }


                    return
                        $a[
                            'import_candidate_id'
                        ]
                        <=>
                        $b[
                            'import_candidate_id'
                        ];
                }
            );


            $waitingRank = 1;


            foreach (
                $waitingCandidates as
                $candidate
            ) {

                $insertWaiting->execute([
                    (int) $candidate[
                        'import_candidate_id'
                    ],
                    (int) $deptId,
                    $waitingRank
                ]);


                $waitingCount++;


                $waitingSummary[
                    (int) $deptId
                ] =
                    (
                        $waitingSummary[
                            (int) $deptId
                        ]
                        ?? 0
                    ) + 1;


                $waitingRank++;
            }
        }


        /*
        |--------------------------------------------------------------------------
        | 10. Finish
        |--------------------------------------------------------------------------
        */

        $pdo->commit();


        return [
            'total_candidates' =>
                count($candidates),

            'allocated_count' =>
                $allocationCount,

            'waiting_count' =>
                $waitingCount,

            'allocation_summary' =>
                $allocationSummary,

            'waiting_summary' =>
                $waitingSummary
        ];


    } catch (Throwable $exception) {

        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        throw $exception;
    }
}




/*
|--------------------------------------------------------------------------
| RE-RANK ACTIVE MERIT LIST
|--------------------------------------------------------------------------
|
| Vacated rows remain in the database as history but do not keep an active
| merit position.
|
| Example:
|   Merit #1 is vacated
|   old #2 -> new #1
|   old #3 -> new #2
|   ...
|
| The promoted waiting candidate is inserted AFTER this re-ranking and gets
| the final active merit position.
|--------------------------------------------------------------------------
*/

function rerankActiveMeritList(
    PDO $pdo,
    int $departmentId
): int {

    if ($departmentId <= 0) {
        throw new InvalidArgumentException(
            'Invalid department ID.'
        );
    }

    if (!$pdo->inTransaction()) {
        throw new RuntimeException(
            'Merit-list re-ranking must run inside a transaction.'
        );
    }

    $stmt = $pdo->prepare("
        SELECT
            allocation_id

        FROM merit_allocations

        WHERE dept_id = ?
          AND allocation_status IN (
              'Allocated',
              'Admitted'
          )

        ORDER BY
            merit_rank ASC,
            allocation_id ASC

        FOR UPDATE
    ");

    $stmt->execute([
        $departmentId
    ]);

    $allocationIds = $stmt->fetchAll(
        PDO::FETCH_COLUMN
    );

    if (!$allocationIds) {
        return 0;
    }

    $update = $pdo->prepare("
        UPDATE merit_allocations
        SET merit_rank = ?
        WHERE allocation_id = ?
          AND dept_id = ?
          AND allocation_status IN (
              'Allocated',
              'Admitted'
          )
    ");

    $rank = 1;

    foreach ($allocationIds as $activeAllocationId) {

        $update->execute([
            $rank,
            (int) $activeAllocationId,
            $departmentId
        ]);

        $rank++;
    }

    return count($allocationIds);
}

/*
|--------------------------------------------------------------------------
| RE-RANK ACTIVE WAITING LIST
|--------------------------------------------------------------------------
|
| Only current Waiting candidates are ranked.
| Called / Admitted / Expired rows remain as history but do not occupy
| an active waiting position.
|
| Example after promoting waiting #1:
|   old #2 -> new #1
|   old #3 -> new #2
|   old #4 -> new #3
|--------------------------------------------------------------------------
*/

function rerankActiveWaitingList(
    PDO $pdo,
    int $departmentId
): void {

    if ($departmentId <= 0) {
        throw new InvalidArgumentException(
            'Invalid department ID.'
        );
    }

    if (!$pdo->inTransaction()) {
        throw new RuntimeException(
            'Waiting-list re-ranking must run inside a transaction.'
        );
    }

    $stmt = $pdo->prepare("
        SELECT
            wl.waiting_id

        FROM waiting_list wl

        INNER JOIN gst_candidate_choices gcc
            ON gcc.import_candidate_id =
               wl.import_candidate_id
           AND gcc.dept_id =
               wl.dept_id

        WHERE wl.dept_id = ?
          AND wl.waiting_status = 'Waiting'

          AND gcc.preference_order = (
              SELECT MIN(gcc2.preference_order)
              FROM gst_candidate_choices gcc2
              WHERE gcc2.import_candidate_id =
                    wl.import_candidate_id
          )

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
            wl.waiting_id ASC

        FOR UPDATE
    ");

    $stmt->execute([
        $departmentId
    ]);

    $waitingIds = $stmt->fetchAll(
        PDO::FETCH_COLUMN
    );

    if (!$waitingIds) {
        return;
    }

    $update = $pdo->prepare("
        UPDATE waiting_list
        SET waiting_rank = ?
        WHERE waiting_id = ?
          AND dept_id = ?
          AND waiting_status = 'Waiting'
    ");

    $rank = 1;

    foreach ($waitingIds as $waitingId) {

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
| CORE VACANCY ENGINE
|--------------------------------------------------------------------------
|
| Mark one provisional allocation Vacated and call the next canonical
| waiting candidate.
|
| $manageTransaction = false is used by bulk workflows such as Viva so the
| caller can keep the whole operation atomic.
|--------------------------------------------------------------------------
*/

function vacateAndCallNextCandidate(
    PDO $pdo,
    int $allocationId,
    int $departmentId,
    bool $manageTransaction = true,
    string $vacateReason = ''
): array {

    if (
        $allocationId <= 0 ||
        $departmentId <= 0
    ) {
        throw new InvalidArgumentException(
            'Invalid allocation or department ID.'
        );
    }

    $vacateReason = trim(
        $vacateReason
    );

    if ($vacateReason === '') {
        throw new InvalidArgumentException(
            'A reason for vacating the candidate is required.'
        );
    }

    $reasonLength = function_exists('mb_strlen')
        ? mb_strlen(
            $vacateReason,
            'UTF-8'
        )
        : strlen($vacateReason);

    if ($reasonLength > 500) {
        throw new InvalidArgumentException(
            'Vacate reason cannot exceed 500 characters.'
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
            'An active database transaction is required.'
        );
    }


    try {

        /*
        |--------------------------------------------------------------------------
        | 1. Lock allocation
        |--------------------------------------------------------------------------
        */

        $stmt = $pdo->prepare("
            SELECT
                ma.allocation_id,
                ma.import_candidate_id,
                ma.applicant_id,
                ma.dept_id,
                ma.merit_rank,
                ma.allocation_status,

                gic.gst_roll,
                gic.full_name,

                COALESCE(
                    NULLIF(TRIM(ga.phone), ''),
                    gic.phone
                ) AS phone,

                ga.email,

                d.dept_name

            FROM merit_allocations ma

            INNER JOIN gst_imported_candidates gic
                ON gic.import_candidate_id =
                   ma.import_candidate_id

            LEFT JOIN gst_applicants ga
                ON ga.applicant_id =
                   gic.applicant_id

            INNER JOIN departments d
                ON d.dept_id =
                   ma.dept_id

            WHERE ma.allocation_id = ?
              AND ma.dept_id = ?

            LIMIT 1
            FOR UPDATE
        ");

        $stmt->execute([
            $allocationId,
            $departmentId
        ]);

        $allocation =
            $stmt->fetch(
                PDO::FETCH_ASSOC
            );


        if (!$allocation) {
            throw new RuntimeException(
                'Allocation not found in your department.'
            );
        }


        if (
            $allocation[
                'allocation_status'
            ] !== 'Allocated'
        ) {
            throw new RuntimeException(
                'Only a provisionally allocated candidate can be vacated.'
            );
        }


        $vacatedImportCandidateId =
            (int) $allocation[
                'import_candidate_id'
            ];

        if (
            $vacatedImportCandidateId
            <= 0
        ) {
            throw new RuntimeException(
                'This allocation is not linked to an imported GST candidate.'
            );
        }


        /*
        |--------------------------------------------------------------------------
        | Candidate notification payload
        |--------------------------------------------------------------------------
        */

        $vacatedCandidateNotification = [
            'full_name' =>
                (string) (
                    $allocation['full_name']
                    ?? ''
                ),

            'gst_roll' =>
                (string) (
                    $allocation['gst_roll']
                    ?? ''
                ),

            'email' =>
                (string) (
                    $allocation['email']
                    ?? ''
                ),

            'phone' =>
                (string) (
                    $allocation['phone']
                    ?? ''
                )
        ];

        $vacatedDepartmentName =
            (string) (
                $allocation['dept_name']
                ?? 'Department'
            );


        /*
        |--------------------------------------------------------------------------
        | 2. Vacate allocation
        |--------------------------------------------------------------------------
        */

        $stmt = $pdo->prepare("
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
        | 3. Re-rank the remaining ACTIVE merit list
        |--------------------------------------------------------------------------
        |
        | The vacated candidate disappears from the active merit list.
        |
        | Example:
        |   old #2 -> new #1
        |   old #3 -> new #2
        |   ...
        |
        | The promoted waiting candidate will receive:
        |   current active count + 1
        |--------------------------------------------------------------------------
        */

        $activeMeritCount =
            rerankActiveMeritList(
                $pdo,
                $departmentId
            );

        $replacementMeritRank =
            $activeMeritCount + 1;


        /*
        |--------------------------------------------------------------------------
        | 4. Close Called waiting record if this candidate came from waiting
        |--------------------------------------------------------------------------
        */

        $stmt = $pdo->prepare("
            UPDATE waiting_list

            SET waiting_status =
                'Expired'

            WHERE import_candidate_id = ?
              AND waiting_status IN (
                  'Waiting',
                  'Called'
              )
        ");

        $stmt->execute([
            $vacatedImportCandidateId
        ]);


        /*
        |--------------------------------------------------------------------------
        | 4A. Permanently close registered applicant's admission progress
        |--------------------------------------------------------------------------
        |
        | A manually vacated candidate is gone from the current admission
        | process and must never fall back into the waiting queue.
        |--------------------------------------------------------------------------
        */

        $vacatedApplicantId =
            !empty(
                $allocation['applicant_id']
            )
                ? (int) $allocation['applicant_id']
                : 0;

        if ($vacatedApplicantId <= 0) {

            $linkedApplicantStmt =
                $pdo->prepare("
                    SELECT applicant_id
                    FROM gst_imported_candidates
                    WHERE import_candidate_id = ?
                    LIMIT 1
                ");

            $linkedApplicantStmt->execute([
                $vacatedImportCandidateId
            ]);

            $vacatedApplicantId =
                (int) (
                    $linkedApplicantStmt
                        ->fetchColumn()
                    ?: 0
                );
        }

        if ($vacatedApplicantId > 0) {

            $progressStmt =
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
                        final_status = 'Rejected'
                ");

            $progressStmt->execute([
                $vacatedApplicantId
            ]);
        }


        /*
        |--------------------------------------------------------------------------
        | 5. Normalize active waiting ranks before promotion
        |--------------------------------------------------------------------------
        */

        rerankActiveWaitingList(
            $pdo,
            $departmentId
        );


        /*
        |--------------------------------------------------------------------------
        | 6. Find next REAL waiting candidate
        |--------------------------------------------------------------------------
        |
        | - Same department
        | - Waiting
        | - Canonical first-preference waiting row
        | - No active allocation anywhere
        | - Not rejected
        | - Not absent / Call Later
        |--------------------------------------------------------------------------
        */

        $stmt = $pdo->prepare("
            SELECT
                wl.waiting_id,
                wl.import_candidate_id,
                wl.waiting_rank,

                gic.applicant_id

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

              AND NOT EXISTS (
                  SELECT 1
                  FROM merit_allocations active_ma
                  WHERE active_ma.import_candidate_id =
                        wl.import_candidate_id
                    AND active_ma.allocation_status IN (
                        'Allocated',
                        'Admitted'
                    )
              )

              AND NOT EXISTS (
                  SELECT 1
                  FROM viva_results vr
                  WHERE vr.applicant_id =
                        gic.applicant_id
                    AND vr.viva_status IN (
                        'Not Selected',
                        'Absent'
                    )
              )

            ORDER BY
                wl.waiting_rank ASC,
                wl.waiting_id ASC

            LIMIT 1
            FOR UPDATE
        ");

        $stmt->execute([
            $departmentId
        ]);

        $nextCandidate =
            $stmt->fetch(
                PDO::FETCH_ASSOC
            );


        /*
        |--------------------------------------------------------------------------
        | 7. No waiting candidate
        |--------------------------------------------------------------------------
        */

        if (!$nextCandidate) {

            $notificationPayload = [
                'candidate' =>
                    $vacatedCandidateNotification,

                'department_name' =>
                    $vacatedDepartmentName,

                'reason' =>
                    $vacateReason
            ];

            $notificationResult = null;

            if ($manageTransaction) {

                $pdo->commit();

                $notificationResult =
                    notifyVacatedCandidate(
                        $vacatedCandidateNotification,
                        $vacatedDepartmentName,
                        $vacateReason
                    );
            }


            return [
                'success' =>
                    true,

                'vacated' =>
                    true,

                'candidate_called' =>
                    false,

                'notification_payload' =>
                    $notificationPayload,

                'notification' =>
                    $notificationResult,

                'message' =>
                    'Candidate removed permanently and seat vacated successfully, but no eligible waiting candidate is available.'
            ];
        }


        $waitingId =
            (int) $nextCandidate[
                'waiting_id'
            ];

        $nextImportCandidateId =
            (int) $nextCandidate[
                'import_candidate_id'
            ];

        $waitingRank =
            (int) $nextCandidate[
                'waiting_rank'
            ];

        $nextApplicantId =
            !empty(
                $nextCandidate[
                    'applicant_id'
                ]
            )
                ? (int) $nextCandidate[
                    'applicant_id'
                  ]
                : null;


        /*
        |--------------------------------------------------------------------------
        | 8. Mark candidate Called
        |--------------------------------------------------------------------------
        */

        $stmt = $pdo->prepare("
            UPDATE waiting_list

            SET
                waiting_status =
                    'Called',

                called_at =
                    NOW()

            WHERE waiting_id = ?
              AND dept_id = ?
              AND waiting_status =
                  'Waiting'
        ");

        $stmt->execute([
            $waitingId,
            $departmentId
        ]);


        if (
            $stmt->rowCount()
            !== 1
        ) {
            throw new RuntimeException(
                'The next waiting candidate could not be called.'
            );
        }


        /*
        |--------------------------------------------------------------------------
        | 9. Re-rank the remaining Waiting candidates
        |--------------------------------------------------------------------------
        |
        | Promoted candidate is no longer part of the active waiting queue.
        | Therefore old waiting #2 becomes new #1, #3 becomes #2, etc.
        |--------------------------------------------------------------------------
        */

        rerankActiveWaitingList(
            $pdo,
            $departmentId
        );


        /*
        |--------------------------------------------------------------------------
        | 10. Create replacement allocation
        |--------------------------------------------------------------------------
        |
        | applicant_id may still be NULL before registration.
        |
        | The candidate receives the LAST current merit position, not the
        | position that was vacated.
        |--------------------------------------------------------------------------
        */

        $stmt = $pdo->prepare("
            INSERT INTO merit_allocations (
                import_candidate_id,
                applicant_id,
                dept_id,
                merit_rank,
                allocation_status,
                allocated_at
            )
            VALUES (
                ?,
                ?,
                ?,
                ?,
                'Allocated',
                NOW()
            )
        ");

        $stmt->execute([
            $nextImportCandidateId,
            $nextApplicantId,
            $departmentId,
            $replacementMeritRank
        ]);


        $newAllocationId =
            (int)
            $pdo->lastInsertId();


        $notificationPayload = [
            'candidate' =>
                $vacatedCandidateNotification,

            'department_name' =>
                $vacatedDepartmentName,

            'reason' =>
                $vacateReason
        ];

        $notificationResult = null;

        if ($manageTransaction) {

            $pdo->commit();

            /*
            | Notify only AFTER the vacancy transaction is committed.
            */
            $notificationResult =
                notifyVacatedCandidate(
                    $vacatedCandidateNotification,
                    $vacatedDepartmentName,
                    $vacateReason
                );
        }


        return [
            'success' =>
                true,

            'vacated' =>
                true,

            'candidate_called' =>
                true,

            'new_allocation_id' =>
                $newAllocationId,

            'called_import_candidate_id' =>
                $nextImportCandidateId,

            'called_applicant_id' =>
                $nextApplicantId,

            'waiting_rank' =>
                $waitingRank,

            'merit_rank' =>
                $replacementMeritRank,

            'notification_payload' =>
                $notificationPayload,

            'notification' =>
                $notificationResult,

            'message' =>
                "Candidate removed permanently. Waiting rank #{$waitingRank} was promoted to merit rank #{$replacementMeritRank}, and both active lists were re-ranked."
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


/*
|--------------------------------------------------------------------------
| VACATE AFTER FAILED / WITHDRAWN FINAL ADMISSION
|--------------------------------------------------------------------------
|
| Preserves your existing public function name.
|--------------------------------------------------------------------------
*/

function vacateAllocationAndPromote(
    PDO $pdo,
    int $allocationId,
    string $failedFinalStatus = 'Rejected',
    bool $registrarRejected = false
): ?array {

    if (
        !in_array(
            $failedFinalStatus,
            [
                'Rejected',
                'Withdrawn'
            ],
            true
        )
    ) {
        throw new InvalidArgumentException(
            'Invalid failed final status.'
        );
    }


    $ownsTransaction =
        !$pdo->inTransaction();


    if ($ownsTransaction) {
        $pdo->beginTransaction();
    }


    try {

        /*
        |--------------------------------------------------------------------------
        | Get current allocation/applicant before vacancy processing
        |--------------------------------------------------------------------------
        */

        $stmt = $pdo->prepare("
            SELECT
                ma.allocation_id,
                ma.import_candidate_id,
                ma.applicant_id,
                ma.dept_id,
                ma.allocation_status,

                gic.applicant_id AS linked_applicant_id

            FROM merit_allocations ma

            INNER JOIN gst_imported_candidates gic
                ON gic.import_candidate_id =
                   ma.import_candidate_id

            WHERE ma.allocation_id = ?

            LIMIT 1
            FOR UPDATE
        ");

        $stmt->execute([
            $allocationId
        ]);

        $allocation =
            $stmt->fetch(
                PDO::FETCH_ASSOC
            );


        if (!$allocation) {
            throw new RuntimeException(
                'Allocation not found.'
            );
        }


        if (
            $allocation[
                'allocation_status'
            ] !== 'Allocated'
        ) {
            throw new RuntimeException(
                'Only a provisional allocation can be vacated.'
            );
        }


        $deptId =
            (int) $allocation[
                'dept_id'
            ];


        $applicantId =
            !empty(
                $allocation[
                    'linked_applicant_id'
                ]
            )
                ? (int) $allocation[
                    'linked_applicant_id'
                  ]
                : (
                    !empty(
                        $allocation[
                            'applicant_id'
                        ]
                    )
                        ? (int) $allocation[
                            'applicant_id'
                          ]
                        : 0
                  );


        /*
        |--------------------------------------------------------------------------
        | Update applicant progress if registered
        |--------------------------------------------------------------------------
        */

        if ($applicantId > 0) {

            if ($registrarRejected) {

                $progressStmt =
                    $pdo->prepare("
                        INSERT INTO admission_progress (
                            applicant_id,
                            registrar_step,
                            final_status
                        )
                        VALUES (
                            ?,
                            'Rejected',
                            ?
                        )

                        ON DUPLICATE KEY UPDATE
                            registrar_step =
                                'Rejected',

                            final_status =
                                VALUES(
                                    final_status
                                )
                    ");

                $progressStmt->execute([
                    $applicantId,
                    $failedFinalStatus
                ]);

            } else {

                $progressStmt =
                    $pdo->prepare("
                        INSERT INTO admission_progress (
                            applicant_id,
                            final_status
                        )
                        VALUES (
                            ?,
                            ?
                        )

                        ON DUPLICATE KEY UPDATE
                            final_status =
                                VALUES(
                                    final_status
                                )
                    ");

                $progressStmt->execute([
                    $applicantId,
                    $failedFinalStatus
                ]);
            }
        }


        /*
        |--------------------------------------------------------------------------
        | Use one canonical vacancy engine
        |--------------------------------------------------------------------------
        */

        $vacateReason =
            $registrarRejected
                ? 'Final admission was rejected by the Registrar.'
                : (
                    $failedFinalStatus === 'Withdrawn'
                        ? 'The candidate withdrew from the admission process.'
                        : 'The candidate failed to complete the required final admission process.'
                  );


        $result =
            vacateAndCallNextCandidate(
                $pdo,
                $allocationId,
                $deptId,
                false,
                $vacateReason
            );


        if ($ownsTransaction) {

            $pdo->commit();

            if (
                !empty(
                    $result[
                        'notification_payload'
                    ]
                )
            ) {

                $payload =
                    $result[
                        'notification_payload'
                    ];

                $result['notification'] =
                    notifyVacatedCandidate(
                        $payload['candidate'],
                        $payload['department_name'],
                        $payload['reason']
                    );
            }
        }


        if (
            empty(
                $result[
                    'candidate_called'
                ]
            )
        ) {
            return null;
        }


        return [
            'allocation_id' =>
                (int) $result[
                    'new_allocation_id'
                ],

            'import_candidate_id' =>
                (int) $result[
                    'called_import_candidate_id'
                ],

            'applicant_id' =>
                $result[
                    'called_applicant_id'
                ],

            'dept_id' =>
                $deptId,

            'merit_rank' =>
                (int) $result[
                    'merit_rank'
                ],

            'waiting_rank' =>
                (int) $result[
                    'waiting_rank'
                ]
        ];


    } catch (Throwable $exception) {

        if (
            $ownsTransaction
            &&
            $pdo->inTransaction()
        ) {
            $pdo->rollBack();
        }


        throw $exception;
    }
}


/*
|--------------------------------------------------------------------------
| FINAL ADMISSION
|--------------------------------------------------------------------------
|
| Input remains applicant_id because this action happens after registration.
| Internally, allocation/waiting are resolved through import_candidate_id.
|--------------------------------------------------------------------------
*/

function markApplicantFinallyAdmitted(
    PDO $pdo,
    int $applicantId
): void {

    if ($applicantId <= 0) {
        throw new InvalidArgumentException(
            'Invalid applicant ID.'
        );
    }


    $ownsTransaction =
        !$pdo->inTransaction();


    if ($ownsTransaction) {
        $pdo->beginTransaction();
    }


    try {

        /*
        |--------------------------------------------------------------------------
        | Resolve imported candidate
        |--------------------------------------------------------------------------
        */

        $stmt = $pdo->prepare("
            SELECT
                import_candidate_id
            FROM gst_imported_candidates
            WHERE applicant_id = ?
            LIMIT 1
            FOR UPDATE
        ");

        $stmt->execute([
            $applicantId
        ]);


        $importCandidateId =
            (int) (
                $stmt->fetchColumn()
                ?: 0
            );


        if ($importCandidateId <= 0) {
            throw new RuntimeException(
                'The registered applicant is not linked to an imported GST candidate.'
            );
        }


        /*
        |--------------------------------------------------------------------------
        | Lock current provisional allocation
        |--------------------------------------------------------------------------
        */

        $stmt = $pdo->prepare("
            SELECT
                allocation_id

            FROM merit_allocations

            WHERE import_candidate_id = ?
              AND allocation_status =
                  'Allocated'

            ORDER BY
                allocation_id DESC

            LIMIT 1
            FOR UPDATE
        ");

        $stmt->execute([
            $importCandidateId
        ]);


        $allocationId =
            (int) (
                $stmt->fetchColumn()
                ?: 0
            );


        if ($allocationId <= 0) {
            throw new RuntimeException(
                'No active provisional allocation was found.'
            );
        }


        /*
        |--------------------------------------------------------------------------
        | Finalize merit allocation
        |--------------------------------------------------------------------------
        */

        $stmt = $pdo->prepare("
            UPDATE merit_allocations

            SET
                allocation_status =
                    'Admitted',

                applicant_id = ?

            WHERE allocation_id = ?
              AND allocation_status =
                  'Allocated'
        ");

        $stmt->execute([
            $applicantId,
            $allocationId
        ]);


        if (
            $stmt->rowCount()
            !== 1
        ) {
            throw new RuntimeException(
                'The allocation could not be finalized.'
            );
        }


        /*
        |--------------------------------------------------------------------------
        | Waiting candidate becomes Admitted
        |--------------------------------------------------------------------------
        */

        $stmt = $pdo->prepare("
            UPDATE waiting_list

            SET
                waiting_status =
                    'Admitted',

                applicant_id = ?

            WHERE import_candidate_id = ?
              AND waiting_status =
                  'Called'
        ");

        $stmt->execute([
            $applicantId,
            $importCandidateId
        ]);


        /*
        |--------------------------------------------------------------------------
        | Final admission progress
        |--------------------------------------------------------------------------
        */

        $stmt = $pdo->prepare("
            INSERT INTO admission_progress (
                applicant_id,
                registrar_step,
                final_status
            )
            VALUES (
                ?,
                'Approved',
                'Admitted'
            )

            ON DUPLICATE KEY UPDATE
                registrar_step =
                    'Approved',

                final_status =
                    'Admitted'
        ");

        $stmt->execute([
            $applicantId
        ]);


        if ($ownsTransaction) {
            $pdo->commit();
        }


    } catch (Throwable $exception) {

        if (
            $ownsTransaction
            &&
            $pdo->inTransaction()
        ) {
            $pdo->rollBack();
        }


        throw $exception;
    }
}
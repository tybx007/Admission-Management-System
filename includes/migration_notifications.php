<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| JKKNIU Migration Notifications
|--------------------------------------------------------------------------
|
| Intended path:
|   includes/migration_notifications.php
|
| IMPORTANT:
| Call this ONLY AFTER Migration has been committed successfully.
| Email/SMS failure must never roll back Migration.
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


/*
|--------------------------------------------------------------------------
| If a candidate migrated multiple times inside one cascade, combine those
| moves so they receive only ONE final notification.
|--------------------------------------------------------------------------
*/

function migrationNotificationFinalMoves(
    array $moves
): array {

    $final = [];


    foreach ($moves as $move) {

        $importCandidateId =
            (int) (
                $move[
                    'import_candidate_id'
                ]
                ?? 0
            );


        if ($importCandidateId <= 0) {
            continue;
        }


        if (
            !isset(
                $final[
                    $importCandidateId
                ]
            )
        ) {

            $final[
                $importCandidateId
            ] = [
                'import_candidate_id' =>
                    $importCandidateId,

                'applicant_id' =>
                    (int) (
                        $move[
                            'applicant_id'
                        ]
                        ?? 0
                    ),

                'gst_roll' =>
                    (string) (
                        $move[
                            'gst_roll'
                        ]
                        ?? ''
                    ),

                'full_name' =>
                    (string) (
                        $move[
                            'full_name'
                        ]
                        ?? ''
                    ),

                'from_dept_id' =>
                    (int) (
                        $move[
                            'from_dept_id'
                        ]
                        ?? 0
                    ),

                'from_department' =>
                    (string) (
                        $move[
                            'from_department'
                        ]
                        ?? ''
                    ),

                'from_preference_order' =>
                    (int) (
                        $move[
                            'from_preference_order'
                        ]
                        ?? 0
                    ),

                'to_dept_id' =>
                    (int) (
                        $move[
                            'to_dept_id'
                        ]
                        ?? 0
                    ),

                'to_department' =>
                    (string) (
                        $move[
                            'to_department'
                        ]
                        ?? ''
                    ),

                'to_preference_order' =>
                    (int) (
                        $move[
                            'to_preference_order'
                        ]
                        ?? 0
                    ),

                'migration_steps' =>
                    1,
            ];


            continue;
        }


        /*
         * Keep the original "from" department,
         * update only the final destination.
         */

        $final[
            $importCandidateId
        ][
            'to_dept_id'
        ] =
            (int) (
                $move[
                    'to_dept_id'
                ]
                ?? 0
            );


        $final[
            $importCandidateId
        ][
            'to_department'
        ] =
            (string) (
                $move[
                    'to_department'
                ]
                ?? ''
            );


        $final[
            $importCandidateId
        ][
            'to_preference_order'
        ] =
            (int) (
                $move[
                    'to_preference_order'
                ]
                ?? 0
            );


        $final[
            $importCandidateId
        ][
            'migration_steps'
        ]++;
    }


    return array_values(
        $final
    );
}


/*
|--------------------------------------------------------------------------
| Contact details
|--------------------------------------------------------------------------
|
| Registered applicant data is preferred.
| Imported GST phone is used as fallback.
|--------------------------------------------------------------------------
*/

function migrationNotificationContact(
    PDO $pdo,
    int $importCandidateId,
    string $academicYear
): ?array {

    $stmt =
        $pdo->prepare("
            SELECT
                gic.import_candidate_id,
                gic.gst_roll,
                gic.full_name,
                gic.applicant_id,

                ga.email,

                COALESCE(
                    NULLIF(
                        TRIM(
                            ga.phone
                        ),
                        ''
                    ),
                    gic.phone
                ) AS phone

            FROM gst_imported_candidates gic

            LEFT JOIN gst_applicants ga
                ON ga.applicant_id =
                   gic.applicant_id

            WHERE gic.import_candidate_id = ?
              AND gic.academic_year = ?

            LIMIT 1
        ");


    $stmt->execute([
        $importCandidateId,
        $academicYear
    ]);


    $row =
        $stmt->fetch(
            PDO::FETCH_ASSOC
        );


    return $row ?: null;
}


/*
|--------------------------------------------------------------------------
| Merit Call name
|--------------------------------------------------------------------------
*/

function migrationNotificationCallTitle(
    PDO $pdo,
    ?int $meritCallId
): string {

    if (
        $meritCallId === null
        ||
        $meritCallId <= 0
    ) {
        return 'Migration Round';
    }


    $stmt =
        $pdo->prepare("
            SELECT call_title
            FROM merit_calls
            WHERE merit_call_id = ?
            LIMIT 1
        ");


    $stmt->execute([
        $meritCallId
    ]);


    $title =
        trim(
            (string)
            $stmt->fetchColumn()
        );


    return
        $title !== ''
            ? $title
            : 'Migration Round';
}


/*
|--------------------------------------------------------------------------
| Send one candidate notification
|--------------------------------------------------------------------------
*/

function sendSingleMigrationNotification(
    PDO $pdo,
    array $move,
    string $academicYear,
    ?int $meritCallId = null
): array {

    $contact =
        migrationNotificationContact(
            $pdo,
            (int)
            $move[
                'import_candidate_id'
            ],
            $academicYear
        );


    if (!$contact) {

        return [
            'import_candidate_id' =>
                (int)
                $move[
                    'import_candidate_id'
                ],

            'email_available' =>
                false,

            'phone_available' =>
                false,

            'email_sent' =>
                false,

            'sms_sent' =>
                false,

            'error' =>
                'Candidate contact information was not found.'
        ];
    }


    $fullName =
        trim(
            (string) (
                $contact[
                    'full_name'
                ]
                ?? $move[
                    'full_name'
                ]
                ?? 'Candidate'
            )
        );


    $gstRoll =
        trim(
            (string) (
                $contact[
                    'gst_roll'
                ]
                ?? $move[
                    'gst_roll'
                ]
                ?? ''
            )
        );


    $email =
        trim(
            (string) (
                $contact[
                    'email'
                ]
                ?? ''
            )
        );


    $phone =
        trim(
            (string) (
                $contact[
                    'phone'
                ]
                ?? ''
            )
        );


    $fromDepartment =
        trim(
            (string) (
                $move[
                    'from_department'
                ]
                ?? ''
            )
        );


    $toDepartment =
        trim(
            (string) (
                $move[
                    'to_department'
                ]
                ?? ''
            )
        );


    $fromPreference =
        (int) (
            $move[
                'from_preference_order'
            ]
            ?? 0
        );


    $toPreference =
        (int) (
            $move[
                'to_preference_order'
            ]
            ?? 0
        );


    $callTitle =
        migrationNotificationCallTitle(
            $pdo,
            $meritCallId
        );


    $emailSent = false;
    $smsSent = false;


    /*
    |--------------------------------------------------------------------------
    | EMAIL
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
        function_exists(
            'sendMail'
        )
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


        $safeFrom =
            htmlspecialchars(
                $fromDepartment,
                ENT_QUOTES,
                'UTF-8'
            );


        $safeTo =
            htmlspecialchars(
                $toDepartment,
                ENT_QUOTES,
                'UTF-8'
            );


        $safeYear =
            htmlspecialchars(
                $academicYear,
                ENT_QUOTES,
                'UTF-8'
            );


        $safeCall =
            htmlspecialchars(
                $callTitle,
                ENT_QUOTES,
                'UTF-8'
            );


        $subject =
            'JKKNIU Admission Migration Successful';


        $body = "
            <div style=\"
                font-family:Arial,sans-serif;
                max-width:650px;
                margin:auto;
                border:1px solid #dddddd;
                border-radius:10px;
                overflow:hidden;
            \">

                <div style=\"
                    background:#1a3c5e;
                    color:#ffffff;
                    padding:22px;
                    text-align:center;
                \">

                    <h2 style=\"margin:0;\">
                        JKKNIU Admission Management System
                    </h2>

                </div>


                <div style=\"padding:25px;\">

                    <p>
                        Dear <strong>{$safeName}</strong>,
                    </p>


                    <p>
                        Your department Migration has been completed successfully.
                    </p>


                    <div style=\"
                        background:#f5f7fa;
                        padding:16px;
                        margin:18px 0;
                        border-radius:7px;
                    \">

                        <p style=\"margin:5px 0;\">
                            <strong>GST Roll:</strong>
                            {$safeRoll}
                        </p>

                        <p style=\"margin:5px 0;\">
                            <strong>Admission Session:</strong>
                            {$safeYear}
                        </p>

                        <p style=\"margin:5px 0;\">
                            <strong>Migration Round:</strong>
                            {$safeCall}
                        </p>

                        <p style=\"margin:5px 0;\">
                            <strong>Previous Department:</strong>
                            {$safeFrom}
                            "
                            . (
                                $fromPreference > 0
                                    ? " (Choice #{$fromPreference})"
                                    : ''
                              )
                            . "
                        </p>

                        <p style=\"margin:5px 0;\">
                            <strong>New Department:</strong>
                            {$safeTo}
                            "
                            . (
                                $toPreference > 0
                                    ? " (Choice #{$toPreference})"
                                    : ''
                              )
                            . "
                        </p>

                    </div>
        ";


        if ($toPreference === 1) {

            $body .= "
                    <p>
                        You have reached your first-choice department,
                        so no further upward Migration is possible.
                    </p>
            ";

        } else {

            $body .= "
                    <p>
                        Your Migration remains enabled for future rounds
                        unless you permanently opt out from your student portal.
                    </p>
            ";
        }


        $body .= "
                    <p>
                        Please check your admission portal for the updated department information.
                    </p>

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
                'Migration email error for '
                . $gstRoll
                . ': '
                . $exception->getMessage()
            );
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
        function_exists(
            'sendSMS'
        )
    ) {

        $smsMessage =
            'JKKNIU Admission: Migration successful. '
            . 'GST Roll: '
            . $gstRoll
            . '. '
            . $fromDepartment
            . ' -> '
            . $toDepartment;


        if ($toPreference > 0) {

            $smsMessage .=
                ' (Choice #'
                . $toPreference
                . ')';
        }


        $smsMessage .=
            '. Session: '
            . $academicYear
            . '. Check your admission portal.';


        try {

            $smsSent =
                (bool)
                sendSMS(
                    $phone,
                    $smsMessage
                );

        } catch (Throwable $exception) {

            error_log(
                'Migration SMS error for '
                . $gstRoll
                . ': '
                . $exception->getMessage()
            );
        }
    }


    return [
        'import_candidate_id' =>
            (int)
            $move[
                'import_candidate_id'
            ],

        'applicant_id' =>
            (int) (
                $contact[
                    'applicant_id'
                ]
                ?? 0
            ),

        'gst_roll' =>
            $gstRoll,

        'email_available' =>
            $email !== '',

        'phone_available' =>
            $phone !== '',

        'email_sent' =>
            $emailSent,

        'sms_sent' =>
            $smsSent,

        'error' =>
            null
    ];
}


/*
|--------------------------------------------------------------------------
| Send all Migration notifications
|--------------------------------------------------------------------------
*/

function sendMigrationNotifications(
    PDO $pdo,
    array $moves,
    string $academicYear,
    ?int $meritCallId = null
): array {

    $finalMoves =
        migrationNotificationFinalMoves(
            $moves
        );


    $summary = [
        'candidate_count' =>
            count(
                $finalMoves
            ),

        'email_sent' =>
            0,

        'email_failed' =>
            0,

        'sms_sent' =>
            0,

        'sms_failed' =>
            0,

        'results' =>
            [],
    ];


    foreach ($finalMoves as $move) {

        try {

            $result =
                sendSingleMigrationNotification(
                    $pdo,
                    $move,
                    $academicYear,
                    $meritCallId
                );


            if (
                !empty(
                    $result[
                        'email_available'
                    ]
                )
            ) {

                if (
                    !empty(
                        $result[
                            'email_sent'
                        ]
                    )
                ) {
                    $summary[
                        'email_sent'
                    ]++;

                } else {
                    $summary[
                        'email_failed'
                    ]++;
                }
            }


            if (
                !empty(
                    $result[
                        'phone_available'
                    ]
                )
            ) {

                if (
                    !empty(
                        $result[
                            'sms_sent'
                        ]
                    )
                ) {
                    $summary[
                        'sms_sent'
                    ]++;

                } else {
                    $summary[
                        'sms_failed'
                    ]++;
                }
            }


            $summary[
                'results'
            ][] =
                $result;


        } catch (Throwable $exception) {

            /*
             * Never allow notification failure
             * to affect completed Migration.
             */

            error_log(
                'Migration notification error: '
                . $exception->getMessage()
            );


            $summary[
                'results'
            ][] = [
                'import_candidate_id' =>
                    (int) (
                        $move[
                            'import_candidate_id'
                        ]
                        ?? 0
                    ),

                'email_available' =>
                    false,

                'phone_available' =>
                    false,

                'email_sent' =>
                    false,

                'sms_sent' =>
                    false,

                'error' =>
                    $exception->getMessage()
            ];
        }
    }


    return $summary;
}

<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Merit Call Notification Helper
|--------------------------------------------------------------------------
|
| Intended project location:
|
|   includes/notifications/merit_call_notifications.php
|
| Before registration, imported GST phone is authoritative.
| Email is sent only when the candidate already has a registered email.
|--------------------------------------------------------------------------
*/

$smsHelper = __DIR__ . '/../sms/send_sms.php';
$mailHelper = __DIR__ . '/../mail/send_mail.php';

if (is_file($smsHelper)) {
    require_once $smsHelper;
}

if (is_file($mailHelper)) {
    require_once $mailHelper;
}


/*
|--------------------------------------------------------------------------
| HTML escape for email content
|--------------------------------------------------------------------------
*/

function meritNoticeEscape(mixed $value): string
{
    return htmlspecialchars(
        (string) ($value ?? ''),
        ENT_QUOTES,
        'UTF-8'
    );
}


/*
|--------------------------------------------------------------------------
| Format Bangladesh date/time
|--------------------------------------------------------------------------
*/

function meritNoticeDate(string $dateTime): string
{
    $timestamp = strtotime($dateTime);

    if ($timestamp === false) {
        return $dateTime;
    }

    return date(
        'd M Y, h:i A',
        $timestamp
    );
}


/*
|--------------------------------------------------------------------------
| Send one published Merit Call to all candidates assigned to that call
|--------------------------------------------------------------------------
|
| Allocated candidates receive:
| - Merit Call number/title
| - Allocated department
| - Department merit rank
| - Registration start
| - Registration deadline
| - Instruction to register before deadline
|
| Waiting candidates are NOT included because they are not yet eligible
| to register in this Merit Call.
|--------------------------------------------------------------------------
*/

function sendMeritCallNotifications(
    PDO $pdo,
    int $meritCallId
): array {

    if ($meritCallId <= 0) {
        throw new InvalidArgumentException(
            'Invalid Merit Call ID.'
        );
    }


    /*
    |--------------------------------------------------------------------------
    | Merit Call
    |--------------------------------------------------------------------------
    */

    $callStmt = $pdo->prepare("
        SELECT
            merit_call_id,
            call_number,
            call_title,
            registration_start,
            registration_deadline,
            published_at
        FROM merit_calls
        WHERE merit_call_id = ?
        LIMIT 1
    ");

    $callStmt->execute([
        $meritCallId
    ]);

    $call = $callStmt->fetch(
        PDO::FETCH_ASSOC
    );


    if (!$call) {
        throw new RuntimeException(
            'Merit Call was not found.'
        );
    }


    /*
    |--------------------------------------------------------------------------
    | Candidates in this Merit Call
    |--------------------------------------------------------------------------
    |
    | Phone:
    |   registered applicant phone -> imported GST phone fallback
    |
    | Email:
    |   registered applicant only, because imported GST table does not
    |   provide an email address in the current architecture.
    |--------------------------------------------------------------------------
    */

    $stmt = $pdo->prepare("
        SELECT
            ma.allocation_id,
            ma.import_candidate_id,
            ma.merit_rank,

            gic.gst_roll,
            gic.full_name,
            gic.phone AS imported_phone,

            ga.email,
            ga.phone AS applicant_phone,

            d.dept_name,
            d.dept_code

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

        WHERE ma.merit_call_id = ?
          AND ma.allocation_status = 'Allocated'

        ORDER BY
            ma.dept_id,
            ma.merit_rank
    ");

    $stmt->execute([
        $meritCallId
    ]);

    $candidates = $stmt->fetchAll(
        PDO::FETCH_ASSOC
    );


    $result = [
        'total' => count($candidates),

        'sms_sent' => 0,
        'sms_failed' => 0,
        'sms_skipped' => 0,

        'email_sent' => 0,
        'email_failed' => 0,
        'email_skipped' => 0,
    ];


    $callTitle =
        (string) $call['call_title'];

    $registrationStart =
        meritNoticeDate(
            (string)
            $call['registration_start']
        );

    $registrationDeadline =
        meritNoticeDate(
            (string)
            $call['registration_deadline']
        );


    foreach ($candidates as $candidate) {

        $fullName =
            trim(
                (string)
                $candidate['full_name']
            );

        $gstRoll =
            trim(
                (string)
                $candidate['gst_roll']
            );

        $departmentName =
            trim(
                (string)
                $candidate['dept_name']
            );

        $departmentLabel =
            trim(
                (string) (
                    $candidate['dept_code']
                    ?? ''
                )
            );

        if ($departmentLabel === '') {
            $departmentLabel =
                $departmentName;
        }


        $meritRank =
            (int)
            $candidate['merit_rank'];


        $phone =
            trim(
                (string) (
                    $candidate[
                        'applicant_phone'
                    ]
                    ?? ''
                )
            );

        if ($phone === '') {
            $phone =
                trim(
                    (string) (
                        $candidate[
                            'imported_phone'
                        ]
                        ?? ''
                    )
                );
        }


        $email =
            trim(
                (string) (
                    $candidate['email']
                    ?? ''
                )
            );


        /*
        |--------------------------------------------------------------------------
        | SMS
        |--------------------------------------------------------------------------
        */

        $smsMessage =
            'JKKNIU Admission: '
            . $gstRoll
            . ', you are selected in '
            . $callTitle
            . ' for '
            . $departmentLabel
            . ' (Merit Rank #'
            . $meritRank
            . '). Registration: '
            . $registrationStart
            . ' to '
            . $registrationDeadline
            . '. Complete registration before the deadline. Visit the JKKNIU admission homepage.';


        if (
            $phone === ''
            ||
            !function_exists('sendSMS')
        ) {

            $result['sms_skipped']++;

        } else {

            try {

                $sent = (bool) sendSMS(
                    $phone,
                    $smsMessage
                );

                if ($sent) {
                    $result['sms_sent']++;
                } else {
                    $result['sms_failed']++;
                }

            } catch (Throwable $exception) {

                error_log(
                    'Merit Call SMS failed for GST Roll '
                    . $gstRoll
                    . ': '
                    . $exception->getMessage()
                );

                $result['sms_failed']++;
            }
        }


        /*
        |--------------------------------------------------------------------------
        | Email
        |--------------------------------------------------------------------------
        |
        | Initial Merit Call candidates are normally not registered yet, so
        | most will have no applicant email. SMS remains the primary channel
        | before registration.
        |--------------------------------------------------------------------------
        */

        if (
            $email === ''
            ||
            !filter_var(
                $email,
                FILTER_VALIDATE_EMAIL
            )
            ||
            !function_exists('sendMail')
        ) {

            $result['email_skipped']++;

            continue;
        }


        $safeName =
            meritNoticeEscape(
                $fullName
            );

        $safeRoll =
            meritNoticeEscape(
                $gstRoll
            );

        $safeCall =
            meritNoticeEscape(
                $callTitle
            );

        $safeDepartment =
            meritNoticeEscape(
                $departmentName
            );

        $safeStart =
            meritNoticeEscape(
                $registrationStart
            );

        $safeDeadline =
            meritNoticeEscape(
                $registrationDeadline
            );


        $subject =
            'JKKNIU '
            . $callTitle
            . ' Selection & Registration Deadline';


        $body = "
            <div style=\"font-family:Arial,sans-serif;
                        max-width:650px;
                        margin:auto;
                        border:1px solid #dddddd;
                        border-radius:10px;
                        overflow:hidden;\">

                <div style=\"background:#198754;
                            color:#ffffff;
                            padding:22px;
                            text-align:center;\">

                    <h2 style=\"margin:0;\">
                        JKKNIU Admission Management System
                    </h2>

                    <div style=\"margin-top:6px;\">
                        {$safeCall}
                    </div>
                </div>


                <div style=\"padding:25px;\">

                    <p>
                        Dear <strong>{$safeName}</strong>,
                    </p>

                    <p>
                        Congratulations! You have been selected for admission
                        in the <strong>{$safeCall}</strong>.
                    </p>


                    <div style=\"background:#f5f7fa;
                                border-left:4px solid #198754;
                                padding:16px;
                                margin:18px 0;\">

                        <p style=\"margin:5px 0;\">
                            <strong>GST Roll:</strong>
                            {$safeRoll}
                        </p>

                        <p style=\"margin:5px 0;\">
                            <strong>Department:</strong>
                            {$safeDepartment}
                        </p>

                        <p style=\"margin:5px 0;\">
                            <strong>Department Merit Rank:</strong>
                            #{$meritRank}
                        </p>

                        <p style=\"margin:5px 0;\">
                            <strong>Registration Opens:</strong>
                            {$safeStart}
                        </p>

                        <p style=\"margin:5px 0;\">
                            <strong>Registration Deadline:</strong>
                            {$safeDeadline}
                        </p>

                    </div>


                    <p>
                        You must complete online registration within this
                        Merit Call's registration window. Failure to register
                        before the deadline may result in cancellation of
                        your allocation.
                    </p>


                    <p>
                        Visit the JKKNIU Admission Management System homepage
                        to view the live countdown and registration option.
                    </p>


                    <p style=\"margin-top:25px;\">
                        Regards,<br>
                        <strong>
                            JKKNIU Admission Authority
                        </strong>
                    </p>

                </div>
            </div>
        ";


        try {

            $sent = (bool) sendMail(
                $email,
                $subject,
                $body
            );

            if ($sent) {
                $result['email_sent']++;
            } else {
                $result['email_failed']++;
            }

        } catch (Throwable $exception) {

            error_log(
                'Merit Call email failed for GST Roll '
                . $gstRoll
                . ': '
                . $exception->getMessage()
            );

            $result['email_failed']++;
        }
    }


    return $result;
}
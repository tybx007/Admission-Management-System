<?php

declare(strict_types=1);

require_once __DIR__ . '/send_sms.php';
require_once __DIR__ . '/../mail/send_mail.php';


function vivaNoticeE(mixed $value): string
{
    return htmlspecialchars(
        (string) ($value ?? ''),
        ENT_QUOTES,
        'UTF-8'
    );
}


function vivaNoticeDate(string $date): string
{
    $timestamp = strtotime($date);

    return $timestamp !== false
        ? date('d M Y', $timestamp)
        : $date;
}


function vivaNoticeTime(string $time): string
{
    $timestamp = strtotime($time);

    return $timestamp !== false
        ? date('h:i A', $timestamp)
        : $time;
}


function sendVivaCallNotifications(
    PDO $pdo,
    int $meritCallId
): array {

    if ($meritCallId <= 0) {
        throw new InvalidArgumentException(
            'Invalid Merit Call ID.'
        );
    }


    $callStmt = $pdo->prepare("
        SELECT
            merit_call_id,
            call_title,
            viva_status,
            viva_date,
            viva_reporting_time,
            viva_instructions
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


    if ((string) $call['viva_status'] !== 'Open') {
        throw new RuntimeException(
            'Viva notification can be sent only for an Open Viva.'
        );
    }


    if (
        empty($call['viva_date'])
        ||
        empty($call['viva_reporting_time'])
    ) {
        throw new RuntimeException(
            'Viva date and reporting time are missing.'
        );
    }


    $candidateStmt = $pdo->prepare("
        SELECT
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

        INNER JOIN gst_applicants ga
            ON ga.applicant_id =
               gic.applicant_id

        WHERE ma.merit_call_id = ?
          AND ma.allocation_status = 'Allocated'
          AND gic.is_registered = 1
          AND gic.applicant_id IS NOT NULL

        ORDER BY
            ma.dept_id,
            ma.merit_rank
    ");

    $candidateStmt->execute([
        $meritCallId
    ]);

    $candidates = $candidateStmt->fetchAll(
        PDO::FETCH_ASSOC
    );


    $result = [
        'total' => count($candidates),
        'sms_sent' => 0,
        'sms_failed' => 0,
        'sms_skipped' => 0,
        'email_sent' => 0,
        'email_failed' => 0,
        'email_skipped' => 0
    ];


    $callTitle =
        trim(
            (string) $call['call_title']
        );

    $vivaDate =
        vivaNoticeDate(
            (string) $call['viva_date']
        );

    $reportingTime =
        vivaNoticeTime(
            (string) $call['viva_reporting_time']
        );

    $instructions =
        trim(
            (string) (
                $call['viva_instructions']
                ?? ''
            )
        );


    foreach ($candidates as $candidate) {

        $gstRoll =
            trim(
                (string) $candidate['gst_roll']
            );

        $fullName =
            trim(
                (string) $candidate['full_name']
            );

        $department =
            trim(
                (string) $candidate['dept_name']
            );

        $deptCode =
            trim(
                (string) (
                    $candidate['dept_code']
                    ?? ''
                )
            );

        $departmentLabel =
            $deptCode !== ''
                ? $deptCode
                : $department;


        $phone =
            trim(
                (string) (
                    $candidate['applicant_phone']
                    ?? ''
                )
            );

        if ($phone === '') {
            $phone =
                trim(
                    (string) (
                        $candidate['imported_phone']
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


        $smsMessage =
            'JKKNIU Admission: Viva for '
            . $callTitle
            . ' has been announced. GST Roll: '
            . $gstRoll
            . '. Department: '
            . $departmentLabel
            . '. Viva Date: '
            . $vivaDate
            . '. Reporting Time: '
            . $reportingTime
            . '.';

        if ($instructions !== '') {
            $smsMessage .=
                ' Instructions: '
                . preg_replace(
                    '/\s+/',
                    ' ',
                    $instructions
                )
                . '.';
        }

        $smsMessage .=
            ' Please attend on time.';


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
                    'Viva SMS failed for GST Roll '
                    . $gstRoll
                    . ': '
                    . $exception->getMessage()
                );

                $result['sms_failed']++;
            }
        }


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
            vivaNoticeE(
                $fullName
            );

        $safeRoll =
            vivaNoticeE(
                $gstRoll
            );

        $safeCall =
            vivaNoticeE(
                $callTitle
            );

        $safeDepartment =
            vivaNoticeE(
                $department
            );

        $safeVivaDate =
            vivaNoticeE(
                $vivaDate
            );

        $safeReportingTime =
            vivaNoticeE(
                $reportingTime
            );

        $safeInstructions =
            vivaNoticeE(
                $instructions
            );


        $instructionHtml =
            $safeInstructions !== ''
                ? nl2br(
                    $safeInstructions
                )
                : 'Follow the official JKKNIU admission instructions and bring the required documents.';


        $subject =
            'JKKNIU '
            . $callTitle
            . ' Viva Voce Notice';


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

                <div style=\"margin-top:7px;\">
                    {$safeCall} — Viva Voce Notice
                </div>
            </div>

            <div style=\"padding:25px;\">

                <p>
                    Dear <strong>{$safeName}</strong>,
                </p>

                <p>
                    Your Viva Voce schedule has been announced.
                </p>

                <div style=\"background:#f5f7fa;
                            border-left:4px solid #1a3c5e;
                            padding:16px;
                            margin:18px 0;\">

                    <p style=\"margin:6px 0;\">
                        <strong>GST Roll:</strong>
                        {$safeRoll}
                    </p>

                    <p style=\"margin:6px 0;\">
                        <strong>Allocated Department:</strong>
                        {$safeDepartment}
                    </p>

                    <p style=\"margin:6px 0;\">
                        <strong>Viva Date:</strong>
                        {$safeVivaDate}
                    </p>

                    <p style=\"margin:6px 0;\">
                        <strong>Reporting Start Time:</strong>
                        {$safeReportingTime}
                    </p>
                </div>

                <p>
                    <strong>Instructions</strong>
                </p>

                <div style=\"background:#fff3cd;
                            padding:14px;
                            border-radius:6px;\">
                    {$instructionHtml}
                </div>

                <p style=\"margin-top:20px;\">
                    Please attend the Viva on time.
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
                'Viva email failed for GST Roll '
                . $gstRoll
                . ': '
                . $exception->getMessage()
            );

            $result['email_failed']++;
        }
    }


    return $result;
}
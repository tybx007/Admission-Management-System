<?php

declare(strict_types=1);

require_once __DIR__ . '/send_sms.php';


function sendMeritAllocationSMS(PDO $pdo): array
{
    $result = [
        'sent' => 0,
        'failed' => 0,
        'skipped' => 0
    ];

    /*
    |--------------------------------------------------------------------------
    | Find allocated candidates who have not already received
    | this department allocation SMS
    |--------------------------------------------------------------------------
    */

    $stmt = $pdo->query("
        SELECT
            ma.import_candidate_id,
            ma.dept_id,

            gic.gst_roll,
            gic.full_name,
            gic.phone,

            d.dept_name,
            d.dept_code

        FROM merit_allocations ma

        INNER JOIN gst_imported_candidates gic
            ON gic.import_candidate_id =
               ma.import_candidate_id

        INNER JOIN departments d
            ON d.dept_id = ma.dept_id

        LEFT JOIN sms_notifications sn
            ON sn.import_candidate_id =
               ma.import_candidate_id

           AND sn.dept_id =
               ma.dept_id

           AND sn.notification_type =
               'Merit Allocation'

           AND sn.status = 'Sent'

        WHERE ma.allocation_status = 'Allocated'

          AND gic.phone IS NOT NULL

          AND TRIM(gic.phone) <> ''

          AND sn.notification_id IS NULL

        ORDER BY
            ma.merit_rank ASC
    ");


    $candidates = $stmt->fetchAll(
        PDO::FETCH_ASSOC
    );


    /*
    |--------------------------------------------------------------------------
    | Notification insert/update
    |--------------------------------------------------------------------------
    */

    $notificationStmt = $pdo->prepare("
        INSERT INTO sms_notifications (
            import_candidate_id,
            dept_id,
            notification_type,
            phone,
            message,
            status,
            attempt_count,
            sent_at
        )

        VALUES (
            ?,
            ?,
            'Merit Allocation',
            ?,
            ?,
            ?,
            1,
            ?
        )

        ON DUPLICATE KEY UPDATE

            phone = VALUES(phone),

            message = VALUES(message),

            status = VALUES(status),

            attempt_count =
                attempt_count + 1,

            sent_at = VALUES(sent_at)
    ");


    foreach ($candidates as $candidate) {

        $phone = trim(
            (string) $candidate['phone']
        );


        if ($phone === '') {

            $result['skipped']++;

            continue;
        }


        /*
        |--------------------------------------------------------------------------
        | Keep SMS short
        |--------------------------------------------------------------------------
        */

        $department =
            !empty($candidate['dept_code'])
                ? $candidate['dept_code']
                : $candidate['dept_name'];


        $message = sprintf(

            'JKKNIU Admission: GST Roll %s, '
            . 'you have been allocated to %s. '
            . 'Please complete your registration '
            . 'through the JKKNIU Admission Portal.',

            $candidate['gst_roll'],

            $department
        );


        /*
        |--------------------------------------------------------------------------
        | Demo SMS sender
        |--------------------------------------------------------------------------
        */

        $smsSender = 'sendSMS';

        $sent = is_callable($smsSender)
            && (bool) $smsSender(
                $phone,
                $message
            );


        if ($sent) {

            $result['sent']++;

            $status = 'Sent';

            $sentAt = date(
                'Y-m-d H:i:s'
            );

        } else {

            $result['failed']++;

            $status = 'Failed';

            $sentAt = null;
        }


        /*
        |--------------------------------------------------------------------------
        | Save notification result
        |--------------------------------------------------------------------------
        */

        $notificationStmt->execute([
            (int) $candidate[
                'import_candidate_id'
            ],

            (int) $candidate[
                'dept_id'
            ],

            $phone,

            $message,

            $status,

            $sentAt
        ]);
    }


    return $result;
}
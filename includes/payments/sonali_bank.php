<?php

declare(strict_types=1);

require_once __DIR__ . '/../../config/payment.php';
require_once __DIR__ . '/sslcommerz.php';

/*
|--------------------------------------------------------------------------
| Sonali Bank BillPay Integration
|--------------------------------------------------------------------------
|
| The university collects admission / semester / hall fees through the
| Sonali Bank bill-pay portal:
|
|   Payment page:  billpay.sonalibank.com.bd/JKKNIU/AdmissionFee/Honours
|   Status API:    billpay.sonalibank.com.bd/JKKNIU/AdmissionFee/GetAdmissionStudent
|
| Verification is PULL-based: we POST the student's GST Roll to the status
| endpoint and the bank replies with JSON. When Status === 'Y' the student
| has already paid. A single payment on the portal covers Department +
| Hall + Admission fees at once, so one confirmed Status='Y' marks all
| three steps as paid in our payment_transactions table.
|--------------------------------------------------------------------------
*/


/**
 * Human-safe cURL POST used by the Sonali Bank API helpers.
 */
function sonaliBankPost(
    string $url,
    array $data
): ?array {

    $ch = curl_init();

    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query($data),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/x-www-form-urlencoded',
        ],
    ]);

    $body = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);

    curl_close($ch);

    if ($body === false || $code < 200 || $code >= 300) {
        return null;
    }

    $decoded = json_decode($body, true);

    return is_array($decoded)
        ? $decoded
        : null;
}


/**
 * Ask Sonali Bank whether this GST Roll exists / has paid.
 *
 * Returns the decoded JSON array:
 *   {
 *     GST_Roll, Name, Mobile, DeptName, HallName,
 *     FeeAmount, DeptAmount, HallAmount, TotalAmount,
 *     Status: 'Y'|null
 *   }
 *
 * Returns null when the API is unreachable or did not reply with JSON.
 */
function sonaliBankCheckPayment(string $gstRoll): ?array
{
    $roll = strtoupper(trim($gstRoll));

    if ($roll === '') {
        return null;
    }

    return sonaliBankPost(
        SONALI_BANK_GET_STUDENT_URL,
        ['id' => $roll]
    );
}


/**
 * True when Sonali Bank reports the student has already paid.
 */
function sonaliBankIsPaid(string $gstRoll): bool
{
    $data = sonaliBankCheckPayment($gstRoll);

    return is_array($data)
        && isset($data['Status'])
        && (string) $data['Status'] === 'Y';
}


/**
 * The public Sonali Bank payment page for Honours Admission Fee.
 */
function sonaliBankPaymentUrl(): string
{
    return SONALI_BANK_ADMISSION_URL;
}


/**
 * Record a confirmed Sonali Bank payment for every step that is not yet
 * settled. Returns how many steps were newly marked as paid.
 *
 * A single Status='Y' on the bank side means ALL fees (department, hall
 * and admission) were paid together.
 */
function sonaliBankRecordPayments(
    PDO $pdo,
    int $applicantId,
    string $gstRoll
): int {
    if (!sonaliBankIsPaid($gstRoll)) {
        return 0;
    }

    $recorded = 0;

    foreach (PAYMENT_VALID_STEPS as $step) {
        if (paymentIsSettled($pdo, $applicantId, $step)) {
            continue;
        }

        $tx = paymentCreateTransaction($pdo, $applicantId, $step);

        paymentMarkStatus(
            $pdo,
            $tx['tran_id'],
            PAYMENT_STATUS_VERIFIED,
            [
                'card_type'     => 'Sonali Bank BillPay',
                'bank_trans_id' => $gstRoll,
                'gateway_response' => 'Verified via billpay.sonalibank.com.bd',
            ]
        );

        paymentSyncSubmission($pdo, $applicantId, $step, 'Sonali Bank');

        paymentLog(
            sprintf(
                '[SONALI BANK] applicant=%d gst_roll=%s step=%s VERIFIED',
                $applicantId,
                $gstRoll,
                $step
            )
        );

        $recorded++;
    }

    return $recorded;
}
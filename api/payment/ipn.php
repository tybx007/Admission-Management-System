<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| SSLCommerz IPN endpoint (server-to-server, no browser session required).
| In demo mode this only logs. In live/sandbox mode it verifies the
| transaction, persists the gateway reference and flips status to VERIFIED.
|--------------------------------------------------------------------------
*/

require_once '../../config/db.php';
require_once '../../includes/payments/sslcommerz.php';

$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$post = $_POST;

paymentLog(
    sprintf(
        "[IPN RECEIVED] tran_id=%s status=%s",
        (string) ($post['tran_id'] ?? ''),
        (string) ($post['status'] ?? '')
    )
);

if (PAYMENT_DEMO_MODE) {
    echo 'OK-DEMO';
    exit;
}

$tran_id =
    trim((string) ($post['tran_id'] ?? ''));

if ($tran_id === '') {
    http_response_code(400);
    echo 'Missing tran_id';
    exit;
}

$stmt = $pdo->prepare("
    SELECT *
    FROM payment_transactions
    WHERE tran_id = ?
    LIMIT 1
");

$stmt->execute([$tran_id]);

$transaction = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$transaction) {
    http_response_code(404);
    echo 'Transaction not found';
    exit;
}

if (
    (string) $transaction['status']
    === PAYMENT_STATUS_VERIFIED
) {
    echo 'OK-ALREADY';
    exit;
}

$gatewayStatus =
    (string) ($post['status'] ?? '');

$storeId =
    (string) ($post['store_id'] ?? '');

if (
    strcasecmp($gatewayStatus, 'VALID') !== 0
    ||
    strcasecmp($storeId, SSLCOMMERZ_STORE_ID) !== 0
) {

    paymentMarkStatus(
        $pdo,
        $tran_id,
        PAYMENT_STATUS_FAILED
    );

    echo 'OK-DECLINED';
    exit;
}

if (!sslIpnSignatureValid($post)) {

    paymentLog(
        sprintf(
            "[IPN SIGNATURE MISMATCH] tran_id=%s",
            $tran_id
        )
    );

    http_response_code(400);
    echo 'Hash validation failed';
    exit;
}

$expected = (float) $transaction['amount'];

$paid =
    (float) ($post['amount'] ?? 0);

$currency =
    (string) ($post['currency'] ?? 'BDT');

if (
    abs($paid - $expected) > 0.01
    ||
    strcasecmp($currency, 'BDT') !== 0
) {

    paymentMarkStatus(
        $pdo,
        $tran_id,
        PAYMENT_STATUS_FAILED,
        [
            'gateway_response' => json_encode($post),
        ]
    );

    echo 'OK-AMOUNT-MISMATCH';
    exit;
}

paymentMarkStatus(
    $pdo,
    $tran_id,
    PAYMENT_STATUS_VERIFIED,
    [
        'trx_id' =>
            (string) ($post['bank_tran_id'] ?? ''),

        'val_id' =>
            (string) ($post['val_id'] ?? ''),

        'card_type' =>
            (string) ($post['card_type'] ?? ''),

        'bank_trans_id' =>
            (string) ($post['bank_tran_id'] ?? ''),

        'gateway_response' =>
            json_encode($post),
    ]
);

paymentSyncSubmission(
    $pdo,
    (int) $transaction['applicant_id'],
    (string) $transaction['payment_step']
);

echo 'VALID';
exit;
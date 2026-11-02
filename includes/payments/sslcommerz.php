<?php

declare(strict_types=1);

require_once __DIR__ . '/../../config/payment.php';

$GLOBALS['PAYMENT_LAST_ERROR'] = '';

const PAYMENT_STEP_DEPT = 'dept';
const PAYMENT_STEP_HALL = 'hall';
const PAYMENT_STEP_ADMISSION = 'admission';

const PAYMENT_STATUS_INITIATED = 'INITIATED';
const PAYMENT_STATUS_SUCCESS = 'SUCCESS';
const PAYMENT_STATUS_FAILED = 'FAILED';
const PAYMENT_STATUS_CANCELED = 'CANCELED';
const PAYMENT_STATUS_VERIFIED = 'VERIFIED';

const PAYMENT_VALID_STEPS = [
    PAYMENT_STEP_DEPT,
    PAYMENT_STEP_HALL,
    PAYMENT_STEP_ADMISSION,
];

const PAYMENT_SETTLED_STATUSES = [
    PAYMENT_STATUS_SUCCESS,
    PAYMENT_STATUS_VERIFIED,
];


function paymentLastError(): string
{
    return (string) ($GLOBALS['PAYMENT_LAST_ERROR'] ?? '');
}


function paymentSetError(string $message): void
{
    $GLOBALS['PAYMENT_LAST_ERROR'] = $message;

    error_log(
        'Payment Error: '
        . $message
    );
}


function paymentStepLabel(string $step): string
{
    if (!in_array($step, PAYMENT_VALID_STEPS, true)) {
        throw new InvalidArgumentException(
            'Unknown payment step: ' . $step
        );
    }

    return match ($step) {
        PAYMENT_STEP_DEPT => 'Department Fee',
        PAYMENT_STEP_HALL => 'Hall Fee',
        PAYMENT_STEP_ADMISSION => 'Admission Fee',
    };
}


function paymentFeeAmount(string $step): float
{
    if (!in_array($step, PAYMENT_VALID_STEPS, true)) {
        throw new InvalidArgumentException(
            'Unknown payment step: ' . $step
        );
    }

    return match ($step) {
        PAYMENT_STEP_DEPT => (float) DEPT_FEE_AMOUNT,
        PAYMENT_STEP_HALL => (float) HALL_FEE_AMOUNT,
        PAYMENT_STEP_ADMISSION => (float) ADMISSION_FEE_AMOUNT,
    };
}


function paymentTranId(int $applicantId): string
{
    return sprintf(
        'PSL%08d%s%04X',
        $applicantId,
        date('YmdHis'),
        random_int(0, 0xFFFF)
    );
}


function paymentLog(string $message): void
{
    if ($message === '') {
        return;
    }

    try {
        $directory = dirname(PAYMENT_LOG_FILE);

        if (!is_dir($directory)) {
            if (
                !mkdir($directory, 0775, true)
                && !is_dir($directory)
            ) {
                throw new RuntimeException(
                    'Could not create payment log directory: '
                    . $directory
                );
            }
        }

        if (!is_writable($directory)) {
            throw new RuntimeException(
                'Payment log directory is not writable: '
                . $directory
            );
        }

        $entry = sprintf(
            "[%s] %s%s",
            date('Y-m-d H:i:s'),
            str_replace(["\r", "\n"], ' ', $message),
            PHP_EOL
        );

        $result = file_put_contents(
            PAYMENT_LOG_FILE,
            $entry,
            FILE_APPEND | LOCK_EX
        );

        if ($result === false) {
            throw new RuntimeException(
                'Could not write payment log file: '
                . PAYMENT_LOG_FILE
            );
        }

    } catch (Throwable $exception) {

        error_log(
            'Payment log error: '
            . $exception->getMessage()
        );
    }
}


function paymentCreateTransaction(
    PDO $pdo,
    int $applicantId,
    string $step
): array {

    if (!in_array($step, PAYMENT_VALID_STEPS, true)) {
        throw new InvalidArgumentException(
            'Unknown payment step: ' . $step
        );
    }

    if ($applicantId <= 0) {
        throw new RuntimeException(
            'Invalid applicant id for payment.'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Reuse an existing open (INITIATED) transaction for this step
    |--------------------------------------------------------------------------
    */

    $existing = paymentFetchLatest(
        $pdo,
        $applicantId,
        $step,
        PAYMENT_STATUS_INITIATED
    );

    if ($existing) {
        return $existing;
    }

    $tranId = paymentTranId($applicantId);
    $amount = paymentFeeAmount($step);

    $stmt = $pdo->prepare("
        INSERT INTO payment_transactions
        (
            tran_id,
            applicant_id,
            payment_step,
            amount,
            status,
            created_at,
            updated_at
        )
        VALUES (?,?,?,?,?, NOW(), NOW())
    ");

    $stmt->execute([
        $tranId,
        $applicantId,
        $step,
        $amount,
        PAYMENT_STATUS_INITIATED,
    ]);

    $stmt = $pdo->prepare("
        SELECT *
        FROM payment_transactions
        WHERE tran_id = ?
          AND applicant_id = ?
        LIMIT 1
    ");

    $stmt->execute([
        $tranId,
        $applicantId,
    ]);

    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        throw new RuntimeException(
            'Could not create payment transaction.'
        );
    }

    return $row;
}


function paymentFetchLatest(
    PDO $pdo,
    int $applicantId,
    string $step,
    array|string|null $status = null
): ?array {

    $params = [
        $applicantId,
        $step,
    ];

    $sql = "
        SELECT *
        FROM payment_transactions
        WHERE applicant_id = ?
          AND payment_step = ?
    ";

    if ($status !== null) {

        $statuses = is_array($status)
            ? array_values($status)
            : [$status];

        if ($statuses === []) {
            $sql .= " AND 1 = 0";
        } else {

            $sql .= " AND status IN ("
                . implode(
                    ',',
                    array_fill(0, count($statuses), '?')
                )
                . ")";

            foreach ($statuses as $statusValue) {
                $params[] = (string) $statusValue;
            }
        }
    }

    $sql .= " ORDER BY id DESC LIMIT 1";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return $row ?: null;
}


function paymentIsSettled(
    PDO $pdo,
    int $applicantId,
    string $step
): bool {

    return paymentFetchLatest(
        $pdo,
        $applicantId,
        $step,
        PAYMENT_SETTLED_STATUSES
    ) !== null;
}


function paymentMarkStatus(
    PDO $pdo,
    string $tranId,
    string $status,
    array $fields = []
): bool {

    $set = [
        'status = ?',
        'updated_at = NOW()',
    ];

    $params = [
        $status,
    ];

    if (in_array($status, PAYMENT_SETTLED_STATUSES, true)) {
        $set[] = 'paid_at = NOW()';
    }

    $allowed = [
        'trx_id',
        'val_id',
        'card_type',
        'bank_trans_id',
        'gateway_response',
    ];

    foreach ($fields as $key => $value) {

        if (!in_array($key, $allowed, true)) {
            continue;
        }

        $set[] = '`' . $key . '` = ?';

        $params[] = $value === null
            ? null
            : (string) $value;
    }

    $sql = "UPDATE payment_transactions SET "
        . implode(', ', $set)
        . " WHERE tran_id = ?";

    $params[] = $tranId;

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    paymentLog(
        sprintf(
            "[STATUS CHANGED] tran_id=%s -> %s",
            $tranId,
            $status
        )
    );

    return true;
}


function paymentCustomerInfo(
    PDO $pdo,
    int $applicantId,
    string $academicYear
): array {

    require_once __DIR__ . '/../student_biodata.php';

    $student = fetchStudentBiodata(
        $pdo,
        $applicantId,
        $academicYear
    );

    return [
        'cus_name' =>
            (string) (
                $student['full_name']
                ?? 'JKKNIU Applicant'
            ),

        'cus_email' =>
            (string) (
                $student['email']
                ?? 'applicant@jkkniu.edu.bd'
            ),

        'cus_phone' =>
            (string) (
                $student['phone']
                ?? '01700000000'
            ),

        'cus_add1' =>
            (string) (
                $student['present_address']
                ?? 'JKKNIU, Trishal, Mymensingh'
            ),
    ];
}


function sslGatewayBaseUrl(): string
{
    return SSLCOMMERZ_SANDBOX
        ? SSLCOMMERZ_URL_SANDBOX
        : SSLCOMMERZ_URL_LIVE;
}


function sslApiCall(string $path, array $payload): array
{
    $url = rtrim(sslGatewayBaseUrl(), '/')
        . '/'
        . ltrim($path, '/');

    if (!function_exists('curl_init')) {

        paymentSetError(
            'cURL extension is required for SSLCommerz.'
        );

        return [
            'status' => 'FAILED',
            'failedreason' => 'cURL extension is missing.',
        ];
    }

    $ch = curl_init($url);

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($payload),
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_CONNECTTIMEOUT => 10,
    ]);

    $response = curl_exec($ch);

    $curlError = curl_error($ch);
    $curlErrno = curl_errno($ch);

    curl_close($ch);

    if ($response === false) {

        paymentSetError(
            'SSLCommerz request failed (curl #'
            . $curlErrno
            . '): '
            . $curlError
        );

        return [
            'status' => 'FAILED',
            'failedreason' => 'Could not reach the payment gateway.',
        ];
    }

    $decoded = json_decode((string) $response, true);

    if (!is_array($decoded)) {

        paymentSetError(
            'SSLCommerz returned an invalid response.'
        );

        return [
            'status' => 'FAILED',
            'failedreason' => 'Invalid gateway response.',
        ];
    }

    return $decoded;
}


function sslInitiateSession(
    PDO $pdo,
    int $applicantId,
    string $step
): array {

    $txn = paymentCreateTransaction(
        $pdo,
        $applicantId,
        $step
    );

    $tranId = (string) $txn['tran_id'];
    $amount = (float) $txn['amount'];

    /*
    |--------------------------------------------------------------------------
    | Demo mode — simulate the gateway and record the intent.
    | In demo mode the student's browser is redirected straight to our success
    | page, so a site-relative URL is used instead of PAYMENT_SITE_BASE (which
    | only matters for live gateway callbacks and must not point at a
    | placeholder domain during testing).
    |--------------------------------------------------------------------------
    */

    if (PAYMENT_DEMO_MODE) {

        paymentLog(
            sprintf(
                "[DEMO SSLCOMMERZ] %s | step=%s | applicant_id=%d | tran_id=%s | amount=BDT %.2f",
                paymentStepLabel($step),
                $step,
                $applicantId,
                $tranId,
                $amount
            )
        );

        $siteBase = dirname(
            __DIR__,
            2
        );

        $docRoot =
            str_replace('\\', '/', (string) ($_SERVER['DOCUMENT_ROOT'] ?? ''));

        $sitePath =
            str_replace('\\', '/', $siteBase);

        $appUrlPath =
            ($docRoot !== '' && str_starts_with($sitePath, $docRoot))
                ? substr($sitePath, strlen($docRoot))
                : '/jkkniu-admission';

        return [
            'demo' => true,
            'tran_id' => $tranId,
            'redirect_url' =>
                $appUrlPath
                . '/api/payment/success.php?demo=1&tran_id='
                . rawurlencode($tranId)
                . '&step='
                . rawurlencode($step),
        ];

    }

    /*
    |--------------------------------------------------------------------------
    | Live / sandbox mode — create the SSLCommerz session
    |--------------------------------------------------------------------------
    */

    $academicYear = admissionSessionCurrentYear($pdo);

    $customer = paymentCustomerInfo(
        $pdo,
        $applicantId,
        $academicYear
    );

    $payload = [
        'store_id' => SSLCOMMERZ_STORE_ID,
        'store_passwd' => SSLCOMMERZ_STORE_PASSWD,
        'total_amount' => number_format($amount, 2, '.', ''),
        'currency' => 'BDT',
        'tran_id' => $tranId,
        'success_url' => PAYMENT_SITE_BASE . '/api/payment/success.php',
        'fail_url' => PAYMENT_SITE_BASE . '/api/payment/fail.php',
        'cancel_url' => PAYMENT_SITE_BASE . '/api/payment/cancel.php',
        'ipn_url' => PAYMENT_SITE_BASE . '/api/payment/ipn.php',
        'cus_name' => $customer['cus_name'],
        'cus_email' => $customer['cus_email'],
        'cus_phone' => $customer['cus_phone'],
        'cus_add1' => $customer['cus_add1'],
        'cus_city' => 'Mymensingh',
        'cus_country' => 'Bangladesh',
        'product_name' =>
            paymentStepLabel($step)
            . ' - JKKNIU Admission '
            . date('Y'),
        'product_category' => paymentStepLabel($step),
        'product_profile' => 'non-physical-goods',
        'shipping_method' => 'NO',
        'num_of_item' => 1,
        'value_a' => (string) $applicantId,
        'value_b' => $step,
    ];

    $result = sslApiCall(
        '/gwprocess/v4/api.php',
        $payload
    );

    paymentLog(
        sprintf(
            "[SSLCOMMERZ INIT] tran_id=%s | status=%s | failedreason=%s",
            $tranId,
            (string) ($result['status'] ?? 'UNKNOWN'),
            (string) ($result['failedreason'] ?? '')
        )
    );

    if (
        (string) ($result['status'] ?? '') === 'SUCCESS'
        &&
        !empty($result['GatewayPageURL'])
    ) {

        return [
            'demo' => false,
            'tran_id' => $tranId,
            'redirect_url' => (string) $result['GatewayPageURL'],
        ];
    }

    paymentMarkStatus(
        $pdo,
        $tranId,
        PAYMENT_STATUS_FAILED,
        [
            'gateway_response' => json_encode($result),
        ]
    );

    paymentSetError(
        'SSLCommerz could not start the payment session: '
        . (string) ($result['failedreason'] ?? 'Unknown error.')
    );

    throw new RuntimeException(paymentLastError());
}


/*
|--------------------------------------------------------------------------
| Validate a completed payment using the SSLCommerz validator API.
| Works only in live/sandbox mode; demo transactions are always trusted.
|--------------------------------------------------------------------------
*/

function sslValidateTransaction(
    PDO $pdo,
    string $tranId,
    string $valId,
    int $applicantId,
    string $step,
    float $expectedAmount,
    string $currency = 'BDT'
): bool {

    if (PAYMENT_DEMO_MODE) {
        return true;
    }

    $result = sslApiCall(
        '/validator/api/validationserverAPI.php',
        [
            'val_id' => $valId,
            'store_id' => SSLCOMMERZ_STORE_ID,
            'store_passwd' => SSLCOMMERZ_STORE_PASSWD,
        ]
    );

    $gatewayStatus = (string) ($result['status'] ?? '');

    paymentLog(
        sprintf(
            "[SSLCOMMERZ VALIDATE] tran_id=%s val_id=%s gateway_status=%s amount=%s currency=%s",
            $tranId,
            $valId,
            $gatewayStatus,
            (string) ($result['amount'] ?? ''),
            (string) ($result['currency'] ?? '')
        )
    );

    if ($gatewayStatus !== 'VALID') {

        paymentMarkStatus(
            $pdo,
            $tranId,
            PAYMENT_STATUS_FAILED,
            [
                'val_id' => $valId,
                'gateway_response' => json_encode($result),
            ]
        );

        paymentSetError(
            'Payment validation failed at the gateway.'
        );

        return false;
    }

    $gatewayAmount =
        (float) ($result['amount'] ?? 0);

    $gatewayCurrency =
        (string) ($result['currency'] ?? $currency);

    if (
        abs($gatewayAmount - $expectedAmount) > 0.01
        ||
        strcasecmp($gatewayCurrency, $currency) !== 0
    ) {

        paymentMarkStatus(
            $pdo,
            $tranId,
            PAYMENT_STATUS_FAILED,
            [
                'val_id' => $valId,
                'gateway_response' => json_encode($result),
            ]
        );

        paymentSetError(
            'Payment amount/currency mismatch.'
        );

        return false;
    }

    paymentMarkStatus(
        $pdo,
        $tranId,
        PAYMENT_STATUS_VERIFIED,
        [
            'trx_id' => (string) ($result['bank_tran_id'] ?? ''),
            'val_id' => $valId,
            'card_type' => (string) ($result['card_type'] ?? ''),
            'bank_trans_id' => (string) ($result['bank_tran_id'] ?? ''),
            'gateway_response' => json_encode($result),
        ]
    );

    return true;
}


/*
|--------------------------------------------------------------------------
| Keep the step submission records in sync once a payment settles.
| Safety net: the student forms already write payment_method
| = 'SSLCommerz' at submit time; this just guarantees it.
|--------------------------------------------------------------------------
*/

function paymentStepTable(string $step): string
{
    return match ($step) {
        PAYMENT_STEP_DEPT => 'dept_submissions',
        PAYMENT_STEP_HALL => 'hall_submissions',
        PAYMENT_STEP_ADMISSION => 'central_admissions',
        default => throw new InvalidArgumentException(
            'Unknown payment step: ' . $step
        ),
    };
}


function paymentSyncSubmission(
    PDO $pdo,
    int $applicantId,
    string $step,
    string $method = 'SSLCommerz'
): void {

    $table = paymentStepTable($step);

    $stmt = $pdo->prepare(
        "UPDATE {$table}
         SET payment_method = ?
         WHERE applicant_id = ?
           AND (
               payment_method IS NULL
               OR payment_method = ''
           )"
    );

    $stmt->execute([
        $method,
        $applicantId,
    ]);
}


/*
|--------------------------------------------------------------------------
| SSLCommerz IPN reverse-hash signature check.
|--------------------------------------------------------------------------
*/

function sslIpnSignatureValid(array $post): bool
{
    $storePassword = SSLCOMMERZ_STORE_PASSWD;

    $validationKey =
        (string) ($post['verify_key'] ?? '');

    if ($validationKey === '') {
        return false;
    }

    $validateHash = '';

    foreach (explode(',', $validationKey) as $key) {

        if (
            $key === ''
            ||
            !array_key_exists($key, $post)
        ) {
            continue;
        }

        $validateHash .= $key
            . '='
            . $post[$key]
            . '&';
    }

    $validateHash .= 'store_passwd=' . $storePassword;

    $calculated =
        strtoupper(md5($validateHash));

    $receivedHash =
        strtoupper(
            (string) ($post['verify_sign'] ?? '')
        );

    return hash_equals($receivedHash, $calculated);
}


/*
|--------------------------------------------------------------------------
| Helpers for admin / student views
|--------------------------------------------------------------------------
*/

function paymentBadgeHtml(?array $tx): string
{
    if (!$tx) {
        return '<span class="badge bg-secondary">Unpaid</span>';
    }

    $status = (string) ($tx['status'] ?? '');

    $map = [
        PAYMENT_STATUS_SUCCESS => ['bg-success', 'Paid'],
        PAYMENT_STATUS_VERIFIED => ['bg-success', 'Paid (Verified)'],
        PAYMENT_STATUS_INITIATED => ['bg-warning text-dark', 'Pending'],
        PAYMENT_STATUS_FAILED => ['bg-danger', 'Failed'],
        PAYMENT_STATUS_CANCELED => ['bg-secondary', 'Cancelled'],
    ];

    $label =
        $map[$status] ?? [
            'bg-secondary',
            ucfirst(strtolower($status)),
        ];

    $html = '<span class="badge '
        . $label[0]
        . '">'
        . htmlspecialchars($label[1], ENT_QUOTES, 'UTF-8')
        . '</span>';

    if (!empty($tx['trx_id'])) {
        $html .= ' <small class="text-muted">'
            . 'TRX: '
            . htmlspecialchars(
                (string) $tx['trx_id'],
                ENT_QUOTES,
                'UTF-8'
            )
            . '</small>';
    }

    if (!empty($tx['paid_at'])) {
        $html .= ' <small class="text-muted">'
            . date(
                'd M Y, h:i A',
                strtotime((string) $tx['paid_at'])
            )
            . '</small>';
    }

    return $html;
}
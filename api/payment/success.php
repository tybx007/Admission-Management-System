<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| SSLCommerz payment SUCCESS return URL.
| In demo mode this page is hit via a simulated redirect.
| In live/sandbox mode SSLCommerz redirects the customer here with
| tran_id / val_id / status after a successful payment.
|--------------------------------------------------------------------------
*/

require_once '../../includes/auth.php';
require_once '../../config/db.php';
require_once '../../includes/admission_session.php';
require_once '../../includes/payments/sslcommerz.php';

$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$tran_id = trim((string) ($_REQUEST['tran_id'] ?? ''));
$is_demo = isset($_GET['demo']);

$transaction = null;
$errorMessage = '';

if ($tran_id === '') {
    $errorMessage = 'No payment reference was returned.';
} else {

    $stmt = $pdo->prepare("
        SELECT *
        FROM payment_transactions
        WHERE tran_id = ?
        LIMIT 1
    ");

    $stmt->execute([$tran_id]);

    $transaction = $stmt->fetch(PDO::FETCH_ASSOC);
}

if (!$transaction) {
    $errorMessage = 'Payment transaction not found.';
} else {

    $applicantId =
        (int) $transaction['applicant_id'];

    $step =
        (string) $transaction['payment_step'];

    if ($is_demo) {

        if (
            !in_array(
                (string) $transaction['status'],
                PAYMENT_SETTLED_STATUSES,
                true
            )
        ) {

            paymentMarkStatus(
                $pdo,
                $tran_id,
                PAYMENT_STATUS_SUCCESS,
                [
                    'trx_id' => 'DEMO-TRX-' . $tran_id,
                    'val_id' => 'DEMO-VAL-' . $tran_id,
                ]
            );

            paymentLog(
                sprintf(
                    "[DEMO SUCCESS] applicant_id=%d step=%s tran_id=%s",
                    $applicantId,
                    $step,
                    $tran_id
                )
            );
        }

    } else {

        $val_id =
            trim((string) ($_GET['val_id'] ?? ''));

        $gatewayStatus =
            trim((string) ($_GET['status'] ?? ''));

        if (
            $val_id === ''
            ||
            strcasecmp($gatewayStatus, 'VALID') !== 0
        ) {

            paymentMarkStatus(
                $pdo,
                $tran_id,
                PAYMENT_STATUS_FAILED
            );

            $errorMessage =
                'Payment was not completed. Please try again.';

        } else {

            $validated = sslValidateTransaction(
                $pdo,
                $tran_id,
                $val_id,
                $applicantId,
                $step,
                (float) $transaction['amount']
            );

            if (!$validated) {
                $errorMessage = paymentLastError();
            } else {
                paymentSyncSubmission(
                    $pdo,
                    $applicantId,
                    $step
                );
            }
        }
    }
}

$stepPages = [
    PAYMENT_STEP_DEPT => '/jkkniu-admission/student/step1_dept.php',
    PAYMENT_STEP_HALL => '/jkkniu-admission/student/step2_hall.php',
    PAYMENT_STEP_ADMISSION => '/jkkniu-admission/student/step3_registrar.php',
];

$backStep = '/jkkniu-admission/student/dashboard.php';

if ($transaction) {
    $backStep =
        $stepPages[(string) $transaction['payment_step']]
        ?? $backStep;
}

$pageTitle = 'Payment Status';
require_once '../../includes/header.php';
?>

<div class="container py-5">

    <div class="row justify-content-center">

        <div class="col-md-7">

            <div class="card shadow-lg border-0">

                <?php if ($errorMessage): ?>

                    <div class="card-body p-5 text-center">

                        <div class="display-4 text-danger mb-3">
                            <i class="bi bi-x-circle-fill"></i>
                        </div>

                        <h4 class="fw-bold mb-2">
                            Payment Not Successful
                        </h4>

                        <p class="text-muted">
                            <?= htmlspecialchars($errorMessage, ENT_QUOTES, 'UTF-8') ?>
                        </p>

                        <a href="<?= $backStep ?>" class="btn btn-primary btn-lg px-4">
                            <i class="bi bi-arrow-left"></i>
                            Back
                        </a>

                    </div>

                <?php else: ?>

                    <div class="card-body p-5 text-center">

                        <div class="display-4 text-success mb-3">
                            <i class="bi bi-check-circle-fill"></i>
                        </div>

                        <h4 class="fw-bold mb-2">
                            Payment Successful
                        </h4>

                        <p class="text-muted">
                            <?= htmlspecialchars(
                                paymentStepLabel((string) $transaction['payment_step']),
                                ENT_QUOTES,
                                'UTF-8'
                            ) ?>
                            has been paid.
                        </p>

                        <?php if ($is_demo): ?>
                            <div class="alert alert-warning small">
                                <i class="bi bi-info-circle"></i>
                                Demo mode — no real money was charged.
                            </div>
                        <?php endif; ?>

                        <div class="border rounded p-3 bg-light mb-4 text-start small">
                            <div class="d-flex justify-content-between">
                                <span class="text-muted">Transaction</span>
                                <strong><?= htmlspecialchars((string) $transaction['tran_id']) ?></strong>
                            </div>
                            <div class="d-flex justify-content-between">
                                <span class="text-muted">Amount</span>
                                <strong>
                                    ৳<?= number_format((float) $transaction['amount'], 2) ?>
                                </strong>
                            </div>
                            <div class="d-flex justify-content-between">
                                <span class="text-muted">Gateway</span>
                                <strong><?= htmlspecialchars((string) $transaction['gateway']) ?></strong>
                            </div>
                            <?php if (!empty($transaction['trx_id'])): ?>
                                <div class="d-flex justify-content-between">
                                    <span class="text-muted">Gateway TRX</span>
                                    <strong><?= htmlspecialchars((string) $transaction['trx_id']) ?></strong>
                                </div>
                            <?php endif; ?>
                            <?php if (!empty($transaction['paid_at'])): ?>
                                <div class="d-flex justify-content-between">
                                    <span class="text-muted">Paid At</span>
                                    <strong>
                                        <?= date(
                                            'd M Y, h:i A',
                                            strtotime((string) $transaction['paid_at'])
                                        ) ?>
                                    </strong>
                                </div>
                            <?php endif; ?>
                        </div>

                        <a href="<?= $backStep ?>" class="btn btn-success btn-lg px-4">
                            Continue Admission <i class="bi bi-arrow-right"></i>
                        </a>

                    </div>

                <?php endif; ?>

            </div>

        </div>

    </div>

</div>

<?php require_once '../../includes/footer.php'; ?>
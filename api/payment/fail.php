<?php

declare(strict_types=1);

require_once '../../config/db.php';
require_once '../../includes/admission_session.php';
require_once '../../includes/payments/sslcommerz.php';

$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$tran_id = trim((string) ($_REQUEST['tran_id'] ?? ''));

$step = PAYMENT_STEP_DEPT;
$backStep = '/jkkniu-admission/student/dashboard.php';

$stmt = $pdo->prepare("
    SELECT payment_step
    FROM payment_transactions
    WHERE tran_id = ?
    LIMIT 1
");

$stmt->execute([$tran_id]);

$row = $stmt->fetch(PDO::FETCH_ASSOC);

if ($row) {
    $step = (string) $row['payment_step'];
    $backStep =
        [
            PAYMENT_STEP_DEPT => '/jkkniu-admission/student/step1_dept.php',
            PAYMENT_STEP_HALL => '/jkkniu-admission/student/step2_hall.php',
            PAYMENT_STEP_ADMISSION => '/jkkniu-admission/student/step3_registrar.php',
        ][$step] ?? '/jkkniu-admission/student/dashboard.php';

    paymentMarkStatus(
        $pdo,
        $tran_id,
        PAYMENT_STATUS_FAILED
    );

    paymentLog(
        sprintf(
            "[FAILED] tran_id=%s",
            $tran_id
        )
    );
}

$pageTitle = 'Payment Failed';
require_once '../../includes/header.php';
?>

<div class="container py-5">

    <div class="row justify-content-center">

        <div class="col-md-6">

            <div class="card shadow-lg border-0">

                <div class="card-body p-5 text-center">

                    <div class="display-4 text-danger mb-3">
                        <i class="bi bi-x-circle-fill"></i>
                    </div>

                    <h4 class="fw-bold mb-2">Payment Failed</h4>

                    <p class="text-muted">
                        Your payment did not go through. No money has been deducted.
                        You may try again.
                    </p>

                    <a href="<?= $backStep ?>" class="btn btn-primary btn-lg px-4">
                        <i class="bi bi-arrow-left"></i>
                        Try Again
                    </a>

                </div>

            </div>

        </div>

    </div>

</div>

<?php require_once '../../includes/footer.php'; ?>
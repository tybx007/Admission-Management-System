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
    $backStep =
        [
            PAYMENT_STEP_DEPT => '/jkkniu-admission/student/step1_dept.php',
            PAYMENT_STEP_HALL => '/jkkniu-admission/student/step2_hall.php',
            PAYMENT_STEP_ADMISSION => '/jkkniu-admission/student/step3_registrar.php',
        ][(string) $row['payment_step']] ?? '/jkkniu-admission/student/dashboard.php';

    paymentMarkStatus(
        $pdo,
        $tran_id,
        PAYMENT_STATUS_CANCELED
    );

    paymentLog(
        sprintf(
            "[CANCELED] tran_id=%s",
            $tran_id
        )
    );
}

header('Location: ' . $backStep);
exit;
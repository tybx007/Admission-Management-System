<?php

declare(strict_types=1);

$pageTitle = 'Payment Initiation';

require_once '../../includes/auth.php';
require_once '../../config/db.php';
require_once '../../includes/admission_session.php';
require_once '../../includes/payments/sslcommerz.php';

requireLogin('student');

$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$applicant_id = (int) ($_SESSION['user_id'] ?? 0);

$step =
    trim((string) ($_POST['step'] ?? ''));

if ($step === '') {
    $step = trim((string) ($_GET['step'] ?? ''));
}

$stepPages = [
    PAYMENT_STEP_DEPT => '/jkkniu-admission/student/step1_dept.php',
    PAYMENT_STEP_HALL => '/jkkniu-admission/student/step2_hall.php',
    PAYMENT_STEP_ADMISSION => '/jkkniu-admission/student/step3_registrar.php',
];

if (!in_array($step, PAYMENT_VALID_STEPS, true)) {
    header('Location: /jkkniu-admission/student/dashboard.php');
    exit;
}

try {

    $result = sslInitiateSession(
        $pdo,
        $applicant_id,
        $step
    );

    if (!empty($result['redirect_url'])) {
        header('Location: ' . $result['redirect_url']);
        exit;
    }

    throw new RuntimeException(
        'Payment gateway did not return a redirect URL.'
    );

} catch (Throwable $e) {

    paymentLog(
        sprintf(
            "[INITIATE ERROR] applicant_id=%d step=%s error=%s",
            $applicant_id,
            $step,
            $e->getMessage()
        )
    );

    $redirect = $stepPages[$step];

    header(
        'Location: '
        . $redirect
        . '?pay_error='
        . rawurlencode(
            $e->getMessage()
        )
    );

    exit;
}
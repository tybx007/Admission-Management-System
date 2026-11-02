<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/payments/sonali_bank.php';

requireLogin('student');

$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$applicantId = (int) ($_SESSION['user_id'] ?? 0);
$step        = trim((string) ($_GET['step'] ?? ''));

if ($applicantId <= 0) {
    header('Location: /jkkniu-admission/student/login.php');
    exit;
}

if (!in_array($step, PAYMENT_VALID_STEPS, true)) {
    header('Location: /jkkniu-admission/student/dashboard.php');
    exit;
}

/*
|--------------------------------------------------------------------------
| Look up the student's GST Roll from gst_applicants
|--------------------------------------------------------------------------
*/
$stmt = $pdo->prepare("
    SELECT gst_roll
    FROM gst_applicants
    WHERE applicant_id = ?
    LIMIT 1
");
$stmt->execute([$applicantId]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$row || trim((string) ($row['gst_roll'] ?? '')) === '') {
    $redirect = stepToPage($step) . '?pay_error=' . urlencode(
        'GST Roll not found for this account.'
    );
    header('Location: ' . $redirect);
    exit;
}

$gstRoll = strtoupper(trim((string) $row['gst_roll']));

/*
|--------------------------------------------------------------------------
| Check the Sonali Bank portal
|--------------------------------------------------------------------------
*/
$bankData = sonaliBankCheckPayment($gstRoll);

if ($bankData === null) {
    $redirect = stepToPage($step) . '?pay_error=' . urlencode(
        'Could not reach Sonali Bank portal. Please try again in a moment.'
    );
    header('Location: ' . $redirect);
    exit;
}

if (!isset($bankData['Status']) || (string) $bankData['Status'] !== 'Y') {
    $redirect = stepToPage($step) . '?pay_error=' . urlencode(
        'Payment not found on Sonali Bank portal. '
        . 'Please complete the payment first and try again.'
    );
    header('Location: ' . $redirect);
    exit;
}

/*
|--------------------------------------------------------------------------
| Payment confirmed — record transactions for all unsettled steps
|--------------------------------------------------------------------------
*/
$recorded = sonaliBankRecordPayments($pdo, $applicantId, $gstRoll);

if ($recorded > 0) {
    $redirect = stepToPage($step)
        . '?pay_ok=' . urlencode(
            "Payment verified! {$recorded} fee(s) marked as paid."
        );
} else {
    $redirect = stepToPage($step)
        . '?pay_ok=' . urlencode(
            'All fees were already recorded. Nothing to update.'
        );
}

header('Location: ' . $redirect);
exit;


/*
|--------------------------------------------------------------------------
| Map step keys back to student step page URLs
|--------------------------------------------------------------------------
*/
function stepToPage(string $step): string
{
    return match ($step) {
        'dept'      => '/jkkniu-admission/student/step1_dept.php',
        'hall'      => '/jkkniu-admission/student/step2_hall.php',
        'admission' => '/jkkniu-admission/student/step3_registrar.php',
        default     => '/jkkniu-admission/student/dashboard.php',
    };
}
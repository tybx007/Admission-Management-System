<?php
require_once '../includes/auth.php';
require_once '../config/db.php';
require_once '../includes/admission_engine.php';

requireLogin('dept_admin');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: viva_manage.php');
    exit;
}

$applicantId = (int) ($_POST['applicant_id'] ?? 0);
$vivaStatus = $_POST['viva_status'] ?? '';
$facultyId = (int) ($_POST['faculty_id'] ?? 0);
$sessionDeptId = (int) ($_SESSION['dept_id'] ?? 0);

if (
    $applicantId <= 0
    || $facultyId <= 0
    || $sessionDeptId <= 0
    || !in_array($vivaStatus, ['Selected', 'Not Selected', 'Absent'], true)
) {
    $_SESSION['flash'] = ['type' => 'danger', 'msg' => 'Invalid viva request.'];
    header('Location: viva_manage.php');
    exit;
}

try {
    $pdo->beginTransaction();

    $allocationStmt = $pdo->prepare("\n        SELECT allocation_id\n        FROM merit_allocations\n        WHERE applicant_id = ?\n          AND dept_id = ?\n          AND allocation_status = 'Allocated'\n        ORDER BY allocation_id DESC\n        LIMIT 1\n        FOR UPDATE\n    ");
    $allocationStmt->execute([$applicantId, $sessionDeptId]);
    $allocationId = $allocationStmt->fetchColumn();

    if (!$allocationId) {
        throw new RuntimeException('No active allocation exists for this department.');
    }

    $pdo->prepare("\n        INSERT INTO viva_results\n            (applicant_id, faculty_id, viva_status)\n        VALUES\n            (?, ?, ?)\n        ON DUPLICATE KEY UPDATE\n            faculty_id = VALUES(faculty_id),\n            viva_status = VALUES(viva_status),\n            conducted_at = NOW()\n    ")->execute([$applicantId, $facultyId, $vivaStatus]);

    $promoted = null;

    if ($vivaStatus === 'Selected') {
        $pdo->prepare("
            INSERT INTO admission_progress (applicant_id)
            VALUES (?)
            ON DUPLICATE KEY UPDATE applicant_id = VALUES(applicant_id)
        ")->execute([$applicantId]);
    }

    if (in_array($vivaStatus, ['Not Selected', 'Absent'], true)) {
        $promoted = vacateAllocationAndPromote(
            $pdo,
            (int) $allocationId,
            'Rejected'
        );
    }

    $pdo->commit();

    if ($vivaStatus === 'Selected') {
        $message = 'Viva result saved. The candidate may continue the admission process.';
    } elseif ($promoted) {
        $message = 'Candidate removed and the next waiting candidate was called.';
    } else {
        $message = 'Candidate removed. No waiting candidate is available for the vacancy.';
    }

    $_SESSION['flash'] = ['type' => 'success', 'msg' => $message];
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    $_SESSION['flash'] = ['type' => 'danger', 'msg' => $e->getMessage()];
}

header('Location: viva_manage.php');
exit;
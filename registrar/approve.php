<?php
require_once '../includes/auth.php';
require_once '../config/db.php';
require_once '../includes/admission_engine.php';

requireLogin('registrar');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: dashboard.php');
    exit;
}

$applicantId = (int) ($_GET['applicant_id'] ?? 0);
$action = $_POST['action'] ?? '';
$remarks = trim((string) ($_POST['remarks'] ?? ''));
$approvedBy = (int) ($_SESSION['userid'] ?? $_SESSION['user_id'] ?? 0);

if ($applicantId <= 0 || !in_array($action, ['approve', 'reject'], true)) {
    $_SESSION['flash'] = ['type' => 'danger', 'msg' => 'Invalid approval request.'];
    header('Location: dashboard.php');
    exit;
}

if ($action === 'reject' && $remarks === '') {
    $_SESSION['flash'] = ['type' => 'danger', 'msg' => 'A rejection reason is required.'];
    header('Location: approvals.php?applicant_id=' . $applicantId);
    exit;
}

try {
    $pdo->beginTransaction();

    $approvalStmt = $pdo->prepare("\n        SELECT approval_status\n        FROM registrar_approvals\n        WHERE applicant_id = ?\n        LIMIT 1\n        FOR UPDATE\n    ");
    $approvalStmt->execute([$applicantId]);
    $currentStatus = $approvalStmt->fetchColumn();

    if (!$currentStatus) {
        throw new RuntimeException('Approval row not found.');
    }

    if (in_array($currentStatus, ['Approved', 'Rejected'], true)) {
        throw new RuntimeException('This admission decision is already final.');
    }

    $newStatus = $action === 'approve' ? 'Approved' : 'Rejected';

    $pdo->prepare("\n        UPDATE registrar_approvals\n        SET approval_status = ?,\n            remarks = ?,\n            approved_at = NOW(),\n            approved_by = ?\n        WHERE applicant_id = ?\n    ")->execute([
        $newStatus,
        $remarks,
        $approvedBy,
        $applicantId,
    ]);

    $promoted = null;

    if ($action === 'approve') {
        markApplicantFinallyAdmitted($pdo, $applicantId);
    } else {
        $allocationStmt = $pdo->prepare("\n            SELECT allocation_id\n            FROM merit_allocations\n            WHERE applicant_id = ?\n              AND allocation_status = 'Allocated'\n            ORDER BY allocation_id DESC\n            LIMIT 1\n            FOR UPDATE\n        ");
        $allocationStmt->execute([$applicantId]);
        $allocationId = $allocationStmt->fetchColumn();

        if (!$allocationId) {
            throw new RuntimeException('No active allocation was found for the rejected applicant.');
        }

        $promoted = vacateAllocationAndPromote(
            $pdo,
            (int) $allocationId,
            'Rejected',
            true
        );
    }

    $pdo->commit();

    if ($action === 'approve') {
        $message = 'Admission approved. The department seat is now finally filled.';
        $type = 'success';
    } elseif ($promoted) {
        $message = 'Admission rejected. The next waiting candidate was called automatically.';
        $type = 'warning';
    } else {
        $message = 'Admission rejected. The seat is vacant because no waiting candidate is available.';
        $type = 'warning';
    }

    $_SESSION['flash'] = ['type' => $type, 'msg' => $message];
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    $_SESSION['flash'] = ['type' => 'danger', 'msg' => $e->getMessage()];
}

header('Location: approvals.php?applicant_id=' . $applicantId);
exit;
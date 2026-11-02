<?php
$pageTitle = "Verify Submission";
require_once '../includes/auth.php';
require_once '../config/db.php';
require_once '../includes/admission_session.php';
require_once '../includes/payments/sslcommerz.php';

require_once '../includes/header.php';
require_once '../includes/navbar.php';

requireLogin('dept_admin');
$sub_id = (int)($_GET['sub_id'] ?? 0);

if (!$sub_id) {
    header('Location: submissions.php');
    exit;
}

// Fetch submission + student data
$stmt = $pdo->prepare("
    SELECT ds.*, ga.*, ma.merit_rank
    FROM dept_submissions ds
    JOIN gst_applicants ga ON ds.applicant_id = ga.applicant_id
    LEFT JOIN merit_allocations ma ON ga.applicant_id = ma.applicant_id
    WHERE ds.dept_sub_id = ?
");
$stmt->execute([$sub_id]);
$submission = $stmt->fetch();

if (!$submission || $submission['dept_id'] != $_SESSION['dept_id']) {
    header('Location: submissions.php');
    exit;
}

// Payment lookups
$deptPayment = paymentIsSettled($pdo, (int)$submission['applicant_id'], 'dept')
    ? paymentFetchLatest($pdo, (int)$submission['applicant_id'], 'dept')
    : null;
$hallPayment = paymentIsSettled($pdo, (int)$submission['applicant_id'], 'hall')
    ? paymentFetchLatest($pdo, (int)$submission['applicant_id'], 'hall')
    : null;

// Handle approve/reject
$message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $allowed_status = ['Verified', 'Rejected'];
    $status = $_POST['status'] ?? '';
    $remarks = trim($_POST['remarks'] ?? '');

    if (in_array($status, $allowed_status)) {
        if ($remarks) {
            $stmt = $pdo->prepare("
                UPDATE dept_submissions 
                SET submission_status = ?, verified_at = NOW(), remarks = ?
                WHERE dept_sub_id = ?
            ");
            $stmt->execute([$status, $remarks, $sub_id]);
        } else {
            $stmt = $pdo->prepare("
                UPDATE dept_submissions 
                SET submission_status = ?, verified_at = NOW()
                WHERE dept_sub_id = ?
            ");
            $stmt->execute([$status, $sub_id]);
        }
        $message = "Submission has been <strong>$status</strong>" . ($remarks ? " with remarks: $remarks" : "");
        // Refresh submission data
        $stmt = $pdo->prepare("
            SELECT ds.*, ga.*, ma.merit_rank
            FROM dept_submissions ds
            JOIN gst_applicants ga ON ds.applicant_id = ga.applicant_id
            LEFT JOIN merit_allocations ma ON ga.applicant_id = ma.applicant_id
            WHERE ds.dept_sub_id = ?
        ");
        $stmt->execute([$sub_id]);
        $submission = $stmt->fetch();
    } else {
        $message = "<strong class='text-danger'>Invalid status selected!</strong>";
    }
}

// Helper for badge color
function statusBadge($status) {
    return match($status) {
        'Verified' => 'bg-success',
        'Rejected' => 'bg-danger',
        default => 'bg-secondary'
    };
}
?>

<div class="container py-4">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb">
            <li class="breadcrumb-item"><a href="dashboard.php">Dashboard</a></li>
            <li class="breadcrumb-item"><a href="submissions.php">Submissions</a></li>
            <li class="breadcrumb-item active"><?= htmlspecialchars($submission['gst_roll']) ?></li>
        </ol>
    </nav>

    <?php if ($message): ?>
        <div class="alert alert-info"><?= $message ?></div>
    <?php endif ?>

    <div class="row">
        <div class="col-lg-8">
            <div class="card shadow-lg">
                <div class="card-header bg-primary text-white">
                    <div class="d-flex justify-content-between">
                        <div>
                            <h5><?= htmlspecialchars($submission['full_name']) ?></h5>
                            <small>
                                GST Roll: <strong><?= $submission['gst_roll'] ?></strong> | 
                                Merit Rank: #<?= $submission['merit_rank'] ?: 'N/A' ?>
                            </small>
                        </div>
                        <span class="badge fs-6 <?= statusBadge($submission['submission_status']) ?>">
                            <?= ucwords(str_replace('_', ' ', $submission['submission_status'])) ?>
                        </span>
                    </div>
                </div>
                <div class="card-body">

                    <!-- Documents -->
                    <h6 class="fw-bold mb-3"><i class="bi bi-files me-2"></i>Documents</h6>
                    <div class="row g-3 mb-4">
                        <?php 
                        $docs = [
                            ['SSC Transcript', '/jkkniu-admission/uploads/transcripts/' . ($submission['ssc_transcript'] ?? ''), $submission['ssc_transcript'] ?? ''],
                            ['HSC Transcript', '/jkkniu-admission/uploads/transcripts/' . ($submission['hsc_transcript'] ?? ''), $submission['hsc_transcript'] ?? ''],
                            ['Fee Receipt', '/jkkniu-admission/uploads/receipts/dept/' . ($submission['dept_fee_receipt'] ?? ''), $submission['dept_fee_receipt'] ?? '']
                        ];

                        if (!empty($deptPayment)) {
                            $docs[2][0] = 'Dept Fee Payment';
                            $docs[2][1] = '#';
                            $docs[2][] = paymentBadgeHtml($deptPayment);
                        }

                        foreach ($docs as $doc):
                        ?>
                        <div class="col-md-4">
                            <div class="card h-100 <?= $doc[2] ? 'border-success' : 'border-danger' ?>">
                                <div class="card-body text-center py-3">
                                    <h6 class="mb-1"><?= $doc[0] ?></h6>
                                    <?php if (!empty($doc[3])): ?>
                                        <?= $doc[3] ?>
                                        <div class="small text-success mt-1">Paid online via gateway</div>
                                    <?php elseif ($doc[2]): ?>
                                        <a href="<?= $doc[1] ?>" target="_blank" class="btn btn-sm btn-success">
                                            <i class="bi bi-eye"></i> View
                                        </a>
                                    <?php else: ?>
                                        <span class="text-muted small">❌ Missing</span>
                                    <?php endif ?>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>

                    <!-- Biodata / Personal Information -->
                    <h6 class="fw-bold mb-3"><i class="bi bi-person-lines-fill me-2"></i>Biodata</h6>
                    <div class="row mb-4">
                        <div class="col-md-6">
                            <h6 class="fw-bold">Personal Information</h6>
                            <div class="mb-2">
                                <label class="form-label">Full Name</label>
                                <input type="text" class="form-control-plaintext" value="<?= htmlspecialchars($submission['full_name']) ?>" readonly>
                            </div>
                            <div class="mb-2">
                                <label class="form-label">Date of Birth</label>
                                <input type="text" class="form-control-plaintext" value="<?= htmlspecialchars($submission['date_of_birth']) ?>" readonly>
                            </div>
                            <div class="mb-2">
                                <label class="form-label">Gender</label>
                                <input type="text" class="form-control-plaintext" value="<?= htmlspecialchars($submission['gender']) ?>" readonly>
                            </div>
                            <div class="mb-2">
                                <label class="form-label">Phone</label>
                                <input type="text" class="form-control-plaintext" value="<?= htmlspecialchars($submission['phone']) ?>" readonly>
                            </div>
                            <div class="mb-2">
                                <label class="form-label">Email</label>
                                <input type="text" class="form-control-plaintext" value="<?= htmlspecialchars($submission['email']) ?>" readonly>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <h6 class="fw-bold">Academic Information</h6>
                            <div class="mb-2">
                                <label class="form-label">SSC Roll</label>
                                <input type="text" class="form-control-plaintext" value="<?= htmlspecialchars($submission['ssc_roll']) ?>" readonly>
                            </div>
                            <div class="mb-2">
                                <label class="form-label">SSC GPA / Marks</label>
                                <input type="text" class="form-control-plaintext" value="<?= htmlspecialchars($submission['ssc_gpa']) ?>" readonly>
                            </div>
                            <div class="mb-2">
                                <label class="form-label">HSC Roll</label>
                                <input type="text" class="form-control-plaintext" value="<?= htmlspecialchars($submission['hsc_roll']) ?>" readonly>
                            </div>
                            <div class="mb-2">
                                <label class="form-label">HSC GPA / Marks</label>
                                <input type="text" class="form-control-plaintext" value="<?= htmlspecialchars($submission['hsc_gpa']) ?>" readonly>
                            </div>
                            <div class="mb-2">
                                <label class="form-label">GST Score</label>
                                <input type="text" class="form-control-plaintext" value="<?= number_format($submission['gst_score'], 2) ?>" readonly>
                            </div>
                        </div>
                    </div>

                    <!-- Address -->
                    <h6 class="fw-bold mb-3"><i class="bi bi-geo-alt me-2"></i>Address</h6>
                    <div class="row mb-4">
                        <div class="col-md-6">
                            <div class="mb-2">
                                <label class="form-label">Present Address</label>
                                <textarea class="form-control-plaintext" rows="3" readonly><?= htmlspecialchars($submission['present_address']) ?></textarea>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-2">
                                <label class="form-label">Permanent Address</label>
                                <textarea class="form-control-plaintext" rows="3" readonly><?= htmlspecialchars($submission['permanent_address']) ?></textarea>
                            </div>
                        </div>
                    </div>

                    <!-- Fee Details -->
                    <div class="row mb-4">
                        <div class="col-md-6">
                            <strong>Fee Amount:</strong> ৳<?= number_format($submission['dept_fee_amount'], 2) ?><br>
                            <strong>Method:</strong> <?= htmlspecialchars($submission['payment_method'] ?? '') ?><br>
                            <?php if (!empty($deptPayment)): ?>
                                <div class="mt-1"><?= paymentBadgeHtml($deptPayment) ?></div>
                            <?php endif ?>
                        </div>
                        <div class="col-md-6">
                            <strong>Submitted:</strong> <?= date('M j, Y H:i', strtotime($submission['submitted_at'])) ?>
                        </div>
                    </div>

                    <?php if (!empty($hallPayment)): ?>
                        <div class="alert alert-info py-2">Hall fee also paid online. <?= paymentBadgeHtml($hallPayment) ?></div>
                    <?php endif ?>

                    <!-- Action Form -->
                    <?php if (!in_array($submission['submission_status'], ['Verified', 'Rejected'])): ?>
                        <form method="POST">
                            <div class="mb-3">
                                <label class="form-label fw-bold">Status</label>
                                <select name="status" class="form-select" required>
                                    <option value="">Select Action</option>
                                    <option value="Verified">✔ Verify & Approve</option>
                                    <option value="Rejected">❌ Reject</option>
                                </select>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Remarks (optional)</label>
                                <textarea name="remarks" class="form-control" rows="3" 
                                          placeholder="Reason for rejection or approval note..."></textarea>
                            </div>
                            <div class="d-flex gap-2">
                                <button type="submit" class="btn btn-success">
                                    <i class="bi bi-check2"></i> Update Status
                                </button>
                                <a href="submissions.php" class="btn btn-outline-secondary">Back to List</a>
                            </div>
                        </form>
                    <?php endif ?>
                </div>
            </div>
        </div>

        <div class="col-lg-4">
            <!-- Quick student info -->
            <div class="card shadow-sm mb-3">
                <div class="card-header bg-secondary text-white">Student Info</div>
                <div class="card-body">
                    <div class="mb-2"><strong>GST Score:</strong> <?= number_format($submission['gst_score'], 2) ?></div>
                    <div class="mb-2"><strong>Email:</strong> <?= htmlspecialchars($submission['email']) ?></div>
                    <div class="mb-2"><strong>Phone:</strong> <?= htmlspecialchars($submission['phone']) ?></div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require_once '../includes/footer.php'; ?>

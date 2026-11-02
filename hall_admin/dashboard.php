<?php
$pageTitle = "Hall Admin Dashboard";
require_once '../includes/auth.php';
require_once '../config/db.php';
require_once '../includes/header.php';
require_once '../includes/navbar.php';

requireLogin('hall_admin');

$admin_id = (int) currentUserId();

// Hall admin info
$stmt = $pdo->prepare("SELECT full_name, hall_name, status FROM admin_users WHERE admin_id=? AND role='hall_admin' LIMIT 1");
$stmt->execute([$admin_id]);
$admin = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$admin) die("Hall admin not found.");
if (($admin['status'] ?? 'Pending') !== 'Active') die("Your account is not active.");

$hall_name = $admin['hall_name'] ?? '';
if ($hall_name === '') die("Hall name not set for this hall admin.");

// =====================
// STATS
// =====================
$stats_query = "
    SELECT
        COUNT(CASE WHEN hs.submission_status='Pending' THEN 1 END)  AS pending_submissions,
        COUNT(CASE WHEN hs.submission_status='Verified' THEN 1 END) AS verified_submissions,
        COUNT(CASE WHEN hs.submission_status='Rejected' THEN 1 END) AS rejected_submissions,
        COUNT(*) AS total_submissions
    FROM hall_submissions hs
    WHERE hs.hall_name = ?
";
$stmt = $pdo->prepare($stats_query);
$stmt->execute([$hall_name]);
$stats = $stmt->fetch(PDO::FETCH_ASSOC) ?: [
    'pending_submissions'=>0,'verified_submissions'=>0,'rejected_submissions'=>0,'total_submissions'=>0
];

// =====================
// PROGRESS OVERVIEW (Hall step)
// =====================
// hall_step exists in admission_progress; join by applicant_id from hall_submissions
$progress_query = "
    SELECT
        COUNT(CASE WHEN ap.hall_step='Pending' THEN 1 END) AS hall_pending,
        COUNT(CASE WHEN ap.hall_step='Done' THEN 1 END)    AS hall_done
    FROM admission_progress ap
    JOIN hall_submissions hs ON hs.applicant_id = ap.applicant_id
    WHERE hs.hall_name = ?
";
$stmt_progress = $pdo->prepare($progress_query);
$stmt_progress->execute([$hall_name]);
$progress = $stmt_progress->fetch(PDO::FETCH_ASSOC) ?: ['hall_pending'=>0,'hall_done'=>0];

// =====================
// RECENT SUBMISSIONS (last 10)
// student details + dept via merit_allocations
// =====================
$recent_query = "
    SELECT
        hs.hall_sub_id, hs.applicant_id, hs.submission_status, hs.submitted_at,
        hs.hall_fee_amount, hs.payment_method,

        ga.full_name, ga.gst_roll, ga.gst_score, ga.phone, ga.email,

        d.dept_name
    FROM hall_submissions hs
    JOIN gst_applicants ga ON ga.applicant_id = hs.applicant_id
    LEFT JOIN merit_allocations ma ON ma.applicant_id = hs.applicant_id
    LEFT JOIN departments d ON d.dept_id = ma.dept_id
    WHERE hs.hall_name = ?
    ORDER BY hs.submitted_at DESC
    LIMIT 10
";
$stmt_recent = $pdo->prepare($recent_query);
$stmt_recent->execute([$hall_name]);
$recent = $stmt_recent->fetchAll(PDO::FETCH_ASSOC);

function e($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
?>

<div class="container py-4">

    <!-- Header -->
    <div class="alert alert-primary d-flex align-items-center gap-3 mb-4">
        <i class="bi bi-building fs-2"></i>
        <div>
            <h5><?= e($hall_name) ?> Dashboard</h5>
            <small>
                Hall Admin: <?= e($admin['full_name']) ?> |
                Total Submissions: <?= (int)$stats['total_submissions'] ?> |
                Verified: <?= (int)$stats['verified_submissions'] ?>
            </small>
        </div>
    </div>

    <!-- Stats Cards -->
    <div class="row g-4 mb-4">
        <div class="col-md-3">
            <div class="card border-start border-4 border-warning border-top-0 h-100">
                <div class="card-body text-center py-4">
                    <i class="bi bi-hourglass-split fs-2 text-warning mb-2"></i>
                    <div class="h3 fw-bold text-warning"><?= (int)$stats['pending_submissions'] ?></div>
                    <small>Pending Review</small>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-start border-4 border-success border-top-0 h-100">
                <div class="card-body text-center py-4">
                    <i class="bi bi-check-circle fs-2 text-success mb-2"></i>
                    <div class="h3 fw-bold text-success"><?= (int)$stats['verified_submissions'] ?></div>
                    <small>Verified</small>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-start border-4 border-danger border-top-0 h-100">
                <div class="card-body text-center py-4">
                    <i class="bi bi-x-circle fs-2 text-danger mb-2"></i>
                    <div class="h3 fw-bold text-danger"><?= (int)$stats['rejected_submissions'] ?></div>
                    <small>Rejected</small>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-start border-4 border-info border-top-0 h-100">
                <div class="card-body text-center py-4">
                    <i class="bi bi-people fs-2 text-info mb-2"></i>
                    <div class="h3 fw-bold text-info"><?= (int)$stats['total_submissions'] ?></div>
                    <small>Total Step-2</small>
                </div>
            </div>
        </div>
    </div>

    <!-- Quick Actions -->
    <div class="row g-3 mb-4">
        <div class="col-md-6">
            <a href="submissions.php?status=Pending" class="btn btn-primary w-100 h-100 d-flex align-items-center justify-content-center text-decoration-none">
                <i class="bi bi-inbox fs-3 mb-2 d-block"></i>
                <span class="fw-bold text-center">
                    Review Submissions<br>
                    <small class="text-white-50"><?= (int)$stats['pending_submissions'] ?> pending</small>
                </span>
            </a>
        </div>
        <div class="col-md-6">
            <a href="submissions.php?status=All" class="btn btn-success w-100 h-100 d-flex align-items-center justify-content-center text-decoration-none">
                <i class="bi bi-list-check fs-3 mb-2 d-block"></i>
                <span class="fw-bold text-center">
                    All Submissions<br>
                    <small class="text-white-50"><?= (int)$stats['total_submissions'] ?> total</small>
                </span>
            </a>
        </div>
    </div>

    <!-- Progress Overview -->
    <div class="row g-4 mb-4">
        <div class="col-md-6">
            <div class="card h-100">
                <div class="card-header bg-primary text-white">
                    <i class="bi bi-graph-up me-2"></i>Hall Step Progress
                </div>
                <div class="card-body">
                    <div class="row text-center">
                        <div class="col-6 border-end">
                            <div class="h4 fw-bold text-warning"><?= (int)($progress['hall_pending'] ?? 0) ?></div>
                            <small>Hall Pending</small>
                        </div>
                        <div class="col-6">
                            <div class="h4 fw-bold text-success"><?= (int)($progress['hall_done'] ?? 0) ?></div>
                            <small>Hall Completed</small>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Extra Box (Optional useful info) -->
        <div class="col-md-6">
            <div class="card h-100">
                <div class="card-header bg-success text-white">
                    <i class="bi bi-shield-check me-2"></i>Verification Summary
                </div>
                <div class="card-body text-center py-4">
                    <div class="display-4 fw-bold text-info">
                        <?= (int)$stats['verified_submissions'] ?>/<?= (int)$stats['total_submissions'] ?>
                    </div>
                    <small class="text-muted">Verified / Total Submissions</small>
                </div>
            </div>
        </div>
    </div>

    <!-- Recent Submissions -->
    <div class="card shadow-sm">
        <div class="card-header bg-secondary text-white d-flex justify-content-between align-items-center">
            <span><i class="bi bi-clock-history me-2"></i>Recent Step 2 Submissions</span>
            <a href="submissions.php?status=All" class="btn btn-sm btn-light">View All</a>
        </div>

        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead class="table-light">
                    <tr>
                        <th>GST Roll</th>
                        <th>Student</th>
                        <th>Phone</th>
                        <th>GST Score</th>
                        <th>Department</th>
                        <th>Submitted</th>
                        <th>Status</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($recent as $sub): ?>
                    <tr>
                        <td><strong><?= e($sub['gst_roll']) ?></strong></td>
                        <td>
                            <?= e($sub['full_name']) ?>
                            <?php if (!empty($sub['email'])): ?>
                                <div class="small text-muted"><?= e($sub['email']) ?></div>
                            <?php endif; ?>
                        </td>
                        <td><?= e($sub['phone'] ?? 'N/A') ?></td>
                        <td><strong><?= number_format((float)$sub['gst_score'], 2) ?></strong></td>
                        <td><?= e($sub['dept_name'] ?? '—') ?></td>
                        <td><?= date('M j, H:i', strtotime($sub['submitted_at'])) ?></td>
                        <td>
                            <span class="badge bg-<?=
                                $sub['submission_status'] === 'Verified' ? 'success' :
                                ($sub['submission_status'] === 'Rejected' ? 'danger' : 'warning text-dark')
                            ?> fs-6">
                                <?= e($sub['submission_status']) ?>
                            </span>
                        </td>
                        <td>
                            <a href="verify_submission.php?id=<?= (int)$sub['hall_sub_id'] ?>"
                               class="btn btn-sm btn-outline-primary">
                                <i class="bi bi-eye"></i> Review
                            </a>

                            <!-- Student detail page (you can create this file) -->
                            
                        </td>
                    </tr>
                <?php endforeach ?>

                <?php if (empty($recent)): ?>
                    <tr><td colspan="8" class="text-center py-4 text-muted">No recent submissions</td></tr>
                <?php endif ?>
                </tbody>
            </table>
        </div>
    </div>

</div>

<?php require_once '../includes/footer.php'; ?>
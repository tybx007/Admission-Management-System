<?php
$pageTitle = 'Admission Notices';
require_once '../includes/auth.php';
require_once '../config/db.php';
require_once '../includes/header.php';
require_once '../includes/navbar.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'student') {
    header('Location: /jkkniu-admission/student/login.php'); exit();
}
$applicant_id = $_SESSION['user_id'];

// Get student's allocated dept_id
$stmtMerit = $pdo->prepare("SELECT dept_id FROM merit_allocations WHERE applicant_id = ?");
$stmtMerit->execute([$applicant_id]);
$merit = $stmtMerit->fetch();
$dept_id = $merit['dept_id'] ?? null;

// Fetch general + dept-specific notices
$stmtNotices = $pdo->prepare("
    SELECT n.*, d.dept_name
    FROM admission_notices n
    LEFT JOIN departments d ON n.dept_id = d.dept_id
    WHERE n.dept_id IS NULL OR n.dept_id = ?
    ORDER BY n.published_at DESC
");
$stmtNotices->execute([$dept_id]);
$notices = $stmtNotices->fetchAll();
?>

<div class="container py-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h4 class="fw-bold mb-0">
            <i class="bi bi-bell-fill text-warning"></i> Admission Notices
        </h4>
        <a href="dashboard.php" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-arrow-left"></i> Dashboard
        </a>
    </div>

    <?php if (empty($notices)): ?>
        <div class="alert alert-info">
            <i class="bi bi-info-circle-fill"></i> No notices published yet. Check back later.
        </div>
    <?php else: ?>
        <?php foreach ($notices as $notice): ?>
        <div class="card shadow-sm mb-3 notice-card">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
                    <div class="flex-grow-1">
                        <h6 class="fw-bold mb-1">
                            <i class="bi bi-megaphone-fill text-warning me-1"></i>
                            <?= htmlspecialchars($notice['notice_title']) ?>
                        </h6>
                        <div class="mb-2">
                            <?php if ($notice['dept_name']): ?>
                                <span class="badge bg-primary"><?= htmlspecialchars($notice['dept_name']) ?></span>
                            <?php else: ?>
                                <span class="badge bg-secondary">General</span>
                            <?php endif; ?>
                            <span class="text-muted small ms-2">
                                <i class="bi bi-clock"></i>
                                <?= date('d M Y, h:i A', strtotime($notice['published_at'])) ?>
                            </span>
                        </div>
                        <?php if (!empty($notice['notice_body'])): ?>
                            <p class="mb-2 text-secondary small">
                                <?= nl2br(htmlspecialchars($notice['notice_body'])) ?>
                            </p>
                        <?php endif; ?>
                        <div class="d-flex gap-3 text-muted small mt-2">
                            <span>
                                <i class="bi bi-calendar-check text-success"></i>
                                Start: <strong><?= date('d M Y', strtotime($notice['admission_start_date'])) ?></strong>
                            </span>
                            <?php if (!empty($notice['admission_end_date'])): ?>
                            <span>
                                <i class="bi bi-calendar-x text-danger"></i>
                                End: <strong><?= date('d M Y', strtotime($notice['admission_end_date'])) ?></strong>
                            </span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<?php require_once '../includes/footer.php'; ?>

<?php
$pageTitle = "Step 1: Verify Department Submissions";
require_once '../includes/auth.php';
require_once '../config/db.php';
require_once '../includes/header.php';
require_once '../includes/navbar.php';

requireLogin('dept_admin');
$dept_id = (int)$_SESSION['dept_id'];
$dept_name = $_SESSION['dept_name'] ?? 'Department';

// **SECURE** Handle bulk actions with prepared statements
$message = '';
$alert_type = 'info';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $sub_ids = array_map('intval', $_POST['sub_ids'] ?? []);
    $action = $_POST['action'] ?? '';
    $remarks = trim($_POST['remarks'] ?? '');
    
    if (!empty($sub_ids) && in_array($action, ['verify', 'reject'])) {
        if ($action === 'verify') {
            $placeholders = str_repeat('?,', count($sub_ids) - 1) . '?';
            $stmt = $pdo->prepare("UPDATE dept_submissions SET submission_status = 'Verified', verified_at = NOW() WHERE dept_sub_id IN ($placeholders) AND dept_id = ?");
            $params = array_merge($sub_ids, [$dept_id]);
            $stmt->execute($params);
            $message = "✅ " . count($sub_ids) . " submissions verified successfully!";
            $alert_type = 'success';
        } elseif ($action === 'reject') {
            $placeholders = str_repeat('?,', count($sub_ids) - 1) . '?';
            $stmt = $pdo->prepare("UPDATE dept_submissions SET submission_status = 'Rejected', verified_at = NOW()" . ($remarks ? ", remarks = ?" : "") . " WHERE dept_sub_id IN ($placeholders) AND dept_id = ?");
            $params = array_merge($sub_ids, [$dept_id]);
            if ($remarks) $params[] = $remarks;
            $stmt->execute($params);
            $message = "❌ " . count($sub_ids) . " submissions rejected" . ($remarks ? " with remarks" : "") . "!";
            $alert_type = 'danger';
        }
    }
}

// **SECURE** Fetch submissions for this dept only
$stmt_submissions = $pdo->prepare("
    SELECT ds.*, ga.full_name, ga.gst_roll, ga.gst_score, ga.phone, ga.email,
           ma.merit_rank, ap.final_status,
           d.total_seats
    FROM dept_submissions ds
    JOIN gst_applicants ga ON ds.applicant_id = ga.applicant_id
    LEFT JOIN merit_allocations ma ON ga.applicant_id = ma.applicant_id AND ma.dept_id = ds.dept_id
    LEFT JOIN admission_progress ap ON ga.applicant_id = ap.applicant_id
    LEFT JOIN departments d ON ds.dept_id = d.dept_id
    WHERE ds.dept_id = ?
    ORDER BY 
        CASE ds.submission_status 
            WHEN 'Pending' THEN 1 
            WHEN 'Verified' THEN 2 
            WHEN 'Rejected' THEN 3 
        END,
        ds.submitted_at DESC
");
$stmt_submissions->execute([$dept_id]);
$submissions = $stmt_submissions->fetchAll();

// Stats
$pending_count = count(array_filter($submissions, fn($s) => $s['submission_status'] === 'Pending'));
$verified_count = count(array_filter($submissions, fn($s) => $s['submission_status'] === 'Verified'));
$rejected_count = count(array_filter($submissions, fn($s) => $s['submission_status'] === 'Rejected'));
$total_submissions = count($submissions);
?>

<div class="container py-4">
    <!-- Header with Stats -->
    <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
        <div>
            <h2><i class="bi bi-upload text-primary me-2"></i>Step 1: Dept Submissions</h2>
            <small class="text-muted"><?= htmlspecialchars($dept_name) ?> (<?= $total_submissions ?> total)</small>
        </div>
        <div class="btn-group">
            <a href="dashboard.php" class="btn btn-outline-secondary">
                <i class="bi bi-house-door me-1"></i>Dashboard
            </a>
            <a href="merit_list.php" class="btn btn-outline-primary">
                <i class="bi bi-trophy me-1"></i>Merit List
            </a>
        </div>
    </div>

    <!-- Stats Cards -->
    <div class="row g-3 mb-4">
        <div class="col-md-3">
            <div class="card border-start border-4 border-warning">
                <div class="card-body">
                    <h3 class="text-warning mb-1"><?= $pending_count ?></h3>
                    <small class="text-muted">Pending Review</small>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-start border-4 border-success">
                <div class="card-body">
                    <h3 class="text-success mb-1"><?= $verified_count ?></h3>
                    <small class="text-muted">Verified</small>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-start border-4 border-danger">
                <div class="card-body">
                    <h3 class="text-danger mb-1"><?= $rejected_count ?></h3>
                    <small class="text-muted">Rejected</small>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-start border-4 border-info">
                <div class="card-body">
                    <h3 class="text-info mb-1"><?= $total_submissions ?></h3>
                    <small class="text-muted">Total</small>
                </div>
            </div>
        </div>
    </div>

    <?php if ($message): ?>
    <div class="alert alert-<?= $alert_type ?> alert-dismissible fade show" role="alert">
        <?= htmlspecialchars($message) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>

    <?php if (empty($submissions)): ?>
    <div class="alert alert-info text-center py-5">
        <i class="bi bi-inbox fs-1 mb-3 d-block text-muted"></i>
        <h5>No submissions yet</h5>
        <p class="mb-0">Students will submit after merit list publication.</p>
    </div>
    <?php else: ?>
    <form method="POST" id="bulkForm">
        <input type="hidden" name="action" id="bulkAction">
        <input type="hidden" name="remarks" id="bulkRemarks">
        
        <div class="card shadow-lg">
            <div class="card-header bg-light d-flex justify-content-between align-items-center p-3">
                <div>
                    <div class="btn-group btn-group-sm" role="group">
                        <button type="button" class="btn btn-outline-success" onclick="bulkAction('verify')" id="btnVerify">
                            <i class="bi bi-check-all"></i> Verify Selected (<?= $pending_count ?>)
                        </button>
                        <button type="button" class="btn btn-outline-danger" onclick="bulkAction('reject')" id="btnReject">
                            <i class="bi bi-x-circle"></i> Reject Selected
                        </button>
                        <button type="button" class="btn btn-outline-secondary" onclick="selectAllPending()">
                            <i class="bi bi-check-square"></i> All Pending
                        </button>
                    </div>
                </div>
                <div class="d-flex align-items-center gap-2">
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" id="showVerified" onchange="toggleVerified()">
                        <label class="form-check-label small" for="showVerified">Show Verified</label>
                    </div>
                    <span class="badge bg-primary" id="selectedCount">0 selected</span>
                    <span class="badge bg-info"><?= $pending_count ?> pending</span>
                </div>
            </div>
            
            <div class="table-responsive">
                <table class="table table-hover mb-0 align-middle" id="submissionsTable">
                    <thead class="table-light sticky-top">
                        <tr>
                            <th width="50"><input type="checkbox" id="selectAll" title="Select All Visible"></th>
                            <th width="120">GST Roll</th>
                            <th>Student</th>
                            <th width="80">Merit Rank</th>
                            <th width="100">Amount</th>
                            <th width="140">Submitted</th>
                            <th width="100">Status</th>
                            <th width="120">Files</th>
                            <th width="100">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($submissions as $sub): ?>
                        <tr class="submission-row" 
                            data-status="<?= $sub['submission_status'] ?>"
                            style="<?= $sub['submission_status'] === 'Verified' ? 'opacity: 0.6;' : '' ?>">
                            <td>
                                <input type="checkbox" class="form-check-input sub-checkbox" 
                                       name="sub_ids[]" value="<?= $sub['dept_sub_id'] ?>" 
                                       <?= $sub['submission_status'] !== 'Pending' ? 'disabled' : '' ?>>
                            </td>
                            <td>
                                <strong class="badge bg-primary"><?= htmlspecialchars($sub['gst_roll']) ?></strong>
                                <?php if ($sub['merit_rank']): ?>
                                <div class="small text-success">#<?= $sub['merit_rank'] ?></div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div><?= htmlspecialchars($sub['full_name']) ?></div>
                                <?php if ($sub['phone']): ?>
                                <small class="text-muted"><i class="bi bi-telephone"></i> <?= htmlspecialchars($sub['phone']) ?></small>
                                <?php endif; ?>
                                <?php if ($sub['email']): ?>
                                <div><small class="text-muted"><i class="bi bi-envelope"></i> <?= htmlspecialchars($sub['email']) ?></small></div>
                                <?php endif; ?>
                            </td>
                            <td><?= $sub['merit_rank'] ? '#' . $sub['merit_rank'] : '<span class="text-muted">N/A</span>' ?></td>
                            <td>
                                <strong class="text-success">৳<?= number_format($sub['dept_fee_amount'] ?? 0, 2) ?></strong>
                                <?php if ($sub['payment_method']): ?>
                                <br><small class="badge bg-light text-dark"><?= htmlspecialchars($sub['payment_method']) ?></small>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?= date('M j, H:i', strtotime($sub['submitted_at'])) ?>
                                <?php if ($sub['verified_at']): ?>
                                <br><small class="text-success">✓ <?= date('M j, H:i', strtotime($sub['verified_at'])) ?></small>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="badge fs-6 bg-<?= 
                                    $sub['submission_status'] === 'Verified' ? 'success' : 
                                    ($sub['submission_status'] === 'Rejected' ? 'danger' : 'warning text-dark')
                                ?>">
                                    <?= ucwords(str_replace('_', ' ', $sub['submission_status'])) ?>
                                </span>
                                <?php if ($sub['final_status']): ?>
                                <br><small class="badge bg-info"><?= $sub['final_status'] ?></small>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($sub['transcript_file'] || $sub['biodata_file'] || $sub['dept_fee_receipt']): ?>
                                    <div class="btn-group-vertical btn-group-sm" role="group">
                                        <?php if ($sub['transcript_file']): ?>
                                        <a href="../uploads/transcripts/<?= htmlspecialchars($sub['transcript_file']) ?>" target="_blank" class="btn btn-outline-info btn-sm">
                                            <i class="bi bi-file-earmark-text"></i> Transcript
                                        </a>
                                        <?php endif; ?>
                                        <?php if ($sub['biodata_file']): ?>
                                        <a href="../uploads/<?= htmlspecialchars($sub['biodata_file']) ?>" target="_blank" class="btn btn-outline-secondary btn-sm">
                                            <i class="bi bi-person"></i> Biodata
                                        </a>
                                        <?php endif; ?>
                                        <?php if ($sub['dept_fee_receipt']): ?>
                                        <a href="../uploads/receipts/dept/<?= htmlspecialchars($sub['dept_fee_receipt']) ?>" target="_blank" class="btn btn-outline-success btn-sm">
                                            <i class="bi bi-receipt"></i> Receipt
                                        </a>
                                        <?php endif; ?>
                                    </div>
                                <?php else: ?>
                                    <span class="text-muted small">No files</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($sub['submission_status'] === 'Pending'): ?>
                                    <div class="btn-group btn-group-sm" role="group">
                                        <a href="verify_submission.php?sub_id=<?= $sub['dept_sub_id'] ?>" 
                                           class="btn btn-outline-primary" title="Individual Review">
                                            <i class="bi bi-eye"></i>
                                        </a>
                                        <button class="btn btn-outline-success btn-verify" 
                                                data-id="<?= $sub['dept_sub_id'] ?>" title="Quick Verify">
                                            <i class="bi bi-check-lg"></i>
                                        </button>
                                        <button class="btn btn-outline-danger btn-reject" 
                                                data-id="<?= $sub['dept_sub_id'] ?>" title="Quick Reject">
                                            <i class="bi bi-x-lg"></i>
                                        </button>
                                    </div>
                                <?php else: ?>
                                    <span class="badge bg-secondary">Complete</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </form>
    <?php endif; ?>
</div>

<script>
let checkboxes = document.querySelectorAll('.sub-checkbox');
let selectAll = document.getElementById('selectAll');
let selectedCount = document.getElementById('selectedCount');
let showVerified = document.getElementById('showVerified');
let submissionsTable = document.getElementById('submissionsTable');

// Update selection count
function updateSelection() {
    let checked = document.querySelectorAll('.sub-checkbox:checked').length;
    selectedCount.textContent = checked + ' selected';
    selectedCount.className = checked ? 'badge bg-primary' : 'badge bg-secondary';
    
    // Enable/disable bulk buttons
    document.querySelectorAll('[onclick="bulkAction()"]').forEach(btn => {
        btn.disabled = checked === 0;
    });
}

// Select/deselect all visible checkboxes
function toggleSelectAll() {
    let visibleCheckboxes = document.querySelectorAll('.submission-row:not([style*="opacity"]):not([style*="display:none"]) .sub-checkbox');
    let allChecked = Array.from(visibleCheckboxes).every(cb => cb.checked);
    
    visibleCheckboxes.forEach(cb => {
        if (cb.disabled) return;
        cb.checked = !allChecked;
    });
    updateSelection();
}

// Select only pending submissions
function selectAllPending() {
    document.querySelectorAll('.submission-row[data-status="Pending"] .sub-checkbox').forEach(cb => {
        cb.checked = true;
    });
    updateSelection();
}

// Toggle verified rows visibility
function toggleVerified() {
    let rows = document.querySelectorAll('.submission-row[data-status="Verified"]');
    rows.forEach(row => {
        row.style.display = showVerified.checked ? '' : 'none';
    });
    updateSelection();
}

// Bulk actions
function bulkAction(action) {
    let checkedBoxes = document.querySelectorAll('.sub-checkbox:checked');
    if (checkedBoxes.length === 0) {
        alert('Please select submissions first!');
        return;
    }
    
    if (action === 'verify') {
        if (confirm(`Verify ${checkedBoxes.length} submission(s)?`)) {
            document.getElementById('bulkAction').value = 'verify';
            document.getElementById('bulkForm').submit();
        }
    } else if (action === 'reject') {
        let remarks = prompt('Rejection reason (optional):');
        if (remarks !== null) {
            document.getElementById('bulkAction').value = 'reject';
            document.getElementById('bulkRemarks').value = remarks;
            document.getElementById('bulkForm').submit();
        }
    }
}

// Quick individual actions
document.querySelectorAll('.btn-verify, .btn-reject').forEach(btn => {
    btn.addEventListener('click', function(e) {
        e.preventDefault();
        let subId = this.dataset.id;
        let action = this.classList.contains('btn-verify') ? 'verify' : 'reject';
        let confirmMsg = action === 'verify' ? 
            `Verify submission ${subId}?` : 
            `Reject submission ${subId}?\n\nAdd reason:`;
        
        if (action === 'reject') {
            let remarks = prompt(confirmMsg);
            if (remarks === null) return;
        }
        
        // Quick AJAX-like form submission
        let form = document.createElement('form');
        form.method = 'POST';
        form.innerHTML = `
            <input type="hidden" name="sub_ids[]" value="${subId}">
            <input type="hidden" name="action" value="${action}">
        `;
        if (action === 'reject') {
            form.innerHTML += `<input type="hidden" name="remarks" value="${remarks || ''}">`;
        }
        document.body.appendChild(form);
        form.submit();
    });
});

// Event listeners
checkboxes.forEach(cb => cb.addEventListener('change', updateSelection));
selectAll.addEventListener('click', toggleSelectAll);
updateSelection();
</script>

<?php require_once '../includes/footer.php'; ?>

<?php
$pageTitle = "Hall Submissions";
require_once '../includes/auth.php';
require_once '../config/db.php';
require_once '../includes/header.php';
require_once '../includes/navbar.php';

requireLogin('hall_admin');

$admin_id  = (int)($_SESSION['user_id'] ?? 0);
$hall_name = trim($_SESSION['hall_name'] ?? '');

if ($admin_id <= 0) die("Invalid session admin_id. Please login again.");
if ($hall_name === '') die("Session hall_name missing. Please login again.");

function e($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

// Filters
$status = $_GET['status'] ?? 'Pending';
$allowed = ['All','Pending','Verified','Rejected'];
if (!in_array($status, $allowed, true)) $status = 'Pending';

$q = trim($_GET['q'] ?? '');

// Query
$sql = "
  SELECT
    hs.hall_sub_id,
    hs.applicant_id,
    hs.submission_status,
    hs.submitted_at,
    hs.hall_fee_amount,
    hs.payment_method,

    ga.full_name AS applicant_name,
    ga.gst_roll,
    ga.gst_score,

    d.dept_name
  FROM hall_submissions hs
  JOIN gst_applicants ga ON ga.applicant_id = hs.applicant_id
  LEFT JOIN merit_allocations ma ON ma.applicant_id = hs.applicant_id
  LEFT JOIN departments d ON d.dept_id = ma.dept_id
  WHERE hs.hall_name = ?
";
$params = [$hall_name];

if ($status !== 'All') {
  $sql .= " AND hs.submission_status = ? ";
  $params[] = $status;
}

if ($q !== '') {
  $sql .= " AND (ga.gst_roll LIKE ? OR ga.full_name LIKE ?) ";
  $params[] = "%$q%";
  $params[] = "%$q%";
}

$sql .= " ORDER BY hs.submitted_at DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<div class="container py-4">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <div>
      <h3 class="mb-0">Hall Submissions</h3>
      <div class="text-muted small">Hall: <?= e($hall_name) ?></div>
    </div>
    <a href="dashboard.php" class="btn btn-outline-secondary btn-sm">Dashboard</a>
  </div>

  <div class="card shadow-sm mb-3">
    <div class="card-body">
      <form class="row g-2" method="GET">
        <div class="col-md-3">
          <label class="form-label small mb-1">Status</label>
          <select name="status" class="form-select form-select-sm">
            <?php foreach($allowed as $s): ?>
              <option value="<?= e($s) ?>" <?= $status===$s ? 'selected' : '' ?>><?= e($s) ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="col-md-6">
          <label class="form-label small mb-1">Search (GST Roll / Name)</label>
          <input type="text" class="form-control form-control-sm" name="q" value="<?= e($q) ?>">
        </div>

        <div class="col-md-3 d-flex align-items-end gap-2">
          <button class="btn btn-primary btn-sm w-100" type="submit">Filter</button>
          <a class="btn btn-outline-dark btn-sm w-100" href="submissions.php">Reset</a>
        </div>
      </form>
    </div>
  </div>

  <div class="card shadow-sm">
    <div class="card-header bg-light fw-semibold">Results (<?= count($rows) ?>)</div>

    <div class="table-responsive">
      <table class="table table-hover mb-0">
        <thead class="table-light">
          <tr>
            <th>#</th>
            <th>GST Roll</th>
            <th>Student</th>
            <th>Dept</th>
            <th>GST</th>
            <th>Submitted</th>
            <th>Status</th>
            <th>Action</th>
          </tr>
        </thead>
        <tbody>
        <?php if (!$rows): ?>
          <tr><td colspan="8" class="text-center py-4 text-muted">No submissions found.</td></tr>
        <?php else: ?>
          <?php foreach($rows as $i => $r): ?>
            <tr>
              <td><?= $i + 1 ?></td>
              <td><strong><?= e($r['gst_roll']) ?></strong></td>
              <td><?= e($r['applicant_name']) ?></td>
              <td><?= e($r['dept_name'] ?? '—') ?></td>
              <td><?= e($r['gst_score']) ?></td>
              <td><?= e($r['submitted_at']) ?></td>
              <td><?= e($r['submission_status']) ?></td>
              <td>
                <a class="btn btn-sm btn-outline-primary"
                   href="verify_submission.php?sub_id=<?= (int)$r['hall_sub_id'] ?>">
                  Review
                </a>
              </td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
        </tbody>
      </table>
    </div>

  </div>
</div>

<?php require_once '../includes/footer.php'; ?>
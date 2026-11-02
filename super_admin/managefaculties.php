<?php
$pageTitle = "Manage Faculties";
require_once '../includes/auth.php';
require_once '../config/db.php';
require_once '../includes/header.php';
require_once '../includes/navbar.php';

requireLogin('super_admin');

// ── RESOLVE SELF ID ────────────────────────────────────
$selfId = (int)($_SESSION['userid'] ?? 0);
if (!$selfId) {
    foreach (['user_id','admin_id','id'] as $k) {
        if (!empty($_SESSION[$k])) { $selfId = (int)$_SESSION[$k]; break; }
    }
    if (!$selfId && !empty($_SESSION['name'])) {
        $tmp = $pdo->prepare("SELECT admin_id FROM admin_users WHERE full_name=? AND role='super_admin' LIMIT 1");
        $tmp->execute([$_SESSION['name']]);
        $selfId = (int)($tmp->fetchColumn() ?: 0);
    }
    if ($selfId) $_SESSION['userid'] = $selfId;
}

// ── HANDLE ACTIONS ─────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action     = $_POST['action']      ?? '';
    $facultyId  = (int)($_POST['faculty_id'] ?? 0);
    $facultyName = trim($_POST['faculty_name'] ?? '');

    switch ($action) {

        case 'create':
            if (!$facultyName) {
                $_SESSION['flash'] = ['type'=>'danger', 'msg'=>'Faculty name is required.'];
                break;
            }
            // Check duplicate
            $chk = $pdo->prepare("SELECT faculty_id FROM faculties WHERE faculty_name = ?");
            $chk->execute([$facultyName]);
            if ($chk->fetch()) {
                $_SESSION['flash'] = ['type'=>'danger', 'msg'=>'Faculty name already exists.'];
            } else {
                $pdo->prepare("INSERT INTO faculties (faculty_name) VALUES (?)")
                    ->execute([$facultyName]);
                $_SESSION['flash'] = ['type'=>'success', 'msg'=>"Faculty '$facultyName' created successfully."];
            }
            break;

        case 'edit':
            if (!$facultyId || !$facultyName) {
                $_SESSION['flash'] = ['type'=>'danger', 'msg'=>'Faculty name is required.'];
                break;
            }
            // Check duplicate (excluding self)
            $chk = $pdo->prepare("SELECT faculty_id FROM faculties WHERE faculty_name=? AND faculty_id != ?");
            $chk->execute([$facultyName, $facultyId]);
            if ($chk->fetch()) {
                $_SESSION['flash'] = ['type'=>'danger', 'msg'=>'Another faculty with this name already exists.'];
            } else {
                $pdo->prepare("UPDATE faculties SET faculty_name=? WHERE faculty_id=?")
                    ->execute([$facultyName, $facultyId]);
                $_SESSION['flash'] = ['type'=>'success', 'msg'=>"Faculty updated successfully."];
            }
            break;

        case 'delete':
            if (!$facultyId) break;
            // Check if faculty has departments
            $deptCount = $pdo->prepare("SELECT COUNT(*) FROM departments WHERE faculty_id=?");
            $deptCount->execute([$facultyId]);
            if ($deptCount->fetchColumn() > 0) {
                $_SESSION['flash'] = ['type'=>'danger', 'msg'=>'Cannot delete faculty — it still has departments. Delete departments first.'];
            } else {
                $pdo->prepare("DELETE FROM faculties WHERE faculty_id=?")
                    ->execute([$facultyId]);
                $_SESSION['flash'] = ['type'=>'success', 'msg'=>'Faculty deleted successfully.'];
            }
            break;
    }

    header("Location: managefaculties.php");
    exit;
}

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

// ── FETCH ALL FACULTIES WITH STATS ─────────────────────
$faculties = $pdo->query("
    SELECT f.faculty_id, f.faculty_name, f.created_at,
           COUNT(DISTINCT d.dept_id)         AS dept_count,
           COUNT(DISTINCT g.applicant_id)    AS applicant_count,
           SUM(d.total_seats)                AS total_seats
    FROM faculties f
    LEFT JOIN departments   d ON d.faculty_id  = f.faculty_id
    LEFT JOIN gst_applicants g ON g.faculty_id = f.faculty_id
    GROUP BY f.faculty_id, f.faculty_name, f.created_at
    ORDER BY f.faculty_id
")->fetchAll();
?>

<div class="container py-4">

  <!-- ── BANNER ── -->
  <div class="alert alert-primary d-flex align-items-center justify-content-between flex-wrap gap-2 mb-4">
    <div class="d-flex align-items-center gap-2">
      <i class="bi bi-diagram-3-fill fs-4"></i>
      <div>
        <strong>Manage Faculties</strong>
        <span class="badge bg-warning text-dark ms-2">Super Admin</span>
      </div>
    </div>
    <a href="dashboard.php" class="btn btn-sm btn-light">
      <i class="bi bi-arrow-left me-1"></i>Back to Dashboard
    </a>
  </div>

  <!-- ── FLASH ── -->
  <?php if ($flash): ?>
    <div class="alert alert-<?= $flash['type'] ?> alert-dismissible fade show d-flex align-items-center gap-2">
      <i class="bi bi-<?= $flash['type']==='success' ? 'check-circle-fill' : 'x-circle-fill' ?>"></i>
      <?= htmlspecialchars($flash['msg']) ?>
      <button type="button" class="btn-close ms-auto" data-bs-dismiss="alert"></button>
    </div>
  <?php endif; ?>

  <div class="row g-4">

    <!-- ════ LEFT — Add Faculty ════ -->
    <div class="col-lg-4">
      <div class="card shadow-sm mb-4">
        <div class="card-header bg-dark py-2 fw-semibold">
          <i class="bi bi-plus-circle-fill text-success me-1"></i> Add New Faculty
        </div>
        <div class="card-body">
          <form method="POST">
            <input type="hidden" name="action" value="create">
            <div class="mb-3">
              <label class="form-label fw-semibold">
                Faculty Name <span class="text-danger">*</span>
              </label>
              <input type="text" name="faculty_name" class="form-control"
                     placeholder="e.g. Science and Engineering" required>
            </div>
            <button type="submit" class="btn btn-success w-100 fw-bold">
              <i class="bi bi-plus-circle-fill me-1"></i>Add Faculty
            </button>
          </form>
        </div>
      </div>

      <!-- Summary Card -->
      <div class="card shadow-sm">
        <div class="card-header bg-dark py-2 fw-semibold">
          <i class="bi bi-bar-chart-fill text-info me-1"></i> Summary
        </div>
        <div class="card-body p-0">
          <div class="d-flex align-items-center justify-content-between px-3 py-2 border-bottom">
            <span class="small"><i class="bi bi-diagram-3-fill text-primary me-2"></i>Total Faculties</span>
            <span class="badge bg-primary"><?= count($faculties) ?></span>
          </div>
          <div class="d-flex align-items-center justify-content-between px-3 py-2 border-bottom">
            <span class="small"><i class="bi bi-grid-fill text-success me-2"></i>Total Departments</span>
            <span class="badge bg-success">
              <?= array_sum(array_column($faculties, 'dept_count')) ?>
            </span>
          </div>
          <div class="d-flex align-items-center justify-content-between px-3 py-2 border-bottom">
            <span class="small"><i class="bi bi-people-fill text-info me-2"></i>Total Applicants</span>
            <span class="badge bg-info text-dark">
              <?= array_sum(array_column($faculties, 'applicant_count')) ?>
            </span>
          </div>
          <div class="d-flex align-items-center justify-content-between px-3 py-2">
            <span class="small"><i class="bi bi-person-fill text-warning me-2"></i>Total Seats</span>
            <span class="badge bg-warning text-dark">
              <?= array_sum(array_column($faculties, 'total_seats')) ?>
            </span>
          </div>
        </div>
      </div>
    </div><!-- /col-lg-4 -->

    <!-- ════ RIGHT — Faculty List ════ -->
    <div class="col-lg-8">
      <div class="card shadow-sm">
        <div class="card-header bg-dark py-2 fw-semibold d-flex align-items-center justify-content-between">
          <span><i class="bi bi-list-ul text-warning me-1"></i> All Faculties</span>
          <span class="badge bg-secondary"><?= count($faculties) ?> total</span>
        </div>
        <div class="card-body p-0">
          <?php if (empty($faculties)): ?>
            <div class="text-center py-5 text-muted">
              <i class="bi bi-diagram-3 fs-1 d-block mb-2"></i>
              No faculties found. Add one to get started.
            </div>
          <?php else: ?>
          <div class="table-responsive">
            <table class="table table-sm table-bordered table-hover mb-0 align-middle">
              <thead class="table-light">
                <tr>
                  <th>#</th>
                  <th>Faculty Name</th>
                  <th class="text-center">Depts</th>
                  <th class="text-center">Total Seats</th>
                  <th class="text-center">Applicants</th>
                  <th>Created</th>
                  <th class="text-center">Actions</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($faculties as $i => $f): ?>
                <tr>
                  <td><?= $i + 1 ?></td>
                  <td>
                    <div class="fw-semibold"><?= htmlspecialchars($f['faculty_name']) ?></div>
                    <small class="text-muted">ID: <?= $f['faculty_id'] ?></small>
                  </td>
                  <td class="text-center">
                    <a href="managedepartments.php?faculty_id=<?= $f['faculty_id'] ?>"
                       class="badge bg-primary text-decoration-none">
                      <?= $f['dept_count'] ?> dept<?= $f['dept_count'] != 1 ? 's' : '' ?>
                    </a>
                  </td>
                  <td class="text-center">
                    <span class="badge bg-warning text-dark"><?= $f['total_seats'] ?? 0 ?></span>
                  </td>
                  <td class="text-center">
                    <span class="badge bg-info text-dark"><?= $f['applicant_count'] ?></span>
                  </td>
                  <td><small><?= date('d M Y', strtotime($f['created_at'])) ?></small></td>
                  <td class="text-center">
                    <div class="d-flex gap-1 justify-content-center">
                      <!-- Edit Button -->
                      <button class="btn btn-outline-primary btn-sm py-0 px-2"
                              title="Edit"
                              onclick="openEdit(<?= $f['faculty_id'] ?>, '<?= htmlspecialchars(addslashes($f['faculty_name'])) ?>')">
                        <i class="bi bi-pencil-fill"></i>
                      </button>
                      <!-- View Departments -->
                      <a href="managedepartments.php?faculty_id=<?= $f['faculty_id'] ?>"
                         class="btn btn-outline-info btn-sm py-0 px-2" title="View Departments">
                        <i class="bi bi-grid-fill"></i>
                      </a>
                      <!-- Delete -->
                      <?php if ($f['dept_count'] == 0): ?>
                      <form method="POST" class="d-inline">
                        <input type="hidden" name="action"     value="delete">
                        <input type="hidden" name="faculty_id" value="<?= $f['faculty_id'] ?>">
                        <button class="btn btn-danger btn-sm py-0 px-2" title="Delete"
                                onclick="return confirm('Delete faculty \'<?= htmlspecialchars(addslashes($f['faculty_name'])) ?>\'?\nThis cannot be undone.')">
                          <i class="bi bi-trash-fill"></i>
                        </button>
                      </form>
                      <?php else: ?>
                      <button class="btn btn-outline-danger btn-sm py-0 px-2 disabled"
                              title="Cannot delete — has <?= $f['dept_count'] ?> department(s)">
                        <i class="bi bi-trash-fill"></i>
                      </button>
                      <?php endif; ?>
                    </div>
                  </td>
                </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
          <div class="p-2 text-muted small text-end">
            <?= count($faculties) ?> facult<?= count($faculties) != 1 ? 'ies' : 'y' ?> total
          </div>
          <?php endif; ?>
        </div>
      </div>
    </div><!-- /col-lg-8 -->

  </div><!-- /row -->
</div>

<!-- ── EDIT MODAL ── -->
<div class="modal fade" id="editModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header bg-dark text-white">
        <h5 class="modal-title fw-bold">
          <i class="bi bi-pencil-fill me-2"></i>Edit Faculty
        </h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <form method="POST">
        <input type="hidden" name="action"     value="edit">
        <input type="hidden" name="faculty_id" id="editFacultyId">
        <div class="modal-body">
          <div class="mb-3">
            <label class="form-label fw-semibold">
              Faculty Name <span class="text-danger">*</span>
            </label>
            <input type="text" name="faculty_name" id="editFacultyName"
                   class="form-control" placeholder="Faculty name" required>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary fw-bold">
            <i class="bi bi-save-fill me-1"></i>Save Changes
          </button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
function openEdit(id, name) {
    document.getElementById('editFacultyId').value   = id;
    document.getElementById('editFacultyName').value = name;
    new bootstrap.Modal(document.getElementById('editModal')).show();
}
</script>

<?php require_once '../includes/footer.php'; ?>

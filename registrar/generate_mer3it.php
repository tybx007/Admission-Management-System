<?php
$pageTitle = "Generate Merit Lists";
require_once '../includes/auth.php';
require_once '../config/db.php';
require_once '../includes/header.php';
require_once '../includes/navbar.php';

requireLogin('registrar');

// ✅ FIXED: Check if POST data exists
if (isset($_POST['generate_merit']) && $_POST['generate_merit']) {
    try {
        // 1. Clear existing merit allocations
        $pdo->exec("DELETE FROM `merit_allocations`");
        $pdo->exec("DELETE FROM `waiting_list`");
        
        // 2. Get all applicants with their top dept choice
        $stmt = $pdo->query("
            SELECT ga.applicant_id, ga.gst_score, ga.gst_roll, ga.full_name,
                   FIRST_VALUE(dc.dept_id) OVER (
                       PARTITION BY ga.applicant_id 
                       ORDER BY dc.preference_order ASC
                   ) as top_dept_id,
                   d.dept_name, d.total_seats
            FROM `gst_applicants` ga
            JOIN `dept_choices` dc ON ga.applicant_id = dc.applicant_id
            JOIN `departments` d ON dc.dept_id = d.dept_id
            ORDER BY ga.gst_score DESC
        ");
        
        $applicants = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $rank = 1;
        $allocated_count = 0;
        
        foreach ($applicants as $app) {
            // Skip if already allocated
            $exists = $pdo->prepare("SELECT COUNT(*) FROM `merit_allocations` WHERE applicant_id = ?");
            $exists->execute([$app['applicant_id']]);
            
            if ($exists->fetchColumn() == 0) {
                // Allocate to top preference
                $pdo->prepare("
                    INSERT INTO `merit_allocations` 
                    (applicant_id, dept_id, merit_rank, allocation_status) 
                    VALUES (?, ?, ?, 'Allocated')
                ")->execute([
                    $app['applicant_id'], 
                    $app['top_dept_id'], 
                    $rank++
                ]);
                $allocated_count++;
            }
        }
        
        $message = "✅ SUCCESS! Generated {$allocated_count} merit positions!";
        
    } catch (Exception $e) {
        $message = "❌ Error: " . $e->getMessage();
    }
}

// Live stats
$total_applicants = $pdo->query("SELECT COUNT(*) FROM `gst_applicants`")->fetchColumn();
$merit_count = $pdo->query("SELECT COUNT(*) FROM `merit_allocations`")->fetchColumn();
?>

<div class="container py-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2><i class="bi bi-lightning-charge"></i> Generate Merit Lists</h2>
        <a href="dashboard.php" class="btn btn-outline-secondary">← Dashboard</a>
    </div>
    
    <?php if (isset($message)): ?>
        <div class="alert alert-success alert-dismissible fade show">
            <?= htmlspecialchars($message) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>
    
    <div class="row g-4">
        <div class="col-md-8">
            <div class="card shadow">
                <div class="card-body text-center p-5">
                    <i class="bi bi-award display-1 text-primary mb-4"></i>
                    <h3>Generate Official Merit Lists</h3>
                    <p class="lead text-muted">
                        Ranks applicants by GST score → Assigns to 1st preference → Fills 45 seats per dept
                    </p>
                    <form method="POST" class="d-inline">
                        <input type="hidden" name="generate_merit" value="1">
                        <button type="submit" class="btn btn-primary btn-lg px-5 py-3">
                            <i class="bi bi-rocket-takeoff"></i> Generate Merit Now
                        </button>
                    </form>
                </div>
            </div>
        </div>
        
        <div class="col-md-4">
            <div class="card shadow h-100">
                <div class="card-header bg-primary text-white">
                    <i class="bi bi-graph-up"></i> Status
                </div>
                <div class="card-body">
                    <div class="row text-center g-3">
                        <div class="col-6">
                            <div class="h3 text-info"><?= $total_applicants ?></div>
                            <div class="text-muted small">GST Applicants</div>
                        </div>
                        <div class="col-6">
                            <div class="h3 text-success"><?= $merit_count ?></div>
                            <div class="text-muted small">Merit Positions</div>
                        </div>
                    </div>
                    
                    <?php if ($merit_count > 0): ?>
                    <hr>
                    <h6 class="text-primary mb-2">#1 Merit Student</h6>
                    <?php
                    $top = $pdo->query("
                        SELECT ga.gst_roll, ga.full_name, d.dept_name 
                        FROM `merit_allocations` ma
                        JOIN `gst_applicants` ga ON ma.applicant_id = ga.applicant_id
                        JOIN `departments` d ON ma.dept_id = d.dept_id
                        ORDER BY ma.merit_rank LIMIT 1
                    ")->fetch();
                    ?>
                    <?php if ($top): ?>
                        <div class="alert alert-success p-2 small">
                            <strong><?= htmlspecialchars($top['gst_roll']) ?></strong><br>
                            <small><?= htmlspecialchars($top['full_name']) ?></small><br>
                            <small><?= htmlspecialchars($top['dept_name']) ?></small>
                        </div>
                    <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require_once '../includes/footer.php'; ?>

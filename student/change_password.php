<?php
$pageTitle = 'Change Password';
require_once '../includes/auth.php';
require_once '../config/db.php';
require_once '../includes/header.php';
require_once '../includes/navbar.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'student') {
    header('Location: /jkkniu-admission/student/login.php'); exit();
}
$applicant_id = $_SESSION['user_id'];
$error = $success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $current  = $_POST['current_password']  ?? '';
    $new      = $_POST['new_password']      ?? '';
    $confirm  = $_POST['confirm_password']  ?? '';

    $stmt = $pdo->prepare("SELECT password FROM gst_applicants WHERE applicant_id = ?");
    $stmt->execute([$applicant_id]);
    $student = $stmt->fetch();

    if (!password_verify($current, $student['password'])) {
        $error = 'Current password is incorrect.';
    } elseif (strlen($new) < 8) {
        $error = 'New password must be at least 8 characters.';
    } elseif ($new !== $confirm) {
        $error = 'New passwords do not match.';
    } else {
        $pdo->prepare("UPDATE gst_applicants SET password = ? WHERE applicant_id = ?")
            ->execute([password_hash($new, PASSWORD_DEFAULT), $applicant_id]);
        $success = 'Password changed successfully!';
    }
}
?>

<div class="container py-4">
    <div class="row justify-content-center">
        <div class="col-md-5">
            <div class="card shadow-sm">
                <div class="card-header">
                    <i class="bi bi-lock-fill"></i> Change Password
                </div>
                <div class="card-body p-4">

                    <?php if ($error): ?>
                        <div class="alert alert-danger"><i class="bi bi-x-circle-fill"></i> <?= $error ?></div>
                    <?php endif; ?>
                    <?php if ($success): ?>
                        <div class="alert alert-success"><i class="bi bi-check-circle-fill"></i> <?= $success ?></div>
                    <?php endif; ?>

                    <form method="POST" novalidate>
                        <div class="mb-3">
                            <label class="form-label fw-semibold">Current Password</label>
                            <input type="password" name="current_password"
                                   class="form-control" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-semibold">New Password</label>
                            <input type="password" name="new_password"
                                   class="form-control" minlength="8" required>
                            <div class="form-text">Minimum 8 characters.</div>
                        </div>
                        <div class="mb-4">
                            <label class="form-label fw-semibold">Confirm New Password</label>
                            <input type="password" name="confirm_password"
                                   class="form-control" required>
                        </div>
                        <div class="d-flex gap-2">
                            <button type="submit" class="btn btn-primary fw-bold">
                                <i class="bi bi-lock-fill"></i> Update Password
                            </button>
                            <a href="dashboard.php" class="btn btn-outline-secondary">Cancel</a>
                        </div>
                    </form>

                </div>
            </div>
        </div>
    </div>
</div>

<?php require_once '../includes/footer.php'; ?>

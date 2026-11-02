<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function isLoggedIn() {
    return isset($_SESSION['user_id']) && isset($_SESSION['role']);
}

function requireLogin($role = null) {
    if (!isLoggedIn()) {
        header('Location: /jkkniu-admission/index.php');
        exit();
    }
    if ($role !== null && $_SESSION['role'] !== $role) {
        header('Location: /jkkniu-admission/index.php');
        exit();
    }
}

function redirectIfLoggedIn() {
    if (isLoggedIn()) {
        switch ($_SESSION['role']) {
            case 'student':
                header('Location: /jkkniu-admission/student/dashboard.php');
                break;
            case 'dept_admin':
                header('Location: /jkkniu-admission/dept_admin/dashboard.php');
                break;
            case 'hall_admin':
                header('Location: /jkkniu-admission/hall_admin/dashboard.php');
                break;
            case 'registrar':
                header('Location: /jkkniu-admission/registrar/dashboard.php');
                break;
            case 'super_admin':
                header('Location: /jkkniu-admission/super_admin/dashboard.php');
                break;
        }
        exit();
    }
}

function currentRole() {
    return $_SESSION['role'] ?? null;
}

function currentUserId() {
    return $_SESSION['user_id'] ?? null;
}
?>

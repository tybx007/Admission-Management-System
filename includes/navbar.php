<?php if (session_status() === PHP_SESSION_NONE) session_start(); ?>
<nav class="navbar navbar-expand-lg navbar-dark bg-dark">
    <div class="container">
        <a class="navbar-brand fw-bold" href="/jkkniu-admission/index.php">
            <img src="/jkkniu-admission/assets/img/logo.png" height="35" alt="JKKNIU" class="me-2">
            JKKNIU Post-GST Enrollment
        </a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="navbarNav">
            <ul class="navbar-nav ms-auto">
                <li class="nav-item">
                    <a class="nav-link" href="/jkkniu-admission/index.php">Home</a>
                </li>
                <?php if (isLoggedIn()): ?>
                    <?php if ($_SESSION['role'] === 'student'): ?>
                        <li class="nav-item">
                            <a class="nav-link" href="/jkkniu-admission/student/dashboard.php">Dashboard</a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="/jkkniu-admission/student/progress.php">My Progress</a>
                        </li>
                    <?php endif; ?>
                    <li class="nav-item">
                        <a class="nav-link text-danger" href="/jkkniu-admission/<?= $_SESSION['role'] ?>/logout.php">
                            <i class="bi bi-box-arrow-right"></i> Logout
                        </a>
                    </li>
                <?php else: ?>
                    <li class="nav-item">
                        <a class="nav-link" href="/jkkniu-admission/student/login.php">Student Login</a>
                    </li>
                <?php endif; ?>
            </ul>
        </div>
    </div>
</nav>

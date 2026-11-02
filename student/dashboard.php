<?php

declare(strict_types=1);

$pageTitle = 'Student Dashboard';

require_once '../includes/auth.php';
require_once '../config/db.php';
require_once '../includes/admission_session.php';

/*
|--------------------------------------------------------------------------
| AUTH CHECK - MUST HAPPEN BEFORE HEADER/NAVBAR OUTPUT
|--------------------------------------------------------------------------
*/

if (
    !isset($_SESSION['user_id']) ||
    ($_SESSION['role'] ?? '') !== 'student'
) {
    header('Location: /jkkniu-admission/student/login.php');
    exit;
}

$applicantId = (int) $_SESSION['user_id'];

$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$currentAcademicYear = admissionSessionCurrentYear($pdo);

/*
|--------------------------------------------------------------------------
| HELPERS
|--------------------------------------------------------------------------
*/

function e(mixed $value): string
{
    return htmlspecialchars(
        (string) ($value ?? ''),
        ENT_QUOTES,
        'UTF-8'
    );
}

function hasValue(mixed $value): bool
{
    return $value !== null && trim((string) $value) !== '';
}

function showValue(mixed $value, string $fallback = 'N/A'): string
{
    return hasValue($value) ? (string) $value : $fallback;
}

function preferCompletedValue(mixed $applicantValue, mixed $importedValue): mixed
{
    return hasValue($applicantValue)
        ? $applicantValue
        : $importedValue;
}

function showDate(mixed $value): string
{
    if (!hasValue($value)) {
        return 'N/A';
    }

    $time = strtotime((string) $value);

    return $time === false
        ? (string) $value
        : date('d M Y', $time);
}

/*
|--------------------------------------------------------------------------
| 1. REGISTERED ACCOUNT
|--------------------------------------------------------------------------
|
| gst_applicants is now mainly the account + student-completed profile.
| GST supplied information will be read from gst_imported_candidates.
|
*/

$stmt = $pdo->prepare("
    SELECT *
    FROM gst_applicants
    WHERE applicant_id = ?
    LIMIT 1
");
$stmt->execute([$applicantId]);
$applicant = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$applicant) {
    session_unset();
    session_destroy();

    header('Location: /jkkniu-admission/student/login.php');
    exit;
}

/*
|--------------------------------------------------------------------------
| 2. AUTHORITATIVE IMPORTED GST RECORD
|--------------------------------------------------------------------------
*/

$stmt = $pdo->prepare("
    SELECT *
    FROM gst_imported_candidates
    WHERE applicant_id = ?
      AND academic_year = ?
    LIMIT 1
");
$stmt->execute([
    $applicantId,
    $currentAcademicYear
]);
$imported = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

$importCandidateId = isset($imported['import_candidate_id'])
    ? (int) $imported['import_candidate_id']
    : 0;

/*
|--------------------------------------------------------------------------
| 3. EFFECTIVE PERSONAL PROFILE
|--------------------------------------------------------------------------
|
| If the student later completes a value in gst_applicants, use it.
| Otherwise show the value already supplied in the GST imported file.
|
*/

$profile = [
    'full_name' => preferCompletedValue(
        $applicant['full_name'] ?? null,
        $imported['full_name'] ?? null
    ),
    'father_name' => $applicant['father_name'] ?? null,
    'mother_name' => $applicant['mother_name'] ?? null,
    'date_of_birth' => preferCompletedValue(
        $applicant['date_of_birth'] ?? null,
        $imported['date_of_birth'] ?? null
    ),
    'gender' => preferCompletedValue(
        $applicant['gender'] ?? null,
        $imported['gender'] ?? null
    ),
    'nationality' => preferCompletedValue(
        $applicant['nationality'] ?? null,
        $imported['nationality'] ?? null
    ),
    'quota' => preferCompletedValue(
        $applicant['quota'] ?? null,
        $imported['quota'] ?? null
    ),
    'email' => $applicant['email'] ?? null,
    'phone' => preferCompletedValue(
        $applicant['phone'] ?? null,
        $imported['phone'] ?? null
    ),
    'photo' => $applicant['photo'] ?? null,
    'signature' => $applicant['signature'] ?? null,
];

/*
|--------------------------------------------------------------------------
| 4. CURRENT MERIT ALLOCATION
|--------------------------------------------------------------------------
|
| Use import_candidate_id because merit exists before registration.
|
*/

$merit = false;

if ($importCandidateId > 0) {
    $stmt = $pdo->prepare("
        SELECT
            ma.*,
            d.dept_name,
            d.dept_code,
            f.faculty_name AS alloc_faculty
        FROM merit_allocations ma
        INNER JOIN departments d
            ON d.dept_id = ma.dept_id
        INNER JOIN faculties f
            ON f.faculty_id = d.faculty_id
        WHERE ma.import_candidate_id = ?
          AND ma.allocation_status IN ('Allocated', 'Admitted')
        ORDER BY ma.allocation_id DESC
        LIMIT 1
    ");
    $stmt->execute([$importCandidateId]);
    $merit = $stmt->fetch(PDO::FETCH_ASSOC);
}

/*
|--------------------------------------------------------------------------
| 5. DEPARTMENT PREFERENCES FROM IMPORTED GST CHOICES
|--------------------------------------------------------------------------
*/

$deptChoices = [];

if ($importCandidateId > 0) {
    $stmt = $pdo->prepare("
        SELECT
            gcc.preference_order,
            d.dept_id,
            d.dept_code,
            d.dept_name,
            f.faculty_name
        FROM gst_candidate_choices gcc
        INNER JOIN departments d
            ON d.dept_id = gcc.dept_id
        LEFT JOIN faculties f
            ON f.faculty_id = d.faculty_id
        WHERE gcc.import_candidate_id = ?
        ORDER BY gcc.preference_order ASC
    ");
    $stmt->execute([$importCandidateId]);
    $deptChoices = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/*
|--------------------------------------------------------------------------
| 6. ADMISSION PROGRESS
|--------------------------------------------------------------------------
*/

$stmt = $pdo->prepare("
    SELECT *
    FROM admission_progress
    WHERE applicant_id = ?
    LIMIT 1
");
$stmt->execute([$applicantId]);
$progress = $stmt->fetch(PDO::FETCH_ASSOC);

/*
|--------------------------------------------------------------------------
| 6A. MIGRATION STATUS
|--------------------------------------------------------------------------
|
| Migration is available only after final admission.
| The candidate can permanently Opt Out from student/migration.php.
|--------------------------------------------------------------------------
*/

$currentPreferenceOrder = 0;

if ($merit && $deptChoices) {
    foreach ($deptChoices as $choice) {
        if ((int) $choice['dept_id'] === (int) $merit['dept_id']) {
            $currentPreferenceOrder =
                (int) $choice['preference_order'];
            break;
        }
    }
}

$migrationAvailable =
    $progress
    && (string) ($progress['final_status'] ?? '') === 'Admitted'
    && $merit
    && (string) ($merit['allocation_status'] ?? '') === 'Admitted'
    && $currentPreferenceOrder > 1;

$migrationStatus =
    $progress
        ? (string) ($progress['migration_status'] ?? 'Enabled')
        : '';

$migrationOptedOut =
    $migrationStatus === 'Opted Out';

/*
|--------------------------------------------------------------------------
| 7. VIVA RESULT
|--------------------------------------------------------------------------
*/

$stmt = $pdo->prepare("
    SELECT *
    FROM viva_results
    WHERE applicant_id = ?
    LIMIT 1
");
$stmt->execute([$applicantId]);
$viva = $stmt->fetch(PDO::FETCH_ASSOC);

/*
|--------------------------------------------------------------------------
| 8. GST SUBJECT MARKS FROM THE IMPORTED FILE
|--------------------------------------------------------------------------
*/

$subjectColumns = [
    'Physics' => 'physics_mark',
    'Chemistry' => 'chemistry_mark',
    'Mathematics' => 'mathematics_mark',
    'Biology' => 'biology_mark',
    'Bangla' => 'bangla_mark',
    'English' => 'english_mark',
    'General Knowledge' => 'general_knowledge_mark',
];

$subjectMarks = [];

foreach ($subjectColumns as $subjectName => $column) {
    if (array_key_exists($column, $imported) && hasValue($imported[$column])) {
        $subjectMarks[] = [
            'subject_name' => $subjectName,
            'marks_obtained' => $imported[$column],
        ];
    }
}

/*
|--------------------------------------------------------------------------
| 9. PROFILE COMPLETION CHECK
|--------------------------------------------------------------------------
|
| GST data does NOT need to be entered again.
| Only information unavailable from GST needs completion.
|
*/

$completionFields = [
    'father_name' => 'Father\'s Name',
    'mother_name' => 'Mother\'s Name',
    'date_of_birth' => 'Date of Birth',
    'gender' => 'Gender',
    'nationality' => 'Nationality',
    'photo' => 'Photo',
    'signature' => 'Signature',
];

$missingFields = [];

foreach ($completionFields as $key => $label) {
    if (!hasValue($profile[$key] ?? null)) {
        $missingFields[] = $label;
    }
}

$profileComplete = count($missingFields) === 0;

/*
|--------------------------------------------------------------------------
| 10. OVERALL STATUS
|--------------------------------------------------------------------------
*/

$overallStatus = 'registered';
$overallLabel = 'Registered';
$overallBadge = 'bg-secondary';
$overallIcon = 'bi-person-check-fill';

if ($progress) {
    switch ((string) ($progress['final_status'] ?? '')) {
        case 'Admitted':
            $overallStatus = 'admitted';
            $overallLabel = 'Finally Admitted';
            $overallBadge = 'bg-success';
            $overallIcon = 'bi-mortarboard-fill';
            break;

        case 'Rejected':
            $overallStatus = 'rejected';
            $overallLabel = 'Admission Rejected';
            $overallBadge = 'bg-danger';
            $overallIcon = 'bi-x-circle-fill';
            break;

        case 'Withdrawn':
            $overallStatus = 'withdrawn';
            $overallLabel = 'Withdrawn';
            $overallBadge = 'bg-dark';
            $overallIcon = 'bi-slash-circle-fill';
            break;

        default:
            $overallStatus = 'inprogress';
            $overallLabel = 'Admission In Progress';
            $overallBadge = 'bg-primary';
            $overallIcon = 'bi-arrow-repeat';
            break;
    }
} elseif ($merit) {
    $overallStatus = 'allocated';
    $overallLabel = 'Department Allocated';
    $overallBadge = 'bg-info text-dark';
    $overallIcon = 'bi-award-fill';
} elseif ($viva) {
    $vivaStatus = (string) ($viva['viva_status'] ?? '');

    if ($vivaStatus === 'Selected') {
        $overallStatus = 'viva_selected';
        $overallLabel = 'Selected in Viva';
        $overallBadge = 'bg-warning text-dark';
        $overallIcon = 'bi-check-circle-fill';
    } elseif ($vivaStatus === 'Not Selected') {
        $overallStatus = 'not_selected';
        $overallLabel = 'Not Selected in Viva';
        $overallBadge = 'bg-danger';
        $overallIcon = 'bi-x-circle-fill';
    } else {
        $overallStatus = 'absent';
        $overallLabel = 'Absent in Viva';
        $overallBadge = 'bg-secondary';
        $overallIcon = 'bi-dash-circle-fill';
    }
}

$bannerClass = match ($overallStatus) {
    'admitted' => 'alert-success',
    'rejected', 'not_selected' => 'alert-danger',
    'allocated', 'viva_selected' => 'alert-warning',
    'inprogress' => 'alert-primary',
    default => 'alert-secondary',
};

/*
|--------------------------------------------------------------------------
| LOAD HTML ONLY AFTER ALL REDIRECTS
|--------------------------------------------------------------------------
*/

require_once '../includes/header.php';
require_once '../includes/navbar.php';
?>

<div class="container py-4">

    <?php if (!$profileComplete): ?>
        <div class="alert alert-warning shadow-sm mb-4">
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">
                <div>
                    <div class="fw-bold">
                        <i class="bi bi-exclamation-triangle-fill me-1"></i>
                        Complete Your Applicant Profile
                    </div>
                    <div class="small mt-1">
                        Your GST information is already loaded automatically. Complete only the information that GST did not provide.
                    </div>
                    <div class="small mt-2">
                        <strong>Missing:</strong>
                        <?= e(implode(', ', $missingFields)) ?>
                    </div>
                </div>

                <a
                    href="/jkkniu-admission/student/complete_profile.php"
                    class="btn btn-warning fw-semibold"
                >
                    <i class="bi bi-pencil-square me-1"></i>
                    Complete Profile
                </a>
            </div>
        </div>
    <?php endif; ?>

    <!-- STATUS BANNER -->
    <div class="alert <?= e($bannerClass) ?> d-flex align-items-center justify-content-between flex-wrap gap-2 mb-4">
        <div class="d-flex align-items-center gap-2">
            <i class="bi <?= e($overallIcon) ?> fs-4"></i>
            <div>
                <strong>Admission Status</strong>
                <span class="badge <?= e($overallBadge) ?> ms-2 fs-6">
                    <?= e($overallLabel) ?>
                </span>

                <?php if ($merit): ?>
                    <span class="badge bg-dark ms-1">
                        <?= e(
                            hasValue($merit['dept_code'] ?? null)
                                ? $merit['dept_code']
                                : $merit['dept_name']
                        ) ?>
                        — Rank #<?= (int) $merit['merit_rank'] ?>
                    </span>
                <?php endif; ?>

                <?php if (
                    $progress
                    && (string) ($progress['final_status'] ?? '') === 'Admitted'
                    && $currentPreferenceOrder > 1
                ): ?>

                    <span class="badge <?= $migrationOptedOut
                        ? 'bg-danger'
                        : 'bg-success'
                    ?> ms-1">
                        Migration:
                        <?= $migrationOptedOut
                            ? 'Opted Out'
                            : 'Enabled'
                        ?>
                    </span>

                <?php endif; ?>
            </div>
        </div>

        <div class="text-end">
            <small class="opacity-75 d-block">
                GST Roll:
                <strong>
                    <?= e(showValue(
                        $imported['gst_roll']
                            ?? $applicant['gst_roll']
                            ?? null
                    )) ?>
                </strong>
            </small>

            <small class="opacity-75">
                Registered:
                <strong><?= e(showDate($applicant['created_at'] ?? null)) ?></strong>
            </small>
        </div>
    </div>

    <!-- ADMISSION PROGRESS -->
    <?php if ($progress): ?>
        <div class="card shadow-sm mb-4">
            <div class="card-header bg-success text-white py-2 fw-semibold">
                <i class="bi bi-ui-checks me-1"></i>
                Admission Progress
            </div>

            <div class="card-body py-3">
                <div class="row justify-content-center g-3 text-center">
                    <?php
                    $steps = [
                        [
                            'label' => 'Department Submission',
                            'icon' => 'bi-building',
                            'status' => (string) ($progress['dept_step'] ?? 'Pending'),
                            'done' => (($progress['dept_step'] ?? 'Pending') === 'Done'),
                        ],
                        [
                            'label' => 'Hall Submission',
                            'icon' => 'bi-house-fill',
                            'status' => (string) ($progress['hall_step'] ?? 'Pending'),
                            'done' => (($progress['hall_step'] ?? 'Pending') === 'Done'),
                        ],
                        [
                            'label' => 'Registrar Approval',
                            'icon' => 'bi-patch-check-fill',
                            'status' => (string) ($progress['registrar_step'] ?? 'Pending'),
                            'done' => (($progress['registrar_step'] ?? 'Pending') === 'Approved'),
                        ],
                    ];

                    foreach ($steps as $step):
                        $isDone = (bool) $step['done'];
                        $status = (string) $step['status'];
                        $isRejected = $status === 'Rejected';
                        $isReview = $status === 'Under Review';
                        $isSubmitted = $status === 'Submitted';

                        $textClass = $isDone
                            ? 'text-success'
                            : ($isRejected
                                ? 'text-danger'
                                : (($isReview || $isSubmitted)
                                    ? 'text-warning'
                                    : 'text-secondary'));

                        $circleClass = $isDone
                            ? 'bg-success'
                            : ($isRejected
                                ? 'bg-danger'
                                : (($isReview || $isSubmitted)
                                    ? 'bg-warning'
                                    : 'bg-light border'));

                        $badgeClass = $isDone
                            ? 'bg-success'
                            : ($isRejected
                                ? 'bg-danger'
                                : ($isReview
                                    ? 'bg-warning text-dark'
                                    : ($isSubmitted
                                        ? 'bg-info text-dark'
                                        : 'bg-secondary')));
                    ?>
                        <div class="col-6 col-md-4">
                            <div class="d-flex flex-column align-items-center gap-1">
                                <div
                                    class="rounded-circle d-flex align-items-center justify-content-center <?= e($circleClass) ?>"
                                    style="width:52px;height:52px;"
                                >
                                    <i class="bi <?= e($step['icon']) ?> fs-4 <?= $isDone ? 'text-white' : e($textClass) ?>"></i>
                                </div>

                                <small class="fw-semibold <?= e($textClass) ?>">
                                    <?= e($step['label']) ?>
                                </small>

                                <span class="badge <?= e($badgeClass) ?>">
                                    <?= e($isDone ? 'Done' : showValue($status, 'Pending')) ?>
                                </span>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <div class="row g-4">

        <!-- LEFT COLUMN -->
        <div class="col-lg-4">

            <!-- PROFILE CARD -->
            <div class="card shadow-sm mb-4">
                <div class="card-body text-center p-4">
                    <?php
                    /*
                    |--------------------------------------------------------------------------
                    | Safe profile photo rendering
                    |--------------------------------------------------------------------------
                    |
                    | Do not use an onerror fallback to another image.
                    | If the fallback image is also missing, browsers may repeatedly
                    | retry the broken source and make the page appear to blink.
                    |
                    | We verify the file on the server first. If there is no valid
                    | uploaded photo, render a Bootstrap icon placeholder instead.
                    |--------------------------------------------------------------------------
                    */

                    $photoName = trim(
                        (string) ($profile['photo'] ?? '')
                    );

                    $safePhotoName = $photoName !== ''
                        ? basename($photoName)
                        : '';

                    $photoDiskPath = $safePhotoName !== ''
                        ? __DIR__ . '/../uploads/photos/' . $safePhotoName
                        : '';

                    $photoExists =
                        $safePhotoName !== '' &&
                        is_file($photoDiskPath);

                    $photoUrl = $photoExists
                        ? '/jkkniu-admission/uploads/photos/' .
                            rawurlencode($safePhotoName)
                        : '';
                    ?>

                    <?php if ($photoExists): ?>

                        <img
                            src="<?= e($photoUrl) ?>"
                            alt="Applicant Photo"
                            class="rounded-circle border border-3 border-primary mb-3"
                            style="
                                width:110px;
                                height:110px;
                                object-fit:cover;
                            "
                        >

                    <?php else: ?>

                        <div
                            class="rounded-circle border border-3 border-primary bg-light
                                   d-inline-flex align-items-center justify-content-center mb-3"
                            style="
                                width:110px;
                                height:110px;
                            "
                            aria-label="No profile photo uploaded"
                        >
                            <i
                                class="bi bi-person-fill text-secondary"
                                style="font-size:4rem;"
                            ></i>
                        </div>

                    <?php endif; ?>

                    <h5 class="fw-bold mb-1">
                        <?= e(showValue($profile['full_name'])) ?>
                    </h5>

                    <?php if ($merit): ?>
                        <p class="text-muted small mb-1">
                            <?= e(showValue($merit['alloc_faculty'] ?? null)) ?>
                        </p>
                    <?php endif; ?>

                    <p class="text-muted small mb-2">
                        GST Roll:
                        <strong>
                            <?= e(showValue(
                                $imported['gst_roll']
                                    ?? $applicant['gst_roll']
                                    ?? null
                            )) ?>
                        </strong>
                    </p>

                    <div class="d-flex justify-content-center gap-4 my-3 flex-wrap">
                        <div>
                            <div class="fw-bold fs-4 text-primary">
                                <?= e(showValue(
                                    $imported['gst_score']
                                        ?? $applicant['gst_score']
                                        ?? null
                                )) ?>
                            </div>
                            <small class="text-muted">GST Score</small>
                        </div>

                        <?php if (hasValue($imported['merit_position'] ?? null)): ?>
                            <div>
                                <div class="fw-bold fs-4 text-dark">
                                    #<?= (int) $imported['merit_position'] ?>
                                </div>
                                <small class="text-muted">GST Merit</small>
                            </div>
                        <?php endif; ?>

                        <?php if ($merit): ?>
                            <div>
                                <div class="fw-bold fs-4 text-success">
                                    #<?= (int) $merit['merit_rank'] ?>
                                </div>
                                <small class="text-muted">Dept. Rank</small>
                            </div>
                        <?php endif; ?>
                    </div>

                    <?php if (hasValue($profile['signature'])): ?>
                        <div class="border-top pt-3">
                            <small class="text-muted d-block mb-1">Signature</small>
                            <img
                                src="/jkkniu-admission/uploads/signatures/<?= e($profile['signature']) ?>"
                                alt="Signature"
                                class="img-fluid border rounded"
                                style="max-height:50px;"
                                onerror="this.style.display='none'"
                            >
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- ALLOCATED DEPARTMENT -->
            <?php if ($merit): ?>
                <div class="card shadow-sm border-success mb-4">
                    <div class="card-header bg-success text-white py-2 fw-semibold">
                        <i class="bi bi-award-fill me-1"></i>
                        Allocated Department
                    </div>
                    <div class="card-body">
                        <p class="fw-bold fs-5 mb-1 text-success">
                            <?= e(
                                hasValue($merit['dept_code'] ?? null)
                                    ? $merit['dept_code'] . ' — ' . $merit['dept_name']
                                    : $merit['dept_name']
                            ) ?>
                        </p>

                        <p class="text-muted small mb-2">
                            <?= e(showValue($merit['alloc_faculty'] ?? null)) ?>
                        </p>

                        <div class="d-flex gap-2 flex-wrap">
                            <span class="badge bg-primary">
                                Department Rank #<?= (int) $merit['merit_rank'] ?>
                            </span>

                            <span class="badge bg-warning text-dark">
                                <?= e($merit['allocation_status']) ?>
                            </span>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <!-- MIGRATION -->
            <div class="card shadow-sm border-primary mb-4">

                <div class="card-header bg-primary text-white py-2 fw-semibold">
                    <i class="bi bi-arrow-up-right-circle-fill me-1"></i>
                    Migration
                </div>

                <div class="card-body">

                    <?php
                    $finalAdmissionCompleted =
                        $progress
                        && (string) ($progress['final_status'] ?? '') === 'Admitted'
                        && $merit
                        && (string) ($merit['allocation_status'] ?? '') === 'Admitted';
                    ?>


                    <?php if (!$finalAdmissionCompleted): ?>

                        <div class="mb-2">
                            <span class="badge bg-secondary">
                                MIGRATION LOCKED
                            </span>
                        </div>

                        <div class="small text-muted mb-3">
                            Migration becomes available after your
                            <strong>Final Admission</strong> is completed.
                        </div>

                        <a
                            href="/jkkniu-admission/student/migration.php"
                            class="btn btn-outline-secondary w-100"
                        >
                            <i class="bi bi-lock-fill me-1"></i>
                            View Migration
                        </a>


                    <?php elseif ($currentPreferenceOrder <= 1): ?>

                        <div class="alert alert-success mb-0">

                            <strong>
                                <i class="bi bi-check-circle-fill me-1"></i>
                                First Choice Achieved
                            </strong>

                            <div class="small mt-1">
                                You are already finally admitted to your
                                first-choice department. No higher Migration
                                is possible.
                            </div>

                        </div>


                    <?php elseif ($migrationOptedOut): ?>

                        <div class="mb-2">
                            <span class="badge bg-danger">
                                MIGRATION OPTED OUT
                            </span>
                        </div>

                        <div class="small text-muted">
                            You are permanently outside the Migration system
                            for this admission session.
                        </div>

                        <?php if (!empty($progress['migration_opted_out_at'])): ?>

                            <div class="small text-muted mt-2">
                                Opted out:
                                <?= e(
                                    date(
                                        'd M Y, h:i A',
                                        strtotime(
                                            (string)
                                            $progress['migration_opted_out_at']
                                        )
                                    )
                                ) ?>
                            </div>

                        <?php endif; ?>

                        <a
                            href="/jkkniu-admission/student/migration.php"
                            class="btn btn-outline-danger w-100 mt-3"
                        >
                            <i class="bi bi-eye me-1"></i>
                            View Migration Status
                        </a>


                    <?php else: ?>

                        <div class="mb-2">
                            <span class="badge bg-success">
                                MIGRATION ENABLED
                            </span>
                        </div>

                        <div class="small text-muted mb-2">

                            Current allocation:

                            <strong>
                                Choice #<?= $currentPreferenceOrder ?>
                            </strong>.

                        </div>

                        <div class="small text-muted mb-3">
                            You will be considered automatically for a vacant
                            seat in a higher-preference department according
                            to GST merit order.
                        </div>

                        <a
                            href="/jkkniu-admission/student/migration.php"
                            class="btn btn-outline-primary w-100"
                        >
                            <i class="bi bi-sliders me-1"></i>
                            Migration Settings
                        </a>

                    <?php endif; ?>

                </div>

            </div>

            <!-- IMPORTED DEPARTMENT PREFERENCES -->
            <div class="card shadow-sm mb-4">
                <div class="card-header bg-success text-white py-2 fw-semibold">
                    <i class="bi bi-list-ol me-1"></i>
                    GST Department Preferences
                </div>

                <div class="card-body p-0">
                    <?php if ($deptChoices): ?>
                        <ol class="list-group list-group-numbered list-group-flush">
                            <?php foreach ($deptChoices as $choice): ?>
                                <li class="list-group-item d-flex justify-content-between align-items-center gap-2 py-2 px-3">
                                    <div>
                                        <div class="small fw-semibold">
                                            <?= e($choice['dept_name']) ?>
                                        </div>

                                        <div class="text-muted" style="font-size:.75rem;">
                                            <?= e(showValue($choice['dept_code'] ?? null, '')) ?>
                                            <?php if (hasValue($choice['faculty_name'] ?? null)): ?>
                                                <?= hasValue($choice['dept_code'] ?? null) ? ' · ' : '' ?>
                                                <?= e($choice['faculty_name']) ?>
                                            <?php endif; ?>
                                        </div>
                                    </div>

                                    <span class="badge bg-primary rounded-pill">
                                        Choice <?= (int) $choice['preference_order'] ?>
                                    </span>
                                </li>
                            <?php endforeach; ?>
                        </ol>
                    <?php else: ?>
                        <p class="text-muted small p-3 mb-0">
                            No imported department preferences were found.
                        </p>
                    <?php endif; ?>
                </div>
            </div>

            <!-- QUICK ACTIONS -->
            <div class="card shadow-sm">
                <div class="card-header bg-success text-white py-2 fw-semibold">
                    <i class="bi bi-lightning-fill text-warning me-1"></i>
                    Quick Actions
                </div>

                <div class="list-group list-group-flush">
                    <a
                        href="/jkkniu-admission/student/complete_profile.php"
                        class="list-group-item list-group-item-action py-2"
                    >
                        <i class="bi bi-person-lines-fill text-primary me-2"></i>
                        Complete Profile
                    </a>

                    <a
                        href="/jkkniu-admission/student/print_application.php"
                        class="list-group-item list-group-item-action py-2"
                        target="_blank"
                    >
                        <i class="bi bi-printer-fill text-primary me-2"></i>
                        Print Application
                    </a>

                    <a
                        href="/jkkniu-admission/student/edit_profile.php"
                        class="list-group-item list-group-item-action py-2"
                    >
                        <i class="bi bi-pencil-fill text-success me-2"></i>
                        Edit Profile
                    </a>

                    <a
                        href="/jkkniu-admission/student/change_password.php"
                        class="list-group-item list-group-item-action py-2"
                    >
                        <i class="bi bi-key-fill text-warning me-2"></i>
                        Change Password
                    </a>

                    <?php
                    /*
                    |--------------------------------------------------------------------------
                    | Migration is ALWAYS visible in Quick Actions
                    |--------------------------------------------------------------------------
                    |
                    | Locked      = Final Admission not completed
                    | First Choice = No migration required
                    | On          = Migration Enabled
                    | Off         = Permanently Opted Out
                    |--------------------------------------------------------------------------
                    */

                    $finalAdmissionCompleted =
                        $progress
                        && (string) ($progress['final_status'] ?? '') === 'Admitted'
                        && $merit
                        && (string) ($merit['allocation_status'] ?? '') === 'Admitted';

                    if (!$finalAdmissionCompleted) {
                        $migrationQuickBadge = 'Locked';
                        $migrationQuickBadgeClass = 'bg-secondary';
                    } elseif ($currentPreferenceOrder <= 1) {
                        $migrationQuickBadge = 'First Choice';
                        $migrationQuickBadgeClass = 'bg-success';
                    } elseif ($migrationOptedOut) {
                        $migrationQuickBadge = 'Off';
                        $migrationQuickBadgeClass = 'bg-danger';
                    } else {
                        $migrationQuickBadge = 'On';
                        $migrationQuickBadgeClass = 'bg-success';
                    }
                    ?>

                    <a
                        href="/jkkniu-admission/student/migration.php"
                        class="list-group-item list-group-item-action py-2"
                    >
                        <i class="bi bi-arrow-up-right-circle-fill text-primary me-2"></i>
                        Migration

                        <span class="badge <?= e($migrationQuickBadgeClass) ?> float-end">
                            <?= e($migrationQuickBadge) ?>
                        </span>
                    </a>

                    <a
                        href="/jkkniu-admission/student/logout.php"
                        class="list-group-item list-group-item-action py-2 text-danger"
                    >
                        <i class="bi bi-box-arrow-right me-2"></i>
                        Logout
                    </a>
                </div>
            </div>
        </div>

        <!-- RIGHT COLUMN -->
        <div class="col-lg-8">

            <!-- GST IMPORT INFORMATION -->
            <div class="card shadow-sm mb-4">
                <div class="card-header bg-dark text-white py-2 fw-semibold">
                    <i class="bi bi-file-earmark-check-fill text-info me-1"></i>
                    GST Imported Information
                </div>

                <div class="card-body">
                    <div class="row g-3">
                        <?php
                        $gstFields = [
                            'GST Roll' => $imported['gst_roll'] ?? $applicant['gst_roll'] ?? null,
                            'GST Applicant ID' => $imported['gst_applicant_id'] ?? null,
                            'GST Unit' => $imported['gst_unit'] ?? null,
                            'GST Score' => $imported['gst_score'] ?? $applicant['gst_score'] ?? null,
                            'GST Merit Position' => $imported['merit_position'] ?? null,
                            'Academic Year' => $imported['academic_year'] ?? null,
                            'Selection Status' => $imported['selection_status'] ?? null,
                            'Selected University' => $imported['selected_university_name'] ?? null,
                        ];

                        foreach ($gstFields as $label => $value):
                        ?>
                            <div class="col-md-6">
                                <small class="text-muted d-block"><?= e($label) ?></small>
                                <span class="fw-semibold"><?= e(showValue($value)) ?></span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <!-- PERSONAL INFORMATION -->
            <div class="card shadow-sm mb-4">
                <div class="card-header bg-dark text-white py-2 fw-semibold">
                    <i class="bi bi-person-fill text-primary me-1"></i>
                    Personal Information
                </div>

                <div class="card-body">
                    <div class="row g-3">
                        <?php
                        $personalFields = [
                            'Full Name' => $profile['full_name'],
                            'Father\'s Name' => $profile['father_name'],
                            'Mother\'s Name' => $profile['mother_name'],
                            'Date of Birth' => showDate($profile['date_of_birth']),
                            'Gender' => $profile['gender'],
                            'Nationality' => $profile['nationality'],
                            'Quota' => showValue($profile['quota'], 'None'),
                            'Email' => $profile['email'],
                            'Phone' => $profile['phone'],
                        ];

                        foreach ($personalFields as $label => $value):
                            $missing = !hasValue($value) || $value === 'N/A';
                        ?>
                            <div class="col-md-6">
                                <small class="text-muted d-block"><?= e($label) ?></small>

                                <?php if ($missing): ?>
                                    <span class="text-danger small fw-semibold">
                                        Not completed
                                    </span>
                                <?php else: ?>
                                    <span class="fw-semibold"><?= e($value) ?></span>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <!-- HSC INFORMATION FROM IMPORT -->
            <div class="card shadow-sm mb-4">
                <div class="card-header bg-dark text-white py-2 fw-semibold">
                    <i class="bi bi-mortarboard-fill text-success me-1"></i>
                    HSC Information
                    <span class="badge bg-info text-dark ms-1">GST Imported</span>
                </div>

                <div class="card-body">
                    <div class="row g-3">
                        <?php
                        $hscFields = [
                            'Roll' => $imported['hsc_roll'] ?? null,
                            'Registration' => $imported['hsc_reg'] ?? null,
                            'Group' => $imported['hsc_group'] ?? null,
                            'Board' => $imported['hsc_board'] ?? null,
                            'Year' => $imported['hsc_year'] ?? null,
                            'GPA' => $imported['hsc_gpa'] ?? null,
                            'Institute' => $imported['hsc_institute'] ?? null,
                        ];

                        foreach ($hscFields as $label => $value):
                        ?>
                            <div class="col-md-4">
                                <small class="text-muted d-block"><?= e($label) ?></small>
                                <span class="fw-semibold"><?= e(showValue($value)) ?></span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <!-- SSC INFORMATION FROM IMPORT -->
            <div class="card shadow-sm mb-4">
                <div class="card-header bg-dark text-white py-2 fw-semibold">
                    <i class="bi bi-mortarboard text-info me-1"></i>
                    SSC Information
                    <span class="badge bg-info text-dark ms-1">GST Imported</span>
                </div>

                <div class="card-body">
                    <div class="row g-3">
                        <?php
                        $sscFields = [
                            'Roll' => $imported['ssc_roll'] ?? null,
                            'Registration' => $imported['ssc_reg'] ?? null,
                            'Group' => $imported['ssc_group'] ?? null,
                            'Board' => $imported['ssc_board'] ?? null,
                            'Year' => $imported['ssc_year'] ?? null,
                            'GPA' => $imported['ssc_gpa'] ?? null,
                            'Institute' => $imported['ssc_institute'] ?? null,
                        ];

                        foreach ($sscFields as $label => $value):
                        ?>
                            <div class="col-md-4">
                                <small class="text-muted d-block"><?= e($label) ?></small>
                                <span class="fw-semibold"><?= e(showValue($value)) ?></span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <!-- GST SUBJECT MARKS FROM IMPORT -->
            <div class="card shadow-sm mb-4">
                <div class="card-header bg-dark text-white py-2 fw-semibold">
                    <i class="bi bi-file-earmark-text-fill text-danger me-1"></i>
                    GST Subject Marks
                    <span class="badge bg-info text-dark ms-1">GST Imported</span>
                </div>

                <div class="card-body">
                    <?php if ($subjectMarks): ?>
                        <table class="table table-sm table-bordered mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>#</th>
                                    <th>Subject</th>
                                    <th class="text-end">Marks</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($subjectMarks as $index => $mark): ?>
                                    <tr>
                                        <td><?= $index + 1 ?></td>
                                        <td><?= e($mark['subject_name']) ?></td>
                                        <td class="text-end fw-semibold">
                                            <?= e($mark['marks_obtained']) ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php else: ?>
                        <div class="alert alert-info py-2 mb-0">
                            <i class="bi bi-info-circle me-1"></i>
                            No subject-wise GST marks were included in the imported record.
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- VIVA RESULT -->
            <?php if ($viva): ?>
                <div class="card shadow-sm mb-4">
                    <div class="card-header bg-dark text-white py-2 fw-semibold">
                        <i class="bi bi-person-check-fill text-warning me-1"></i>
                        Viva Result
                    </div>

                    <div class="card-body">
                        <div class="row g-3">
                            <div class="col-md-4">
                                <small class="text-muted d-block">Status</small>
                                <span class="fw-semibold">
                                    <?= e(showValue($viva['viva_status'] ?? null)) ?>
                                </span>
                            </div>

                            <div class="col-md-4">
                                <small class="text-muted d-block">Allocated Faculty</small>
                                <span class="fw-semibold">
                                    <?= e(showValue($merit['alloc_faculty'] ?? null)) ?>
                                </span>
                            </div>

                            <div class="col-md-4">
                                <small class="text-muted d-block">Conducted At</small>
                                <span class="fw-semibold">
                                    <?= e(showDate($viva['conducted_at'] ?? null)) ?>
                                </span>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

        </div>
    </div>
</div>

<?php require_once '../includes/footer.php'; ?>
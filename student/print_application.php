<?php

declare(strict_types=1);

require_once '../includes/auth.php';
require_once '../config/db.php';

/*
|--------------------------------------------------------------------------
| Authentication BEFORE output
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

if ($applicantId <= 0) {
    header('Location: /jkkniu-admission/student/login.php');
    exit;
}

$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);


/*
|--------------------------------------------------------------------------
| Helpers
|--------------------------------------------------------------------------
*/

function h(mixed $value): string
{
    return htmlspecialchars(
        (string) ($value ?? ''),
        ENT_QUOTES,
        'UTF-8'
    );
}

function hasValue(mixed $value): bool
{
    return $value !== null &&
        trim((string) $value) !== '';
}

function effectiveValue(
    mixed $applicantValue,
    mixed $importedValue,
    string $fallback = '—'
): string {
    if (hasValue($applicantValue)) {
        return trim((string) $applicantValue);
    }

    if (hasValue($importedValue)) {
        return trim((string) $importedValue);
    }

    return $fallback;
}

function formatDateValue(
    mixed $applicantValue,
    mixed $importedValue
): string {
    $value = effectiveValue(
        $applicantValue,
        $importedValue,
        ''
    );

    if ($value === '') {
        return '—';
    }

    $timestamp = strtotime($value);

    return $timestamp !== false
        ? date('d M Y', $timestamp)
        : $value;
}


/*
|--------------------------------------------------------------------------
| 1. Registered applicant + imported GST candidate
|--------------------------------------------------------------------------
|
| Imported GST record is the authoritative source for:
| - GST identity
| - GST score / merit
| - SSC / HSC
| - subject marks
| - GST-supplied phone / demographic information
|
| gst_applicants is used for:
| - email / login account
| - father / mother
| - photo / signature
| - any missing information later completed by the student
|--------------------------------------------------------------------------
*/

$studentStmt = $pdo->prepare("
    SELECT
        ga.applicant_id,
        ga.gst_roll AS applicant_gst_roll,
        ga.full_name AS applicant_full_name,
        ga.email,
        ga.phone AS applicant_phone,
        ga.date_of_birth AS applicant_date_of_birth,
        ga.gender AS applicant_gender,
        ga.nationality AS applicant_nationality,
        ga.quota AS applicant_quota,
        ga.father_name,
        ga.mother_name,

        ga.hsc_roll AS applicant_hsc_roll,
        ga.hsc_reg AS applicant_hsc_reg,
        ga.hsc_group AS applicant_hsc_group,
        ga.hsc_board AS applicant_hsc_board,
        ga.hsc_year AS applicant_hsc_year,
        ga.hsc_gpa AS applicant_hsc_gpa,
        ga.hsc_institute AS applicant_hsc_institute,

        ga.ssc_roll AS applicant_ssc_roll,
        ga.ssc_reg AS applicant_ssc_reg,
        ga.ssc_board AS applicant_ssc_board,
        ga.ssc_year AS applicant_ssc_year,
        ga.ssc_gpa AS applicant_ssc_gpa,
        ga.ssc_institute AS applicant_ssc_institute,

        ga.photo,
        ga.signature,
        ga.created_at,

        gi.import_candidate_id,
        gi.gst_roll,
        gi.gst_applicant_id,
        gi.full_name,
        gi.gst_unit,
        gi.gst_score,
        gi.merit_position,
        gi.phone AS imported_phone,
        gi.date_of_birth AS imported_date_of_birth,
        gi.gender AS imported_gender,
        gi.nationality AS imported_nationality,
        gi.quota AS imported_quota,

        gi.hsc_roll,
        gi.hsc_reg,
        gi.hsc_group,
        gi.hsc_board,
        gi.hsc_year,
        gi.hsc_gpa,
        gi.hsc_institute,

        gi.ssc_roll,
        gi.ssc_reg,
        gi.ssc_board,
        gi.ssc_year,
        gi.ssc_gpa,
        gi.ssc_institute,

        gi.physics_mark,
        gi.chemistry_mark,
        gi.mathematics_mark,
        gi.biology_mark,
        gi.bangla_mark,
        gi.english_mark,
        gi.general_knowledge_mark,

        gi.academic_year,
        gi.selected_university_code,
        gi.selected_university_name,
        gi.selection_status

    FROM gst_applicants ga

    INNER JOIN gst_imported_candidates gi
        ON gi.applicant_id = ga.applicant_id

    WHERE ga.applicant_id = ?

    LIMIT 1
");

$studentStmt->execute([
    $applicantId
]);

$student = $studentStmt->fetch(
    PDO::FETCH_ASSOC
);

if (!$student) {
    http_response_code(409);

    exit(
        'Your registered applicant account is not linked to an imported GST candidate.'
    );
}

$importCandidateId =
    (int) $student['import_candidate_id'];


/*
|--------------------------------------------------------------------------
| 2. Active department allocation
|--------------------------------------------------------------------------
*/

$meritStmt = $pdo->prepare("
    SELECT
        ma.allocation_id,
        ma.dept_id,
        ma.merit_rank,
        ma.allocation_status,
        ma.allocated_at,

        d.dept_code,
        d.dept_name,

        f.faculty_name

    FROM merit_allocations ma

    INNER JOIN departments d
        ON d.dept_id = ma.dept_id

    INNER JOIN faculties f
        ON f.faculty_id = d.faculty_id

    WHERE ma.import_candidate_id = ?
      AND ma.allocation_status IN (
          'Allocated',
          'Admitted'
      )

    ORDER BY ma.allocation_id DESC

    LIMIT 1
");

$meritStmt->execute([
    $importCandidateId
]);

$merit = $meritStmt->fetch(
    PDO::FETCH_ASSOC
);


/*
|--------------------------------------------------------------------------
| 3. Admission progress
|--------------------------------------------------------------------------
*/

$progressStmt = $pdo->prepare("
    SELECT *
    FROM admission_progress
    WHERE applicant_id = ?
    LIMIT 1
");

$progressStmt->execute([
    $applicantId
]);

$progress = $progressStmt->fetch(
    PDO::FETCH_ASSOC
);


/*
|--------------------------------------------------------------------------
| 4. Department preferences from imported GST choices
|--------------------------------------------------------------------------
*/

$choiceStmt = $pdo->prepare("
    SELECT
        gcc.preference_order,
        d.dept_code,
        d.dept_name

    FROM gst_candidate_choices gcc

    INNER JOIN departments d
        ON d.dept_id = gcc.dept_id

    WHERE gcc.import_candidate_id = ?

    ORDER BY gcc.preference_order ASC
");

$choiceStmt->execute([
    $importCandidateId
]);

$deptChoices = $choiceStmt->fetchAll(
    PDO::FETCH_ASSOC
);


/*
|--------------------------------------------------------------------------
| 5. Effective student values
|--------------------------------------------------------------------------
*/

$fullName = effectiveValue(
    $student['applicant_full_name'],
    $student['full_name']
);

$phone = effectiveValue(
    $student['applicant_phone'],
    $student['imported_phone']
);

$dateOfBirth = formatDateValue(
    $student['applicant_date_of_birth'],
    $student['imported_date_of_birth']
);

$gender = effectiveValue(
    $student['applicant_gender'],
    $student['imported_gender']
);

$nationality = effectiveValue(
    $student['applicant_nationality'],
    $student['imported_nationality'],
    'Bangladeshi'
);

$quota = effectiveValue(
    $student['applicant_quota'],
    $student['imported_quota'],
    'General'
);


/*
|--------------------------------------------------------------------------
| 6. Effective academic information
|--------------------------------------------------------------------------
*/

$hsc = [
    'Roll' => effectiveValue(
        $student['applicant_hsc_roll'],
        $student['hsc_roll']
    ),
    'Registration No.' => effectiveValue(
        $student['applicant_hsc_reg'],
        $student['hsc_reg']
    ),
    'Group' => effectiveValue(
        $student['applicant_hsc_group'],
        $student['hsc_group']
    ),
    'Board' => effectiveValue(
        $student['applicant_hsc_board'],
        $student['hsc_board']
    ),
    'Year' => effectiveValue(
        $student['applicant_hsc_year'],
        $student['hsc_year']
    ),
    'GPA' => effectiveValue(
        $student['applicant_hsc_gpa'],
        $student['hsc_gpa']
    ),
    'Institute' => effectiveValue(
        $student['applicant_hsc_institute'],
        $student['hsc_institute']
    ),
];

$ssc = [
    'Roll' => effectiveValue(
        $student['applicant_ssc_roll'],
        $student['ssc_roll']
    ),
    'Registration No.' => effectiveValue(
        $student['applicant_ssc_reg'],
        $student['ssc_reg']
    ),
    'Board' => effectiveValue(
        $student['applicant_ssc_board'],
        $student['ssc_board']
    ),
    'Year' => effectiveValue(
        $student['applicant_ssc_year'],
        $student['ssc_year']
    ),
    'GPA' => effectiveValue(
        $student['applicant_ssc_gpa'],
        $student['ssc_gpa']
    ),
    'Institute' => effectiveValue(
        $student['applicant_ssc_institute'],
        $student['ssc_institute']
    ),
];


/*
|--------------------------------------------------------------------------
| 7. Subject marks directly from imported GST record
|--------------------------------------------------------------------------
*/

$subjectMarks = [
    'Physics' => $student['physics_mark'],
    'Chemistry' => $student['chemistry_mark'],
    'Mathematics' => $student['mathematics_mark'],
    'Biology' => $student['biology_mark'],
    'Bangla' => $student['bangla_mark'],
    'English' => $student['english_mark'],
    'General Knowledge' =>
        $student['general_knowledge_mark'],
];

$subjectMarks = array_filter(
    $subjectMarks,
    static fn(mixed $mark): bool =>
        hasValue($mark)
);


/*
|--------------------------------------------------------------------------
| 8. Application completeness
|--------------------------------------------------------------------------
|
| Imported data counts as complete.
| Only genuinely missing profile data is flagged.
|--------------------------------------------------------------------------
*/

$requiredApplicationFields = [
    "Father's Name" =>
        $student['father_name'],

    "Mother's Name" =>
        $student['mother_name'],

    'Date of Birth' =>
        $dateOfBirth !== '—'
            ? $dateOfBirth
            : null,

    'Gender' =>
        $gender !== '—'
            ? $gender
            : null,

    'Phone' =>
        $phone !== '—'
            ? $phone
            : null,

    'HSC Roll' =>
        $hsc['Roll'] !== '—'
            ? $hsc['Roll']
            : null,

    'HSC Board' =>
        $hsc['Board'] !== '—'
            ? $hsc['Board']
            : null,

    'HSC Year' =>
        $hsc['Year'] !== '—'
            ? $hsc['Year']
            : null,

    'HSC GPA' =>
        $hsc['GPA'] !== '—'
            ? $hsc['GPA']
            : null,

    'SSC Roll' =>
        $ssc['Roll'] !== '—'
            ? $ssc['Roll']
            : null,

    'SSC Board' =>
        $ssc['Board'] !== '—'
            ? $ssc['Board']
            : null,

    'SSC Year' =>
        $ssc['Year'] !== '—'
            ? $ssc['Year']
            : null,

    'SSC GPA' =>
        $ssc['GPA'] !== '—'
            ? $ssc['GPA']
            : null,

    'Photo' =>
        $student['photo'],

    'Signature' =>
        $student['signature'],
];

$missingApplicationFields = [];

foreach (
    $requiredApplicationFields as
    $label => $value
) {
    if (!hasValue($value)) {
        $missingApplicationFields[] =
            $label;
    }
}

$isApplicationComplete =
    empty($missingApplicationFields);


/*
|--------------------------------------------------------------------------
| 9. Status presentation
|--------------------------------------------------------------------------
*/

$finalStatus =
    (string) (
        $progress['final_status']
        ?? ''
    );

if ($finalStatus === 'Admitted') {
    $statusText =
        'Finally Admitted';

    $statusClass =
        'status-success';

} elseif ($finalStatus === 'Rejected') {

    $statusText =
        'Admission Rejected';

    $statusClass =
        'status-danger';

} elseif ($merit) {

    $statusText =
        'Provisionally Allocated';

    $statusClass =
        'status-primary';

} else {

    $statusText =
        'Application In Progress';

    $statusClass =
        'status-warning';
}


/*
|--------------------------------------------------------------------------
| 10. Application reference
|--------------------------------------------------------------------------
*/

$applicationReference = sprintf(
    'JKKNIU-%s-%06d',
    preg_replace(
        '/[^A-Za-z0-9]/',
        '',
        (string) $student['academic_year']
    ) ?: date('Y'),
    $applicantId
);

?>
<!DOCTYPE html>
<html lang="en">

<head>

<meta charset="UTF-8">

<meta
    name="viewport"
    content="width=device-width, initial-scale=1"
>

<title>
    Admission Application —
    <?= h($fullName) ?>
</title>


<style>

* {
    box-sizing: border-box;
}

body {
    margin: 0;
    background: #eef1f5;
    color: #1f2937;
    font-family:
        "Segoe UI",
        Arial,
        sans-serif;
    font-size: 12px;
}

.screen-toolbar {
    width: 210mm;
    max-width: calc(100% - 24px);
    margin: 18px auto 10px;
    display: flex;
    justify-content: space-between;
    gap: 10px;
}

.screen-toolbar a,
.screen-toolbar button {
    border: 0;
    border-radius: 7px;
    padding: 9px 15px;
    font-size: 13px;
    cursor: pointer;
    text-decoration: none;
}

.btn-back {
    background: #fff;
    color: #374151;
    border: 1px solid #d1d5db !important;
}

.btn-print {
    background: #14532d;
    color: #fff;
}

.application-sheet {
    width: 210mm;
    min-height: 297mm;
    margin: 0 auto 22px;
    background: #fff;
    padding: 11mm 12mm 10mm;
    box-shadow:
        0 8px 32px rgba(0, 0, 0, 0.10);
}

.university-header {
    display: grid;
    grid-template-columns: 74px 1fr 74px;
    align-items: center;
    gap: 12px;
    padding-bottom: 10px;
    border-bottom: 2px solid #14532d;
}

.university-logo {
    width: 68px;
    height: 68px;
    object-fit: contain;
}

.university-text {
    text-align: center;
}

.university-text h1 {
    margin: 0;
    color: #14532d;
    font-size: 20px;
    line-height: 1.25;
    text-transform: uppercase;
}

.university-text h2 {
    margin: 4px 0 0;
    font-size: 12px;
    font-weight: 600;
}

.university-text p {
    margin: 3px 0 0;
    font-size: 10px;
    color: #6b7280;
}

.header-spacer {
    width: 68px;
}

.application-heading {
    margin-top: 10px;
    padding: 8px 10px;
    background: #f0fdf4;
    border: 1px solid #bbf7d0;
    display: flex;
    justify-content: space-between;
    gap: 10px;
    align-items: center;
}

.application-heading h3 {
    margin: 0;
    color: #14532d;
    font-size: 16px;
    text-transform: uppercase;
}

.application-reference {
    text-align: right;
    font-size: 10px;
    color: #4b5563;
}

.identity-area {
    display: grid;
    grid-template-columns: 1fr 105px;
    gap: 14px;
    margin-top: 12px;
}

.identity-summary {
    border: 1px solid #d1d5db;
}

.identity-row {
    display: grid;
    grid-template-columns: 145px 1fr;
    border-bottom: 1px solid #e5e7eb;
}

.identity-row:last-child {
    border-bottom: 0;
}

.identity-label {
    background: #f8fafc;
    padding: 6px 8px;
    font-weight: 600;
    color: #4b5563;
}

.identity-value {
    padding: 6px 8px;
    font-weight: 600;
}

.photo-frame {
    width: 105px;
    height: 125px;
    border: 1px solid #9ca3af;
    display: flex;
    align-items: center;
    justify-content: center;
    overflow: hidden;
    background: #f9fafb;
}

.photo-frame img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

.photo-placeholder {
    text-align: center;
    color: #9ca3af;
    font-size: 10px;
}

.status-bar {
    display: flex;
    justify-content: space-between;
    gap: 8px;
    margin-top: 10px;
}

.status-box {
    flex: 1;
    padding: 7px 9px;
    border-radius: 5px;
    font-weight: 700;
    text-align: center;
    border: 1px solid transparent;
}

.status-success {
    color: #166534;
    background: #dcfce7;
    border-color: #86efac;
}

.status-danger {
    color: #991b1b;
    background: #fee2e2;
    border-color: #fecaca;
}

.status-primary {
    color: #1e3a8a;
    background: #dbeafe;
    border-color: #bfdbfe;
}

.status-warning {
    color: #92400e;
    background: #fef3c7;
    border-color: #fde68a;
}

.status-incomplete {
    color: #991b1b;
    background: #fff1f2;
    border-color: #fecdd3;
}

.section {
    margin-top: 11px;
}

.section-title {
    background: #14532d;
    color: #fff;
    padding: 5px 8px;
    font-size: 11px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .3px;
}

.detail-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    border-left: 1px solid #d1d5db;
    border-top: 1px solid #d1d5db;
}

.detail-item {
    min-height: 43px;
    border-right: 1px solid #d1d5db;
    border-bottom: 1px solid #d1d5db;
    padding: 5px 7px;
}

.detail-item.wide {
    grid-column: span 2;
}

.detail-label {
    display: block;
    margin-bottom: 2px;
    color: #6b7280;
    font-size: 9px;
    text-transform: uppercase;
}

.detail-value {
    font-weight: 600;
    font-size: 11px;
    word-break: break-word;
}

.academic-columns {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 9px;
}

.academic-card {
    border: 1px solid #d1d5db;
}

.academic-card-title {
    padding: 5px 7px;
    background: #f0fdf4;
    color: #14532d;
    font-weight: 700;
    border-bottom: 1px solid #d1d5db;
}

.academic-table,
.preference-table,
.mark-table {
    width: 100%;
    border-collapse: collapse;
}

.academic-table td,
.preference-table th,
.preference-table td,
.mark-table th,
.mark-table td {
    border: 1px solid #d1d5db;
    padding: 4px 6px;
    vertical-align: top;
}

.academic-table td:first-child {
    width: 40%;
    background: #f8fafc;
    color: #6b7280;
    font-size: 9px;
    font-weight: 600;
}

.preference-table th,
.mark-table th {
    background: #f8fafc;
    font-size: 9px;
    text-transform: uppercase;
}

.preference-table td,
.mark-table td {
    font-size: 10px;
}

.allocated-row {
    background: #ecfdf5;
    font-weight: 700;
}

.preference-number {
    text-align: center;
    width: 60px;
}

.preference-code {
    width: 75px;
    text-align: center;
}

.preference-allocation {
    width: 82px;
    text-align: center;
}

.subject-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 9px;
}

.marks-total {
    font-weight: 700;
    color: #14532d;
}

.signature-area {
    margin-top: 18px;
    display: grid;
    grid-template-columns:
        repeat(3, 1fr);
    gap: 18px;
    text-align: center;
}

.signature-box {
    height: 52px;
    display: flex;
    align-items: flex-end;
    justify-content: center;
}

.signature-box img {
    max-width: 125px;
    max-height: 42px;
    object-fit: contain;
}

.signature-line {
    border-top: 1px solid #111827;
    padding-top: 4px;
    font-size: 9px;
    font-weight: 600;
}

.document-footer {
    margin-top: 13px;
    padding-top: 7px;
    border-top: 1px dashed #9ca3af;
    display: grid;
    grid-template-columns:
        1fr 1.6fr 1fr;
    gap: 8px;
    font-size: 8.5px;
    color: #6b7280;
}

.document-footer > div:nth-child(2) {
    text-align: center;
}

.document-footer > div:last-child {
    text-align: right;
}

.missing-warning {
    margin-top: 8px;
    padding: 7px 9px;
    border: 1px solid #fecaca;
    background: #fff1f2;
    color: #991b1b;
    font-size: 10px;
}

@page {
    size: A4 portrait;
    margin: 7mm;
}

@media print {

    body {
        background: #fff;
        font-size: 10px;
    }

    .screen-toolbar {
        display: none !important;
    }

    .application-sheet {
        width: auto;
        min-height: auto;
        margin: 0;
        padding: 0;
        box-shadow: none;
    }

    .section,
    .academic-card,
    .identity-area,
    .signature-area {
        break-inside: avoid;
        page-break-inside: avoid;
    }
}

@media screen and (max-width: 850px) {

    .application-sheet,
    .screen-toolbar {
        width: calc(100% - 20px);
    }

    .application-sheet {
        padding: 18px;
    }

    .academic-columns,
    .subject-grid {
        grid-template-columns: 1fr;
    }
}

</style>

</head>

<body>


<div class="screen-toolbar">

    <a
        href="/jkkniu-admission/student/dashboard.php"
        class="btn-back"
    >
        ← Back to Dashboard
    </a>


    <button
        type="button"
        class="btn-print"
        onclick="window.print()"
    >
        🖨 Print / Save as PDF
    </button>

</div>


<div class="application-sheet">


    <!-- =========================================================
         UNIVERSITY HEADER
    ========================================================== -->

    <div class="university-header">

        <img
            src="/jkkniu-admission/assets/img/logo.png"
            class="university-logo"
            alt="JKKNIU Logo"
        >


        <div class="university-text">

            <h1>
                Jatiya Kabi Kazi Nazrul Islam University
            </h1>

            <h2>
                Trishal, Mymensingh-2224, Bangladesh
            </h2>

            <p>
                Post-GST enrollment
            </p>

        </div>


        <div class="header-spacer"></div>

    </div>


    <!-- =========================================================
         APPLICATION TITLE
    ========================================================== -->

    <div class="application-heading">

        <h3>
            Admission Application Form
        </h3>


        <div class="application-reference">

            <div>
                Application Ref:
                <strong>
                    <?= h($applicationReference) ?>
                </strong>
            </div>

            <div>
                Academic Year:
                <strong>
                    <?= h(
                        $student['academic_year']
                        ?? '—'
                    ) ?>
                </strong>
            </div>

        </div>

    </div>


    <!-- =========================================================
         IDENTITY
    ========================================================== -->

    <div class="identity-area">

        <div class="identity-summary">

            <div class="identity-row">

                <div class="identity-label">
                    Applicant Name
                </div>

                <div class="identity-value">
                    <?= h($fullName) ?>
                </div>

            </div>


            <div class="identity-row">

                <div class="identity-label">
                    GST Roll
                </div>

                <div class="identity-value">
                    <?= h($student['gst_roll']) ?>
                </div>

            </div>


            <div class="identity-row">

                <div class="identity-label">
                    GST Applicant ID
                </div>

                <div class="identity-value">
                    <?= h(
                        $student[
                            'gst_applicant_id'
                        ]
                    ) ?>
                </div>

            </div>


            <div class="identity-row">

                <div class="identity-label">
                    GST Unit / Score
                </div>

                <div class="identity-value">

                    <?= h(
                        $student['gst_unit']
                        ?? '—'
                    ) ?>

                    &nbsp; / &nbsp;

                    <?= h(
                        $student['gst_score']
                        ?? '—'
                    ) ?>

                    <?php if (
                        hasValue(
                            $student[
                                'merit_position'
                            ]
                        )
                    ): ?>

                        &nbsp;
                        (GST Merit:
                        #<?= (int)
                            $student[
                                'merit_position'
                            ]
                        ?>)

                    <?php endif; ?>

                </div>

            </div>

        </div>


        <div class="photo-frame">

            <?php if (
                hasValue(
                    $student['photo']
                )
            ): ?>

                <img
                    src="/jkkniu-admission/uploads/photos/<?= h(
                        basename(
                            (string)
                            $student['photo']
                        )
                    ) ?>"
                    alt="Applicant Photo"
                >

            <?php else: ?>

                <div class="photo-placeholder">
                    Applicant<br>Photo
                </div>

            <?php endif; ?>

        </div>

    </div>


    <!-- =========================================================
         STATUS
    ========================================================== -->

    <div class="status-bar">

        <div class="status-box <?= h($statusClass) ?>">

            <?= h($statusText) ?>

        </div>


        <?php if ($merit): ?>

            <div class="status-box status-success">

                Allocated:
                <?= h(
                    $merit['dept_code']
                    ? $merit['dept_code']
                        . ' — '
                        . $merit['dept_name']
                    : $merit['dept_name']
                ) ?>

                &nbsp;|&nbsp;

                Dept. Rank
                #<?= (int)
                    $merit['merit_rank']
                ?>

            </div>

        <?php endif; ?>


        <?php if (
            !$isApplicationComplete
        ): ?>

            <div class="status-box status-incomplete">
                Profile Incomplete
            </div>

        <?php endif; ?>

    </div>


    <?php if (
        !$isApplicationComplete
    ): ?>

        <div class="missing-warning">

            <strong>
                Missing information:
            </strong>

            <?= h(
                implode(
                    ', ',
                    $missingApplicationFields
                )
            ) ?>

            — complete these fields before final submission.

        </div>

    <?php endif; ?>


    <!-- =========================================================
         PERSONAL INFORMATION
    ========================================================== -->

    <div class="section">

        <div class="section-title">
            Personal Information
        </div>


        <div class="detail-grid">


            <?php

            $personalFields = [

                "Father's Name" =>
                    $student['father_name']
                    ?: '—',

                "Mother's Name" =>
                    $student['mother_name']
                    ?: '—',

                'Date of Birth' =>
                    $dateOfBirth,

                'Gender' =>
                    $gender,

                'Nationality' =>
                    $nationality,

                'Quota' =>
                    $quota,

                'Phone' =>
                    $phone,

                'Email' =>
                    hasValue(
                        $student['email']
                    )
                        ? $student['email']
                        : '—',

            ];

            foreach (
                $personalFields as
                $label => $value
            ):

            ?>

                <div class="detail-item">

                    <span class="detail-label">
                        <?= h($label) ?>
                    </span>

                    <span class="detail-value">
                        <?= h($value) ?>
                    </span>

                </div>

            <?php endforeach; ?>

        </div>

    </div>


    <!-- =========================================================
         ACADEMIC INFORMATION
    ========================================================== -->

    <div class="section">

        <div class="section-title">
            Previous Academic Information
        </div>


        <div class="academic-columns">


            <div class="academic-card">

                <div class="academic-card-title">
                    HSC / Alim / Equivalent
                </div>


                <table class="academic-table">

                    <?php foreach (
                        $hsc as
                        $label => $value
                    ): ?>

                        <tr>

                            <td>
                                <?= h($label) ?>
                            </td>

                            <td>
                                <?= h($value) ?>
                            </td>

                        </tr>

                    <?php endforeach; ?>

                </table>

            </div>


            <div class="academic-card">

                <div class="academic-card-title">
                    SSC / Dakhil / Equivalent
                </div>


                <table class="academic-table">

                    <?php foreach (
                        $ssc as
                        $label => $value
                    ): ?>

                        <tr>

                            <td>
                                <?= h($label) ?>
                            </td>

                            <td>
                                <?= h($value) ?>
                            </td>

                        </tr>

                    <?php endforeach; ?>

                </table>

            </div>

        </div>

    </div>


    <!-- =========================================================
         GST RESULT
    ========================================================== -->

    <div class="section">

        <div class="section-title">
            GST Examination Information
        </div>


        <div class="subject-grid">


            <div>

                <table class="academic-table">

                    <tr>

                        <td>GST Roll</td>

                        <td>
                            <?= h(
                                $student['gst_roll']
                            ) ?>
                        </td>

                    </tr>


                    <tr>

                        <td>Applicant ID</td>

                        <td>
                            <?= h(
                                $student[
                                    'gst_applicant_id'
                                ]
                            ) ?>
                        </td>

                    </tr>


                    <tr>

                        <td>GST Unit</td>

                        <td>
                            <?= h(
                                $student[
                                    'gst_unit'
                                ]
                                ?? '—'
                            ) ?>
                        </td>

                    </tr>


                    <tr>

                        <td>GST Score</td>

                        <td class="marks-total">
                            <?= h(
                                $student[
                                    'gst_score'
                                ]
                                ?? '—'
                            ) ?>
                        </td>

                    </tr>


                    <tr>

                        <td>GST Merit</td>

                        <td>

                            <?= hasValue(
                                $student[
                                    'merit_position'
                                ]
                            )
                                ? '#'
                                    . (int)
                                    $student[
                                        'merit_position'
                                    ]
                                : '—'
                            ?>

                        </td>

                    </tr>

                </table>

            </div>


            <div>

                <?php if (
                    $subjectMarks
                ): ?>

                    <table class="mark-table">

                        <thead>

                            <tr>
                                <th>Subject</th>
                                <th>Marks</th>
                            </tr>

                        </thead>


                        <tbody>

                            <?php foreach (
                                $subjectMarks as
                                $subject => $mark
                            ): ?>

                                <tr>

                                    <td>
                                        <?= h($subject) ?>
                                    </td>

                                    <td>
                                        <?= h($mark) ?>
                                    </td>

                                </tr>

                            <?php endforeach; ?>

                        </tbody>

                    </table>

                <?php else: ?>

                    <div
                        style="
                            border:1px solid #d1d5db;
                            padding:10px;
                            color:#6b7280;
                        "
                    >
                        No subject-wise GST marks
                        were included in the
                        imported record.
                    </div>

                <?php endif; ?>

            </div>

        </div>

    </div>


    <!-- =========================================================
         DEPARTMENT PREFERENCES
    ========================================================== -->

    <?php if ($deptChoices): ?>

        <div class="section">

            <div class="section-title">
                Imported Department Preferences
            </div>


            <table class="preference-table">

                <thead>

                    <tr>

                        <th class="preference-number">
                            Choice
                        </th>

                        <th class="preference-code">
                            Code
                        </th>

                        <th>
                            Department
                        </th>

                        <th class="preference-allocation">
                            Allocation
                        </th>

                    </tr>

                </thead>


                <tbody>

                    <?php foreach (
                        $deptChoices as
                        $choice
                    ): ?>

                        <?php

                        $allocated =
                            $merit &&
                            (string)
                            $merit['dept_code']
                            ===
                            (string)
                            $choice['dept_code'];

                        ?>

                        <tr
                            class="<?= $allocated
                                ? 'allocated-row'
                                : ''
                            ?>"
                        >

                            <td class="preference-number">
                                <?= (int)
                                    $choice[
                                        'preference_order'
                                    ]
                                ?>
                            </td>

                            <td class="preference-code">
                                <?= h(
                                    $choice[
                                        'dept_code'
                                    ]
                                ) ?>
                            </td>

                            <td>
                                <?= h(
                                    $choice[
                                        'dept_name'
                                    ]
                                ) ?>
                            </td>

                            <td class="preference-allocation">

                                <?= $allocated
                                    ? 'Allocated'
                                    : '—'
                                ?>

                            </td>

                        </tr>

                    <?php endforeach; ?>

                </tbody>

            </table>

        </div>

    <?php endif; ?>


    <!-- =========================================================
         ALLOCATION INFORMATION
    ========================================================== -->

    <?php if ($merit): ?>

        <div class="section">

            <div class="section-title">
                Department Allocation
            </div>


            <div class="detail-grid">


                <div class="detail-item">

                    <span class="detail-label">
                        Allocated Department
                    </span>

                    <span class="detail-value">

                        <?= h(
                            $merit[
                                'dept_name'
                            ]
                        ) ?>

                    </span>

                </div>


                <div class="detail-item">

                    <span class="detail-label">
                        Faculty
                    </span>

                    <span class="detail-value">

                        <?= h(
                            $merit[
                                'faculty_name'
                            ]
                        ) ?>

                    </span>

                </div>


                <div class="detail-item">

                    <span class="detail-label">
                        Department Merit Rank
                    </span>

                    <span class="detail-value">

                        #<?= (int)
                            $merit[
                                'merit_rank'
                            ]
                        ?>

                    </span>

                </div>


                <div class="detail-item">

                    <span class="detail-label">
                        Allocation Status
                    </span>

                    <span class="detail-value">

                        <?= h(
                            $merit[
                                'allocation_status'
                            ]
                        ) ?>

                    </span>

                </div>

            </div>

        </div>

    <?php endif; ?>


    <!-- =========================================================
         SIGNATURES
    ========================================================== -->

    <div class="signature-area">


        <div>

            <div class="signature-box">

                <?php if (
                    hasValue(
                        $student['signature']
                    )
                ): ?>

                    <img
                        src="/jkkniu-admission/uploads/signatures/<?= h(
                            basename(
                                (string)
                                $student[
                                    'signature'
                                ]
                            )
                        ) ?>"
                        alt="Applicant Signature"
                    >

                <?php endif; ?>

            </div>

            <div class="signature-line">
                Applicant's Signature
            </div>

        </div>


        <div>

            <div class="signature-box"></div>

            <div class="signature-line">
                Department Authority
                Signature & Seal
            </div>

        </div>


        <div>

            <div class="signature-box"></div>

            <div class="signature-line">
                Registrar
                Signature & Seal
            </div>

        </div>

    </div>


    <!-- =========================================================
         FOOTER
    ========================================================== -->

    <div class="document-footer">

        <div>
            Printed:
            <strong>
                <?= h(
                    date(
                        'd M Y'
                    )
                ) ?>
            </strong>
        </div>


        <div>

            This application was generated
            from JKKNIU Admission Management System.

        </div>


        <div>

            GST Roll:
            <strong>
                <?= h(
                    $student['gst_roll']
                ) ?>
            </strong>

        </div>

    </div>


</div>

</body>

</html>
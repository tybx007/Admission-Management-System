<?php

declare(strict_types=1);

$pageTitle = 'Review Approval';

require_once '../includes/auth.php';
require_once '../config/db.php';
require_once '../includes/admission_session.php';
require_once '../includes/student_biodata.php';
require_once '../includes/payments/sslcommerz.php';

requireLogin('registrar');

$pdo->setAttribute(
    PDO::ATTR_ERRMODE,
    PDO::ERRMODE_EXCEPTION
);

$currentAcademicYear =
    admissionSessionCurrentYear($pdo);

$applicant_id =
    (int) ($_GET['applicant_id'] ?? 0);

if ($applicant_id <= 0) {
    $_SESSION['flash'] = [
        'type' => 'danger',
        'msg'  => 'No applicant selected.'
    ];

    header('Location: dashboard.php');
    exit;
}

function reviewE(mixed $value): string
{
    return htmlspecialchars(
        (string) ($value ?? ''),
        ENT_QUOTES,
        'UTF-8'
    );
}

function reviewStatusBadge(string $status): string
{
    return match ($status) {
        'Approved'     => 'success',
        'Rejected'     => 'danger',
        'Under Review' => 'warning',
        'Submitted'    => 'info',
        default        => 'secondary',
    };
}

function reviewMoney(mixed $value): string
{
    return '৳ ' . number_format(
        (float) ($value ?? 0),
        2
    );
}

/*
|--------------------------------------------------------------------------
| Registrar approval
|--------------------------------------------------------------------------
*/

$stmt = $pdo->prepare("
    SELECT *
    FROM registrar_approvals
    WHERE applicant_id = ?
    LIMIT 1
");

$stmt->execute([
    $applicant_id
]);

$approval =
    $stmt->fetch(PDO::FETCH_ASSOC);

if (!$approval) {
    $_SESSION['flash'] = [
        'type' => 'danger',
        'msg'  => 'Registrar approval submission was not found.'
    ];

    header('Location: dashboard.php');
    exit;
}

$deptPayment = paymentFetchLatest(
    $pdo,
    $applicant_id,
    PAYMENT_STEP_DEPT
);

$hallPayment = paymentFetchLatest(
    $pdo,
    $applicant_id,
    PAYMENT_STEP_HALL
);

$admissionPayment = paymentFetchLatest(
    $pdo,
    $applicant_id,
    PAYMENT_STEP_ADMISSION
);

/*
|--------------------------------------------------------------------------
| Student biodata
|--------------------------------------------------------------------------
|
| The page intentionally uses the shared biodata helper instead of
| SELECT ga.* joins. That helper combines gst_applicants with the imported
| GST record and supports older registrations by gst_roll fallback.
|--------------------------------------------------------------------------
*/

$student =
    fetchStudentBiodata(
        $pdo,
        $applicant_id,
        $currentAcademicYear
    );

if (!$student) {
    $_SESSION['flash'] = [
        'type' => 'danger',
        'msg'  => 'Student biodata was not found.'
    ];

    header('Location: dashboard.php');
    exit;
}

/*
|--------------------------------------------------------------------------
| Current active merit allocation
|--------------------------------------------------------------------------
*/

$merit = null;

if (
    !empty(
        $student[
            'import_candidate_id'
        ]
    )
) {
    $stmt = $pdo->prepare("
        SELECT
            ma.*,
            d.dept_name
        FROM merit_allocations ma
        INNER JOIN departments d
            ON d.dept_id = ma.dept_id
        WHERE ma.import_candidate_id = ?
          AND ma.allocation_status IN (
              'Allocated',
              'Admitted'
          )
        ORDER BY ma.allocation_id DESC
        LIMIT 1
    ");

    $stmt->execute([
        (int) $student[
            'import_candidate_id'
        ]
    ]);

    $merit =
        $stmt->fetch(PDO::FETCH_ASSOC);
}

if (!$merit) {
    $_SESSION['flash'] = [
        'type' => 'danger',
        'msg'  => 'No active department allocation was found for this student.'
    ];

    header('Location: dashboard.php');
    exit;
}

/*
|--------------------------------------------------------------------------
| Keep old applicant_id link synchronized
|--------------------------------------------------------------------------
*/

if (
    empty(
        $merit[
            'applicant_id'
        ]
    )
    ||
    (int) $merit[
        'applicant_id'
    ] !== $applicant_id
) {
    $stmt = $pdo->prepare("
        UPDATE merit_allocations
        SET applicant_id = ?
        WHERE allocation_id = ?
    ");

    $stmt->execute([
        $applicant_id,
        (int) $merit[
            'allocation_id'
        ]
    ]);

    $merit['applicant_id'] =
        $applicant_id;
}

/*
|--------------------------------------------------------------------------
| Admission progress
|--------------------------------------------------------------------------
*/

$stmt = $pdo->prepare("
    SELECT *
    FROM admission_progress
    WHERE applicant_id = ?
    LIMIT 1
");

$stmt->execute([
    $applicant_id
]);

$progress =
    $stmt->fetch(PDO::FETCH_ASSOC);

/*
|--------------------------------------------------------------------------
| Step submissions
|--------------------------------------------------------------------------
*/

$stmt = $pdo->prepare("
    SELECT *
    FROM dept_submissions
    WHERE applicant_id = ?
    LIMIT 1
");

$stmt->execute([
    $applicant_id
]);

$dept =
    $stmt->fetch(PDO::FETCH_ASSOC);


$stmt = $pdo->prepare("
    SELECT *
    FROM hall_submissions
    WHERE applicant_id = ?
    LIMIT 1
");

$stmt->execute([
    $applicant_id
]);

$hall =
    $stmt->fetch(PDO::FETCH_ASSOC);


$stmt = $pdo->prepare("
    SELECT *
    FROM central_admissions
    WHERE applicant_id = ?
    LIMIT 1
");

$stmt->execute([
    $applicant_id
]);

$central =
    $stmt->fetch(PDO::FETCH_ASSOC);

/*
|--------------------------------------------------------------------------
| Faculty name
|--------------------------------------------------------------------------
*/

$facultyName = '—';

if (
    !empty(
        $student[
            'faculty_id'
        ]
    )
) {
    $stmt = $pdo->prepare("
        SELECT faculty_name
        FROM faculties
        WHERE faculty_id = ?
        LIMIT 1
    ");

    $stmt->execute([
        (int) $student[
            'faculty_id'
        ]
    ]);

    $facultyName =
        (string) (
            $stmt->fetchColumn()
            ?: '—'
        );
}

require_once '../includes/header.php';
require_once '../includes/navbar.php';

?>

<div class="container py-4">

    <nav
        aria-label="breadcrumb"
        class="mb-3"
    >
        <ol class="breadcrumb">

            <li class="breadcrumb-item">
                <a href="dashboard.php">
                    Dashboard
                </a>
            </li>

            <li class="breadcrumb-item active">
                <?= reviewE(
                    $student[
                        'gst_roll'
                    ]
                    ?? ''
                ) ?>
                -
                <?= reviewE(
                    $student[
                        'full_name'
                    ]
                    ?? ''
                ) ?>
            </li>

        </ol>
    </nav>


    <?php if (
        !empty(
            $_SESSION[
                'flash'
            ]
        )
    ): ?>

        <div
            class="alert
                   alert-<?= reviewE(
                       $_SESSION[
                           'flash'
                       ]['type']
                       ?? 'info'
                   ) ?>"
        >
            <?= reviewE(
                $_SESSION[
                    'flash'
                ]['msg']
                ?? ''
            ) ?>
        </div>

        <?php
        unset(
            $_SESSION[
                'flash'
            ]
        );
        ?>

    <?php endif; ?>


    <div class="row g-4">

        <!-- Main Review -->

        <div class="col-lg-9">

            <div class="card shadow-lg">

                <div
                    class="card-header
                           bg-primary
                           text-white
                           p-3"
                >

                    <div
                        class="d-flex
                               justify-content-between
                               align-items-center
                               gap-3
                               flex-wrap"
                    >

                        <div>

                            <h5 class="mb-0">

                                <i
                                    class="bi
                                           bi-file-earmark-check
                                           me-2"
                                ></i>

                                Final Approval Review

                            </h5>

                            <small>

                                Department:

                                <strong>
                                    <?= reviewE(
                                        $merit[
                                            'dept_name'
                                        ]
                                        ?? '—'
                                    ) ?>
                                </strong>

                                &nbsp;|&nbsp;

                                Rank:

                                <span
                                    class="badge
                                           bg-light
                                           text-dark"
                                >
                                    #<?= (int) (
                                        $merit[
                                            'merit_rank'
                                        ]
                                        ?? 0
                                    ) ?>
                                </span>

                            </small>

                        </div>


                        <span
                            class="badge
                                   fs-6
                                   bg-<?= reviewStatusBadge(
                                       (string) (
                                           $approval[
                                               'approval_status'
                                           ]
                                           ?? ''
                                       )
                                   ) ?>"
                        >
                            <?= reviewE(
                                $approval[
                                    'approval_status'
                                ]
                                ?? ''
                            ) ?>
                        </span>

                    </div>

                </div>


                <div class="card-body p-4">


                    <!-- Receipt Checklist -->

                    <h6
                        class="fw-bold
                               mb-3
                               pb-2
                               border-bottom"
                    >

                        <i
                            class="bi
                                   bi-receipt
                                   me-2
                                   text-primary"
                        ></i>

                        Receipt Checklist

                    </h6>


                    <div class="row g-3 mb-4">


                        <!-- Department Receipt -->

                        <div class="col-md-4">

                            <div
                                class="card
                                       h-100
                                       border-<?= !empty(
                                           $approval[
                                               'dept_receipt_attached'
                                           ]
                                       )
                                           ? 'success'
                                           : 'warning'
                                       ?>"
                            >

                                <div
                                    class="card-body
                                           text-center
                                           py-3"
                                >

                                    <i
                                        class="bi
                                               <?= !empty(
                                                   $approval[
                                                       'dept_receipt_attached'
                                                   ]
                                               )
                                                   ? 'bi-check-circle text-success'
                                                   : 'bi-exclamation-triangle text-warning'
                                               ?>
                                               fs-2
                                               mb-2"
                                    ></i>

                                    <div class="fw-bold">
                                        <?= reviewMoney(
                                            $dept[
                                                'dept_fee_amount'
                                            ]
                                            ?? 0
                                        ) ?>
                                    </div>

                                    <small>
                                        Department Fee
                                    </small>


                                    <div class="mt-2">

                                            <?php if (
                                                !empty(
                                                    $dept[
                                                        'dept_fee_receipt'
                                                    ]
                                                )
                                            ): ?>

                                                <a
                                                    class="btn
                                                           btn-sm
                                                           btn-outline-primary"
                                                    target="_blank"
                                                    href="../uploads/receipts/dept/<?= reviewE(
                                                        $dept[
                                                            'dept_fee_receipt'
                                                        ]
                                                    ) ?>"
                                                >

                                                    <i
                                                        class="bi
                                                               bi-eye"
                                                    ></i>

                                                    View

                                                </a>

                                            <?php else: ?>

                                                <div>
                                                    <?= paymentBadgeHtml($deptPayment) ?>
                                                </div>

                                            <?php endif; ?>

                                        </div>

                                </div>

                            </div>

                        </div>


                        <!-- Hall Receipt -->

                        <div class="col-md-4">

                            <div
                                class="card
                                       h-100
                                       border-<?= !empty(
                                           $approval[
                                               'hall_receipt_attached'
                                           ]
                                       )
                                           ? 'success'
                                           : 'warning'
                                       ?>"
                            >

                                <div
                                    class="card-body
                                           text-center
                                           py-3"
                                >

                                    <i
                                        class="bi
                                               <?= !empty(
                                                   $approval[
                                                       'hall_receipt_attached'
                                                   ]
                                               )
                                                   ? 'bi-check-circle text-success'
                                                   : 'bi-exclamation-triangle text-warning'
                                               ?>
                                               fs-2
                                               mb-2"
                                    ></i>

                                    <div class="fw-bold">
                                        <?= reviewMoney(
                                            $hall[
                                                'hall_fee_amount'
                                            ]
                                            ?? 0
                                        ) ?>
                                    </div>

                                    <small>
                                        Hall Fee
                                    </small>


                                    <div class="mt-2">

                                            <?php if (
                                                !empty(
                                                    $hall[
                                                        'hall_fee_receipt'
                                                    ]
                                                )
                                            ): ?>

                                                <a
                                                    class="btn
                                                           btn-sm
                                                           btn-outline-primary"
                                                    target="_blank"
                                                    href="../uploads/receipts/hall/<?= reviewE(
                                                        $hall[
                                                            'hall_fee_receipt'
                                                        ]
                                                    ) ?>"
                                                >

                                                    <i
                                                        class="bi
                                                               bi-eye"
                                                    ></i>

                                                    View

                                                </a>

                                            <?php else: ?>

                                                <div>
                                                    <?= paymentBadgeHtml($hallPayment) ?>
                                                </div>

                                            <?php endif; ?>

                                        </div>

                                </div>

                            </div>

                        </div>


                        <!-- Admission Receipt -->

                        <div class="col-md-4">

                            <div
                                class="card
                                       h-100
                                       border-<?= !empty(
                                           $approval[
                                               'admission_receipt_attached'
                                           ]
                                       )
                                           ? 'success'
                                           : 'warning'
                                       ?>"
                            >

                                <div
                                    class="card-body
                                           text-center
                                           py-3"
                                >

                                    <i
                                        class="bi
                                               <?= !empty(
                                                   $approval[
                                                       'admission_receipt_attached'
                                                   ]
                                               )
                                                   ? 'bi-check-circle text-success'
                                                   : 'bi-exclamation-triangle text-warning'
                                               ?>
                                               fs-2
                                               mb-2"
                                    ></i>

                                    <div class="fw-bold">
                                        <?= reviewMoney(
                                            $central[
                                                'admission_fee_amount'
                                            ]
                                            ?? 0
                                        ) ?>
                                    </div>

                                    <small>
                                        Admission Fee
                                    </small>


                                    <div class="mt-2">

                                            <?php if (
                                                !empty(
                                                    $central[
                                                        'admission_fee_receipt'
                                                    ]
                                                )
                                            ): ?>

                                                <a
                                                    class="btn
                                                           btn-sm
                                                           btn-outline-primary"
                                                    target="_blank"
                                                    href="../uploads/receipts/admission/<?= reviewE(
                                                        $central[
                                                            'admission_fee_receipt'
                                                        ]
                                                    ) ?>"
                                                >

                                                    <i
                                                        class="bi
                                                               bi-eye"
                                                    ></i>

                                                    View

                                                </a>

                                            <?php else: ?>

                                                <div>
                                                    <?= paymentBadgeHtml($admissionPayment) ?>
                                                </div>

                                            <?php endif; ?>

                                        </div>

                                </div>

                            </div>

                        </div>

                    </div>


                    <!-- Biodata -->

                    <h6
                        class="fw-bold
                               mb-3
                               pb-2
                               border-bottom"
                    >

                        <i
                            class="bi
                                   bi-person-lines-fill
                                   me-2
                                   text-primary"
                        ></i>

                        Student Biodata

                    </h6>


                    <div
                        class="alert
                               alert-light
                               border
                               small
                               mb-4"
                    >

                        <i
                            class="bi
                                   bi-database-check
                                   me-1"
                        ></i>

                        Biodata is loaded through
                        <strong>
                            includes/student_biodata.php
                        </strong>

                    </div>


                    <!-- Personal -->

                    <h6 class="fw-bold mb-3">
                        Personal Information
                    </h6>


                    <?php

                    $personalFields = [

                        'GST Roll' =>
                            $student[
                                'gst_roll'
                            ]
                            ?? '—',

                        'Full Name' =>
                            $student[
                                'full_name'
                            ]
                            ?? '—',

                        "Father's Name" =>
                            $student[
                                'father_name'
                            ]
                            ?? '—',

                        "Mother's Name" =>
                            $student[
                                'mother_name'
                            ]
                            ?? '—',

                        'Date of Birth' =>
                            $student[
                                'date_of_birth'
                            ]
                            ?? '—',

                        'Gender' =>
                            $student[
                                'gender'
                            ]
                            ?? '—',

                        'Phone' =>
                            $student[
                                'phone'
                            ]
                            ?? '—',

                        'Email' =>
                            $student[
                                'email'
                            ]
                            ?? '—',

                        'Nationality' =>
                            $student[
                                'nationality'
                            ]
                            ?? '—',

                        'Quota' =>
                            $student[
                                'quota'
                            ]
                            ?? '—',

                        'GST Score' =>
                            $student[
                                'gst_score'
                            ] !== null
                                ? number_format(
                                    (float) $student[
                                        'gst_score'
                                    ],
                                    2
                                )
                                : '—',

                        'GST Unit' =>
                            $student[
                                'gst_unit'
                            ]
                            ?? '—',

                        'GST Merit Position' =>
                            !empty(
                                $student[
                                    'merit_position'
                                ]
                            )
                                ? '#'
                                  . (int)
                                    $student[
                                        'merit_position'
                                    ]
                                : '—',

                        'Faculty' =>
                            $facultyName,

                        'Department' =>
                            $merit[
                                'dept_name'
                            ]
                            ?? '—',

                        'Department Merit Rank' =>
                            !empty(
                                $merit[
                                    'merit_rank'
                                ]
                            )
                                ? '#'
                                  . (int)
                                    $merit[
                                        'merit_rank'
                                    ]
                                : '—',
                    ];

                    ?>


                    <div class="row g-3 mb-4">

                        <?php foreach (
                            $personalFields
                            as $label =>
                            $value
                        ): ?>

                            <div class="col-md-4">

                                <small
                                    class="text-muted
                                           d-block"
                                >
                                    <?= reviewE(
                                        $label
                                    ) ?>
                                </small>

                                <strong>
                                    <?= reviewE(
                                        $value !== ''
                                            ? $value
                                            : '—'
                                    ) ?>
                                </strong>

                            </div>

                        <?php endforeach; ?>

                    </div>


                    <!-- HSC -->

                    <h6 class="fw-bold mb-3">
                        HSC Information
                    </h6>


                    <div
                        class="border
                               rounded
                               p-3
                               mb-4"
                    >

                        <div class="row g-3">

                            <div class="col-md-4">
                                <small class="text-muted d-block">
                                    Roll
                                </small>
                                <strong>
                                    <?= reviewE(
                                        $student[
                                            'hsc_roll'
                                        ]
                                        ?? '—'
                                    ) ?>
                                </strong>
                            </div>

                            <div class="col-md-4">
                                <small class="text-muted d-block">
                                    Registration
                                </small>
                                <strong>
                                    <?= reviewE(
                                        $student[
                                            'hsc_reg'
                                        ]
                                        ?? '—'
                                    ) ?>
                                </strong>
                            </div>

                            <div class="col-md-4">
                                <small class="text-muted d-block">
                                    Group
                                </small>
                                <strong>
                                    <?= reviewE(
                                        $student[
                                            'hsc_group'
                                        ]
                                        ?? '—'
                                    ) ?>
                                </strong>
                            </div>

                            <div class="col-md-4">
                                <small class="text-muted d-block">
                                    Board
                                </small>
                                <strong>
                                    <?= reviewE(
                                        $student[
                                            'hsc_board'
                                        ]
                                        ?? '—'
                                    ) ?>
                                </strong>
                            </div>

                            <div class="col-md-4">
                                <small class="text-muted d-block">
                                    Year
                                </small>
                                <strong>
                                    <?= reviewE(
                                        $student[
                                            'hsc_year'
                                        ]
                                        ?? '—'
                                    ) ?>
                                </strong>
                            </div>

                            <div class="col-md-4">
                                <small class="text-muted d-block">
                                    GPA
                                </small>
                                <strong>
                                    <?= reviewE(
                                        $student[
                                            'hsc_gpa'
                                        ]
                                        ?? '—'
                                    ) ?>
                                </strong>
                            </div>

                            <div class="col-12">
                                <small class="text-muted d-block">
                                    Institute
                                </small>
                                <strong>
                                    <?= reviewE(
                                        $student[
                                            'hsc_institute'
                                        ]
                                        ?? '—'
                                    ) ?>
                                </strong>
                            </div>

                        </div>

                    </div>


                    <!-- SSC -->

                    <h6 class="fw-bold mb-3">
                        SSC Information
                    </h6>


                    <div
                        class="border
                               rounded
                               p-3
                               mb-4"
                    >

                        <div class="row g-3">

                            <div class="col-md-4">
                                <small class="text-muted d-block">
                                    Roll
                                </small>
                                <strong>
                                    <?= reviewE(
                                        $student[
                                            'ssc_roll'
                                        ]
                                        ?? '—'
                                    ) ?>
                                </strong>
                            </div>

                            <div class="col-md-4">
                                <small class="text-muted d-block">
                                    Registration
                                </small>
                                <strong>
                                    <?= reviewE(
                                        $student[
                                            'ssc_reg'
                                        ]
                                        ?? '—'
                                    ) ?>
                                </strong>
                            </div>

                            <div class="col-md-4">
                                <small class="text-muted d-block">
                                    Board
                                </small>
                                <strong>
                                    <?= reviewE(
                                        $student[
                                            'ssc_board'
                                        ]
                                        ?? '—'
                                    ) ?>
                                </strong>
                            </div>

                            <div class="col-md-4">
                                <small class="text-muted d-block">
                                    Year
                                </small>
                                <strong>
                                    <?= reviewE(
                                        $student[
                                            'ssc_year'
                                        ]
                                        ?? '—'
                                    ) ?>
                                </strong>
                            </div>

                            <div class="col-md-4">
                                <small class="text-muted d-block">
                                    GPA
                                </small>
                                <strong>
                                    <?= reviewE(
                                        $student[
                                            'ssc_gpa'
                                        ]
                                        ?? '—'
                                    ) ?>
                                </strong>
                            </div>

                            <div class="col-md-8">
                                <small class="text-muted d-block">
                                    Institute
                                </small>
                                <strong>
                                    <?= reviewE(
                                        $student[
                                            'ssc_institute'
                                        ]
                                        ?? '—'
                                    ) ?>
                                </strong>
                            </div>

                        </div>

                    </div>


                    <!-- Profile Files -->

                    <?php if (
                        !empty(
                            $student[
                                'photo'
                            ]
                        )
                        ||
                        !empty(
                            $student[
                                'signature'
                            ]
                        )
                    ): ?>

                        <h6 class="fw-bold mb-3">
                            Profile Files
                        </h6>

                        <div class="d-flex gap-2 flex-wrap mb-4">

                            <?php if (
                                !empty(
                                    $student[
                                        'photo'
                                    ]
                                )
                            ): ?>

                                <a
                                    target="_blank"
                                    href="../uploads/photos/<?= reviewE(
                                        $student[
                                            'photo'
                                        ]
                                    ) ?>"
                                    class="btn
                                           btn-outline-primary"
                                >
                                    <i class="bi bi-image"></i>
                                    View Photo
                                </a>

                            <?php endif; ?>


                            <?php if (
                                !empty(
                                    $student[
                                        'signature'
                                    ]
                                )
                            ): ?>

                                <a
                                    target="_blank"
                                    href="../uploads/signatures/<?= reviewE(
                                        $student[
                                            'signature'
                                        ]
                                    ) ?>"
                                    class="btn
                                           btn-outline-primary"
                                >
                                    <i class="bi bi-pen"></i>
                                    View Signature
                                </a>

                            <?php endif; ?>

                        </div>

                    <?php endif; ?>


                    <!-- Submission Status -->

                    <h6
                        class="fw-bold
                               mb-3
                               pb-2
                               border-bottom"
                    >
                        Submission Status
                    </h6>


                    <div class="row g-3 mb-4">

                        <div class="col-md-4">

                            <div class="border rounded p-3 h-100">

                                <small
                                    class="text-muted
                                           d-block"
                                >
                                    Department Submission
                                </small>

                                <strong>
                                    <?= reviewE(
                                        $dept[
                                            'submission_status'
                                        ]
                                        ?? 'Not Submitted'
                                    ) ?>
                                </strong>

                            </div>

                        </div>


                        <div class="col-md-4">

                            <div class="border rounded p-3 h-100">

                                <small
                                    class="text-muted
                                           d-block"
                                >
                                    Hall Submission
                                </small>

                                <strong>
                                    <?= reviewE(
                                        $hall[
                                            'submission_status'
                                        ]
                                        ?? 'Not Submitted'
                                    ) ?>
                                </strong>

                            </div>

                        </div>


                        <div class="col-md-4">

                            <div class="border rounded p-3 h-100">

                                <small
                                    class="text-muted
                                           d-block"
                                >
                                    Registrar Submission
                                </small>

                                <strong>
                                    <?= reviewE(
                                        $central[
                                            'submission_status'
                                        ]
                                        ?? 'Not Submitted'
                                    ) ?>
                                </strong>

                            </div>

                        </div>

                    </div>


                    <!-- Action -->

                    <?php if (
                        in_array(
                            (string) (
                                $approval[
                                    'approval_status'
                                ]
                                ?? ''
                            ),
                            [
                                'Submitted',
                                'Under Review'
                            ],
                            true
                        )
                    ): ?>

                        <h6
                            class="fw-bold
                                   mb-3
                                   pb-2
                                   border-bottom"
                        >
                            Registrar Decision
                        </h6>


                        <div class="row g-3">


                            <div class="col-md-6">

                                <form
                                    method="POST"
                                    action="approve.php?applicant_id=<?= $applicant_id ?>"
                                >

                                    <input
                                        type="hidden"
                                        name="action"
                                        value="approve"
                                    >

                                    <div class="mb-2">

                                        <label
                                            class="form-label
                                                   small
                                                   text-muted"
                                        >
                                            Remarks
                                            (optional)
                                        </label>

                                        <textarea
                                            name="remarks"
                                            class="form-control"
                                            rows="3"
                                            placeholder="Optional approval note..."
                                        ></textarea>

                                    </div>

                                    <button
                                        type="submit"
                                        class="btn
                                               btn-success
                                               w-100"
                                    >

                                        <i
                                            class="bi
                                                   bi-check-lg
                                                   me-1"
                                        ></i>

                                        Approve Admission

                                    </button>

                                </form>

                            </div>


                            <div class="col-md-6">

                                <form
                                    method="POST"
                                    action="approve.php?applicant_id=<?= $applicant_id ?>"
                                >

                                    <input
                                        type="hidden"
                                        name="action"
                                        value="reject"
                                    >

                                    <div class="mb-2">

                                        <label
                                            class="form-label
                                                   small
                                                   text-muted"
                                        >
                                            Rejection reason
                                        </label>

                                        <textarea
                                            name="remarks"
                                            class="form-control"
                                            rows="3"
                                            placeholder="Reason for rejection..."
                                            required
                                        ></textarea>

                                    </div>

                                    <button
                                        type="submit"
                                        class="btn
                                               btn-danger
                                               w-100"
                                        onclick="return confirm('Reject this admission?')"
                                    >

                                        <i
                                            class="bi
                                                   bi-x-lg
                                                   me-1"
                                        ></i>

                                        Reject

                                    </button>

                                </form>

                            </div>


                        </div>


                    <?php else: ?>

                        <div
                            class="alert
                                   <?= (
                                       $approval[
                                           'approval_status'
                                       ]
                                       ?? ''
                                   ) === 'Approved'
                                       ? 'alert-success'
                                       : 'alert-danger'
                                   ?>
                                   mb-0"
                        >

                            <strong>
                                Registrar Decision:
                            </strong>

                            <?= reviewE(
                                $approval[
                                    'approval_status'
                                ]
                                ?? '—'
                            ) ?>


                            <?php if (
                                !empty(
                                    $approval[
                                        'remarks'
                                    ]
                                )
                            ): ?>

                                <br>

                                <strong>
                                    Remarks:
                                </strong>

                                <?= reviewE(
                                    $approval[
                                        'remarks'
                                    ]
                                ) ?>

                            <?php endif; ?>

                        </div>

                    <?php endif; ?>


                </div>

            </div>

        </div>


        <!-- Sidebar -->

        <div class="col-lg-3">

            <div class="card shadow-sm">

                <div
                    class="card-header
                           bg-secondary
                           text-white"
                >
                    Student Summary
                </div>

                <div class="card-body">

                    <div class="mb-2">

                        <strong>
                            GST Score:
                        </strong>

                        <?= $student[
                            'gst_score'
                        ] !== null
                            ? number_format(
                                (float) $student[
                                    'gst_score'
                                ],
                                2
                            )
                            : '—'
                        ?>

                    </div>


                    <div class="mb-2">

                        <strong>
                            Quota:
                        </strong>

                        <?= reviewE(
                            !empty(
                                $student[
                                    'quota'
                                ]
                            )
                                ? $student[
                                    'quota'
                                ]
                                : 'None'
                        ) ?>

                    </div>


                    <div class="mb-2">

                        <strong>
                            Department:
                        </strong>

                        <?= reviewE(
                            $merit[
                                'dept_name'
                            ]
                            ?? '—'
                        ) ?>

                    </div>


                    <div class="mb-3">

                        <strong>
                            Progress:
                        </strong>

                        <span
                            class="badge
                                   bg-info
                                   text-dark"
                        >
                            <?= reviewE(
                                $progress[
                                    'final_status'
                                ]
                                ?? 'In Progress'
                            ) ?>
                        </span>

                    </div>


                    <div class="mb-3">

                        <strong>
                            Approval:
                        </strong>

                        <span
                            class="badge
                                   bg-<?= reviewStatusBadge(
                                       (string) (
                                           $approval[
                                               'approval_status'
                                           ]
                                           ?? ''
                                       )
                                   ) ?>"
                        >
                            <?= reviewE(
                                $approval[
                                    'approval_status'
                                ]
                                ?? '—'
                            ) ?>
                        </span>

                    </div>


                    <a
                        href="dashboard.php"
                        class="btn
                               btn-outline-secondary
                               w-100"
                    >

                        <i
                            class="bi
                                   bi-arrow-left"
                        ></i>

                        Back to Dashboard

                    </a>

                </div>

            </div>

        </div>

    </div>

</div>

<?php
require_once '../includes/footer.php';
?>

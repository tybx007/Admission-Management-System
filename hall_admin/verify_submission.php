<?php

declare(strict_types=1);

$pageTitle = 'Verify Hall Submission';

require_once '../includes/auth.php';
require_once '../config/db.php';
require_once '../includes/admission_session.php';
require_once '../includes/student_biodata.php';
require_once '../includes/payments/sslcommerz.php';

requireLogin('hall_admin');

$pdo->setAttribute(
    PDO::ATTR_ERRMODE,
    PDO::ERRMODE_EXCEPTION
);

$currentAcademicYear =
    admissionSessionCurrentYear($pdo);

$admin_id =
    (int) ($_SESSION['user_id'] ?? 0);

$hall_name =
    trim(
        (string) (
            $_SESSION['hall_name']
            ?? ''
        )
    );

$sub_id =
    (int) ($_GET['sub_id'] ?? 0);

if (
    $admin_id <= 0
    ||
    $hall_name === ''
) {
    header(
        'Location: /jkkniu-admission/hall_admin/login.php'
    );
    exit;
}

if ($sub_id <= 0) {
    header('Location: submissions.php');
    exit;
}

function hallPageE(mixed $value): string
{
    return htmlspecialchars(
        (string) ($value ?? ''),
        ENT_QUOTES,
        'UTF-8'
    );
}

function hallPageBadge(
    string $status
): string {
    return match ($status) {
        'Verified' =>
            'bg-success',

        'Rejected' =>
            'bg-danger',

        'Pending' =>
            'bg-warning text-dark',

        default =>
            'bg-secondary',
    };
}

/*
|--------------------------------------------------------------------------
| Hall submission only
|--------------------------------------------------------------------------
*/

$stmt = $pdo->prepare("
    SELECT *
    FROM hall_submissions
    WHERE hall_sub_id = ?
    LIMIT 1
");

$stmt->execute([
    $sub_id
]);

$hallSub =
    $stmt->fetch(PDO::FETCH_ASSOC);

if (
    !$hallSub
    ||
    strcasecmp(
        trim(
            (string) (
                $hallSub['hall_name']
                ?? ''
            )
        ),
        $hall_name
    ) !== 0
) {
    header('Location: submissions.php');
    exit;
}

$applicantId =
    (int) $hallSub['applicant_id'];

$deptPayment = paymentFetchLatest(
    $pdo,
    $applicantId,
    PAYMENT_STEP_DEPT
);

$hallPayment = paymentFetchLatest(
    $pdo,
    $applicantId,
    PAYMENT_STEP_HALL
);

/*
|--------------------------------------------------------------------------
| REAL BIODATA SOURCE
|--------------------------------------------------------------------------
|
| This page no longer reads HSC/SSC/DOB/phone directly from its SQL JOIN.
| Everything comes from includes/student_biodata.php.
|--------------------------------------------------------------------------
*/

$student =
    fetchStudentBiodata(
        $pdo,
        $applicantId,
        $currentAcademicYear
    );

if (!$student) {
    header('Location: submissions.php');
    exit;
}

/*
|--------------------------------------------------------------------------
| Active allocation through import_candidate_id
|--------------------------------------------------------------------------
*/

$merit = null;
$deptName = '—';

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
        ORDER BY
            ma.allocation_id DESC
        LIMIT 1
    ");

    $stmt->execute([
        (int) $student[
            'import_candidate_id'
        ]
    ]);

    $merit =
        $stmt->fetch(
            PDO::FETCH_ASSOC
        );

    if ($merit) {

        $deptName =
            (string) (
                $merit['dept_name']
                ?? '—'
            );

        if (
            empty(
                $merit[
                    'applicant_id'
                ]
            )
            ||
            (int) $merit[
                'applicant_id'
            ] !== $applicantId
        ) {
            $stmt = $pdo->prepare("
                UPDATE merit_allocations
                SET applicant_id = ?
                WHERE allocation_id = ?
            ");

            $stmt->execute([
                $applicantId,
                (int) $merit[
                    'allocation_id'
                ]
            ]);
        }
    }
}

/*
|--------------------------------------------------------------------------
| Step 1 submission
|--------------------------------------------------------------------------
*/

$stmt = $pdo->prepare("
    SELECT *
    FROM dept_submissions
    WHERE applicant_id = ?
    LIMIT 1
");

$stmt->execute([
    $applicantId
]);

$deptSub =
    $stmt->fetch(PDO::FETCH_ASSOC);

/*
|--------------------------------------------------------------------------
| Verify / Reject
|--------------------------------------------------------------------------
*/

$message = '';
$messageClass = 'alert-info';

if (
    $_SERVER[
        'REQUEST_METHOD'
    ]
    === 'POST'
) {

    $status =
        trim(
            (string) (
                $_POST['status']
                ?? ''
            )
        );

    if (
        !in_array(
            $status,
            [
                'Verified',
                'Rejected'
            ],
            true
        )
    ) {

        $message =
            'Invalid status selected.';

        $messageClass =
            'alert-danger';

    } else {

        try {

            $pdo->beginTransaction();

            $stmt = $pdo->prepare("
                UPDATE hall_submissions
                SET
                    submission_status = ?,
                    verified_at = NOW()
                WHERE hall_sub_id = ?
                  AND hall_name = ?
            ");

            $stmt->execute([
                $status,
                $sub_id,
                $hall_name
            ]);

            /*
             * Verification result only.
             * Step 3 unlocking is already based on Hall submission existence.
             */
            $progressState =
                $status === 'Verified'
                    ? 'Done'
                    : 'Rejected';

            $stmt = $pdo->prepare("
                UPDATE admission_progress
                SET hall_step = ?
                WHERE applicant_id = ?
            ");

            $stmt->execute([
                $progressState,
                $applicantId
            ]);

            $pdo->commit();

            $message =
                $status === 'Verified'
                    ? 'Hall submission verified successfully.'
                    : 'Hall submission rejected.';

            $messageClass =
                $status === 'Verified'
                    ? 'alert-success'
                    : 'alert-danger';

            $stmt = $pdo->prepare("
                SELECT *
                FROM hall_submissions
                WHERE hall_sub_id = ?
                LIMIT 1
            ");

            $stmt->execute([
                $sub_id
            ]);

            $hallSub =
                $stmt->fetch(
                    PDO::FETCH_ASSOC
                );

        } catch (Throwable $e) {

            if (
                $pdo->inTransaction()
            ) {
                $pdo->rollBack();
            }

            $message =
                'Unable to update submission: '
                . $e->getMessage();

            $messageClass =
                'alert-danger';
        }
    }
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

            <li class="breadcrumb-item">
                <a href="submissions.php">
                    Submissions
                </a>
            </li>

            <li class="breadcrumb-item active">
                <?= hallPageE(
                    $student[
                        'gst_roll'
                    ]
                    ?? ''
                ) ?>
            </li>

        </ol>

    </nav>


    <?php if ($message): ?>

        <div
            class="alert
                   <?= hallPageE(
                       $messageClass
                   ) ?>"
        >
            <?= hallPageE(
                $message
            ) ?>
        </div>

    <?php endif; ?>


    <div class="row g-4">

        <div class="col-lg-8">

            <div class="card shadow-lg">

                <div
                    class="card-header
                           bg-primary
                           text-white"
                >

                    <div
                        class="d-flex
                               justify-content-between
                               align-items-start
                               gap-3
                               flex-wrap"
                    >

                        <div>

                            <h5 class="mb-1">
                                <?= hallPageE(
                                    $student[
                                        'full_name'
                                    ]
                                    ?? 'Unknown Candidate'
                                ) ?>
                            </h5>

                            <small>

                                GST Roll:

                                <strong>
                                    <?= hallPageE(
                                        $student[
                                            'gst_roll'
                                        ]
                                        ?? '—'
                                    ) ?>
                                </strong>

                                &nbsp;|&nbsp;

                                Merit Rank:

                                <strong>
                                    <?= $merit
                                        &&
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
                                        : 'N/A'
                                    ?>
                                </strong>

                                &nbsp;|&nbsp;

                                Dept:

                                <strong>
                                    <?= hallPageE(
                                        $deptName
                                    ) ?>
                                </strong>

                                &nbsp;|&nbsp;

                                Hall:

                                <strong>
                                    <?= hallPageE(
                                        $hallSub[
                                            'hall_name'
                                        ]
                                        ?? '—'
                                    ) ?>
                                </strong>

                            </small>

                        </div>


                        <span
                            class="badge
                                   fs-6
                                   <?= hallPageBadge(
                                       (string) (
                                           $hallSub[
                                               'submission_status'
                                           ]
                                           ?? ''
                                       )
                                   ) ?>"
                        >
                            <?= hallPageE(
                                $hallSub[
                                    'submission_status'
                                ]
                                ?? ''
                            ) ?>
                        </span>

                    </div>

                </div>


                <div class="card-body">


                    <!-- Documents -->

                    <h6 class="fw-bold mb-3">

                        <i
                            class="bi
                                   bi-files
                                   me-2"
                        ></i>

                        Documents

                    </h6>


                    <?php

                    $docs = [

                        [
                            'SSC Transcript (Step 1)',
                            '/jkkniu-admission/uploads/transcripts/'
                            . (
                                $deptSub[
                                    'ssc_transcript'
                                ]
                                ?? ''
                            ),
                            $deptSub[
                                'ssc_transcript'
                            ]
                            ?? ''
                        ],

                        [
                            'HSC Transcript (Step 1)',
                            '/jkkniu-admission/uploads/transcripts/'
                            . (
                                $deptSub[
                                    'hsc_transcript'
                                ]
                                ?? ''
                            ),
                            $deptSub[
                                'hsc_transcript'
                            ]
                            ?? ''
                        ],

                        [
                            'Dept Fee Receipt (Step 1)',
                            '/jkkniu-admission/uploads/receipts/dept/'
                            . (
                                $deptSub[
                                    'dept_fee_receipt'
                                ]
                                ?? ''
                            ),
                            $deptSub[
                                'dept_fee_receipt'
                            ]
                            ?? ''
                        ],

                        [
                            'Hall Fee Receipt (Step 2)',
                            '/jkkniu-admission/uploads/receipts/hall/'
                            . (
                                $hallSub[
                                    'hall_fee_receipt'
                                ]
                                ?? ''
                            ),
                            $hallSub[
                                'hall_fee_receipt'
                            ]
                            ?? ''
                        ],
                    ];

                    $docs[2][] = paymentBadgeHtml($deptPayment);

                    $docs[3][] = paymentBadgeHtml($hallPayment);

                    ?>


                    <div class="row g-3 mb-4">

                        <?php foreach (
                            $docs as $doc
                        ): ?>

                            <div class="col-md-6">

                                <div
                                    class="card
                                           h-100
                                           <?= $doc[2]
                                               ? 'border-success'
                                               : 'border-danger'
                                           ?>"
                                >

                                    <div
                                        class="card-body
                                               text-center
                                               py-3"
                                    >

                                        <h6 class="mb-2">
                                            <?= hallPageE(
                                                $doc[0]
                                            ) ?>
                                        </h6>

                                        <?php if (
                                            $doc[2]
                                        ): ?>

                                            <a
                                                href="<?= hallPageE(
                                                    $doc[1]
                                                ) ?>"
                                                target="_blank"
                                                class="btn
                                                       btn-sm
                                                       btn-success"
                                            >

                                                <i
                                                    class="bi
                                                           bi-eye"
                                                ></i>

                                                View

                                            </a>

                                        <?php elseif (
                                            !empty($doc[3])
                                        ): ?>

                                            <span class="d-inline-block">
                                                <?= $doc[3] ?>
                                            </span>

                                            <span
                                                class="text-success
                                                       small
                                                       d-block
                                                       mt-1"
                                            >
                                                <i
                                                    class="bi
                                                           bi-check-circle"
                                                ></i>
                                                Paid online via gateway
                                            </span>

                                        <?php else: ?>

                                            <span
                                                class="text-danger
                                                       small"
                                            >
                                                Missing
                                            </span>

                                        <?php endif; ?>

                                    </div>

                                </div>

                            </div>

                        <?php endforeach; ?>

                    </div>


                    <!-- Biodata -->

                    <h6 class="fw-bold mb-3">

                        <i
                            class="bi
                                   bi-person-lines-fill
                                   me-2"
                        ></i>

                        Biodata

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


                    <h6 class="fw-bold">
                        Personal Information
                    </h6>


                    <div class="row g-3 mb-4">

                        <?php

                        $personal = [

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
                        ];

                        ?>


                        <?php foreach (
                            $personal
                            as $label =>
                            $value
                        ): ?>

                            <div class="col-md-4">

                                <small
                                    class="text-muted
                                           d-block"
                                >
                                    <?= hallPageE(
                                        $label
                                    ) ?>
                                </small>

                                <strong>
                                    <?= hallPageE(
                                        $value !== ''
                                            ? $value
                                            : '—'
                                    ) ?>
                                </strong>

                            </div>

                        <?php endforeach; ?>

                    </div>


                    <!-- HSC -->

                    <h6 class="fw-bold">
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
                                    <?= hallPageE(
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
                                    <?= hallPageE(
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
                                    <?= hallPageE(
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
                                    <?= hallPageE(
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
                                    <?= hallPageE(
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
                                    <?= hallPageE(
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
                                    <?= hallPageE(
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

                    <h6 class="fw-bold">
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
                                    <?= hallPageE(
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
                                    <?= hallPageE(
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
                                    <?= hallPageE(
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
                                    <?= hallPageE(
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
                                    <?= hallPageE(
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
                                    <?= hallPageE(
                                        $student[
                                            'ssc_institute'
                                        ]
                                        ?? '—'
                                    ) ?>
                                </strong>
                            </div>

                        </div>

                    </div>


                    <!-- GST -->

                    <h6 class="fw-bold">
                        GST Information
                    </h6>


                    <div class="row g-3 mb-4">

                        <div class="col-md-4">
                            <small class="text-muted d-block">
                                GST Score
                            </small>
                            <strong>
                                <?= $student[
                                    'gst_score'
                                ] !== null
                                    ? number_format(
                                        (float)
                                        $student[
                                            'gst_score'
                                        ],
                                        2
                                    )
                                    : '—'
                                ?>
                            </strong>
                        </div>

                        <div class="col-md-4">
                            <small class="text-muted d-block">
                                GST Unit
                            </small>
                            <strong>
                                <?= hallPageE(
                                    $student[
                                        'gst_unit'
                                    ]
                                    ?? '—'
                                ) ?>
                            </strong>
                        </div>

                        <div class="col-md-4">
                            <small class="text-muted d-block">
                                GST Merit Position
                            </small>
                            <strong>
                                <?= !empty(
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
                            </strong>
                        </div>

                    </div>


                    <!-- Step 1 Address / Guardian -->

                    <h6 class="fw-bold mb-3">

                        <i
                            class="bi
                                   bi-geo-alt-fill
                                   me-2"
                        ></i>

                        Address & Guardian
                        <span
                            class="fw-normal
                                   text-muted"
                        >
                            (Step 1)
                        </span>

                    </h6>


                    <div class="row g-3 mb-4">

                        <div class="col-md-6">

                            <small
                                class="text-muted
                                       d-block
                                       mb-1"
                            >
                                Present Address
                            </small>

                            <div
                                class="border
                                       rounded
                                       p-3
                                       bg-light
                                       h-100"
                            >
                                <?= nl2br(
                                    hallPageE(
                                        $deptSub[
                                            'present_address'
                                        ]
                                        ?? '—'
                                    )
                                ) ?>
                            </div>

                        </div>

                        <div class="col-md-6">

                            <small
                                class="text-muted
                                       d-block
                                       mb-1"
                            >
                                Permanent Address
                            </small>

                            <div
                                class="border
                                       rounded
                                       p-3
                                       bg-light
                                       h-100"
                            >
                                <?= nl2br(
                                    hallPageE(
                                        $deptSub[
                                            'permanent_address'
                                        ]
                                        ?? '—'
                                    )
                                ) ?>
                            </div>

                        </div>

                    </div>


                    <div class="row g-3 mb-4">

                        <div class="col-md-4">
                            <small class="text-muted d-block">
                                Guardian
                            </small>
                            <strong>
                                <?= hallPageE(
                                    $deptSub[
                                        'guardian_name'
                                    ]
                                    ?? '—'
                                ) ?>
                            </strong>
                        </div>

                        <div class="col-md-4">
                            <small class="text-muted d-block">
                                Guardian Phone
                            </small>
                            <strong>
                                <?= hallPageE(
                                    $deptSub[
                                        'guardian_phone'
                                    ]
                                    ?? '—'
                                ) ?>
                            </strong>
                        </div>

                        <div class="col-md-4">
                            <small class="text-muted d-block">
                                Relation
                            </small>
                            <strong>
                                <?= hallPageE(
                                    $deptSub[
                                        'guardian_relation'
                                    ]
                                    ?? '—'
                                ) ?>
                            </strong>
                        </div>

                    </div>


                    <!-- Hall fee -->

                    <h6 class="fw-bold">
                        Hall Fee
                    </h6>


                    <div class="row g-3 mb-4">

                        <div class="col-md-4">
                            <small class="text-muted d-block">
                                Hall
                            </small>
                            <strong>
                                <?= hallPageE(
                                    $hallSub[
                                        'hall_name'
                                    ]
                                    ?? '—'
                                ) ?>
                            </strong>
                        </div>

                        <div class="col-md-4">
                            <small class="text-muted d-block">
                                Amount
                            </small>
                            <strong>
                                ৳<?= number_format(
                                    (float) (
                                        $hallSub[
                                            'hall_fee_amount'
                                        ]
                                        ?? 0
                                    ),
                                    2
                                ) ?>
                            </strong>
                        </div>

                        <div class="col-md-4">
                            <small class="text-muted d-block">
                                Method
                            </small>
                            <strong>
                                <?= hallPageE(
                                    $hallSub[
                                        'payment_method'
                                    ]
                                    ?? '—'
                                ) ?>
                            </strong>
                            <div class="mt-1">
                                <?= paymentBadgeHtml($hallPayment) ?>
                            </div>
                        </div>

                        <div class="col-md-6">
                            <small class="text-muted d-block">
                                Submitted
                            </small>
                            <strong>
                                <?= !empty(
                                    $hallSub[
                                        'submitted_at'
                                    ]
                                )
                                    ? date(
                                        'd M Y, h:i A',
                                        strtotime(
                                            (string)
                                            $hallSub[
                                                'submitted_at'
                                            ]
                                        )
                                    )
                                    : '—'
                                ?>
                            </strong>
                        </div>

                        <div class="col-md-6">
                            <small class="text-muted d-block">
                                Verified / Reviewed
                            </small>
                            <strong>
                                <?= !empty(
                                    $hallSub[
                                        'verified_at'
                                    ]
                                )
                                    ? date(
                                        'd M Y, h:i A',
                                        strtotime(
                                            (string)
                                            $hallSub[
                                                'verified_at'
                                            ]
                                        )
                                    )
                                    : '—'
                                ?>
                            </strong>
                        </div>

                    </div>


                    <!-- Action -->

                    <?php if (
                        !in_array(
                            (string) (
                                $hallSub[
                                    'submission_status'
                                ]
                                ?? ''
                            ),
                            [
                                'Verified',
                                'Rejected'
                            ],
                            true
                        )
                    ): ?>

                        <hr class="my-4">

                        <form method="POST">

                            <div class="mb-3">

                                <label
                                    class="form-label
                                           fw-bold"
                                >
                                    Status
                                </label>

                                <select
                                    name="status"
                                    class="form-select"
                                    required
                                >

                                    <option value="">
                                        Select Action
                                    </option>

                                    <option value="Verified">
                                        ✅ Verify & Approve
                                    </option>

                                    <option value="Rejected">
                                        ❌ Reject
                                    </option>

                                </select>

                            </div>


                            <div class="d-flex gap-2">

                                <button
                                    type="submit"
                                    class="btn
                                           btn-success"
                                >

                                    <i
                                        class="bi
                                               bi-check2"
                                    ></i>

                                    Update Status

                                </button>

                                <a
                                    href="submissions.php"
                                    class="btn
                                           btn-outline-secondary"
                                >
                                    Back to List
                                </a>

                            </div>

                        </form>

                    <?php else: ?>

                        <div
                            class="alert
                                   <?= (
                                       $hallSub[
                                           'submission_status'
                                       ]
                                       ?? ''
                                   ) === 'Verified'
                                       ? 'alert-success'
                                       : 'alert-danger'
                                   ?>
                                   mb-0"
                        >

                            <strong>
                                Current Status:
                            </strong>

                            <?= hallPageE(
                                $hallSub[
                                    'submission_status'
                                ]
                                ?? ''
                            ) ?>

                        </div>

                    <?php endif; ?>


                </div>

            </div>

        </div>


        <!-- Sidebar -->

        <div class="col-lg-4">

            <div class="card shadow-sm mb-3">

                <div
                    class="card-header
                           bg-secondary
                           text-white"
                >
                    Student Info
                </div>

                <div class="card-body">

                    <div class="mb-2">
                        <strong>GST Roll:</strong>
                        <?= hallPageE(
                            $student[
                                'gst_roll'
                            ]
                            ?? '—'
                        ) ?>
                    </div>

                    <div class="mb-2">
                        <strong>GST Score:</strong>
                        <?= $student[
                            'gst_score'
                        ] !== null
                            ? number_format(
                                (float)
                                $student[
                                    'gst_score'
                                ],
                                2
                            )
                            : '—'
                        ?>
                    </div>

                    <div class="mb-2">
                        <strong>Email:</strong>
                        <?= hallPageE(
                            $student[
                                'email'
                            ]
                            ?? '—'
                        ) ?>
                    </div>

                    <div class="mb-2">
                        <strong>Phone:</strong>
                        <?= hallPageE(
                            $student[
                                'phone'
                            ]
                            ?? '—'
                        ) ?>
                    </div>

                    <div class="mb-2">
                        <strong>Dept:</strong>
                        <?= hallPageE(
                            $deptName
                        ) ?>
                    </div>

                    <div>
                        <strong>Hall:</strong>
                        <?= hallPageE(
                            $hallSub[
                                'hall_name'
                            ]
                            ?? '—'
                        ) ?>
                    </div>

                </div>

            </div>


            <div class="card shadow-sm">

                <div
                    class="card-header
                           bg-info
                           text-dark"
                >
                    Hall Receipt
                </div>

                <div class="card-body">

                    <?php if (
                        !empty(
                            $hallSub[
                                'hall_fee_receipt'
                            ]
                        )
                    ): ?>

                        <a
                            class="btn
                                   btn-sm
                                   btn-info
                                   w-100"
                            href="/jkkniu-admission/uploads/receipts/hall/<?= hallPageE(
                                $hallSub[
                                    'hall_fee_receipt'
                                ]
                            ) ?>"
                            target="_blank"
                        >

                            <i
                                class="bi
                                       bi-receipt"
                            ></i>

                            View Hall Receipt

                        </a>

                    <?php else: ?>

                        <div
                            class="text-muted
                                   small"
                        >
                            No hall receipt uploaded.
                        </div>

                        <div class="mt-2">
                            <?= paymentBadgeHtml($hallPayment) ?>
                        </div>

                    <?php endif; ?>

                </div>

            </div>

        </div>

    </div>

</div>

<?php
require_once '../includes/footer.php';
?>

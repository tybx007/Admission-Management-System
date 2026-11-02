<?php

declare(strict_types=1);

$pageTitle = 'Admission Progress';

require_once '../includes/auth.php';
require_once '../config/db.php';
require_once '../includes/admission_session.php';

requireLogin('student');

$pdo->setAttribute(
    PDO::ATTR_ERRMODE,
    PDO::ERRMODE_EXCEPTION
);

$applicant_id =
    (int) ($_SESSION['user_id'] ?? 0);

$currentAcademicYear =
    admissionSessionCurrentYear($pdo);

function progressE(mixed $value): string
{
    return htmlspecialchars(
        (string) ($value ?? ''),
        ENT_QUOTES,
        'UTF-8'
    );
}

/*
|--------------------------------------------------------------------------
| Student
|--------------------------------------------------------------------------
*/

$stmt = $pdo->prepare("
    SELECT *
    FROM gst_applicants
    WHERE applicant_id = ?
    LIMIT 1
");

$stmt->execute([
    $applicant_id
]);

$student =
    $stmt->fetch(PDO::FETCH_ASSOC);

if (!$student) {
    header(
        'Location: /jkkniu-admission/student/login.php'
    );
    exit;
}

/*
|--------------------------------------------------------------------------
| Current GST imported candidate
|--------------------------------------------------------------------------
*/

$stmt = $pdo->prepare("
    SELECT
        import_candidate_id,
        applicant_id,
        gst_roll,
        full_name,
        academic_year

    FROM gst_imported_candidates

    WHERE applicant_id = ?
      AND academic_year = ?

    LIMIT 1
");

$stmt->execute([
    $applicant_id,
    $currentAcademicYear
]);

$imported =
    $stmt->fetch(PDO::FETCH_ASSOC);

/*
|--------------------------------------------------------------------------
| Active Merit Allocation
|--------------------------------------------------------------------------
*/

$merit = null;

if ($imported) {

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
        (int) $imported[
            'import_candidate_id'
        ]
    ]);

    $merit =
        $stmt->fetch(PDO::FETCH_ASSOC);

    /*
    |--------------------------------------------------------------------------
    | Keep legacy applicant_id synchronized
    |--------------------------------------------------------------------------
    */
    if (
        $merit
        &&
        (
            empty(
                $merit['applicant_id']
            )
            ||
            (int) $merit['applicant_id']
            !== $applicant_id
        )
    ) {
        $linkStmt =
            $pdo->prepare("
                UPDATE merit_allocations
                SET applicant_id = ?
                WHERE allocation_id = ?
            ");

        $linkStmt->execute([
            $applicant_id,
            (int) $merit[
                'allocation_id'
            ]
        ]);

        $merit['applicant_id'] =
            $applicant_id;
    }
}

/*
|--------------------------------------------------------------------------
| Admission Progress
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
| Self-heal missing progress row for Viva-selected candidate
|--------------------------------------------------------------------------
*/

if (!$progress) {

    $stmtViva =
        $pdo->prepare("
            SELECT
                viva_status

            FROM viva_results

            WHERE applicant_id = ?

            ORDER BY
                viva_id DESC

            LIMIT 1
        ");

    $stmtViva->execute([
        $applicant_id
    ]);

    $viva =
        $stmtViva->fetch(
            PDO::FETCH_ASSOC
        );

    if (
        $viva
        &&
        (string) (
            $viva['viva_status']
            ?? ''
        )
        === 'Selected'
    ) {
        $insertProgress =
            $pdo->prepare("
                INSERT INTO admission_progress (
                    applicant_id
                )
                VALUES (?)
            ");

        try {
            $insertProgress->execute([
                $applicant_id
            ]);
        } catch (PDOException $e) {
            /*
             * Another request/trigger may already
             * have created it. Re-fetch below.
             */
        }

        $stmt->execute([
            $applicant_id
        ]);

        $progress =
            $stmt->fetch(
                PDO::FETCH_ASSOC
            );
    }
}

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

$deptSub =
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

$hallSub =
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

$centralSub =
    $stmt->fetch(PDO::FETCH_ASSOC);


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

/*
|--------------------------------------------------------------------------
| Unlock rules
|--------------------------------------------------------------------------
|
| Step 1: available after admission process starts.
| Step 2: unlocked as soon as Step 1 has ANY submission row.
| Step 3: unlocked as soon as Step 2 has ANY submission row.
|
| Verification/approval does NOT control unlocking.
|--------------------------------------------------------------------------
*/

$step1Unlocked =
    $progress !== false
    &&
    $progress !== null;

$step2Unlocked =
    !empty($deptSub);

$step3Unlocked =
    !empty($hallSub);

/*
|--------------------------------------------------------------------------
| Display status for each step
|--------------------------------------------------------------------------
*/

$step1Status =
    $deptSub
        ? (string) (
            $deptSub[
                'submission_status'
            ]
            ?? 'Submitted'
        )
        : (
            $step1Unlocked
                ? 'Not Submitted'
                : 'Locked'
        );


$step2Status =
    $hallSub
        ? (string) (
            $hallSub[
                'submission_status'
            ]
            ?? 'Submitted'
        )
        : (
            $step2Unlocked
                ? 'Not Submitted'
                : 'Locked'
        );


if ($approval) {

    $step3Status =
        (string) (
            $approval[
                'approval_status'
            ]
            ?? 'Submitted'
        );

} elseif ($centralSub) {

    $step3Status =
        (string) (
            $centralSub[
                'submission_status'
            ]
            ?? 'Submitted'
        );

} else {

    $step3Status =
        $step3Unlocked
            ? 'Not Submitted'
            : 'Locked';
}

/*
|--------------------------------------------------------------------------
| Badge / border helpers
|--------------------------------------------------------------------------
*/

function progressStateClass(
    string $status
): array {

    return match ($status) {

        'Verified',
        'Approved',
        'Admitted'
            => [
                'border' =>
                    'border-success',

                'badge' =>
                    'bg-success',

                'circle' =>
                    'bg-success',

                'done' =>
                    true,

                'rejected' =>
                    false,
            ],

        'Rejected'
            => [
                'border' =>
                    'border-danger',

                'badge' =>
                    'bg-danger',

                'circle' =>
                    'bg-danger',

                'done' =>
                    false,

                'rejected' =>
                    true,
            ],

        'Pending',
        'Submitted',
        'Under Review'
            => [
                'border' =>
                    'border-info',

                'badge' =>
                    'bg-info text-dark',

                'circle' =>
                    'bg-info',

                'done' =>
                    false,

                'rejected' =>
                    false,
            ],

        'Not Submitted'
            => [
                'border' =>
                    'border-warning',

                'badge' =>
                    'bg-warning text-dark',

                'circle' =>
                    'bg-light border',

                'done' =>
                    false,

                'rejected' =>
                    false,
            ],

        default
            => [
                'border' =>
                    'border-secondary',

                'badge' =>
                    'bg-secondary',

                'circle' =>
                    'bg-light border',

                'done' =>
                    false,

                'rejected' =>
                    false,
            ],
    };
}

require_once '../includes/header.php';
require_once '../includes/navbar.php';

?>

<div class="container py-4">

    <div
        class="d-flex
               justify-content-between
               align-items-center
               mb-4
               flex-wrap
               gap-2"
    >

        <h4 class="fw-bold mb-0">

            <i
                class="bi bi-ui-checks
                       text-primary"
            ></i>

            Admission Progress

        </h4>

        <a
            href="dashboard.php"
            class="btn
                   btn-outline-secondary
                   btn-sm"
        >

            <i
                class="bi bi-arrow-left"
            ></i>

            Dashboard

        </a>

    </div>


    <div class="alert alert-info mb-4">

        <i
            class="bi bi-info-circle-fill
                   me-1"
        ></i>

        <strong>
            Step Unlocking:
        </strong>

        The next step becomes available immediately after you submit the current step.
        Verification and approval continue separately.

    </div>


    <?php if (!$progress): ?>

        <div class="alert alert-warning">

            <i
                class="bi bi-info-circle-fill"
            ></i>

            Your admission process has not started yet.
            Submission steps will become available after you are selected in Viva.

        </div>


    <?php else: ?>


        <?php

        $finalStatus =
            (string) (
                $progress[
                    'final_status'
                ]
                ?? 'In Progress'
            );


        $bannerClass =
            match ($finalStatus) {

                'Admitted'
                    => 'alert-success',

                'Rejected'
                    => 'alert-danger',

                'Withdrawn'
                    => 'alert-dark',

                default
                    => 'alert-primary',
            };


        $bannerIcon =
            match ($finalStatus) {

                'Admitted'
                    => 'bi-mortarboard-fill',

                'Rejected'
                    => 'bi-x-circle-fill',

                'Withdrawn'
                    => 'bi-slash-circle-fill',

                default
                    => 'bi-arrow-repeat',
            };

        ?>


        <!-- Overall Status Banner -->

        <div
            class="alert
                   <?= $bannerClass ?>
                   d-flex
                   align-items-center
                   gap-3
                   mb-4"
        >

            <i
                class="bi
                       <?= $bannerIcon ?>
                       fs-3"
            ></i>

            <div>

                <strong class="fs-5">

                    <?= $finalStatus ===
                        'In Progress'
                            ? 'Admission In Progress'
                            : progressE(
                                $finalStatus
                            )
                    ?>

                </strong>


                <?php if ($merit): ?>

                    <div class="small">

                        Department:

                        <strong>
                            <?= progressE(
                                $merit[
                                    'dept_name'
                                ]
                            ) ?>
                        </strong>

                        &nbsp;|&nbsp;

                        Merit Rank:

                        <strong>
                            #<?= (int) (
                                $merit[
                                    'merit_rank'
                                ]
                                ?? 0
                            ) ?>
                        </strong>

                    </div>

                <?php endif; ?>

            </div>

        </div>


        <?php

        $steps = [

            [
                'num' =>
                    1,

                'title' =>
                    'Department Submission',

                'icon' =>
                    'bi-building',

                'link' =>
                    'step1_dept.php',

                'status' =>
                    $step1Status,

                'unlocked' =>
                    $step1Unlocked,

                'detail' =>
                    $deptSub
                        ? [
                            'Fee' =>
                                '৳ '
                                . number_format(
                                    (float) (
                                        $deptSub[
                                            'dept_fee_amount'
                                        ]
                                        ?? 0
                                    ),
                                    2
                                ),

                            'Method' =>
                                $deptSub[
                                    'payment_method'
                                ]
                                ?? '—',

                            'Submitted' =>
                                !empty(
                                    $deptSub[
                                        'submitted_at'
                                    ]
                                )
                                    ? date(
                                        'd M Y, h:i A',
                                        strtotime(
                                            (string)
                                            $deptSub[
                                                'submitted_at'
                                            ]
                                        )
                                    )
                                    : '—',

                            'Verified At' =>
                                !empty(
                                    $deptSub[
                                        'verified_at'
                                    ]
                                )
                                    ? date(
                                        'd M Y, h:i A',
                                        strtotime(
                                            (string)
                                            $deptSub[
                                                'verified_at'
                                            ]
                                        )
                                    )
                                    : '—',

                            'Status' =>
                                $deptSub[
                                    'submission_status'
                                ]
                                ?? '—',
                        ]
                        : null,
            ],

            [
                'num' =>
                    2,

                'title' =>
                    'Hall Submission',

                'icon' =>
                    'bi-house-fill',

                'link' =>
                    'step2_hall.php',

                'status' =>
                    $step2Status,

                'unlocked' =>
                    $step2Unlocked,

                'detail' =>
                    $hallSub
                        ? [
                            'Hall' =>
                                $hallSub[
                                    'hall_name'
                                ]
                                ?? '—',

                            'Fee' =>
                                '৳ '
                                . number_format(
                                    (float) (
                                        $hallSub[
                                            'hall_fee_amount'
                                        ]
                                        ?? 0
                                    ),
                                    2
                                ),

                            'Submitted' =>
                                !empty(
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
                                    : '—',

                            'Status' =>
                                $hallSub[
                                    'submission_status'
                                ]
                                ?? '—',
                        ]
                        : null,
            ],

            [
                'num' =>
                    3,

                'title' =>
                    'Registrar Submission & Approval',

                'icon' =>
                    'bi-patch-check-fill',

                'link' =>
                    'step3_registrar.php',

                'status' =>
                    $step3Status,

                'unlocked' =>
                    $step3Unlocked,

                'detail' =>
                    (
                        $centralSub
                        ||
                        $approval
                    )
                        ? [
                            'Submission Status' =>
                                $centralSub[
                                    'submission_status'
                                ]
                                ?? '—',

                            'Approval Status' =>
                                $approval[
                                    'approval_status'
                                ]
                                ?? '—',

                            'Submitted' =>
                                !empty(
                                    $centralSub[
                                        'submitted_at'
                                    ]
                                )
                                    ? date(
                                        'd M Y, h:i A',
                                        strtotime(
                                            (string)
                                            $centralSub[
                                                'submitted_at'
                                            ]
                                        )
                                    )
                                    : (
                                        !empty(
                                            $approval[
                                                'submitted_at'
                                            ]
                                        )
                                            ? date(
                                                'd M Y, h:i A',
                                                strtotime(
                                                    (string)
                                                    $approval[
                                                        'submitted_at'
                                                    ]
                                                )
                                            )
                                            : '—'
                                    ),

                            'Remarks' =>
                                $approval[
                                    'remarks'
                                ]
                                ?? '—',
                        ]
                        : null,
            ],
        ];

        ?>


        <?php foreach ($steps as $step): ?>

            <?php

            $state =
                progressStateClass(
                    (string)
                    $step['status']
                );

            ?>


            <div
                class="card
                       shadow-sm
                       mb-3
                       border-start
                       border-4
                       <?= $state[
                           'border'
                       ] ?>"
            >

                <div class="card-body">


                    <div
                        class="d-flex
                               justify-content-between
                               align-items-start
                               gap-3"
                    >


                        <div
                            class="d-flex
                                   gap-3
                                   align-items-center"
                        >


                            <div
                                class="rounded-circle
                                       d-flex
                                       align-items-center
                                       justify-content-center
                                       <?= $state[
                                           'circle'
                                       ] ?>"
                                style="
                                    width:48px;
                                    height:48px;
                                    flex-shrink:0;
                                "
                            >

                                <?php if (
                                    $state[
                                        'done'
                                    ]
                                ): ?>

                                    <i
                                        class="bi bi-check-lg
                                               text-white
                                               fs-4"
                                    ></i>


                                <?php elseif (
                                    $state[
                                        'rejected'
                                    ]
                                ): ?>

                                    <i
                                        class="bi bi-x-lg
                                               text-white
                                               fs-4"
                                    ></i>


                                <?php else: ?>

                                    <span
                                        class="fw-bold
                                               text-muted"
                                    >
                                        <?= (int)
                                            $step[
                                                'num'
                                            ] ?>
                                    </span>

                                <?php endif; ?>

                            </div>


                            <div>

                                <h6 class="mb-1 fw-bold">

                                    <i
                                        class="bi
                                               <?= progressE(
                                                   $step[
                                                       'icon'
                                                   ]
                                               ) ?>
                                               me-1"
                                    ></i>

                                    <?= progressE(
                                        $step[
                                            'title'
                                        ]
                                    ) ?>

                                </h6>


                                <span
                                    class="badge
                                           <?= $state[
                                               'badge'
                                           ] ?>"
                                >
                                    <?= progressE(
                                        $step[
                                            'status'
                                        ]
                                    ) ?>
                                </span>


                                <?php if (
                                    !$step[
                                        'unlocked'
                                    ]
                                ): ?>

                                    <div
                                        class="small
                                               text-muted
                                               mt-1"
                                    >

                                        <i
                                            class="bi bi-lock-fill"
                                        ></i>

                                        <?php if (
                                            (int)
                                            $step[
                                                'num'
                                            ]
                                            === 2
                                        ): ?>

                                            Submit Step 1 to unlock.

                                        <?php elseif (
                                            (int)
                                            $step[
                                                'num'
                                            ]
                                            === 3
                                        ): ?>

                                            Submit Step 2 to unlock.

                                        <?php else: ?>

                                            Admission process has not started.

                                        <?php endif; ?>

                                    </div>

                                <?php endif; ?>

                            </div>

                        </div>


                        <?php if (
                            $step[
                                'unlocked'
                            ]
                        ): ?>

                            <a
                                href="<?= progressE(
                                    $step[
                                        'link'
                                    ]
                                ) ?>"
                                class="btn
                                       btn-outline-primary
                                       btn-sm
                                       flex-shrink-0"
                            >

                                <?= $step[
                                    'detail'
                                ]
                                    ? 'View / Edit'
                                    : 'Go'
                                ?>

                                <i
                                    class="bi
                                           bi-arrow-right"
                                ></i>

                            </a>


                        <?php else: ?>

                            <button
                                type="button"
                                class="btn
                                       btn-outline-secondary
                                       btn-sm
                                       flex-shrink-0"
                                disabled
                            >

                                <i
                                    class="bi bi-lock-fill"
                                ></i>

                                Locked

                            </button>

                        <?php endif; ?>


                    </div>


                    <?php if (
                        $step[
                            'detail'
                        ]
                    ): ?>

                        <div
                            class="row
                                   g-2
                                   mt-2
                                   ms-1
                                   border-top
                                   pt-2"
                        >

                            <?php foreach (
                                $step[
                                    'detail'
                                ]
                                as $key =>
                                $value
                            ): ?>

                                <div
                                    class="col-md-3
                                           col-6"
                                >

                                    <small
                                        class="text-muted
                                               d-block"
                                    >
                                        <?= progressE(
                                            $key
                                        ) ?>
                                    </small>

                                    <span
                                        class="small
                                               fw-semibold"
                                    >
                                        <?= progressE(
                                            $value
                                        ) ?>
                                    </span>

                                </div>

                            <?php endforeach; ?>

                        </div>

                    <?php endif; ?>


                </div>

            </div>

        <?php endforeach; ?>


    <?php endif; ?>

</div>

<?php
require_once '../includes/footer.php';
?>

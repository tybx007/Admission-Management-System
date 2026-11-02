<?php

declare(strict_types=1);

$pageTitle = 'Admission Reports';

require_once '../includes/auth.php';
require_once '../config/db.php';

/*
|--------------------------------------------------------------------------
| Authentication BEFORE HTML output
|--------------------------------------------------------------------------
*/

requireLogin('super_admin');

if (
    !isset($_SESSION['user_id']) ||
    ($_SESSION['role'] ?? '') !== 'super_admin'
) {
    header('Location: /jkkniu-admission/super_admin/login.php');
    exit;
}

$pdo->setAttribute(
    PDO::ATTR_ERRMODE,
    PDO::ERRMODE_EXCEPTION
);


/*
|--------------------------------------------------------------------------
| Helper
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


/*
|--------------------------------------------------------------------------
| Overall statistics
|--------------------------------------------------------------------------
|
| IMPORTANT:
| The admission pool comes from gst_imported_candidates.
| gst_applicants contains only candidates who have registered.
|--------------------------------------------------------------------------
*/

$stats = [];

/* GST selected / imported candidates */
$stats['total_candidates'] = (int) $pdo
    ->query("
        SELECT COUNT(*)
        FROM gst_imported_candidates
        WHERE selection_status = 'Selected'
    ")
    ->fetchColumn();

/* Registered accounts */
$stats['registered_applicants'] = (int) $pdo
    ->query("
        SELECT COUNT(*)
        FROM gst_applicants
    ")
    ->fetchColumn();

/* Viva */
$stats['viva_selected'] = (int) $pdo
    ->query("
        SELECT COUNT(DISTINCT applicant_id)
        FROM viva_results
        WHERE viva_status = 'Selected'
    ")
    ->fetchColumn();

$stats['viva_not_selected'] = (int) $pdo
    ->query("
        SELECT COUNT(DISTINCT applicant_id)
        FROM viva_results
        WHERE viva_status = 'Not Selected'
    ")
    ->fetchColumn();

$stats['viva_absent'] = (int) $pdo
    ->query("
        SELECT COUNT(DISTINCT applicant_id)
        FROM viva_results
        WHERE viva_status = 'Absent'
    ")
    ->fetchColumn();

/* Merit allocations */
$stats['total_allocated'] = (int) $pdo
    ->query("
        SELECT COUNT(*)
        FROM merit_allocations
        WHERE allocation_status IN (
            'Allocated',
            'Admitted'
        )
    ")
    ->fetchColumn();

$stats['total_admitted'] = (int) $pdo
    ->query("
        SELECT COUNT(*)
        FROM merit_allocations
        WHERE allocation_status = 'Admitted'
    ")
    ->fetchColumn();

$stats['total_vacated'] = (int) $pdo
    ->query("
        SELECT COUNT(*)
        FROM merit_allocations
        WHERE allocation_status = 'Vacated'
    ")
    ->fetchColumn();

/* Waiting list */
$stats['waiting_list'] = (int) $pdo
    ->query("
        SELECT COUNT(DISTINCT wl.import_candidate_id)
        FROM waiting_list wl
        INNER JOIN gst_candidate_choices gcc
            ON gcc.import_candidate_id = wl.import_candidate_id
           AND gcc.dept_id = wl.dept_id
        WHERE wl.waiting_status = 'Waiting'
          AND gcc.preference_order = (
              SELECT MIN(gcc2.preference_order)
              FROM gst_candidate_choices gcc2
              WHERE gcc2.import_candidate_id = wl.import_candidate_id
          )
    ")
    ->fetchColumn();

$stats['waiting_called'] = (int) $pdo
    ->query("
        SELECT COUNT(DISTINCT wl.import_candidate_id)
        FROM waiting_list wl
        INNER JOIN gst_candidate_choices gcc
            ON gcc.import_candidate_id = wl.import_candidate_id
           AND gcc.dept_id = wl.dept_id
        WHERE wl.waiting_status = 'Called'
          AND gcc.preference_order = (
              SELECT MIN(gcc2.preference_order)
              FROM gst_candidate_choices gcc2
              WHERE gcc2.import_candidate_id = wl.import_candidate_id
          )
    ")
    ->fetchColumn();

/* Admission progress */
$stats['dept_step_done'] = (int) $pdo
    ->query("
        SELECT COUNT(*)
        FROM admission_progress
        WHERE dept_step = 'Done'
    ")
    ->fetchColumn();

$stats['hall_step_done'] = (int) $pdo
    ->query("
        SELECT COUNT(*)
        FROM admission_progress
        WHERE hall_step = 'Done'
    ")
    ->fetchColumn();

$stats['registrar_approved'] = (int) $pdo
    ->query("
        SELECT COUNT(*)
        FROM admission_progress
        WHERE registrar_step = 'Approved'
    ")
    ->fetchColumn();

$stats['registrar_rejected'] = (int) $pdo
    ->query("
        SELECT COUNT(*)
        FROM admission_progress
        WHERE registrar_step = 'Rejected'
    ")
    ->fetchColumn();

/* Structure */
$stats['total_faculties'] = (int) $pdo
    ->query("
        SELECT COUNT(*)
        FROM faculties
    ")
    ->fetchColumn();

$stats['total_depts'] = (int) $pdo
    ->query("
        SELECT COUNT(*)
        FROM departments
    ")
    ->fetchColumn();

$stats['total_seats'] = (int) (
    $pdo
        ->query("
            SELECT COALESCE(
                SUM(total_seats),
                0
            )
            FROM departments
        ")
        ->fetchColumn()
        ?: 0
);

/* Admin users */
$stats['pending_admins'] = (int) $pdo
    ->query("
        SELECT COUNT(*)
        FROM admin_users
        WHERE status = 'Pending'
    ")
    ->fetchColumn();

$stats['active_admins'] = (int) $pdo
    ->query("
        SELECT COUNT(*)
        FROM admin_users
        WHERE status = 'Active'
    ")
    ->fetchColumn();


/*
|--------------------------------------------------------------------------
| Faculty-wise report
|--------------------------------------------------------------------------
|
| Each metric is pre-aggregated before joining to faculties.
| This prevents department seats and candidate counts from being multiplied
| by large joins.
|--------------------------------------------------------------------------
*/

$facultyReport = $pdo
    ->query("
        SELECT
            f.faculty_id,
            f.faculty_name,

            COALESCE(
                candidate_stats.candidates,
                0
            ) AS candidates,

            COALESCE(
                viva_stats.viva_sel,
                0
            ) AS viva_sel,

            COALESCE(
                viva_stats.viva_no,
                0
            ) AS viva_no,

            COALESCE(
                viva_stats.viva_abs,
                0
            ) AS viva_abs,

            COALESCE(
                allocation_stats.allocated,
                0
            ) AS allocated,

            COALESCE(
                allocation_stats.admitted,
                0
            ) AS admitted,

            COALESCE(
                allocation_stats.vacated,
                0
            ) AS vacated,

            COALESCE(
                waiting_stats.waiting,
                0
            ) AS waiting,

            COALESCE(
                seat_stats.seats,
                0
            ) AS seats

        FROM faculties f


        LEFT JOIN (
            SELECT
                faculty_id,
                COUNT(*) AS candidates
            FROM gst_imported_candidates
            WHERE selection_status = 'Selected'
            GROUP BY faculty_id
        ) candidate_stats
            ON candidate_stats.faculty_id =
               f.faculty_id


        LEFT JOIN (
            SELECT
                gic.faculty_id,

                COUNT(
                    DISTINCT CASE
                        WHEN v.viva_status = 'Selected'
                        THEN v.applicant_id
                    END
                ) AS viva_sel,

                COUNT(
                    DISTINCT CASE
                        WHEN v.viva_status = 'Not Selected'
                        THEN v.applicant_id
                    END
                ) AS viva_no,

                COUNT(
                    DISTINCT CASE
                        WHEN v.viva_status = 'Absent'
                        THEN v.applicant_id
                    END
                ) AS viva_abs

            FROM gst_imported_candidates gic

            INNER JOIN viva_results v
                ON v.applicant_id =
                   gic.applicant_id

            GROUP BY
                gic.faculty_id
        ) viva_stats
            ON viva_stats.faculty_id =
               f.faculty_id


        LEFT JOIN (
            SELECT
                d.faculty_id,

                COUNT(
                    DISTINCT CASE
                        WHEN ma.allocation_status IN (
                            'Allocated',
                            'Admitted'
                        )
                        THEN ma.import_candidate_id
                    END
                ) AS allocated,

                COUNT(
                    DISTINCT CASE
                        WHEN ma.allocation_status = 'Admitted'
                        THEN ma.import_candidate_id
                    END
                ) AS admitted,

                COUNT(
                    DISTINCT CASE
                        WHEN ma.allocation_status = 'Vacated'
                        THEN ma.import_candidate_id
                    END
                ) AS vacated

            FROM merit_allocations ma

            INNER JOIN departments d
                ON d.dept_id =
                   ma.dept_id

            GROUP BY
                d.faculty_id
        ) allocation_stats
            ON allocation_stats.faculty_id =
               f.faculty_id


        LEFT JOIN (
            SELECT
                d.faculty_id,
                COUNT(
                    DISTINCT wl.import_candidate_id
                ) AS waiting

            FROM waiting_list wl

            INNER JOIN departments d
                ON d.dept_id =
                   wl.dept_id

            INNER JOIN gst_candidate_choices gcc
                ON gcc.import_candidate_id =
                   wl.import_candidate_id
               AND gcc.dept_id =
                   wl.dept_id

            WHERE wl.waiting_status = 'Waiting'

              AND gcc.preference_order = (
                  SELECT MIN(gcc2.preference_order)
                  FROM gst_candidate_choices gcc2
                  WHERE gcc2.import_candidate_id =
                        wl.import_candidate_id
              )

            GROUP BY
                d.faculty_id
        ) waiting_stats
            ON waiting_stats.faculty_id =
               f.faculty_id


        LEFT JOIN (
            SELECT
                faculty_id,
                SUM(total_seats) AS seats
            FROM departments
            GROUP BY faculty_id
        ) seat_stats
            ON seat_stats.faculty_id =
               f.faculty_id


        ORDER BY
            f.faculty_id
    ")
    ->fetchAll(PDO::FETCH_ASSOC);


/*
|--------------------------------------------------------------------------
| Department-wise report
|--------------------------------------------------------------------------
*/

$deptReport = $pdo
    ->query("
        SELECT
            d.dept_id,
            d.dept_code,
            d.dept_name,
            d.total_seats,

            f.faculty_name,

            COALESCE(
                choice_stats.choices,
                0
            ) AS choices,

            COALESCE(
                allocation_stats.allocated,
                0
            ) AS allocated,

            COALESCE(
                allocation_stats.admitted,
                0
            ) AS admitted,

            COALESCE(
                allocation_stats.vacated,
                0
            ) AS vacated,

            COALESCE(
                waiting_stats.waiting,
                0
            ) AS waiting,

            COALESCE(
                waiting_stats.called,
                0
            ) AS waiting_called

        FROM departments d

        INNER JOIN faculties f
            ON f.faculty_id =
               d.faculty_id


        LEFT JOIN (
            SELECT
                dept_id,
                COUNT(
                    DISTINCT import_candidate_id
                ) AS choices

            FROM gst_candidate_choices

            GROUP BY dept_id
        ) choice_stats
            ON choice_stats.dept_id =
               d.dept_id


        LEFT JOIN (
            SELECT
                dept_id,

                COUNT(
                    DISTINCT CASE
                        WHEN allocation_status IN (
                            'Allocated',
                            'Admitted'
                        )
                        THEN import_candidate_id
                    END
                ) AS allocated,

                COUNT(
                    DISTINCT CASE
                        WHEN allocation_status = 'Admitted'
                        THEN import_candidate_id
                    END
                ) AS admitted,

                COUNT(
                    DISTINCT CASE
                        WHEN allocation_status = 'Vacated'
                        THEN import_candidate_id
                    END
                ) AS vacated

            FROM merit_allocations

            GROUP BY dept_id
        ) allocation_stats
            ON allocation_stats.dept_id =
               d.dept_id


        LEFT JOIN (
            SELECT
                wl.dept_id,

                COUNT(
                    DISTINCT CASE
                        WHEN wl.waiting_status = 'Waiting'
                        THEN wl.import_candidate_id
                    END
                ) AS waiting,

                COUNT(
                    DISTINCT CASE
                        WHEN wl.waiting_status = 'Called'
                        THEN wl.import_candidate_id
                    END
                ) AS called

            FROM waiting_list wl

            INNER JOIN gst_candidate_choices gcc
                ON gcc.import_candidate_id =
                   wl.import_candidate_id
               AND gcc.dept_id =
                   wl.dept_id

            WHERE gcc.preference_order = (
                SELECT MIN(gcc2.preference_order)
                FROM gst_candidate_choices gcc2
                WHERE gcc2.import_candidate_id =
                      wl.import_candidate_id
            )

            GROUP BY wl.dept_id
        ) waiting_stats
            ON waiting_stats.dept_id =
               d.dept_id


        ORDER BY
            f.faculty_id,
            d.dept_name
    ")
    ->fetchAll(PDO::FETCH_ASSOC);


/*
|--------------------------------------------------------------------------
| Gender breakdown
|--------------------------------------------------------------------------
|
| Use imported candidates so unregistered selected candidates are included.
|--------------------------------------------------------------------------
*/

$genderRows = $pdo
    ->query("
        SELECT
            COALESCE(
                NULLIF(TRIM(gender), ''),
                'Unknown'
            ) AS gender,
            COUNT(*) AS cnt

        FROM gst_imported_candidates

        WHERE selection_status = 'Selected'

        GROUP BY
            COALESCE(
                NULLIF(TRIM(gender), ''),
                'Unknown'
            )

        ORDER BY cnt DESC
    ")
    ->fetchAll(PDO::FETCH_ASSOC);

$genderStats = [];

foreach ($genderRows as $row) {
    $genderStats[
        (string) $row['gender']
    ] = (int) $row['cnt'];
}


/*
|--------------------------------------------------------------------------
| Quota breakdown
|--------------------------------------------------------------------------
*/

$quotaStats = $pdo
    ->query("
        SELECT
            CASE
                WHEN quota IS NULL
                     OR TRIM(quota) = ''
                THEN 'General'
                ELSE quota
            END AS quota,

            COUNT(*) AS cnt

        FROM gst_imported_candidates

        WHERE selection_status = 'Selected'

        GROUP BY
            CASE
                WHEN quota IS NULL
                     OR TRIM(quota) = ''
                THEN 'General'
                ELSE quota
            END

        ORDER BY cnt DESC
    ")
    ->fetchAll(PDO::FETCH_ASSOC);


/*
|--------------------------------------------------------------------------
| Recent final admissions
|--------------------------------------------------------------------------
|
| Use the imported candidate relationship because merit was generated
| before registration and is keyed by import_candidate_id.
|--------------------------------------------------------------------------
*/

$recentAdmissions = $pdo
    ->query("
        SELECT
            COALESCE(
                NULLIF(TRIM(ga.full_name), ''),
                gic.full_name
            ) AS full_name,

            gic.gst_roll,

            d.dept_code,
            d.dept_name,
            f.faculty_name,

            ma.merit_rank,
            ma.allocated_at

        FROM merit_allocations ma

        INNER JOIN gst_imported_candidates gic
            ON gic.import_candidate_id =
               ma.import_candidate_id

        LEFT JOIN gst_applicants ga
            ON ga.applicant_id =
               gic.applicant_id

        INNER JOIN departments d
            ON d.dept_id =
               ma.dept_id

        INNER JOIN faculties f
            ON f.faculty_id =
               d.faculty_id

        WHERE ma.allocation_status = 'Admitted'

        ORDER BY
            ma.allocated_at DESC,
            ma.allocation_id DESC

        LIMIT 10
    ")
    ->fetchAll(PDO::FETCH_ASSOC);


/*
|--------------------------------------------------------------------------
| GST score distribution
|--------------------------------------------------------------------------
*/

$scoreRanges = $pdo
    ->query("
        SELECT
            SUM(
                CASE
                    WHEN gst_score >= 80
                    THEN 1
                    ELSE 0
                END
            ) AS s80,

            SUM(
                CASE
                    WHEN gst_score >= 60
                     AND gst_score < 80
                    THEN 1
                    ELSE 0
                END
            ) AS s60,

            SUM(
                CASE
                    WHEN gst_score >= 40
                     AND gst_score < 60
                    THEN 1
                    ELSE 0
                END
            ) AS s40,

            SUM(
                CASE
                    WHEN gst_score < 40
                    THEN 1
                    ELSE 0
                END
            ) AS sBelow40

        FROM gst_imported_candidates

        WHERE selection_status = 'Selected'
    ")
    ->fetch(PDO::FETCH_ASSOC);

$scoreRanges = [
    's80' => (int) (
        $scoreRanges['s80'] ?? 0
    ),
    's60' => (int) (
        $scoreRanges['s60'] ?? 0
    ),
    's40' => (int) (
        $scoreRanges['s40'] ?? 0
    ),
    'sBelow40' => (int) (
        $scoreRanges['sBelow40'] ?? 0
    ),
];


/*
|--------------------------------------------------------------------------
| Totals
|--------------------------------------------------------------------------
*/

$deptTotals = [
    'seats' => 0,
    'choices' => 0,
    'allocated' => 0,
    'admitted' => 0,
    'vacated' => 0,
    'waiting' => 0,
];

foreach ($deptReport as $row) {
    $deptTotals['seats'] +=
        (int) $row['total_seats'];

    $deptTotals['choices'] +=
        (int) $row['choices'];

    $deptTotals['allocated'] +=
        (int) $row['allocated'];

    $deptTotals['admitted'] +=
        (int) $row['admitted'];

    $deptTotals['vacated'] +=
        (int) $row['vacated'];

    $deptTotals['waiting'] +=
        (int) $row['waiting'];
}


/*
|--------------------------------------------------------------------------
| HTML only after data preparation
|--------------------------------------------------------------------------
*/

require_once '../includes/header.php';
require_once '../includes/navbar.php';

?>

<style>
.report-table thead th {
    white-space: nowrap;
    font-size: .78rem;
}

.report-table td {
    font-size: .82rem;
}
</style>


<div class="container py-4">


    <!-- =========================================================
         HEADER
    ========================================================== -->

    <div
        class="alert alert-primary d-flex align-items-center
               justify-content-between flex-wrap gap-2 mb-4"
    >

        <div
            class="d-flex align-items-center gap-3"
        >

            <div
                class="d-flex align-items-center justify-content-center"
                style="width:46px;height:46px;"
            >

                <i
                    class="bi bi-file-earmark-bar-graph-fill fs-4"
                ></i>

            </div>


            <div>

                <div class="fw-bold fs-5">
                    Admission Reports
                </div>

                <small class="opacity-75">
                    Super Admin · System-wide admission analytics
                </small>

            </div>

        </div>


        <div class="text-end">

            <small
                class="d-block opacity-75"
            >
                Generated:
                <?= date(
                    'd M Y, h:i A'
                ) ?>
            </small>

            <a
                href="dashboard.php"
                class="btn btn-sm
                       btn-light mt-1"
            >

                <i
                    class="bi bi-arrow-left me-1"
                ></i>

                Dashboard

            </a>

        </div>

    </div>


    <!-- =========================================================
         OVERVIEW
    ========================================================== -->

    <div class="card shadow-sm mb-4">

        <div
            class="card-header bg-dark py-2 fw-semibold"
        >

            <strong>

                <i
                    class="bi bi-speedometer2 text-warning me-1"
                ></i>

                Overall Admission Overview

            </strong>

        </div>


        <div class="card-body py-4">

            <?php
            $overviewStats = [
                [
                    'label' => 'GST Candidates',
                    'value' => $stats['total_candidates'],
                    'icon' => 'bi-people-fill',
                    'bg' => 'bg-primary',
                ],
                [
                    'label' => 'Registered',
                    'value' => $stats['registered_applicants'],
                    'icon' => 'bi-person-check-fill',
                    'bg' => 'bg-success',
                ],
                [
                    'label' => 'Viva Selected',
                    'value' => $stats['viva_selected'],
                    'icon' => 'bi-person-badge-fill',
                    'bg' => 'bg-success',
                ],
                [
                    'label' => 'Viva Rejected',
                    'value' => $stats['viva_not_selected'],
                    'icon' => 'bi-person-x-fill',
                    'bg' => 'bg-danger',
                ],
                [
                    'label' => 'Allocated',
                    'value' => $stats['total_allocated'],
                    'icon' => 'bi-award-fill',
                    'bg' => 'bg-info',
                ],
                [
                    'label' => 'Admitted',
                    'value' => $stats['total_admitted'],
                    'icon' => 'bi-mortarboard-fill',
                    'bg' => 'bg-warning',
                ],
                [
                    'label' => 'Waiting',
                    'value' => $stats['waiting_list'],
                    'icon' => 'bi-hourglass-split',
                    'bg' => 'bg-secondary',
                ],
                [
                    'label' => 'Vacated',
                    'value' => $stats['total_vacated'],
                    'icon' => 'bi-door-open-fill',
                    'bg' => 'bg-danger',
                ],
            ];
            ?>


            <div class="row g-3 text-center">

                <?php foreach (
                    $overviewStats as $item
                ): ?>

                    <div
                        class="col-6
                               col-md-3"
                    >

                        <div
                            class="d-flex flex-column
                                   align-items-center gap-2"
                        >

                            <div
                                class="rounded-circle d-flex align-items-center
                                       justify-content-center <?= e($item['bg']) ?> text-white"
                                style="
                                    width:52px;
                                    height:52px;
                                "
                            >

                                <i
                                    class="bi <?= e(
                                        $item['icon']
                                    ) ?> fs-4"
                                ></i>

                            </div>


                            <small
                                class="fw-semibold text-muted"
                            >
                                <?= e(
                                    $item['label']
                                ) ?>
                            </small>


                            <span
                                class="badge <?= e($item['bg']) ?> fs-6 px-3"
                            >

                                <?= number_format(
                                    (int)
                                    $item['value']
                                ) ?>

                            </span>

                        </div>

                    </div>

                <?php endforeach; ?>

            </div>

        </div>

    </div>


    <!-- =========================================================
         PIPELINE
    ========================================================== -->

    <div class="card shadow-sm mb-4">

        <div
            class="card-header bg-dark py-2 fw-semibold"
        >

            <strong>

                <i
                    class="bi bi-arrow-right-circle-fill text-info me-1"
                ></i>

                Admission Pipeline Progress

            </strong>

        </div>


        <div class="card-body">

            <?php

            $registeredBase =
                max(
                    $stats[
                        'registered_applicants'
                    ],
                    1
                );

            $pipeline = [
                [
                    'label' => 'Registered Applicants',
                    'value' => $stats['registered_applicants'],
                    'base' => max($stats['total_candidates'], 1),
                    'color' => 'bg-primary',
                ],
                [
                    'label' => 'Department Step Completed',
                    'value' => $stats['dept_step_done'],
                    'base' => $registeredBase,
                    'color' => 'bg-info',
                ],
                [
                    'label' => 'Hall Step Completed',
                    'value' => $stats['hall_step_done'],
                    'base' => $registeredBase,
                    'color' => 'bg-warning',
                ],
                [
                    'label' => 'Registrar Approved',
                    'value' => $stats['registrar_approved'],
                    'base' => $registeredBase,
                    'color' => 'bg-success',
                ],
            ];

            ?>


            <div class="row g-3">

                <?php foreach (
                    $pipeline as $item
                ): ?>

                    <?php

                    $percentage =
                        min(
                            100,
                            (int) round(
                                (
                                    (int)
                                    $item['value']
                                    /
                                    (int)
                                    $item['base']
                                ) * 100
                            )
                        );

                    ?>

                    <div class="col-12">

                        <div
                            class="d-flex
                                   justify-content-between
                                   gap-2 mb-1"
                        >

                            <small class="fw-semibold">

                                <?= e(
                                    $item['label']
                                ) ?>

                            </small>


                            <small class="text-muted">

                                <?= number_format(
                                    (int)
                                    $item['value']
                                ) ?>

                                (<?= $percentage ?>%)

                            </small>

                        </div>


                        <div
                            class="progress"
                            style="height:10px;"
                        >

                            <div
                                class="progress-bar <?= e($item['color']) ?>"
                                style="width:<?= $percentage ?>%;"
                            ></div>

                        </div>

                    </div>

                <?php endforeach; ?>


                <?php if (
                    $stats[
                        'registrar_rejected'
                    ] > 0
                ): ?>

                    <?php

                    $rejectedPct =
                        min(
                            100,
                            (int) round(
                                (
                                    $stats[
                                        'registrar_rejected'
                                    ]
                                    /
                                    $registeredBase
                                ) * 100
                            )
                        );

                    ?>

                    <div class="col-12">

                        <div
                            class="d-flex
                                   justify-content-between
                                   gap-2 mb-1"
                        >

                            <small
                                class="fw-semibold
                                       text-danger"
                            >
                                Registrar Rejected
                            </small>

                            <small class="text-muted">

                                <?= number_format(
                                    $stats[
                                        'registrar_rejected'
                                    ]
                                ) ?>

                                (<?= $rejectedPct ?>%)

                            </small>

                        </div>


                        <div
                            class="progress"
                            style="height:10px;"
                        >

                            <div
                                class="progress-bar bg-danger"
                                style="width:<?= $rejectedPct ?>%;"
                            ></div>

                        </div>

                    </div>

                <?php endif; ?>

            </div>

        </div>

    </div>


    <!-- =========================================================
         SCORE + DEMOGRAPHICS
    ========================================================== -->

    <div class="row g-4 mb-4">


        <!-- SCORE -->

        <div class="col-lg-6">

            <div class="card shadow-sm h-100">

                <div
                    class="card-header
                           bg-primary py-3"
                >

                    <strong>

                        <i
                            class="bi bi-bar-chart-fill text-success me-1"
                        ></i>

                        GST Score Distribution

                    </strong>

                </div>


                <div class="card-body">

                    <?php

                    $scoreData = [
                        [
                            'label' => '80 and above',
                            'value' => $scoreRanges['s80'],
                            'color' => 'bg-success',
                        ],
                        [
                            'label' => '60 – 79.99',
                            'value' => $scoreRanges['s60'],
                            'color' => 'bg-primary',
                        ],
                        [
                            'label' => '40 – 59.99',
                            'value' => $scoreRanges['s40'],
                            'color' => 'bg-warning',
                        ],
                        [
                            'label' => 'Below 40',
                            'value' => $scoreRanges['sBelow40'],
                            'color' => 'bg-danger',
                        ],
                    ];

                    $scoreTotal =
                        max(
                            array_sum(
                                array_column(
                                    $scoreData,
                                    'value'
                                )
                            ),
                            1
                        );

                    ?>


                    <?php foreach (
                        $scoreData as $score
                    ): ?>

                        <?php

                        $percentage =
                            (int) round(
                                (
                                    $score['value']
                                    /
                                    $scoreTotal
                                ) * 100
                            );

                        ?>

                        <div class="mb-3">

                            <div
                                class="d-flex
                                       justify-content-between
                                       mb-1"
                            >

                                <small class="fw-semibold">

                                    <?= e(
                                        $score[
                                            'label'
                                        ]
                                    ) ?>

                                </small>


                                <small class="text-muted">

                                    <?= number_format(
                                        (int)
                                        $score['value']
                                    ) ?>

                                    (<?= $percentage ?>%)

                                </small>

                            </div>


                            <div
                                class="progress"
                                style="height:11px;"
                            >

                                <div
                                    class="progress-bar <?= e($score['color']) ?>"
                                    style="width:<?= $percentage ?>%;"
                                ></div>

                            </div>

                        </div>

                    <?php endforeach; ?>

                </div>

            </div>

        </div>


        <!-- DEMOGRAPHICS -->

        <div class="col-lg-6">


            <div class="card shadow-sm mb-4">

                <div
                    class="card-header
                           bg-primary py-3"
                >

                    <strong>

                        <i
                            class="bi bi-gender-ambiguous text-info me-1"
                        ></i>

                        Gender Breakdown

                    </strong>

                </div>


                <div class="card-body p-0">

                    <?php

                    $genderTotal =
                        max(
                            array_sum(
                                $genderStats
                            ),
                            1
                        );

                    $genderColors = [
                        'Male' => 'bg-primary',
                        'Female' => 'bg-success',
                        'Other' => 'bg-secondary',
                        'Unknown' => 'bg-secondary',
                    ];

                    foreach (
                        $genderStats as
                        $gender => $count
                    ):

                        $percentage =
                            (int) round(
                                (
                                    $count /
                                    $genderTotal
                                ) * 100
                            );

                    ?>

                        <div
                            class="d-flex
                                   align-items-center
                                   justify-content-between
                                   px-3 py-2
                                   border-bottom gap-3"
                        >

                            <span
                                class="small fw-semibold"
                            >
                                <?= e($gender) ?>
                            </span>


                            <div
                                class="d-flex
                                       align-items-center
                                       gap-2"
                                style="min-width:180px;"
                            >

                                <div
                                    class="progress
                                           flex-grow-1"
                                    style="height:8px;"
                                >

                                    <div
                                        class="progress-bar <?= e($genderColors[$gender] ?? 'bg-secondary') ?>"
                                        style="width:<?= $percentage ?>%;"
                                    ></div>

                                </div>


                                <small
                                    class="text-muted"
                                >

                                    <?= number_format(
                                        $count
                                    ) ?>

                                    (<?= $percentage ?>%)

                                </small>

                            </div>

                        </div>

                    <?php endforeach; ?>

                </div>

            </div>


            <div class="card shadow-sm">

                <div
                    class="card-header
                           bg-primary py-3"
                >

                    <strong>

                        <i
                            class="bi bi-tags-fill text-warning me-1"
                        ></i>

                        Quota Breakdown

                    </strong>

                </div>


                <div class="card-body p-0">

                    <?php

                    $quotaTotal =
                        max(
                            array_sum(
                                array_map(
                                    static fn(
                                        array $row
                                    ): int =>
                                        (int)
                                        $row['cnt'],
                                    $quotaStats
                                )
                            ),
                            1
                        );

                    ?>


                    <?php foreach (
                        $quotaStats as $quota
                    ): ?>

                        <?php

                        $count =
                            (int) $quota['cnt'];

                        $percentage =
                            (int) round(
                                (
                                    $count /
                                    $quotaTotal
                                ) * 100
                            );

                        ?>

                        <div
                            class="d-flex
                                   align-items-center
                                   justify-content-between
                                   px-3 py-2
                                   border-bottom gap-3"
                        >

                            <span
                                class="small fw-semibold"
                            >

                                <?= e(
                                    $quota['quota']
                                ) ?>

                            </span>


                            <div
                                class="d-flex
                                       align-items-center
                                       gap-2"
                                style="min-width:180px;"
                            >

                                <div
                                    class="progress
                                           flex-grow-1"
                                    style="height:8px;"
                                >

                                    <div
                                        class="progress-bar bg-warning"
                                        style="width:<?= $percentage ?>%;"
                                    ></div>

                                </div>


                                <small class="text-muted">

                                    <?= number_format(
                                        $count
                                    ) ?>

                                    (<?= $percentage ?>%)

                                </small>

                            </div>

                        </div>

                    <?php endforeach; ?>


                    <?php if (!$quotaStats): ?>

                        <div
                            class="p-3
                                   text-muted small"
                        >
                            No quota data available.
                        </div>

                    <?php endif; ?>

                </div>

            </div>

        </div>

    </div>


    <!-- =========================================================
         FACULTY REPORT
    ========================================================== -->

    <div class="card shadow-sm mb-4">

        <div
            class="card-header bg-dark py-2 fw-semibold"
        >

            <strong>

                <i
                    class="bi bi-diagram-3-fill text-primary me-1"
                ></i>

                Faculty-wise Report

            </strong>

        </div>


        <div class="card-body p-0">

            <div class="table-responsive">

                <table
                    class="table table-sm
                           table-bordered
                           table-hover
                           report-table
                           mb-0 align-middle"
                >

                    <thead class="table-light">

                        <tr>

                            <th>#</th>
                            <th>Faculty</th>

                            <th class="text-center">
                                Candidates
                            </th>

                            <th class="text-center">
                                Viva Selected
                            </th>

                            <th class="text-center">
                                Viva Rejected
                            </th>

                            <th class="text-center">
                                Absent
                            </th>

                            <th class="text-center">
                                Allocated
                            </th>

                            <th class="text-center">
                                Admitted
                            </th>

                            <th class="text-center">
                                Waiting
                            </th>

                            <th class="text-center">
                                Seats
                            </th>

                            <th class="text-center">
                                Fill Rate
                            </th>

                        </tr>

                    </thead>


                    <tbody>

                        <?php foreach (
                            $facultyReport as
                            $index => $row
                        ): ?>

                            <?php

                            $seats =
                                (int)
                                $row['seats'];

                            $admitted =
                                (int)
                                $row['admitted'];

                            $fillRate =
                                $seats > 0
                                    ? min(
                                        100,
                                        (int) round(
                                            (
                                                $admitted /
                                                $seats
                                            ) * 100
                                        )
                                    )
                                    : 0;

                            ?>

                            <tr>

                                <td>
                                    <?= $index + 1 ?>
                                </td>


                                <td
                                    class="fw-semibold"
                                >
                                    <?= e(
                                        $row[
                                            'faculty_name'
                                        ]
                                    ) ?>
                                </td>


                                <td class="text-center">
                                    <?= number_format(
                                        (int)
                                        $row['candidates']
                                    ) ?>
                                </td>


                                <td class="text-center">

                                    <span
                                        class="badge bg-success"
                                    >
                                        <?= (int)
                                            $row['viva_sel']
                                        ?>
                                    </span>

                                </td>


                                <td class="text-center">

                                    <span
                                        class="badge
                                               bg-danger"
                                    >
                                        <?= (int)
                                            $row['viva_no']
                                        ?>
                                    </span>

                                </td>


                                <td class="text-center">

                                    <span
                                        class="badge
                                               bg-secondary"
                                    >
                                        <?= (int)
                                            $row['viva_abs']
                                        ?>
                                    </span>

                                </td>


                                <td class="text-center">

                                    <span
                                        class="badge bg-info text-dark"
                                    >
                                        <?= (int)
                                            $row['allocated']
                                        ?>
                                    </span>

                                </td>


                                <td class="text-center">

                                    <span
                                        class="badge bg-warning text-dark"
                                    >
                                        <?= $admitted ?>
                                    </span>

                                </td>


                                <td class="text-center">

                                    <span
                                        class="badge
                                               bg-secondary"
                                    >
                                        <?= (int)
                                            $row['waiting']
                                        ?>
                                    </span>

                                </td>


                                <td
                                    class="text-center
                                           fw-bold"
                                >
                                    <?= $seats ?>
                                </td>


                                <td
                                    style="min-width:95px;"
                                >

                                    <div
                                        class="progress"
                                        style="height:6px;"
                                    >

                                        <div
                                            class="progress-bar <?= $fillRate >= 80
                                                       ? 'bg-success'
                                                       : ($fillRate >= 40
                                                           ? 'bg-warning'
                                                           : 'bg-danger') ?>"
                                            style="width:<?= $fillRate ?>%;"
                                        ></div>

                                    </div>


                                    <small
                                        class="text-muted"
                                    >
                                        <?= $fillRate ?>%
                                    </small>

                                </td>

                            </tr>

                        <?php endforeach; ?>

                    </tbody>

                </table>

            </div>

        </div>

    </div>


    <!-- =========================================================
         DEPARTMENT REPORT
    ========================================================== -->

    <div class="card shadow-sm mb-4">

        <div
            class="card-header bg-dark py-2 fw-semibold"
        >

            <strong>

                <i
                    class="bi bi-grid-fill text-success me-1"
                ></i>

                Department-wise Report

            </strong>

        </div>


        <div class="card-body p-0">

            <div class="table-responsive">

                <table
                    class="table table-sm
                           table-bordered
                           table-hover
                           report-table
                           mb-0 align-middle"
                >

                    <thead class="table-light">

                        <tr>

                            <th>#</th>
                            <th>Department</th>
                            <th>Faculty</th>

                            <th class="text-center">
                                Seats
                            </th>

                            <th class="text-center">
                                Choices
                            </th>

                            <th class="text-center">
                                Allocated
                            </th>

                            <th class="text-center">
                                Admitted
                            </th>

                            <th class="text-center">
                                Vacated
                            </th>

                            <th class="text-center">
                                Waiting
                            </th>

                            <th class="text-center">
                                Occupancy
                            </th>

                        </tr>

                    </thead>


                    <tbody>

                        <?php foreach (
                            $deptReport as
                            $index => $row
                        ): ?>

                            <?php

                            $seats =
                                (int)
                                $row['total_seats'];

                            $allocated =
                                (int)
                                $row['allocated'];

                            $occupancy =
                                $seats > 0
                                    ? min(
                                        100,
                                        (int) round(
                                            (
                                                $allocated /
                                                $seats
                                            ) * 100
                                        )
                                    )
                                    : 0;

                            ?>

                            <tr>

                                <td>
                                    <?= $index + 1 ?>
                                </td>


                                <td>

                                    <span
                                        class="badge bg-light text-dark border me-1"
                                    >
                                        <?= e(
                                            $row[
                                                'dept_code'
                                            ]
                                        ) ?>
                                    </span>

                                    <span
                                        class="fw-semibold"
                                    >
                                        <?= e(
                                            $row[
                                                'dept_name'
                                            ]
                                        ) ?>
                                    </span>

                                </td>


                                <td>

                                    <small
                                        class="badge
                                               bg-light
                                               text-dark border"
                                    >
                                        <?= e(
                                            $row[
                                                'faculty_name'
                                            ]
                                        ) ?>
                                    </small>

                                </td>


                                <td
                                    class="text-center
                                           fw-bold"
                                >
                                    <?= $seats ?>
                                </td>


                                <td class="text-center">

                                    <span
                                        class="badge bg-info text-dark"
                                    >
                                        <?= (int)
                                            $row['choices']
                                        ?>
                                    </span>

                                </td>


                                <td class="text-center">

                                    <span
                                        class="badge bg-primary"
                                    >
                                        <?= $allocated ?>
                                    </span>

                                </td>


                                <td class="text-center">

                                    <span
                                        class="badge bg-success"
                                    >
                                        <?= (int)
                                            $row['admitted']
                                        ?>
                                    </span>

                                </td>


                                <td class="text-center">

                                    <span
                                        class="badge
                                               bg-danger"
                                    >
                                        <?= (int)
                                            $row['vacated']
                                        ?>
                                    </span>

                                </td>


                                <td class="text-center">

                                    <span
                                        class="badge
                                               bg-secondary"
                                    >
                                        <?= (int)
                                            $row['waiting']
                                        ?>
                                    </span>

                                </td>


                                <td
                                    style="min-width:95px;"
                                >

                                    <div
                                        class="progress"
                                        style="height:6px;"
                                    >

                                        <div
                                            class="progress-bar <?= $occupancy >= 90
                                                       ? 'bg-danger'
                                                       : ($occupancy >= 60
                                                           ? 'bg-warning'
                                                           : 'bg-success') ?>"
                                            style="width:<?= $occupancy ?>%;"
                                        ></div>

                                    </div>


                                    <small
                                        class="text-muted"
                                    >
                                        <?= $occupancy ?>%
                                    </small>

                                </td>

                            </tr>

                        <?php endforeach; ?>

                    </tbody>


                    <tfoot class="table-light fw-bold">

                        <tr>

                            <td
                                colspan="3"
                                class="text-end"
                            >
                                Totals:
                            </td>

                            <td class="text-center">
                                <?= number_format(
                                    $deptTotals['seats']
                                ) ?>
                            </td>

                            <td class="text-center">
                                <?= number_format(
                                    $deptTotals['choices']
                                ) ?>
                            </td>

                            <td class="text-center">
                                <?= number_format(
                                    $deptTotals['allocated']
                                ) ?>
                            </td>

                            <td class="text-center">
                                <?= number_format(
                                    $deptTotals['admitted']
                                ) ?>
                            </td>

                            <td class="text-center">
                                <?= number_format(
                                    $deptTotals['vacated']
                                ) ?>
                            </td>

                            <td class="text-center">
                                <?= number_format(
                                    $deptTotals['waiting']
                                ) ?>
                            </td>

                            <td></td>

                        </tr>

                    </tfoot>

                </table>

            </div>

        </div>

    </div>


    <!-- =========================================================
         RECENT ADMISSIONS
    ========================================================== -->

    <div class="card shadow-sm mb-4">

        <div
            class="card-header bg-dark py-2 fw-semibold"
        >

            <strong>

                <i
                    class="bi bi-clock-history text-warning me-1"
                ></i>

                Recently Admitted Candidates

            </strong>

        </div>


        <div class="card-body p-0">

            <?php if (
                !$recentAdmissions
            ): ?>

                <div
                    class="text-center
                           py-5 text-muted"
                >

                    <i
                        class="bi bi-inbox fs-2 d-block mb-2 text-muted"
                    ></i>

                    No final admissions completed yet.

                </div>


            <?php else: ?>


                <div class="table-responsive">

                    <table
                        class="table table-sm
                               table-bordered
                               report-table
                               mb-0 align-middle"
                    >

                        <thead class="table-light">

                            <tr>

                                <th>#</th>
                                <th>Candidate</th>
                                <th>GST Roll</th>
                                <th>Department</th>
                                <th>Faculty</th>

                                <th class="text-center">
                                    Dept. Rank
                                </th>

                                <th>
                                    Allocation Date
                                </th>

                            </tr>

                        </thead>


                        <tbody>

                            <?php foreach (
                                $recentAdmissions as
                                $index => $row
                            ): ?>

                                <tr>

                                    <td>
                                        <?= $index + 1 ?>
                                    </td>


                                    <td
                                        class="fw-semibold"
                                    >
                                        <?= e(
                                            $row[
                                                'full_name'
                                            ]
                                        ) ?>
                                    </td>


                                    <td>
                                        <?= e(
                                            $row[
                                                'gst_roll'
                                            ]
                                        ) ?>
                                    </td>


                                    <td>

                                        <span
                                            class="badge bg-light text-dark border me-1"
                                        >
                                            <?= e(
                                                $row[
                                                    'dept_code'
                                                ]
                                            ) ?>
                                        </span>

                                        <?= e(
                                            $row[
                                                'dept_name'
                                            ]
                                        ) ?>

                                    </td>


                                    <td>
                                        <?= e(
                                            $row[
                                                'faculty_name'
                                            ]
                                        ) ?>
                                    </td>


                                    <td class="text-center">

                                        <span
                                            class="badge bg-primary"
                                        >
                                            #<?= (int)
                                                $row[
                                                    'merit_rank'
                                                ]
                                            ?>
                                        </span>

                                    </td>


                                    <td>

                                        <small>

                                            <?= !empty(
                                                $row[
                                                    'allocated_at'
                                                ]
                                            )
                                                ? date(
                                                    'd M Y, h:i A',
                                                    strtotime(
                                                        (string)
                                                        $row[
                                                            'allocated_at'
                                                        ]
                                                    )
                                                )
                                                : '—'
                                            ?>

                                        </small>

                                    </td>

                                </tr>

                            <?php endforeach; ?>

                        </tbody>

                    </table>

                </div>

            <?php endif; ?>

        </div>

    </div>


    <!-- =========================================================
         SYSTEM FOOTER STATS
    ========================================================== -->

    <div class="row g-3">

        <?php

        $systemStats = [
            [
                'label' => 'Faculties',
                'value' => $stats['total_faculties'],
                'icon' => 'bi-diagram-3-fill',
                'bg' => 'bg-primary',
            ],
            [
                'label' => 'Departments',
                'value' => $stats['total_depts'],
                'icon' => 'bi-grid-fill',
                'bg' => 'bg-success',
            ],
            [
                'label' => 'Total Seats',
                'value' => $stats['total_seats'],
                'icon' => 'bi-person-fill',
                'bg' => 'bg-warning',
            ],
            [
                'label' => 'Active Admins',
                'value' => $stats['active_admins'],
                'icon' => 'bi-shield-fill-check',
                'bg' => 'bg-info',
            ],
            [
                'label' => 'Pending Admins',
                'value' => $stats['pending_admins'],
                'icon' => 'bi-hourglass-split',
                'bg' => $stats['pending_admins'] > 0
                    ? 'bg-danger'
                    : 'bg-secondary',
            ],
            [
                'label' => 'Waiting Called',
                'value' => $stats['waiting_called'],
                'icon' => 'bi-bell-fill',
                'bg' => 'bg-warning',
            ],
        ];

        ?>


        <?php foreach (
            $systemStats as $item
        ): ?>

            <div
                class="col-6
                       col-md-4
                       col-xl-2"
            >

                <div
                    class="card shadow-sm
                           text-center
                           h-100 py-3"
                >

                    <div
                        class="rounded-circle d-flex align-items-center
                               justify-content-center mx-auto mb-2
                               <?= e($item['bg']) ?> text-white"
                        style="
                            width:46px;
                            height:46px;
                        "
                    >

                        <i
                            class="bi <?= e(
                                $item['icon']
                            ) ?> fs-5"
                        ></i>

                    </div>


                    <div
                        class="fw-bold fs-5"
                    >

                        <?= number_format(
                            (int)
                            $item['value']
                        ) ?>

                    </div>


                    <small class="text-muted">

                        <?= e(
                            $item['label']
                        ) ?>

                    </small>

                </div>

            </div>

        <?php endforeach; ?>

    </div>

</div>


<?php require_once '../includes/footer.php'; ?>
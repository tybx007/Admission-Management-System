<?php

declare(strict_types=1);

$pageTitle = 'Waiting List';

require_once '../includes/auth.php';
require_once '../config/db.php';

/*
|--------------------------------------------------------------------------
| Authentication before HTML output
|--------------------------------------------------------------------------
*/

requireLogin('registrar');

if (
    !isset($_SESSION['user_id']) ||
    ($_SESSION['role'] ?? '') !== 'registrar'
) {
    header('Location: /jkkniu-admission/registrar/login.php');
    exit;
}

$pdo->setAttribute(
    PDO::ATTR_ERRMODE,
    PDO::ERRMODE_EXCEPTION
);

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(
        random_bytes(32)
    );
}


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
| Handle actions
|--------------------------------------------------------------------------
*/

$message = '';
$messageType = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    try {

        $csrfToken = (string) (
            $_POST['csrf_token'] ?? ''
        );

        if (
            $csrfToken === '' ||
            !hash_equals(
                (string) $_SESSION['csrf_token'],
                $csrfToken
            )
        ) {
            throw new RuntimeException(
                'Invalid request token.'
            );
        }


        /*
        |--------------------------------------------------------------------------
        | CALL WAITING CANDIDATE
        |--------------------------------------------------------------------------
        |
        | Only a candidate who is currently Waiting may be called.
        |--------------------------------------------------------------------------
        */

        if (isset($_POST['call_waiting'])) {

            $waitingId = (int) (
                $_POST['waiting_id'] ?? 0
            );

            if ($waitingId <= 0) {
                throw new RuntimeException(
                    'Invalid waiting-list record.'
                );
            }

            $stmt = $pdo->prepare("
                UPDATE waiting_list
                SET
                    waiting_status = 'Called',
                    called_at = NOW()
                WHERE waiting_id = ?
                  AND waiting_status = 'Waiting'
            ");

            $stmt->execute([
                $waitingId
            ]);

            if ($stmt->rowCount() !== 1) {
                throw new RuntimeException(
                    'Candidate is no longer in Waiting status.'
                );
            }

            $message =
                'Waiting candidate called successfully.';
        }


        /*
        |--------------------------------------------------------------------------
        | MARK CALLED RECORD EXPIRED
        |--------------------------------------------------------------------------
        */

        elseif (isset($_POST['expire_waiting'])) {

            $waitingId = (int) (
                $_POST['waiting_id'] ?? 0
            );

            if ($waitingId <= 0) {
                throw new RuntimeException(
                    'Invalid waiting-list record.'
                );
            }

            $stmt = $pdo->prepare("
                UPDATE waiting_list
                SET waiting_status = 'Expired'
                WHERE waiting_id = ?
                  AND waiting_status = 'Called'
            ");

            $stmt->execute([
                $waitingId
            ]);

            if ($stmt->rowCount() !== 1) {
                throw new RuntimeException(
                    'Only a called candidate can be expired.'
                );
            }

            $message =
                'Waiting call expired successfully.';
        }


        /*
        |--------------------------------------------------------------------------
        | VACATE ALLOCATION
        |--------------------------------------------------------------------------
        |
        | Keep existing project behavior here.
        | If you have a DB trigger / admission-engine function that calls the
        | next waiting candidate, it can run after this update.
        |--------------------------------------------------------------------------
        */

        elseif (isset($_POST['vacate_seat'])) {

            $allocationId = (int) (
                $_POST['allocation_id'] ?? 0
            );

            if ($allocationId <= 0) {
                throw new RuntimeException(
                    'Invalid merit allocation.'
                );
            }

            $stmt = $pdo->prepare("
                UPDATE merit_allocations
                SET allocation_status = 'Vacated'
                WHERE allocation_id = ?
                  AND allocation_status = 'Allocated'
            ");

            $stmt->execute([
                $allocationId
            ]);

            if ($stmt->rowCount() !== 1) {
                throw new RuntimeException(
                    'Only an Allocated seat can be vacated from this page.'
                );
            }

            $message =
                'Seat vacated successfully.';
        }

        else {
            throw new RuntimeException(
                'Invalid waiting-list action.'
            );
        }


    } catch (Throwable $exception) {

        $message =
            $exception->getMessage();

        $messageType =
            'danger';
    }
}


/*
|--------------------------------------------------------------------------
| Department statistics
|--------------------------------------------------------------------------
|
| IMPORTANT:
| Do NOT join merit_allocations and waiting_list directly in one large query.
| That multiplies rows and produces incorrect counts.
|--------------------------------------------------------------------------
*/

$depts = $pdo->query("
    SELECT
        d.dept_id,
        d.dept_name,
        d.total_seats,

        COALESCE(
            allocation_stats.admitted_count,
            0
        ) AS admitted_count,

        COALESCE(
            waiting_stats.waiting_count,
            0
        ) AS waiting_count

    FROM departments d


    LEFT JOIN (
        SELECT
            dept_id,

            COUNT(
                DISTINCT import_candidate_id
            ) AS admitted_count

        FROM merit_allocations

        WHERE allocation_status = 'Admitted'

        GROUP BY dept_id
    ) allocation_stats
        ON allocation_stats.dept_id =
           d.dept_id


    LEFT JOIN (
        SELECT
            wl.dept_id,

            COUNT(
                DISTINCT wl.import_candidate_id
            ) AS waiting_count

        FROM waiting_list wl

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

        GROUP BY wl.dept_id
    ) waiting_stats
        ON waiting_stats.dept_id =
           d.dept_id


    ORDER BY d.dept_name
")->fetchAll(PDO::FETCH_ASSOC);


/*
|--------------------------------------------------------------------------
| Canonical waiting list
|--------------------------------------------------------------------------
|
| RULE:
| A candidate appears only ONCE in the waiting list.
|
| If old data contains waiting rows for Choice 1, Choice 2, Choice 3, etc.,
| we show only the row that belongs to the candidate's FIRST preference.
|
| Candidate details come from gst_imported_candidates because waiting-list
| candidates may not have registered yet.
|--------------------------------------------------------------------------
*/

$waitingList = $pdo->query("
    SELECT
        wl.waiting_id,
        wl.import_candidate_id,
        wl.applicant_id,
        wl.dept_id,
        wl.waiting_rank,
        wl.waiting_status,
        wl.called_at,

        gic.full_name,
        gic.gst_roll,
        gic.gst_score,
        gic.merit_position,

        d.dept_name,
        d.dept_code

    FROM waiting_list wl

    INNER JOIN gst_imported_candidates gic
        ON gic.import_candidate_id =
           wl.import_candidate_id

    INNER JOIN departments d
        ON d.dept_id =
           wl.dept_id

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


      /*
      | If duplicate rows exist even for the same candidate + first-choice
      | department, keep only the oldest waiting row.
      */
      AND wl.waiting_id = (
          SELECT MIN(wl2.waiting_id)
          FROM waiting_list wl2
          WHERE wl2.import_candidate_id =
                wl.import_candidate_id
            AND wl2.dept_id =
                wl.dept_id
      )


    ORDER BY
        wl.dept_id,
        wl.waiting_rank,
        gic.merit_position,
        gic.gst_score DESC
")->fetchAll(PDO::FETCH_ASSOC);


/*
|--------------------------------------------------------------------------
| Current allocations
|--------------------------------------------------------------------------
|
| Use import_candidate_id instead of the old applicant_id dependency.
|--------------------------------------------------------------------------
*/

$allocations = $pdo->query("
    SELECT
        ma.allocation_id,
        ma.import_candidate_id,
        ma.allocation_status,
        ma.merit_rank,

        gic.full_name,
        gic.gst_roll,

        d.dept_name,
        d.dept_code

    FROM merit_allocations ma

    INNER JOIN gst_imported_candidates gic
        ON gic.import_candidate_id =
           ma.import_candidate_id

    INNER JOIN departments d
        ON d.dept_id =
           ma.dept_id

    WHERE ma.allocation_status IN (
        'Allocated',
        'Admitted'
    )

    ORDER BY
        ma.dept_id,
        ma.merit_rank
")->fetchAll(PDO::FETCH_ASSOC);


/*
|--------------------------------------------------------------------------
| Load layout
|--------------------------------------------------------------------------
*/

require_once '../includes/header.php';
require_once '../includes/navbar.php';

?>

<div class="container py-4">

    <div
        class="d-flex justify-content-between
               align-items-center mb-4"
    >

        <h2>

            <i
                class="bi bi-list-nested
                       text-info me-2"
            ></i>

            Waiting List Management

        </h2>

    </div>


    <?php if ($message !== ''): ?>

        <div
            class="alert alert-<?= e($messageType) ?>
                   alert-dismissible fade show"
            role="alert"
        >

            <?= e($message) ?>

            <button
                type="button"
                class="btn-close"
                data-bs-dismiss="alert"
            ></button>

        </div>

    <?php endif; ?>


    <!-- =========================================================
         DEPARTMENT OCCUPANCY
    ========================================================== -->

    <div class="row g-4 mb-5">

        <?php foreach ($depts as $dept): ?>

            <?php

            $totalSeats =
                max(
                    1,
                    (int)
                    $dept['total_seats']
                );

            $admitted =
                (int)
                $dept['admitted_count'];

            $occupancy =
                min(
                    100,
                    (
                        $admitted /
                        $totalSeats
                    ) * 100
                );

            ?>

            <div
                class="col-xl-4 col-md-6"
            >

                <div
                    class="card shadow-sm h-100"
                >

                    <div class="card-body">

                        <div
                            class="d-flex justify-content-between
                                   align-items-start mb-3"
                        >

                            <h6
                                class="card-title
                                       mb-1 fw-bold"
                            >
                                <?= e(
                                    $dept['dept_name']
                                ) ?>
                            </h6>


                            <span
                                class="badge bg-info"
                            >

                                <?= $admitted ?>
                                /
                                <?= (int)
                                    $dept['total_seats']
                                ?>

                            </span>

                        </div>


                        <div
                            class="progress mb-3"
                            style="height:8px;"
                        >

                            <div
                                class="progress-bar bg-success"
                                style="width:<?= $occupancy ?>%;"
                            ></div>

                        </div>


                        <div class="row text-center">

                            <div class="col-6">

                                <div
                                    class="fw-bold fs-5
                                           text-success"
                                >
                                    <?= $admitted ?>
                                </div>

                                <small class="text-muted">
                                    Admitted
                                </small>

                            </div>


                            <div class="col-6">

                                <div
                                    class="fw-bold fs-5
                                           text-warning"
                                >
                                    <?= (int)
                                        $dept[
                                            'waiting_count'
                                        ]
                                    ?>
                                </div>

                                <small class="text-muted">
                                    Waiting
                                </small>

                            </div>

                        </div>

                    </div>

                </div>

            </div>

        <?php endforeach; ?>

    </div>


    <div class="row g-4">


        <!-- =====================================================
             WAITING LIST
        ====================================================== -->

        <div class="col-lg-7">

            <div class="card shadow-sm">

                <div
                    class="card-header
                           bg-warning text-dark"
                >

                    <h6 class="mb-0">

                        <i
                            class="bi bi-clock-history
                                   me-2"
                        ></i>

                        Active Waiting List

                    </h6>

                </div>


                <div class="table-responsive">

                    <table
                        class="table table-hover mb-0"
                    >

                        <thead class="table-light">

                            <tr>

                                <th>Rank</th>
                                <th>GST Roll</th>
                                <th>Student</th>
                                <th>Department</th>
                                <th>GST Score</th>
                                <th>Status</th>
                                <th>Actions</th>

                            </tr>

                        </thead>


                        <tbody>

                            <?php foreach (
                                $waitingList as $wl
                            ): ?>

                                <tr
                                    class="<?= $wl[
                                        'waiting_status'
                                    ] === 'Called'
                                        ? 'table-warning'
                                        : ''
                                    ?>"
                                >

                                    <td>

                                        <span
                                            class="badge bg-secondary"
                                        >
                                            #<?= (int)
                                                $wl[
                                                    'waiting_rank'
                                                ]
                                            ?>
                                        </span>

                                    </td>


                                    <td>

                                        <strong>
                                            <?= e(
                                                $wl[
                                                    'gst_roll'
                                                ]
                                            ) ?>
                                        </strong>

                                    </td>


                                    <td>
                                        <?= e(
                                            $wl[
                                                'full_name'
                                            ]
                                        ) ?>
                                    </td>


                                    <td>

                                        <span
                                            class="badge bg-primary"
                                        >

                                            <?= e(
                                                $wl[
                                                    'dept_code'
                                                ]
                                                    ?: $wl[
                                                        'dept_name'
                                                    ]
                                            ) ?>

                                        </span>

                                    </td>


                                    <td>

                                        <?= number_format(
                                            (float)
                                            $wl[
                                                'gst_score'
                                            ],
                                            2
                                        ) ?>

                                    </td>


                                    <td>

                                        <?php

                                        $statusClass =
                                            $wl[
                                                'waiting_status'
                                            ] === 'Waiting'
                                                ? 'secondary'
                                                : (
                                                    $wl[
                                                        'waiting_status'
                                                    ] === 'Called'
                                                        ? 'warning text-dark'
                                                        : (
                                                            $wl[
                                                                'waiting_status'
                                                            ] === 'Admitted'
                                                                ? 'success'
                                                                : 'danger'
                                                        )
                                                );

                                        ?>

                                        <span
                                            class="badge bg-<?= $statusClass ?>"
                                        >

                                            <?= e(
                                                ucwords(
                                                    str_replace(
                                                        '_',
                                                        ' ',
                                                        (string)
                                                        $wl[
                                                            'waiting_status'
                                                        ]
                                                    )
                                                )
                                            ) ?>

                                        </span>

                                    </td>


                                    <td>

                                        <div
                                            class="btn-group
                                                   btn-group-sm"
                                            role="group"
                                        >


                                            <?php if (
                                                $wl[
                                                    'waiting_status'
                                                ] === 'Waiting'
                                            ): ?>

                                                <form
                                                    method="POST"
                                                    class="d-inline"
                                                >

                                                    <input
                                                        type="hidden"
                                                        name="csrf_token"
                                                        value="<?= e(
                                                            $_SESSION[
                                                                'csrf_token'
                                                            ]
                                                        ) ?>"
                                                    >

                                                    <input
                                                        type="hidden"
                                                        name="waiting_id"
                                                        value="<?= (int)
                                                            $wl[
                                                                'waiting_id'
                                                            ]
                                                        ?>"
                                                    >

                                                    <input
                                                        type="hidden"
                                                        name="call_waiting"
                                                        value="1"
                                                    >


                                                    <button
                                                        type="submit"
                                                        class="btn
                                                               btn-outline-warning"
                                                        title="Call this candidate"
                                                    >

                                                        <i
                                                            class="bi bi-telephone"
                                                        ></i>

                                                    </button>

                                                </form>

                                            <?php endif; ?>


                                            <?php if (
                                                $wl[
                                                    'waiting_status'
                                                ] === 'Called'
                                            ): ?>

                                                <form
                                                    method="POST"
                                                    class="d-inline"
                                                >

                                                    <input
                                                        type="hidden"
                                                        name="csrf_token"
                                                        value="<?= e(
                                                            $_SESSION[
                                                                'csrf_token'
                                                            ]
                                                        ) ?>"
                                                    >

                                                    <input
                                                        type="hidden"
                                                        name="waiting_id"
                                                        value="<?= (int)
                                                            $wl[
                                                                'waiting_id'
                                                            ]
                                                        ?>"
                                                    >

                                                    <input
                                                        type="hidden"
                                                        name="expire_waiting"
                                                        value="1"
                                                    >


                                                    <button
                                                        type="submit"
                                                        class="btn
                                                               btn-outline-danger"
                                                        title="Mark expired"
                                                        onclick="
                                                            return confirm(
                                                                'Expire this waiting call?'
                                                            );
                                                        "
                                                    >

                                                        <i
                                                            class="bi bi-x-lg"
                                                        ></i>

                                                    </button>

                                                </form>

                                            <?php endif; ?>

                                        </div>

                                    </td>

                                </tr>

                            <?php endforeach; ?>


                            <?php if (
                                !$waitingList
                            ): ?>

                                <tr>

                                    <td
                                        colspan="7"
                                        class="text-center
                                               py-4 text-muted"
                                    >
                                        No waiting candidates
                                    </td>

                                </tr>

                            <?php endif; ?>

                        </tbody>

                    </table>

                </div>

            </div>

        </div>


        <!-- =====================================================
             CURRENT ALLOCATIONS
        ====================================================== -->

        <div class="col-lg-5">

            <div class="card shadow-sm">

                <div
                    class="card-header
                           bg-info text-white"
                >

                    <h6 class="mb-0">

                        <i
                            class="bi bi-award me-2"
                        ></i>

                        Current Allocations

                    </h6>

                    <span
                        class="badge
                               bg-light text-dark
                               ms-2"
                    >
                        Vacate to trigger waiting list
                    </span>

                </div>


                <div class="table-responsive">

                    <table
                        class="table table-sm
                               table-hover mb-0"
                    >

                        <thead class="table-light">

                            <tr>

                                <th>Rank</th>
                                <th>GST Roll</th>
                                <th>Department</th>
                                <th>Status</th>
                                <th></th>

                            </tr>

                        </thead>


                        <tbody>

                            <?php foreach (
                                $allocations as $alloc
                            ): ?>

                                <tr>

                                    <td>
                                        #<?= (int)
                                            $alloc[
                                                'merit_rank'
                                            ]
                                        ?>
                                    </td>


                                    <td>
                                        <?= e(
                                            $alloc[
                                                'gst_roll'
                                            ]
                                        ) ?>
                                    </td>


                                    <td>
                                        <?= e(
                                            $alloc[
                                                'dept_code'
                                            ]
                                                ?: $alloc[
                                                    'dept_name'
                                                ]
                                        ) ?>
                                    </td>


                                    <td>

                                        <span
                                            class="badge bg-<?= $alloc[
                                                'allocation_status'
                                            ] === 'Admitted'
                                                ? 'success'
                                                : 'warning text-dark'
                                            ?>"
                                        >

                                            <?= e(
                                                ucwords(
                                                    str_replace(
                                                        '_',
                                                        ' ',
                                                        (string)
                                                        $alloc[
                                                            'allocation_status'
                                                        ]
                                                    )
                                                )
                                            ) ?>

                                        </span>

                                    </td>


                                    <td>

                                        <?php if (
                                            $alloc[
                                                'allocation_status'
                                            ] === 'Allocated'
                                        ): ?>

                                            <form
                                                method="POST"
                                                class="d-inline"
                                            >

                                                <input
                                                    type="hidden"
                                                    name="csrf_token"
                                                    value="<?= e(
                                                        $_SESSION[
                                                            'csrf_token'
                                                        ]
                                                    ) ?>"
                                                >

                                                <input
                                                    type="hidden"
                                                    name="allocation_id"
                                                    value="<?= (int)
                                                        $alloc[
                                                            'allocation_id'
                                                        ]
                                                    ?>"
                                                >

                                                <input
                                                    type="hidden"
                                                    name="vacate_seat"
                                                    value="1"
                                                >


                                                <button
                                                    type="submit"
                                                    class="btn
                                                           btn-sm
                                                           btn-outline-danger"
                                                    onclick="
                                                        return confirm(
                                                            'Vacate this seat?'
                                                        );
                                                    "
                                                    title="Vacate seat"
                                                >

                                                    <i
                                                        class="bi bi-chair"
                                                    ></i>

                                                </button>

                                            </form>

                                        <?php endif; ?>

                                    </td>

                                </tr>

                            <?php endforeach; ?>


                            <?php if (
                                !$allocations
                            ): ?>

                                <tr>

                                    <td
                                        colspan="5"
                                        class="text-center
                                               py-4 text-muted"
                                    >
                                        No current allocations
                                    </td>

                                </tr>

                            <?php endif; ?>

                        </tbody>

                    </table>

                </div>

            </div>

        </div>

    </div>

</div>


<?php require_once '../includes/footer.php'; ?>
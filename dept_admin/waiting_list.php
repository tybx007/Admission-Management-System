<?php

declare(strict_types=1);

$pageTitle = 'Waiting List Management';

require_once '../includes/auth.php';
require_once '../config/db.php';
require_once '../includes/mail/send_mail.php';

/*
|--------------------------------------------------------------------------
| Authentication BEFORE HTML output
|--------------------------------------------------------------------------
*/

requireLogin('dept_admin');

if (
    !isset($_SESSION['user_id']) ||
    ($_SESSION['role'] ?? '') !== 'dept_admin'
) {
    header('Location: /jkkniu-admission/dept_admin/login.php');
    exit;
}

$deptId = (int) ($_SESSION['dept_id'] ?? 0);

if ($deptId <= 0) {
    http_response_code(403);
    exit(
        'Department information is missing from the session. Please log in again.'
    );
}

$pdo->setAttribute(
    PDO::ATTR_ERRMODE,
    PDO::ERRMODE_EXCEPTION
);


/*
|--------------------------------------------------------------------------
| CSRF token
|--------------------------------------------------------------------------
*/

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
| Flash
|--------------------------------------------------------------------------
*/

$message = '';
$alertType = 'info';

if (!empty($_SESSION['waiting_flash'])) {

    $message = (string) (
        $_SESSION['waiting_flash']['message']
        ?? ''
    );

    $alertType = (string) (
        $_SESSION['waiting_flash']['type']
        ?? 'info'
    );

    unset($_SESSION['waiting_flash']);
}


/*
|--------------------------------------------------------------------------
| Process waiting-list action
|--------------------------------------------------------------------------
|
| Current architecture:
|
| waiting_list.import_candidate_id
|           ↓
| gst_imported_candidates
|           ↓
| merit_allocations.import_candidate_id
|
| applicant_id may still be NULL until the called candidate registers.
|--------------------------------------------------------------------------
*/

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
                'Invalid security token. Please try again.'
            );
        }


        $waitingId = (int) (
            $_POST['waiting_id'] ?? 0
        );

        $action = trim(
            (string) ($_POST['action'] ?? '')
        );


        if (
            $waitingId <= 0 ||
            !in_array(
                $action,
                [
                    'call',
                    'expire',
                    'restore'
                ],
                true
            )
        ) {
            throw new RuntimeException(
                'Invalid waiting-list action.'
            );
        }


        $pdo->beginTransaction();


        /*
        |--------------------------------------------------------------------------
        | Lock selected canonical waiting candidate
        |--------------------------------------------------------------------------
        |
        | A candidate is valid on THIS department waiting list only when this
        | department is the candidate's first preference.
        |--------------------------------------------------------------------------
        */

        $stmt = $pdo->prepare("
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
                gic.quota,
                gic.phone AS imported_phone,
                gic.applicant_id AS linked_applicant_id,

                ga.email,
                ga.phone AS applicant_phone,

                d.dept_name,
                d.total_seats

            FROM waiting_list wl

            INNER JOIN gst_imported_candidates gic
                ON gic.import_candidate_id =
                   wl.import_candidate_id

            LEFT JOIN gst_applicants ga
                ON ga.applicant_id =
                   gic.applicant_id

            INNER JOIN departments d
                ON d.dept_id =
                   wl.dept_id

            INNER JOIN gst_candidate_choices gcc
                ON gcc.import_candidate_id =
                   wl.import_candidate_id
               AND gcc.dept_id =
                   wl.dept_id

            WHERE wl.waiting_id = ?
              AND wl.dept_id = ?

              AND gcc.preference_order = (
                  SELECT MIN(gcc2.preference_order)
                  FROM gst_candidate_choices gcc2
                  WHERE gcc2.import_candidate_id =
                        wl.import_candidate_id
              )

              AND wl.waiting_id = (
                  SELECT MIN(wl2.waiting_id)
                  FROM waiting_list wl2
                  WHERE wl2.import_candidate_id =
                        wl.import_candidate_id
                    AND wl2.dept_id =
                        wl.dept_id
              )

            LIMIT 1
            FOR UPDATE
        ");

        $stmt->execute([
            $waitingId,
            $deptId
        ]);

        $candidate = $stmt->fetch(
            PDO::FETCH_ASSOC
        );


        if (!$candidate) {
            throw new RuntimeException(
                'Waiting candidate not found, is a duplicate choice row, or does not belong to your department.'
            );
        }


        /*
        |--------------------------------------------------------------------------
        | CALL NEXT WAITING CANDIDATE
        |--------------------------------------------------------------------------
        */

        if ($action === 'call') {

            if (
                $candidate['waiting_status']
                !== 'Waiting'
            ) {
                throw new RuntimeException(
                    'Only candidates with Waiting status can be called.'
                );
            }


            /*
            |--------------------------------------------------------------------------
            | Enforce rank order
            |--------------------------------------------------------------------------
            |
            | Department admin cannot skip a higher-ranked Waiting candidate.
            |--------------------------------------------------------------------------
            */

            $nextStmt = $pdo->prepare("
                SELECT wl.waiting_id

                FROM waiting_list wl

                INNER JOIN gst_candidate_choices gcc
                    ON gcc.import_candidate_id =
                       wl.import_candidate_id
                   AND gcc.dept_id =
                       wl.dept_id

                WHERE wl.dept_id = ?
                  AND wl.waiting_status = 'Waiting'

                  AND gcc.preference_order = (
                      SELECT MIN(gcc2.preference_order)
                      FROM gst_candidate_choices gcc2
                      WHERE gcc2.import_candidate_id =
                            wl.import_candidate_id
                  )

                  AND wl.waiting_id = (
                      SELECT MIN(wl2.waiting_id)
                      FROM waiting_list wl2
                      WHERE wl2.import_candidate_id =
                            wl.import_candidate_id
                        AND wl2.dept_id =
                            wl.dept_id
                  )

                ORDER BY
                    wl.waiting_rank ASC,
                    wl.waiting_id ASC

                LIMIT 1
                FOR UPDATE
            ");

            $nextStmt->execute([
                $deptId
            ]);

            $nextWaitingId =
                (int) (
                    $nextStmt->fetchColumn()
                    ?: 0
                );


            if ($nextWaitingId <= 0) {
                throw new RuntimeException(
                    'No eligible waiting candidate is available.'
                );
            }


            if ($nextWaitingId !== $waitingId) {
                throw new RuntimeException(
                    'Candidates must be called in waiting-rank order. Call the next ranked candidate first.'
                );
            }


            /*
            |--------------------------------------------------------------------------
            | Check department seat availability
            |--------------------------------------------------------------------------
            */

            $stmt = $pdo->prepare("
                SELECT COUNT(
                    DISTINCT import_candidate_id
                )
                FROM merit_allocations
                WHERE dept_id = ?
                  AND allocation_status IN (
                      'Allocated',
                      'Admitted'
                  )
            ");

            $stmt->execute([
                $deptId
            ]);

            $occupiedSeats =
                (int) $stmt->fetchColumn();

            $totalSeats =
                (int) $candidate['total_seats'];


            if ($occupiedSeats >= $totalSeats) {
                throw new RuntimeException(
                    'No vacant seat is currently available. Vacate a merit allocation first.'
                );
            }


            /*
            |--------------------------------------------------------------------------
            | Candidate must not already have an active allocation anywhere
            |--------------------------------------------------------------------------
            */

            $activeAllocationStmt =
                $pdo->prepare("
                    SELECT
                        ma.allocation_id,
                        d.dept_name
                    FROM merit_allocations ma
                    INNER JOIN departments d
                        ON d.dept_id =
                           ma.dept_id
                    WHERE ma.import_candidate_id = ?
                      AND ma.allocation_status IN (
                          'Allocated',
                          'Admitted'
                      )
                    ORDER BY ma.allocation_id DESC
                    LIMIT 1
                ");

            $activeAllocationStmt->execute([
                (int)
                $candidate[
                    'import_candidate_id'
                ]
            ]);

            $activeAllocation =
                $activeAllocationStmt->fetch(
                    PDO::FETCH_ASSOC
                );


            if ($activeAllocation) {
                throw new RuntimeException(
                    'This candidate already has an active allocation in ' .
                    $activeAllocation['dept_name'] .
                    '.'
                );
            }


            /*
            |--------------------------------------------------------------------------
            | Generate next departmental merit rank
            |--------------------------------------------------------------------------
            */

            $stmt = $pdo->prepare("
                SELECT
                    COALESCE(
                        MAX(merit_rank),
                        0
                    ) + 1
                FROM merit_allocations
                WHERE dept_id = ?
            ");

            $stmt->execute([
                $deptId
            ]);

            $nextMeritRank =
                (int) $stmt->fetchColumn();


            /*
            |--------------------------------------------------------------------------
            | Create new provisional merit allocation
            |--------------------------------------------------------------------------
            |
            | applicant_id is allowed to stay NULL before registration.
            |--------------------------------------------------------------------------
            */

            $linkedApplicantId =
                !empty(
                    $candidate[
                        'linked_applicant_id'
                    ]
                )
                    ? (int)
                      $candidate[
                          'linked_applicant_id'
                      ]
                    : null;


            $stmt = $pdo->prepare("
                INSERT INTO merit_allocations (
                    import_candidate_id,
                    applicant_id,
                    dept_id,
                    merit_rank,
                    allocation_status,
                    allocated_at
                )
                VALUES (
                    ?,
                    ?,
                    ?,
                    ?,
                    'Allocated',
                    NOW()
                )
            ");

            $stmt->execute([
                (int)
                $candidate[
                    'import_candidate_id'
                ],
                $linkedApplicantId,
                $deptId,
                $nextMeritRank
            ]);


            /*
            |--------------------------------------------------------------------------
            | Mark waiting candidate Called
            |--------------------------------------------------------------------------
            */

            $stmt = $pdo->prepare("
                UPDATE waiting_list
                SET
                    waiting_status = 'Called',
                    called_at = NOW()
                WHERE waiting_id = ?
                  AND dept_id = ?
                  AND waiting_status = 'Waiting'
            ");

            $stmt->execute([
                $waitingId,
                $deptId
            ]);


            if ($stmt->rowCount() !== 1) {
                throw new RuntimeException(
                    'The waiting status could not be updated.'
                );
            }


            /*
            |--------------------------------------------------------------------------
            | Do NOT create admission_progress here
            |--------------------------------------------------------------------------
            |
            | A waiting candidate may still be unregistered. The applicant-side
            | admission progress starts after registration / admission workflow.
            |--------------------------------------------------------------------------
            */

            $pdo->commit();


            /*
            |--------------------------------------------------------------------------
            | Email after commit, only if a registered email exists
            |--------------------------------------------------------------------------
            */

            $emailSent = false;

            if (
                !empty($candidate['email']) &&
                filter_var(
                    $candidate['email'],
                    FILTER_VALIDATE_EMAIL
                )
            ) {

                $subject =
                    'JKKNIU Waiting List Selection Notification';

                $body = "
                    <div style=\"font-family:Arial,sans-serif;
                                max-width:650px;
                                margin:auto;
                                border:1px solid #dddddd;
                                border-radius:10px;
                                overflow:hidden;\">

                        <div style=\"background:#1a3c5e;
                                    color:#ffffff;
                                    padding:22px;
                                    text-align:center;\">

                            <h2 style=\"margin:0;\">
                                JKKNIU Admission Management System
                            </h2>
                        </div>

                        <div style=\"padding:25px;\">

                            <p>
                                Dear <strong>" .
                                e($candidate['full_name']) .
                                "</strong>,
                            </p>

                            <p>
                                Congratulations! A seat has become available
                                and you have been called from the waiting list.
                            </p>

                            <div style=\"background:#f5f7fa;
                                        border-left:4px solid #c8a951;
                                        padding:15px;
                                        margin:18px 0;\">

                                <p style=\"margin:5px 0;\">
                                    <strong>Department:</strong>
                                    " .
                                    e($candidate['dept_name']) .
                                    "
                                </p>

                                <p style=\"margin:5px 0;\">
                                    <strong>GST Roll:</strong>
                                    " .
                                    e($candidate['gst_roll']) .
                                    "
                                </p>

                                <p style=\"margin:5px 0;\">
                                    <strong>Waiting Rank:</strong>
                                    #" .
                                    (int)
                                    $candidate[
                                        'waiting_rank'
                                    ] .
                                    "
                                </p>

                            </div>

                            <p>
                                Please complete the required admission process
                                within the deadline announced by the university.
                            </p>

                            <p style=\"margin-top:25px;\">
                                Regards,<br>
                                <strong>
                                    JKKNIU Admission Authority
                                </strong>
                            </p>

                        </div>
                    </div>
                ";


                $emailSent = sendMail(
                    (string)
                    $candidate['email'],
                    $subject,
                    $body
                );
            }


            $_SESSION['waiting_flash'] = [
                'type' =>
                    $emailSent
                        ? 'success'
                        : 'warning',

                'message' =>
                    $emailSent
                        ? 'Candidate called successfully, provisional allocation created, and email sent.'
                        : (
                            !empty(
                                $candidate['email']
                            )
                                ? 'Candidate called successfully and provisional allocation created, but email could not be sent.'
                                : 'Candidate called successfully and provisional allocation created. No registered email is available yet.'
                        )
            ];
        }


        /*
        |--------------------------------------------------------------------------
        | EXPIRE
        |--------------------------------------------------------------------------
        */

        elseif ($action === 'expire') {

            if (
                !in_array(
                    $candidate[
                        'waiting_status'
                    ],
                    [
                        'Waiting',
                        'Called'
                    ],
                    true
                )
            ) {
                throw new RuntimeException(
                    'This candidate cannot be marked as expired.'
                );
            }


            /*
            | If Called, vacate the candidate's active provisional allocation.
            */

            if (
                $candidate['waiting_status']
                === 'Called'
            ) {

                $stmt = $pdo->prepare("
                    UPDATE merit_allocations
                    SET allocation_status = 'Vacated'
                    WHERE import_candidate_id = ?
                      AND dept_id = ?
                      AND allocation_status = 'Allocated'
                ");

                $stmt->execute([
                    (int)
                    $candidate[
                        'import_candidate_id'
                    ],
                    $deptId
                ]);
            }


            $stmt = $pdo->prepare("
                UPDATE waiting_list
                SET waiting_status = 'Expired'
                WHERE waiting_id = ?
                  AND dept_id = ?
                  AND waiting_status IN (
                      'Waiting',
                      'Called'
                  )
            ");

            $stmt->execute([
                $waitingId,
                $deptId
            ]);


            if ($stmt->rowCount() !== 1) {
                throw new RuntimeException(
                    'The waiting record could not be expired.'
                );
            }


            $pdo->commit();


            $_SESSION['waiting_flash'] = [
                'type' =>
                    'warning',

                'message' =>
                    'Candidate marked as expired successfully.'
            ];
        }


        /*
        |--------------------------------------------------------------------------
        | RESTORE
        |--------------------------------------------------------------------------
        */

        elseif ($action === 'restore') {

            if (
                $candidate[
                    'waiting_status'
                ] !== 'Expired'
            ) {
                throw new RuntimeException(
                    'Only expired candidates can be restored.'
                );
            }


            /*
            | Candidate cannot be restored while actively allocated elsewhere.
            */

            $stmt = $pdo->prepare("
                SELECT COUNT(*)
                FROM merit_allocations
                WHERE import_candidate_id = ?
                  AND allocation_status IN (
                      'Allocated',
                      'Admitted'
                  )
            ");

            $stmt->execute([
                (int)
                $candidate[
                    'import_candidate_id'
                ]
            ]);


            if (
                (int)
                $stmt->fetchColumn() > 0
            ) {
                throw new RuntimeException(
                    'This candidate already has an active allocation and cannot be restored to Waiting.'
                );
            }


            $stmt = $pdo->prepare("
                UPDATE waiting_list
                SET
                    waiting_status = 'Waiting',
                    called_at = NULL
                WHERE waiting_id = ?
                  AND dept_id = ?
                  AND waiting_status = 'Expired'
            ");

            $stmt->execute([
                $waitingId,
                $deptId
            ]);


            if ($stmt->rowCount() !== 1) {
                throw new RuntimeException(
                    'Candidate could not be restored.'
                );
            }


            $pdo->commit();


            $_SESSION['waiting_flash'] = [
                'type' =>
                    'success',

                'message' =>
                    'Candidate restored to the waiting list.'
            ];
        }


    } catch (Throwable $exception) {

        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }


        $_SESSION['waiting_flash'] = [
            'type' =>
                'danger',

            'message' =>
                $exception->getMessage()
        ];
    }


    header(
        'Location: waiting_list.php'
    );

    exit;
}


/*
|--------------------------------------------------------------------------
| Filters
|--------------------------------------------------------------------------
*/

$statusFilter = trim(
    (string) (
        $_GET['status']
        ?? 'All'
    )
);

$search = trim(
    (string) (
        $_GET['search']
        ?? ''
    )
);


$allowedFilters = [
    'All',
    'Waiting',
    'Called',
    'Admitted',
    'Expired'
];


if (
    !in_array(
        $statusFilter,
        $allowedFilters,
        true
    )
) {
    $statusFilter = 'All';
}


/*
|--------------------------------------------------------------------------
| Department information
|--------------------------------------------------------------------------
*/

$stmt = $pdo->prepare("
    SELECT
        d.dept_id,
        d.dept_code,
        d.dept_name,
        d.total_seats,
        f.faculty_name

    FROM departments d

    LEFT JOIN faculties f
        ON f.faculty_id =
           d.faculty_id

    WHERE d.dept_id = ?

    LIMIT 1
");

$stmt->execute([
    $deptId
]);

$department = $stmt->fetch(
    PDO::FETCH_ASSOC
);


if (!$department) {
    http_response_code(404);
    exit('Department not found.');
}


/*
|--------------------------------------------------------------------------
| Seat statistics
|--------------------------------------------------------------------------
*/

$stmt = $pdo->prepare("
    SELECT

        COUNT(
            DISTINCT CASE
                WHEN allocation_status IN (
                    'Allocated',
                    'Admitted'
                )
                THEN import_candidate_id
            END
        ) AS occupied_seats,

        COUNT(
            DISTINCT CASE
                WHEN allocation_status = 'Allocated'
                THEN import_candidate_id
            END
        ) AS allocated_count,

        COUNT(
            DISTINCT CASE
                WHEN allocation_status = 'Admitted'
                THEN import_candidate_id
            END
        ) AS admitted_count,

        COUNT(
            CASE
                WHEN allocation_status = 'Vacated'
                THEN 1
            END
        ) AS vacated_count

    FROM merit_allocations

    WHERE dept_id = ?
");

$stmt->execute([
    $deptId
]);

$seatStats =
    $stmt->fetch(
        PDO::FETCH_ASSOC
    ) ?: [
        'occupied_seats' => 0,
        'allocated_count' => 0,
        'admitted_count' => 0,
        'vacated_count' => 0
    ];


$totalSeats =
    (int)
    $department['total_seats'];

$occupiedSeats =
    (int)
    $seatStats['occupied_seats'];

$availableSeats =
    max(
        0,
        $totalSeats -
        $occupiedSeats
    );


/*
|--------------------------------------------------------------------------
| Canonical waiting-list statistics
|--------------------------------------------------------------------------
|
| Only the first-preference waiting department is counted.
|--------------------------------------------------------------------------
*/

$stmt = $pdo->prepare("
    SELECT

        COUNT(
            DISTINCT wl.import_candidate_id
        ) AS total_count,

        COUNT(
            DISTINCT CASE
                WHEN wl.waiting_status = 'Waiting'
                THEN wl.import_candidate_id
            END
        ) AS waiting_count,

        COUNT(
            DISTINCT CASE
                WHEN wl.waiting_status = 'Called'
                THEN wl.import_candidate_id
            END
        ) AS called_count,

        COUNT(
            DISTINCT CASE
                WHEN wl.waiting_status = 'Admitted'
                THEN wl.import_candidate_id
            END
        ) AS admitted_count,

        COUNT(
            DISTINCT CASE
                WHEN wl.waiting_status = 'Expired'
                THEN wl.import_candidate_id
            END
        ) AS expired_count

    FROM waiting_list wl

    INNER JOIN gst_candidate_choices gcc
        ON gcc.import_candidate_id =
           wl.import_candidate_id
       AND gcc.dept_id =
           wl.dept_id

    WHERE wl.dept_id = ?

      AND gcc.preference_order = (
          SELECT MIN(gcc2.preference_order)
          FROM gst_candidate_choices gcc2
          WHERE gcc2.import_candidate_id =
                wl.import_candidate_id
      )

      AND wl.waiting_id = (
          SELECT MIN(wl2.waiting_id)
          FROM waiting_list wl2
          WHERE wl2.import_candidate_id =
                wl.import_candidate_id
            AND wl2.dept_id =
                wl.dept_id
      )
");

$stmt->execute([
    $deptId
]);

$waitingStats =
    $stmt->fetch(
        PDO::FETCH_ASSOC
    ) ?: [
        'total_count' => 0,
        'waiting_count' => 0,
        'called_count' => 0,
        'admitted_count' => 0,
        'expired_count' => 0
    ];


/*
|--------------------------------------------------------------------------
| Fetch canonical waiting-list candidates
|--------------------------------------------------------------------------
*/

$sql = "
    SELECT
        wl.waiting_id,
        wl.import_candidate_id,
        wl.applicant_id,
        wl.waiting_rank,
        wl.waiting_status,
        wl.called_at,

        gic.gst_roll,
        gic.full_name,
        gic.gst_score,
        gic.quota,
        gic.phone AS imported_phone,

        ga.email,
        ga.phone AS applicant_phone,

        ma.merit_rank,
        ma.allocation_status,

        ap.dept_step,
        ap.hall_step,
        ap.registrar_step,
        ap.final_status

    FROM waiting_list wl

    INNER JOIN gst_imported_candidates gic
        ON gic.import_candidate_id =
           wl.import_candidate_id

    LEFT JOIN gst_applicants ga
        ON ga.applicant_id =
           gic.applicant_id

    INNER JOIN gst_candidate_choices gcc
        ON gcc.import_candidate_id =
           wl.import_candidate_id
       AND gcc.dept_id =
           wl.dept_id


    LEFT JOIN merit_allocations ma
        ON ma.import_candidate_id =
           wl.import_candidate_id
       AND ma.dept_id =
           wl.dept_id

       AND ma.allocation_id = (
           SELECT MAX(ma2.allocation_id)
           FROM merit_allocations ma2
           WHERE ma2.import_candidate_id =
                 wl.import_candidate_id
             AND ma2.dept_id =
                 wl.dept_id
       )


    LEFT JOIN admission_progress ap
        ON ap.applicant_id =
           gic.applicant_id


    WHERE wl.dept_id = ?

      AND gcc.preference_order = (
          SELECT MIN(gcc2.preference_order)
          FROM gst_candidate_choices gcc2
          WHERE gcc2.import_candidate_id =
                wl.import_candidate_id
      )

      AND wl.waiting_id = (
          SELECT MIN(wl2.waiting_id)
          FROM waiting_list wl2
          WHERE wl2.import_candidate_id =
                wl.import_candidate_id
            AND wl2.dept_id =
                wl.dept_id
      )
";

$params = [
    $deptId
];


if ($statusFilter !== 'All') {

    $sql .= "
        AND wl.waiting_status = ?
    ";

    $params[] =
        $statusFilter;
}


if ($search !== '') {

    $sql .= "
        AND (
            gic.gst_roll LIKE ?
            OR gic.full_name LIKE ?
            OR ga.email LIKE ?
            OR COALESCE(
                NULLIF(TRIM(ga.phone), ''),
                gic.phone
            ) LIKE ?
        )
    ";

    $searchTerm =
        '%' . $search . '%';

    $params[] =
        $searchTerm;

    $params[] =
        $searchTerm;

    $params[] =
        $searchTerm;

    $params[] =
        $searchTerm;
}


$sql .= "
    ORDER BY
        CASE wl.waiting_status
            WHEN 'Called' THEN 1
            WHEN 'Waiting' THEN 2
            WHEN 'Admitted' THEN 3
            WHEN 'Expired' THEN 4
            ELSE 5
        END,

        wl.waiting_rank ASC,
        gic.merit_position ASC,
        wl.waiting_id ASC
";


$stmt = $pdo->prepare($sql);

$stmt->execute(
    $params
);

$waitingList =
    $stmt->fetchAll(
        PDO::FETCH_ASSOC
    );


/*
|--------------------------------------------------------------------------
| Load layout AFTER POST processing
|--------------------------------------------------------------------------
*/

require_once '../includes/header.php';
require_once '../includes/navbar.php';

?>

<div class="container py-4">

    <!-- Page heading -->
    <div class="d-flex justify-content-between align-items-start flex-wrap gap-3 mb-4">

        <div>

            <h2 class="mb-1">

                <i class="bi bi-list-ol text-warning me-2"></i>

                Waiting List Management

            </h2>


            <div class="text-muted">

                <?= e($department['dept_name']) ?>

                <?php if (
                    !empty(
                        $department[
                            'faculty_name'
                        ]
                    )
                ): ?>

                    —

                    <?= e(
                        $department[
                            'faculty_name'
                        ]
                    ) ?>

                    Faculty

                <?php endif; ?>

            </div>

        </div>


        <div class="d-flex gap-2">

            <a
                href="dashboard.php"
                class="btn btn-outline-secondary"
            >

                <i class="bi bi-house-door me-1"></i>
                Dashboard

            </a>


            <a
                href="merit_list.php"
                class="btn btn-outline-primary"
            >

                <i class="bi bi-trophy me-1"></i>
                Merit List

            </a>

        </div>

    </div>


    <?php if ($message !== ''): ?>

        <div
            class="alert alert-<?= e($alertType) ?> alert-dismissible fade show"
        >

            <?= e($message) ?>


            <button
                type="button"
                class="btn-close"
                data-bs-dismiss="alert"
            ></button>

        </div>

    <?php endif; ?>


    <!-- Seat availability -->
    <?php if ($availableSeats > 0): ?>

        <div class="alert alert-success d-flex align-items-center gap-3">

            <i class="bi bi-person-plus-fill fs-3"></i>

            <div>

                <strong>

                    <?= $availableSeats ?>

                    seat<?= $availableSeats !== 1
                        ? 's are'
                        : ' is'
                    ?>

                    currently available.

                </strong>


                <div class="small">
                    You may call the next eligible waiting-list candidate.
                </div>

            </div>

        </div>


    <?php else: ?>

        <div class="alert alert-warning d-flex align-items-center gap-3">

            <i class="bi bi-exclamation-triangle-fill fs-3"></i>

            <div>

                <strong>
                    No vacant seat is currently available.
                </strong>


                <div class="small">

                    A merit allocation must be vacated before another candidate
                    can be called.

                </div>

            </div>

        </div>

    <?php endif; ?>


    <!-- Seat statistics -->
    <div class="row g-3 mb-4">


        <div class="col-6 col-md-3">

            <div class="card h-100 border-start border-4 border-primary">

                <div class="card-body text-center">

                    <div class="h3 fw-bold text-primary mb-1">
                        <?= $totalSeats ?>
                    </div>

                    <small class="text-muted">
                        Total Seats
                    </small>

                </div>

            </div>

        </div>


        <div class="col-6 col-md-3">

            <div class="card h-100 border-start border-4 border-success">

                <div class="card-body text-center">

                    <div class="h3 fw-bold text-success mb-1">
                        <?= $occupiedSeats ?>
                    </div>

                    <small class="text-muted">
                        Occupied Seats
                    </small>

                </div>

            </div>

        </div>


        <div class="col-6 col-md-3">

            <div class="card h-100 border-start border-4 border-info">

                <div class="card-body text-center">

                    <div class="h3 fw-bold text-info mb-1">
                        <?= $availableSeats ?>
                    </div>

                    <small class="text-muted">
                        Available Seats
                    </small>

                </div>

            </div>

        </div>


        <div class="col-6 col-md-3">

            <div class="card h-100 border-start border-4 border-warning">

                <div class="card-body text-center">

                    <div class="h3 fw-bold text-warning mb-1">

                        <?= (int)
                            $waitingStats[
                                'waiting_count'
                            ]
                        ?>

                    </div>

                    <small class="text-muted">
                        Currently Waiting
                    </small>

                </div>

            </div>

        </div>

    </div>


    <!-- Waiting-list statistics -->
    <div class="row g-3 mb-4">

        <?php

        $statusCards = [
            [
                'label' =>
                    'Total List',

                'value' =>
                    $waitingStats[
                        'total_count'
                    ],

                'class' =>
                    'secondary',

                'icon' =>
                    'bi-people'
            ],
            [
                'label' =>
                    'Waiting',

                'value' =>
                    $waitingStats[
                        'waiting_count'
                    ],

                'class' =>
                    'primary',

                'icon' =>
                    'bi-hourglass-split'
            ],
            [
                'label' =>
                    'Called',

                'value' =>
                    $waitingStats[
                        'called_count'
                    ],

                'class' =>
                    'warning',

                'icon' =>
                    'bi-bell-fill'
            ],
            [
                'label' =>
                    'Admitted',

                'value' =>
                    $waitingStats[
                        'admitted_count'
                    ],

                'class' =>
                    'success',

                'icon' =>
                    'bi-check-circle-fill'
            ],
            [
                'label' =>
                    'Expired',

                'value' =>
                    $waitingStats[
                        'expired_count'
                    ],

                'class' =>
                    'danger',

                'icon' =>
                    'bi-x-circle-fill'
            ]
        ];

        ?>


        <?php foreach (
            $statusCards as $card
        ): ?>

            <div class="col-6 col-md">

                <div class="card h-100 shadow-sm">

                    <div class="card-body text-center py-3">

                        <i
                            class="bi <?= e(
                                $card['icon']
                            ) ?>
                            fs-3 text-<?= e(
                                $card['class']
                            ) ?>"
                        ></i>


                        <div class="h4 fw-bold mt-2 mb-0">

                            <?= (int)
                                $card['value']
                            ?>

                        </div>


                        <small class="text-muted">

                            <?= e(
                                $card['label']
                            ) ?>

                        </small>

                    </div>

                </div>

            </div>

        <?php endforeach; ?>

    </div>


    <!-- Filters -->
    <div class="card shadow-sm mb-4">

        <div class="card-body">

            <form
                method="GET"
                class="row g-3 align-items-end"
            >


                <div class="col-md-3">

                    <label class="form-label fw-semibold">
                        Status
                    </label>


                    <select
                        name="status"
                        class="form-select"
                    >

                        <?php foreach (
                            $allowedFilters as $filter
                        ): ?>

                            <option
                                value="<?= e($filter) ?>"
                                <?= $statusFilter ===
                                    $filter
                                    ? 'selected'
                                    : ''
                                ?>
                            >

                                <?= e($filter) ?>

                            </option>

                        <?php endforeach; ?>

                    </select>

                </div>


                <div class="col-md-6">

                    <label class="form-label fw-semibold">
                        Search candidate
                    </label>


                    <input
                        type="text"
                        name="search"
                        class="form-control"
                        placeholder="GST roll, name, email or phone"
                        value="<?= e($search) ?>"
                    >

                </div>


                <div class="col-md-3">

                    <div class="d-flex gap-2">

                        <button
                            type="submit"
                            class="btn btn-primary w-100"
                        >

                            <i class="bi bi-search me-1"></i>
                            Search

                        </button>


                        <a
                            href="waiting_list.php"
                            class="btn btn-outline-secondary w-100"
                        >

                            Reset

                        </a>

                    </div>

                </div>

            </form>

        </div>

    </div>


    <!-- Waiting-list table -->
    <div class="card shadow-lg">

        <div
            class="card-header bg-warning text-dark d-flex
                   justify-content-between align-items-center"
        >

            <span class="fw-semibold">

                <i class="bi bi-list-nested me-2"></i>

                Waiting Candidates

            </span>


            <span class="badge bg-dark">

                <?= count($waitingList) ?>
                result(s)

            </span>

        </div>


        <div class="table-responsive">

            <table class="table table-hover align-middle mb-0">

                <thead class="table-light">

                    <tr>

                        <th>Waiting Rank</th>
                        <th>GST Roll</th>
                        <th>Candidate</th>
                        <th>GST Score</th>
                        <th>Status</th>
                        <th>Called At</th>
                        <th>Admission Progress</th>
                        <th class="text-end">Action</th>

                    </tr>

                </thead>


                <tbody>


                <?php if (
                    empty($waitingList)
                ): ?>

                    <tr>

                        <td
                            colspan="8"
                            class="text-center py-5 text-muted"
                        >

                            <i class="bi bi-inbox fs-1 d-block mb-2"></i>

                            No waiting-list candidates found.

                        </td>

                    </tr>


                <?php else: ?>


                    <?php foreach (
                        $waitingList as $candidate
                    ): ?>

                        <?php

                        $statusClass =
                            match (
                                $candidate[
                                    'waiting_status'
                                ]
                            ) {
                                'Waiting' =>
                                    'bg-primary',

                                'Called' =>
                                    'bg-warning text-dark',

                                'Admitted' =>
                                    'bg-success',

                                'Expired' =>
                                    'bg-danger',

                                default =>
                                    'bg-secondary'
                            };


                        $displayPhone =
                            trim(
                                (string) (
                                    $candidate[
                                        'applicant_phone'
                                    ]
                                    ?? ''
                                )
                            ) !== ''
                                ? $candidate[
                                    'applicant_phone'
                                  ]
                                : $candidate[
                                    'imported_phone'
                                  ];

                        ?>


                        <tr>


                            <td>

                                <span class="badge bg-dark fs-6">

                                    #<?= (int)
                                        $candidate[
                                            'waiting_rank'
                                        ]
                                    ?>

                                </span>

                            </td>


                            <td>

                                <strong>

                                    <?= e(
                                        $candidate[
                                            'gst_roll'
                                        ]
                                    ) ?>

                                </strong>


                                <?php if (
                                    !empty(
                                        $candidate[
                                            'merit_rank'
                                        ]
                                    ) &&
                                    in_array(
                                        $candidate[
                                            'allocation_status'
                                        ],
                                        [
                                            'Allocated',
                                            'Admitted'
                                        ],
                                        true
                                    )
                                ): ?>

                                    <div class="small text-success">

                                        Merit
                                        #<?= (int)
                                            $candidate[
                                                'merit_rank'
                                            ]
                                        ?>

                                    </div>

                                <?php endif; ?>

                            </td>


                            <td>

                                <div class="fw-semibold">

                                    <?= e(
                                        $candidate[
                                            'full_name'
                                        ]
                                    ) ?>

                                </div>


                                <?php if (
                                    !empty(
                                        $candidate[
                                            'email'
                                        ]
                                    )
                                ): ?>

                                    <div class="small text-muted">

                                        <i
                                            class="bi bi-envelope me-1"
                                        ></i>

                                        <?= e(
                                            $candidate[
                                                'email'
                                            ]
                                        ) ?>

                                    </div>

                                <?php endif; ?>


                                <?php if (
                                    !empty(
                                        $displayPhone
                                    )
                                ): ?>

                                    <div class="small text-muted">

                                        <i
                                            class="bi bi-telephone me-1"
                                        ></i>

                                        <?= e(
                                            $displayPhone
                                        ) ?>

                                    </div>

                                <?php endif; ?>


                                <?php if (
                                    !empty(
                                        $candidate[
                                            'quota'
                                        ]
                                    )
                                ): ?>

                                    <span
                                        class="badge bg-info text-dark mt-1"
                                    >

                                        <?= e(
                                            $candidate[
                                                'quota'
                                            ]
                                        ) ?>

                                    </span>

                                <?php endif; ?>

                            </td>


                            <td>

                                <strong>

                                    <?= number_format(
                                        (float)
                                        $candidate[
                                            'gst_score'
                                        ],
                                        2
                                    ) ?>

                                </strong>

                            </td>


                            <td>

                                <span
                                    class="badge <?= e(
                                        $statusClass
                                    ) ?> fs-6"
                                >

                                    <?= e(
                                        $candidate[
                                            'waiting_status'
                                        ]
                                    ) ?>

                                </span>


                                <?php if (
                                    !empty(
                                        $candidate[
                                            'allocation_status'
                                        ]
                                    )
                                ): ?>

                                    <div class="small mt-1">

                                        Allocation:

                                        <strong>

                                            <?= e(
                                                $candidate[
                                                    'allocation_status'
                                                ]
                                            ) ?>

                                        </strong>

                                    </div>

                                <?php endif; ?>

                            </td>


                            <td>

                                <?php if (
                                    !empty(
                                        $candidate[
                                            'called_at'
                                        ]
                                    )
                                ): ?>

                                    <?= date(
                                        'M j, Y',
                                        strtotime(
                                            (string)
                                            $candidate[
                                                'called_at'
                                            ]
                                        )
                                    ) ?>


                                    <div class="small text-muted">

                                        <?= date(
                                            'h:i A',
                                            strtotime(
                                                (string)
                                                $candidate[
                                                    'called_at'
                                                ]
                                            )
                                        ) ?>

                                    </div>


                                <?php else: ?>

                                    <span class="text-muted">
                                        Not called
                                    </span>

                                <?php endif; ?>

                            </td>


                            <td>

                                <?php if (
                                    !empty(
                                        $candidate[
                                            'final_status'
                                        ]
                                    )
                                ): ?>

                                    <span
                                        class="badge bg-<?=
                                            $candidate[
                                                'final_status'
                                            ] === 'Admitted'
                                                ? 'success'
                                                : (
                                                    $candidate[
                                                        'final_status'
                                                    ] === 'Rejected'
                                                        ? 'danger'
                                                        : 'info'
                                                )
                                        ?>"
                                    >

                                        <?= e(
                                            $candidate[
                                                'final_status'
                                            ]
                                        ) ?>

                                    </span>


                                    <div
                                        class="small text-muted mt-1"
                                    >

                                        Dept:
                                        <?= e(
                                            $candidate[
                                                'dept_step'
                                            ]
                                            ?? 'Pending'
                                        ) ?>

                                        |

                                        Hall:
                                        <?= e(
                                            $candidate[
                                                'hall_step'
                                            ]
                                            ?? 'Pending'
                                        ) ?>

                                    </div>


                                <?php else: ?>

                                    <span class="text-muted">
                                        Not started
                                    </span>

                                <?php endif; ?>

                            </td>


                            <td class="text-end">


                                <?php if (
                                    $candidate[
                                        'waiting_status'
                                    ] === 'Waiting'
                                ): ?>


                                    <form
                                        method="POST"
                                        class="d-inline"
                                        onsubmit="return confirm(
                                            'Call this candidate from the waiting list?'
                                        );"
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
                                                $candidate[
                                                    'waiting_id'
                                                ]
                                            ?>"
                                        >

                                        <input
                                            type="hidden"
                                            name="action"
                                            value="call"
                                        >


                                        <button
                                            type="submit"
                                            class="btn btn-sm btn-success"
                                            <?= $availableSeats <= 0
                                                ? 'disabled'
                                                : ''
                                            ?>
                                        >

                                            <i
                                                class="bi bi-bell-fill me-1"
                                            ></i>

                                            Call

                                        </button>

                                    </form>


                                    <form
                                        method="POST"
                                        class="d-inline"
                                        onsubmit="return confirm(
                                            'Mark this candidate as expired?'
                                        );"
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
                                                $candidate[
                                                    'waiting_id'
                                                ]
                                            ?>"
                                        >

                                        <input
                                            type="hidden"
                                            name="action"
                                            value="expire"
                                        >


                                        <button
                                            type="submit"
                                            class="btn btn-sm btn-outline-danger"
                                        >

                                            <i
                                                class="bi bi-x-circle me-1"
                                            ></i>

                                            Expire

                                        </button>

                                    </form>


                                <?php elseif (
                                    $candidate[
                                        'waiting_status'
                                    ] === 'Called'
                                ): ?>


                                    <form
                                        method="POST"
                                        class="d-inline"
                                        onsubmit="return confirm(
                                            'Expire this called candidate and vacate the allocation?'
                                        );"
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
                                                $candidate[
                                                    'waiting_id'
                                                ]
                                            ?>"
                                        >

                                        <input
                                            type="hidden"
                                            name="action"
                                            value="expire"
                                        >


                                        <button
                                            type="submit"
                                            class="btn btn-sm btn-danger"
                                        >

                                            <i
                                                class="bi bi-clock-history me-1"
                                            ></i>

                                            Expire Call

                                        </button>

                                    </form>


                                <?php elseif (
                                    $candidate[
                                        'waiting_status'
                                    ] === 'Expired'
                                ): ?>


                                    <form
                                        method="POST"
                                        class="d-inline"
                                        onsubmit="return confirm(
                                            'Restore this candidate to Waiting status?'
                                        );"
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
                                                $candidate[
                                                    'waiting_id'
                                                ]
                                            ?>"
                                        >

                                        <input
                                            type="hidden"
                                            name="action"
                                            value="restore"
                                        >


                                        <button
                                            type="submit"
                                            class="btn btn-sm btn-outline-primary"
                                        >

                                            <i
                                                class="bi bi-arrow-counterclockwise me-1"
                                            ></i>

                                            Restore

                                        </button>

                                    </form>


                                <?php elseif (
                                    $candidate[
                                        'waiting_status'
                                    ] === 'Admitted'
                                ): ?>


                                    <span class="badge bg-success">

                                        <i
                                            class="bi bi-check-circle me-1"
                                        ></i>

                                        Completed

                                    </span>


                                <?php endif; ?>

                            </td>

                        </tr>


                    <?php endforeach; ?>


                <?php endif; ?>


                </tbody>

            </table>

        </div>

    </div>


    <div class="alert alert-info mt-4">

        <div class="d-flex gap-3 align-items-start">

            <i class="bi bi-info-circle-fill fs-3"></i>

            <div>

                <strong>
                    Waiting-list workflow
                </strong>


                <ol class="mb-0 mt-2">

                    <li>
                        A merit seat becomes vacant.
                    </li>

                    <li>
                        The next waiting candidate is called in rank order.
                    </li>

                    <li>
                        A new Allocated merit record is created using the
                        imported GST candidate.
                    </li>

                    <li>
                        If the candidate has not registered yet, the new active
                        allocation now makes registration possible.
                    </li>

                    <li>
                        Final admission later changes the allocation to
                        Admitted.
                    </li>

                </ol>

            </div>

        </div>

    </div>

</div>


<?php require_once '../includes/footer.php'; ?>
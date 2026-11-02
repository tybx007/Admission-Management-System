<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Dynamic Notice Content Renderer
|--------------------------------------------------------------------------
|
| Intended path:
|   includes/notice_dynamic_content.php
|
| Usage from your existing public/student notice page:
|
|   require_once 'includes/notice_dynamic_content.php';
|   renderDynamicNoticeContent($pdo, $notice);
|
| The notice itself stores only the TYPE + merit_call_id.
| Merit/Waiting/Vacancy data always comes from the database.
|--------------------------------------------------------------------------
*/


function dynamicNoticeE(
    mixed $value
): string {
    return htmlspecialchars(
        (string) ($value ?? ''),
        ENT_QUOTES,
        'UTF-8'
    );
}


function dynamicNoticeCall(
    PDO $pdo,
    int $meritCallId
): ?array {

    $stmt =
        $pdo->prepare("
            SELECT
                merit_call_id,
                call_number,
                call_title,
                academic_year
            FROM merit_calls
            WHERE merit_call_id = ?
            LIMIT 1
        ");


    $stmt->execute([
        $meritCallId
    ]);


    $row =
        $stmt->fetch(
            PDO::FETCH_ASSOC
        );


    return $row ?: null;
}


/*
|--------------------------------------------------------------------------
| Merit List
|--------------------------------------------------------------------------
*/

function renderDatabaseMeritList(
    PDO $pdo,
    int $meritCallId,
    ?int $departmentId = null
): void {

    $call =
        dynamicNoticeCall(
            $pdo,
            $meritCallId
        );


    if (!$call) {

        echo '
            <div class="alert alert-warning mb-0">
                Merit Call was not found.
            </div>
        ';

        return;
    }


    $whereDepartment = '';
    $params = [
        $meritCallId
    ];


    if (
        $departmentId !== null
        &&
        $departmentId > 0
    ) {

        $whereDepartment =
            ' AND ma.dept_id = ? ';

        $params[] =
            $departmentId;
    }


    $stmt =
        $pdo->prepare("
            SELECT
                ma.merit_rank,
                ma.allocation_status,

                gic.gst_roll,
                gic.full_name,
                gic.gst_score,
                gic.merit_position,

                d.dept_name,

                gcc.preference_order

            FROM merit_allocations ma

            INNER JOIN gst_imported_candidates gic
                ON gic.import_candidate_id =
                   ma.import_candidate_id

            INNER JOIN departments d
                ON d.dept_id =
                   ma.dept_id

            LEFT JOIN gst_candidate_choices gcc
                ON gcc.import_candidate_id =
                   ma.import_candidate_id
               AND gcc.dept_id =
                   ma.dept_id

            WHERE ma.merit_call_id = ?

              AND ma.allocation_status IN (
                  'Allocated',
                  'Admitted'
              )

              {$whereDepartment}

            ORDER BY
                CASE
                    WHEN gic.merit_position IS NULL
                    THEN 1
                    ELSE 0
                END,

                gic.merit_position ASC,
                gic.gst_score DESC,
                ma.merit_rank ASC
        ");


    $stmt->execute(
        $params
    );


    $rows =
        $stmt->fetchAll(
            PDO::FETCH_ASSOC
        );


    echo '
        <div class="mt-3">

            <div class="d-flex
                        justify-content-between
                        align-items-center
                        mb-2">

                <h6 class="fw-bold mb-0">
                    '
                    . dynamicNoticeE(
                        $call[
                            'call_title'
                        ]
                    )
                    . ' Merit List
                </h6>

                <span class="badge bg-success">
                    '
                    . count($rows)
                    . ' Candidate(s)
                </span>

            </div>
    ';


    if (!$rows) {

        echo '
            <div class="alert alert-light border mb-0">
                No published merit candidates found.
            </div>
        ';

        echo '</div>';

        return;
    }


    echo '
        <div class="table-responsive">

            <table class="table
                          table-sm
                          table-bordered
                          table-hover
                          align-middle
                          mb-0">

                <thead class="table-light">

                    <tr>
                        <th>GST Merit</th>
                        <th>GST Roll</th>
                        <th>Name</th>
                        <th>Department</th>
                        <th>Dept Rank</th>
                        <th>Choice</th>
                        <th>GST Score</th>
                    </tr>

                </thead>

                <tbody>
    ';


    foreach ($rows as $row) {

        echo '
            <tr>

                <td>
                    '
                    . (
                        $row[
                            'merit_position'
                        ]
                        !== null
                            ? '#'
                              . (int)
                                $row[
                                    'merit_position'
                                ]
                            : '—'
                      )
                    . '
                </td>

                <td>
                    <strong>
                        '
                        . dynamicNoticeE(
                            $row[
                                'gst_roll'
                            ]
                        )
                        . '
                    </strong>
                </td>

                <td>
                    '
                    . dynamicNoticeE(
                        $row[
                            'full_name'
                        ]
                    )
                    . '
                </td>

                <td>
                    '
                    . dynamicNoticeE(
                        $row[
                            'dept_name'
                        ]
                    )
                    . '
                </td>

                <td>
                    #'
                    . (int)
                      $row[
                          'merit_rank'
                      ]
                    . '
                </td>

                <td>
                    '
                    . (
                        $row[
                            'preference_order'
                        ]
                        !== null
                            ? '#'
                              . (int)
                                $row[
                                    'preference_order'
                                ]
                            : '—'
                      )
                    . '
                </td>

                <td>
                    '
                    . number_format(
                        (float)
                        $row[
                            'gst_score'
                        ],
                        2
                    )
                    . '
                </td>

            </tr>
        ';
    }


    echo '
                </tbody>

            </table>

        </div>

        </div>
    ';
}


/*
|--------------------------------------------------------------------------
| Waiting List
|--------------------------------------------------------------------------
|
| waiting_list has no merit_call_id.
| The selected Merit Call supplies the admission academic_year.
| The renderer then shows the CURRENT active Waiting pool for that session.
|--------------------------------------------------------------------------
*/

function renderDatabaseWaitingList(
    PDO $pdo,
    int $meritCallId,
    ?int $departmentId = null
): void {

    $call =
        dynamicNoticeCall(
            $pdo,
            $meritCallId
        );


    if (!$call) {

        echo '
            <div class="alert alert-warning mb-0">
                Merit Call was not found.
            </div>
        ';

        return;
    }


    $academicYear =
        (string)
        $call[
            'academic_year'
        ];


    $whereDepartment = '';

    $params = [
        $academicYear
    ];


    if (
        $departmentId !== null
        &&
        $departmentId > 0
    ) {

        $whereDepartment =
            ' AND wl.dept_id = ? ';

        $params[] =
            $departmentId;
    }


    $stmt =
        $pdo->prepare("
            SELECT
                wl.waiting_rank,

                gic.gst_roll,
                gic.full_name,
                gic.gst_score,
                gic.merit_position,

                d.dept_name

            FROM waiting_list wl

            INNER JOIN gst_imported_candidates gic
                ON gic.import_candidate_id =
                   wl.import_candidate_id

            INNER JOIN departments d
                ON d.dept_id =
                   wl.dept_id

            WHERE gic.academic_year = ?

              AND wl.waiting_status =
                  'Waiting'

              {$whereDepartment}

              AND NOT EXISTS (
                  SELECT 1

                  FROM merit_allocations ma

                  WHERE ma.import_candidate_id =
                        wl.import_candidate_id

                    AND ma.allocation_status IN (
                        'Allocated',
                        'Admitted'
                    )
              )

            ORDER BY
                CASE
                    WHEN gic.merit_position IS NULL
                    THEN 1
                    ELSE 0
                END,

                gic.merit_position ASC,
                wl.waiting_rank ASC
        ");


    $stmt->execute(
        $params
    );


    $rows =
        $stmt->fetchAll(
            PDO::FETCH_ASSOC
        );


    echo '
        <div class="mt-3">

            <div class="d-flex
                        justify-content-between
                        align-items-center
                        mb-2">

                <h6 class="fw-bold mb-0">
                    Current Waiting List —
                    '
                    . dynamicNoticeE(
                        $academicYear
                    )
                    . '
                </h6>

                <span class="badge bg-warning text-dark">
                    '
                    . count($rows)
                    . ' Waiting
                </span>

            </div>
    ';


    if (!$rows) {

        echo '
            <div class="alert alert-light border mb-0">
                No active waiting candidates found.
            </div>
        ';

        echo '</div>';

        return;
    }


    echo '
        <div class="table-responsive">

            <table class="table
                          table-sm
                          table-bordered
                          table-hover
                          align-middle
                          mb-0">

                <thead class="table-light">

                    <tr>
                        <th>GST Merit</th>
                        <th>GST Roll</th>
                        <th>Name</th>
                        <th>Waiting Department</th>
                        <th>Waiting Rank</th>
                        <th>GST Score</th>
                    </tr>

                </thead>

                <tbody>
    ';


    foreach ($rows as $row) {

        echo '
            <tr>

                <td>
                    '
                    . (
                        $row[
                            'merit_position'
                        ]
                        !== null
                            ? '#'
                              . (int)
                                $row[
                                    'merit_position'
                                ]
                            : '—'
                      )
                    . '
                </td>

                <td>
                    <strong>
                        '
                        . dynamicNoticeE(
                            $row[
                                'gst_roll'
                            ]
                        )
                        . '
                    </strong>
                </td>

                <td>
                    '
                    . dynamicNoticeE(
                        $row[
                            'full_name'
                        ]
                    )
                    . '
                </td>

                <td>
                    '
                    . dynamicNoticeE(
                        $row[
                            'dept_name'
                        ]
                    )
                    . '
                </td>

                <td>
                    #'
                    . (int)
                      $row[
                          'waiting_rank'
                      ]
                    . '
                </td>

                <td>
                    '
                    . number_format(
                        (float)
                        $row[
                            'gst_score'
                        ],
                        2
                    )
                    . '
                </td>

            </tr>
        ';
    }


    echo '
                </tbody>

            </table>

        </div>

        </div>
    ';
}


/*
|--------------------------------------------------------------------------
| Department Vacancy
|--------------------------------------------------------------------------
*/

function renderDatabaseDepartmentVacancy(
    PDO $pdo,
    int $meritCallId,
    ?int $departmentId = null
): void {

    $call =
        dynamicNoticeCall(
            $pdo,
            $meritCallId
        );


    if (!$call) {

        echo '
            <div class="alert alert-warning mb-0">
                Merit Call was not found.
            </div>
        ';

        return;
    }


    $academicYear =
        (string)
        $call[
            'academic_year'
        ];


    $whereDepartment = '';

    $params = [
        $academicYear
    ];


    if (
        $departmentId !== null
        &&
        $departmentId > 0
    ) {

        $whereDepartment =
            ' WHERE d.dept_id = ? ';

        $params[] =
            $departmentId;
    }


    $stmt =
        $pdo->prepare("
            SELECT
                d.dept_id,
                d.dept_name,
                d.total_seats,

                COUNT(
                    DISTINCT CASE

                        WHEN gic.academic_year = ?

                         AND ma.allocation_status IN (
                             'Allocated',
                             'Admitted'
                         )

                        THEN ma.import_candidate_id

                    END
                ) AS occupied_seats

            FROM departments d

            LEFT JOIN merit_allocations ma
                ON ma.dept_id =
                   d.dept_id

            LEFT JOIN gst_imported_candidates gic
                ON gic.import_candidate_id =
                   ma.import_candidate_id

            {$whereDepartment}

            GROUP BY
                d.dept_id,
                d.dept_name,
                d.total_seats

            ORDER BY
                d.dept_name
        ");


    $stmt->execute(
        $params
    );


    $rows =
        $stmt->fetchAll(
            PDO::FETCH_ASSOC
        );


    echo '
        <div class="mt-3">

            <div class="d-flex
                        justify-content-between
                        align-items-center
                        mb-2">

                <h6 class="fw-bold mb-0">
                    Department-wise Vacancy —
                    '
                    . dynamicNoticeE(
                        $academicYear
                    )
                    . '
                </h6>

            </div>

            <div class="table-responsive">

                <table class="table
                              table-sm
                              table-bordered
                              table-hover
                              align-middle
                              mb-0">

                    <thead class="table-light">

                        <tr>
                            <th>Department</th>
                            <th>Total Seats</th>
                            <th>Occupied</th>
                            <th>Vacant</th>
                        </tr>

                    </thead>

                    <tbody>
    ';


    foreach ($rows as $row) {

        $total =
            max(
                0,
                (int)
                $row[
                    'total_seats'
                ]
            );


        $occupied =
            max(
                0,
                (int)
                $row[
                    'occupied_seats'
                ]
            );


        $vacant =
            max(
                0,
                $total
                - $occupied
            );


        echo '
            <tr>

                <td>
                    '
                    . dynamicNoticeE(
                        $row[
                            'dept_name'
                        ]
                    )
                    . '
                </td>

                <td>
                    '
                    . $total
                    . '
                </td>

                <td>
                    '
                    . $occupied
                    . '
                </td>

                <td>
                    <span class="badge
                                 '
                    . (
                        $vacant > 0
                            ? 'bg-warning text-dark'
                            : 'bg-success'
                      )
                    . '">
                        '
                        . $vacant
                        . '
                    </span>
                </td>

            </tr>
        ';
    }


    echo '
                    </tbody>

                </table>

            </div>

        </div>
    ';
}


/*
|--------------------------------------------------------------------------
| Main renderer
|--------------------------------------------------------------------------
*/

function renderDynamicNoticeContent(
    PDO $pdo,
    array $notice
): void {

    $type =
        (string) (
            $notice[
                'notice_type'
            ]
            ?? 'Text'
        );


    /*
    |--------------------------------------------------------------------------
    | Text
    |--------------------------------------------------------------------------
    */

    if ($type === 'Text') {
        return;
    }


    /*
    |--------------------------------------------------------------------------
    | Link
    |--------------------------------------------------------------------------
    */

    if ($type === 'Link') {

        $url =
            trim(
                (string) (
                    $notice[
                        'notice_link'
                    ]
                    ?? ''
                )
            );


        if ($url !== '') {

            echo '
                <div class="mt-3">

                    <a
                        href="'
                        . dynamicNoticeE(
                            $url
                        )
                        . '"
                        class="btn
                               btn-primary
                               btn-sm"
                        target="_blank"
                        rel="noopener"
                    >

                        <i
                            class="bi
                                   bi-box-arrow-up-right
                                   me-1"
                        ></i>

                        Open Link

                    </a>

                </div>
            ';
        }

        return;
    }


    /*
    |--------------------------------------------------------------------------
    | Database-rendered types
    |--------------------------------------------------------------------------
    */

    $meritCallId =
        (int) (
            $notice[
                'merit_call_id'
            ]
            ?? 0
        );


    if ($meritCallId <= 0) {

        echo '
            <div class="alert alert-warning mt-3 mb-0">
                This notice is not connected to a Merit Call.
            </div>
        ';

        return;
    }


    $departmentId =
        !empty(
            $notice[
                'dept_id'
            ]
        )
            ? (int)
              $notice[
                  'dept_id'
              ]
            : null;


    if (
        $type
        === 'Merit List'
    ) {

        renderDatabaseMeritList(
            $pdo,
            $meritCallId,
            $departmentId
        );

        return;
    }


    if (
        $type
        === 'Waiting List'
    ) {

        renderDatabaseWaitingList(
            $pdo,
            $meritCallId,
            $departmentId
        );

        return;
    }


    if (
        $type
        === 'Department Vacancy'
    ) {

        renderDatabaseDepartmentVacancy(
            $pdo,
            $meritCallId,
            $departmentId
        );
    }
}

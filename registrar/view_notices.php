<?php

declare(strict_types=1);

$pageTitle = 'Manage Notices';

require_once '../includes/auth.php';
require_once '../config/db.php';

requireLogin('registrar');

$pdo->setAttribute(
    PDO::ATTR_ERRMODE,
    PDO::ERRMODE_EXCEPTION
);

date_default_timezone_set('Asia/Dhaka');


/*
|--------------------------------------------------------------------------
| Filters
|--------------------------------------------------------------------------
*/

$dept_id =
    (int) ($_GET['dept_id'] ?? 0);

$q =
    trim(
        (string) ($_GET['q'] ?? '')
    );

$typeFilter =
    trim(
        (string) ($_GET['notice_type'] ?? '')
    );


$noticeTypes = [
    'Text',
    'Link',
    'Merit List',
    'Waiting List',
    'Department Vacancy',
];


/*
|--------------------------------------------------------------------------
| Helpers
|--------------------------------------------------------------------------
*/

function noticeE(mixed $value): string
{
    return htmlspecialchars(
        (string) ($value ?? ''),
        ENT_QUOTES,
        'UTF-8'
    );
}


function noticeShort(
    mixed $text,
    int $length = 50
): string {
    $text =
        (string) ($text ?? '');

    if (
        mb_strlen($text)
        > $length
    ) {
        return
            mb_substr(
                $text,
                0,
                $length
            )
            . '...';
    }

    return $text;
}


function noticeBadge(
    string $type
): string {
    return match ($type) {
        'Link' =>
            'bg-primary',

        'Merit List' =>
            'bg-success',

        'Waiting List' =>
            'bg-warning text-dark',

        'Department Vacancy' =>
            'bg-info text-dark',

        default =>
            'bg-secondary',
    };
}


function noticeSafeLink(
    string $url
): ?string {
    $url =
        trim($url);

    if ($url === '') {
        return null;
    }

    /*
     * Internal project URL.
     */
    if (
        str_starts_with(
            $url,
            '/'
        )
    ) {
        return $url;
    }

    /*
     * Full external http/https URL.
     */
    if (
        filter_var(
            $url,
            FILTER_VALIDATE_URL
        )
        !== false
    ) {
        $scheme =
            strtolower(
                (string)
                parse_url(
                    $url,
                    PHP_URL_SCHEME
                )
            );

        if (
            in_array(
                $scheme,
                [
                    'http',
                    'https'
                ],
                true
            )
        ) {
            return $url;
        }
    }

    throw new RuntimeException(
        'Enter a valid link beginning with /, http://, or https://.'
    );
}


/*
|--------------------------------------------------------------------------
| Load Merit Calls
|--------------------------------------------------------------------------
|
| These are used only when the Registrar chooses:
|   Merit List
|   Waiting List
|   Department Vacancy
|--------------------------------------------------------------------------
*/

$meritCalls =
    $pdo->query("
        SELECT
            merit_call_id,
            call_number,
            call_title,
            academic_year,
            published_at
        FROM merit_calls
        ORDER BY
            academic_year DESC,
            call_number DESC
    ")->fetchAll(
        PDO::FETCH_ASSOC
    );


/*
|--------------------------------------------------------------------------
| Actions
|--------------------------------------------------------------------------
*/

$message = '';
$messageType = 'success';


if (
    $_SERVER['REQUEST_METHOD']
    === 'POST'
) {

    try {

        /*
        |--------------------------------------------------------------------------
        | Delete
        |--------------------------------------------------------------------------
        */

        if (
            isset(
                $_POST['delete']
            )
        ) {

            $noticeId =
                (int) (
                    $_POST['notice_id']
                    ?? 0
                );


            if ($noticeId <= 0) {
                throw new RuntimeException(
                    'Invalid notice id.'
                );
            }


            $stmt =
                $pdo->prepare("
                    DELETE FROM admission_notices
                    WHERE notice_id = ?
                ");


            $stmt->execute([
                $noticeId
            ]);


            if (
                $stmt->rowCount()
                !== 1
            ) {
                throw new RuntimeException(
                    'Notice was not found.'
                );
            }


            $message =
                'Notice deleted successfully.';
        }


        /*
        |--------------------------------------------------------------------------
        | Create Notice
        |--------------------------------------------------------------------------
        */

        elseif (
            isset(
                $_POST['create']
            )
        ) {

            $type =
                trim(
                    (string) (
                        $_POST[
                            'notice_type'
                        ]
                        ?? 'Text'
                    )
                );


            if (
                !in_array(
                    $type,
                    $noticeTypes,
                    true
                )
            ) {
                throw new RuntimeException(
                    'Invalid notice type.'
                );
            }


            $title =
                trim(
                    (string) (
                        $_POST['title']
                        ?? ''
                    )
                );


            $body =
                trim(
                    (string) (
                        $_POST['body']
                        ?? ''
                    )
                );


            $deptIdPost =
                (
                    ($_POST['dept_id'] ?? '')
                    !== ''
                )
                    ? (int)
                      $_POST['dept_id']
                    : null;


            $startDate =
                trim(
                    (string) (
                        $_POST['start_date']
                        ?? ''
                    )
                );


            $endDate =
                trim(
                    (string) (
                        $_POST['end_date']
                        ?? ''
                    )
                );


            $endDate =
                $endDate !== ''
                    ? $endDate
                    : null;


            $noticeLink = null;
            $meritCallId = null;
            $selectedCall = null;


            /*
            |--------------------------------------------------------------------------
            | TEXT
            |--------------------------------------------------------------------------
            */

            if ($type === 'Text') {

                if ($body === '') {
                    throw new RuntimeException(
                        'Notice Body is required for a Text notice.'
                    );
                }
            }


            /*
            |--------------------------------------------------------------------------
            | LINK
            |--------------------------------------------------------------------------
            */

            elseif ($type === 'Link') {

                if ($body === '') {
                    throw new RuntimeException(
                        'Notice Body is required for a Link notice.'
                    );
                }


                $noticeLink =
                    noticeSafeLink(
                        (string) (
                            $_POST[
                                'notice_link'
                            ]
                            ?? ''
                        )
                    );


                if ($noticeLink === null) {
                    throw new RuntimeException(
                        'Link URL is required.'
                    );
                }
            }


            /*
            |--------------------------------------------------------------------------
            | DATABASE NOTICE TYPES
            |--------------------------------------------------------------------------
            */

            else {

                $meritCallId =
                    (int) (
                        $_POST[
                            'merit_call_id'
                        ]
                        ?? 0
                    );


                if ($meritCallId <= 0) {
                    throw new RuntimeException(
                        'Select a Merit Call.'
                    );
                }


                $callStmt =
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


                $callStmt->execute([
                    $meritCallId
                ]);


                $selectedCall =
                    $callStmt->fetch(
                        PDO::FETCH_ASSOC
                    );


                if (!$selectedCall) {
                    throw new RuntimeException(
                        'Selected Merit Call was not found.'
                    );
                }


                /*
                 * Auto title/body keeps publishing easy.
                 */

                if ($title === '') {

                    if (
                        $type
                        === 'Merit List'
                    ) {
                        $title =
                            $selectedCall[
                                'call_title'
                            ]
                            . ' Merit List — '
                            . $selectedCall[
                                'academic_year'
                            ];
                    }

                    elseif (
                        $type
                        === 'Waiting List'
                    ) {
                        $title =
                            'Waiting List — '
                            . $selectedCall[
                                'academic_year'
                            ];
                    }

                    else {
                        $title =
                            'Department-wise Vacancy — '
                            . $selectedCall[
                                'academic_year'
                            ];
                    }
                }


                if ($body === '') {

                    if (
                        $type
                        === 'Merit List'
                    ) {
                        $body =
                            'The '
                            . $selectedCall[
                                'call_title'
                            ]
                            . ' Merit List has been published.';
                    }

                    elseif (
                        $type
                        === 'Waiting List'
                    ) {
                        $body =
                            'The current Waiting List has been published.';
                    }

                    else {
                        $body =
                            'The current department-wise seat vacancy information has been published.';
                    }
                }
            }


            /*
            |--------------------------------------------------------------------------
            | Common validation
            |--------------------------------------------------------------------------
            */

            if ($title === '') {
                throw new RuntimeException(
                    'Notice Title is required.'
                );
            }


            if ($startDate === '') {

                $startDate =
                    date('Y-m-d');
            }


            if (
                $endDate !== null
                &&
                $endDate < $startDate
            ) {
                throw new RuntimeException(
                    'End Date cannot be before Start Date.'
                );
            }


            $publishedBy =
                (int) (
                    $_SESSION['admin_id']
                    ?? $_SESSION['user_id']
                    ?? 0
                );


            $stmt =
                $pdo->prepare("
                    INSERT INTO admission_notices (
                        dept_id,
                        notice_title,
                        notice_body,
                        notice_type,
                        notice_link,
                        merit_call_id,
                        admission_start_date,
                        admission_end_date,
                        published_by,
                        published_at
                    )
                    VALUES (
                        ?,
                        ?,
                        ?,
                        ?,
                        ?,
                        ?,
                        ?,
                        ?,
                        ?,
                        NOW()
                    )
                ");


            $stmt->execute([
                $deptIdPost,
                $title,
                $body,
                $type,
                $noticeLink,
                $meritCallId,
                $startDate,
                $endDate,
                $publishedBy > 0
                    ? $publishedBy
                    : null
            ]);


            $message =
                $type
                . ' notice published successfully!';
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
| Build filters
|--------------------------------------------------------------------------
*/

$where = [];
$params = [];


if ($dept_id > 0) {

    $where[] =
        'n.dept_id = ?';

    $params[] =
        $dept_id;
}


if (
    $typeFilter !== ''
    &&
    in_array(
        $typeFilter,
        $noticeTypes,
        true
    )
) {

    $where[] =
        'n.notice_type = ?';

    $params[] =
        $typeFilter;
}


if ($q !== '') {

    $where[] =
        '(
            n.notice_title LIKE ?
            OR n.notice_body LIKE ?
        )';

    $params[] =
        '%' . $q . '%';

    $params[] =
        '%' . $q . '%';
}


$whereSql =
    $where
        ? (
            'WHERE '
            . implode(
                ' AND ',
                $where
            )
          )
        : '';


/*
|--------------------------------------------------------------------------
| Fetch notices
|--------------------------------------------------------------------------
*/

$stmt =
    $pdo->prepare("
        SELECT
            n.*,
            d.dept_name,
            mc.call_title,
            mc.academic_year

        FROM admission_notices n

        LEFT JOIN departments d
            ON d.dept_id =
               n.dept_id

        LEFT JOIN merit_calls mc
            ON mc.merit_call_id =
               n.merit_call_id

        {$whereSql}

        ORDER BY
            n.published_at DESC,
            n.admission_start_date DESC,
            n.notice_id DESC
    ");


$stmt->execute(
    $params
);


$notices =
    $stmt->fetchAll(
        PDO::FETCH_ASSOC
    );


/*
|--------------------------------------------------------------------------
| Departments
|--------------------------------------------------------------------------
*/

$departments =
    $pdo->query("
        SELECT
            dept_id,
            dept_name
        FROM departments
        ORDER BY dept_name
    ")->fetchAll(
        PDO::FETCH_ASSOC
    );


/*
|--------------------------------------------------------------------------
| UI
|--------------------------------------------------------------------------
*/

require_once '../includes/header.php';
require_once '../includes/navbar.php';

?>


<div class="container py-4">


    <div
        class="d-flex
               justify-content-between
               align-items-center
               mb-4"
    >

        <h2>

            <i
                class="bi
                       bi-megaphone
                       text-primary
                       me-2"
            ></i>

            Admission Notices

        </h2>


        <button
            class="btn btn-primary"
            data-bs-toggle="modal"
            data-bs-target="#createNoticeModal"
        >

            <i class="bi bi-plus-circle me-1"></i>

            New Notice

        </button>

    </div>


    <?php if ($message !== ''): ?>

        <div
            class="alert
                   alert-<?= noticeE(
                       $messageType
                   ) ?>
                   alert-dismissible
                   fade
                   show"
        >

            <?= noticeE(
                $message
            ) ?>

            <button
                type="button"
                class="btn-close"
                data-bs-dismiss="alert"
            ></button>

        </div>

    <?php endif; ?>


    <!-- ================================================================
         FILTERS
    ================================================================= -->

    <div class="card shadow-sm mb-4">

        <div
            class="card-header
                   bg-light
                   fw-semibold"
        >

            <i class="bi bi-funnel"></i>

            Filter

        </div>


        <div class="card-body">

            <form
                method="GET"
                class="row g-3"
            >


                <div class="col-md-3">

                    <label class="form-label">

                        Department

                    </label>


                    <select
                        name="dept_id"
                        class="form-select"
                    >

                        <option value="0">

                            All Departments

                        </option>


                        <?php foreach (
                            $departments as $d
                        ): ?>

                            <option
                                value="<?= (int)
                                    $d[
                                        'dept_id'
                                    ]
                                ?>"
                                <?= $dept_id
                                    ===
                                    (int)
                                    $d[
                                        'dept_id'
                                    ]
                                        ? 'selected'
                                        : ''
                                ?>
                            >

                                <?= noticeE(
                                    $d[
                                        'dept_name'
                                    ]
                                ) ?>

                            </option>

                        <?php endforeach; ?>

                    </select>

                </div>


                <div class="col-md-3">

                    <label class="form-label">

                        Notice Type

                    </label>


                    <select
                        name="notice_type"
                        class="form-select"
                    >

                        <option value="">

                            All Types

                        </option>


                        <?php foreach (
                            $noticeTypes as $type
                        ): ?>

                            <option
                                value="<?= noticeE(
                                    $type
                                ) ?>"
                                <?= $typeFilter
                                    === $type
                                        ? 'selected'
                                        : ''
                                ?>
                            >

                                <?= noticeE(
                                    $type
                                ) ?>

                            </option>

                        <?php endforeach; ?>

                    </select>

                </div>


                <div class="col-md-4">

                    <label class="form-label">

                        Search

                    </label>


                    <input
                        type="text"
                        name="q"
                        value="<?= noticeE(
                            $q
                        ) ?>"
                        class="form-control"
                        placeholder="Title or body keywords"
                    >

                </div>


                <div
                    class="col-md-2
                           d-flex
                           align-items-end
                           gap-2"
                >

                    <button
                        class="btn
                               btn-primary
                               w-100"
                    >

                        <i class="bi bi-search"></i>

                        Apply

                    </button>


                    <a
                        href="view_notices.php"
                        class="btn
                               btn-outline-secondary"
                        title="Reset"
                    >

                        <i class="bi bi-x-circle"></i>

                    </a>

                </div>


            </form>

        </div>

    </div>


    <!-- ================================================================
         NOTICES TABLE
    ================================================================= -->

    <div class="card shadow-sm">

        <div
            class="card-header
                   bg-secondary
                   text-white
                   d-flex
                   justify-content-between
                   align-items-center"
        >

            <span>

                <i class="bi bi-table"></i>

                Notices

            </span>


            <span class="small">

                Total:
                <?= count(
                    $notices
                ) ?>

            </span>

        </div>


        <div class="table-responsive">

            <table
                class="table
                       table-hover
                       mb-0
                       align-middle"
            >

                <thead class="table-light">

                    <tr>

                        <th style="width:30%;">
                            Title
                        </th>

                        <th>
                            Type
                        </th>

                        <th>
                            Department
                        </th>

                        <th>
                            Reference
                        </th>

                        <th>
                            Admission Period
                        </th>

                        <th>
                            Published
                        </th>

                        <th></th>

                    </tr>

                </thead>


                <tbody>


                <?php if (
                    empty(
                        $notices
                    )
                ): ?>

                    <tr>

                        <td
                            colspan="7"
                            class="text-center
                                   py-4
                                   text-muted"
                        >

                            No notices found

                        </td>

                    </tr>

                <?php endif; ?>


                <?php foreach (
                    $notices as $n
                ): ?>

                    <?php
                    $type =
                        (string) (
                            $n['notice_type']
                            ?? 'Text'
                        );
                    ?>

                    <tr>


                        <td>

                            <div class="fw-bold">

                                <?= noticeE(
                                    noticeShort(
                                        $n[
                                            'notice_title'
                                        ],
                                        70
                                    )
                                ) ?>

                            </div>


                            <div
                                class="small
                                       text-muted"
                            >

                                <?= noticeE(
                                    noticeShort(
                                        $n[
                                            'notice_body'
                                        ],
                                        110
                                    )
                                ) ?>

                            </div>

                        </td>


                        <td>

                            <span
                                class="badge
                                       <?= noticeE(
                                           noticeBadge(
                                               $type
                                           )
                                       ) ?>"
                            >

                                <?= noticeE(
                                    $type
                                ) ?>

                            </span>

                        </td>


                        <td>

                            <?php if (
                                !empty(
                                    $n[
                                        'dept_id'
                                    ]
                                )
                            ): ?>

                                <?= noticeE(
                                    $n[
                                        'dept_name'
                                    ]
                                    ?? '—'
                                ) ?>

                            <?php else: ?>

                                <span
                                    class="badge
                                           bg-secondary"
                                >

                                    All Depts

                                </span>

                            <?php endif; ?>

                        </td>


                        <td class="small">

                            <?php if (
                                $type
                                === 'Link'
                            ): ?>

                                <a
                                    href="<?= noticeE(
                                        $n[
                                            'notice_link'
                                        ]
                                    ) ?>"
                                    target="_blank"
                                    rel="noopener"
                                    class="btn
                                           btn-sm
                                           btn-outline-primary"
                                >

                                    Open Link

                                </a>


                            <?php elseif (
                                in_array(
                                    $type,
                                    [
                                        'Merit List',
                                        'Waiting List',
                                        'Department Vacancy'
                                    ],
                                    true
                                )
                            ): ?>

                                <strong>

                                    <?= noticeE(
                                        $n[
                                            'call_title'
                                        ]
                                        ?? 'Merit Call'
                                    ) ?>

                                </strong>

                                <br>

                                <span class="text-muted">

                                    <?= noticeE(
                                        $n[
                                            'academic_year'
                                        ]
                                        ?? ''
                                    ) ?>

                                </span>


                            <?php else: ?>

                                —

                            <?php endif; ?>

                        </td>


                        <td class="small">

                            <?= !empty(
                                $n[
                                    'admission_start_date'
                                ]
                            )
                                ? date(
                                    'd M Y',
                                    strtotime(
                                        (string)
                                        $n[
                                            'admission_start_date'
                                        ]
                                    )
                                  )
                                : '—'
                            ?>


                            <?php if (
                                !empty(
                                    $n[
                                        'admission_end_date'
                                    ]
                                )
                            ): ?>

                                <br>

                                <span class="text-muted">

                                    to

                                </span>

                                <?= date(
                                    'd M Y',
                                    strtotime(
                                        (string)
                                        $n[
                                            'admission_end_date'
                                        ]
                                    )
                                ) ?>

                            <?php endif; ?>

                        </td>


                        <td class="small">

                            <?php if (
                                !empty(
                                    $n[
                                        'published_at'
                                    ]
                                )
                            ): ?>

                                <span
                                    class="badge
                                           bg-success"
                                >

                                    Published

                                </span>

                                <br>

                                <?= date(
                                    'd M Y, h:i A',
                                    strtotime(
                                        (string)
                                        $n[
                                            'published_at'
                                        ]
                                    )
                                ) ?>

                            <?php else: ?>

                                <span
                                    class="badge
                                           bg-warning
                                           text-dark"
                                >

                                    Draft

                                </span>

                            <?php endif; ?>

                        </td>


                        <td>

                            <form
                                method="POST"
                                onsubmit="
                                    return confirm(
                                        'Delete this notice?'
                                    );
                                "
                            >

                                <input
                                    type="hidden"
                                    name="notice_id"
                                    value="<?= (int)
                                        $n[
                                            'notice_id'
                                        ]
                                    ?>"
                                >

                                <input
                                    type="hidden"
                                    name="delete"
                                    value="1"
                                >


                                <button
                                    class="btn
                                           btn-sm
                                           btn-outline-danger"
                                    title="Delete"
                                >

                                    <i class="bi bi-trash"></i>

                                </button>

                            </form>

                        </td>


                    </tr>

                <?php endforeach; ?>


                </tbody>

            </table>

        </div>

    </div>


</div>


<!-- ================================================================
     CREATE NOTICE MODAL
================================================================= -->

<div
    class="modal fade"
    id="createNoticeModal"
    tabindex="-1"
>

    <div class="modal-dialog modal-lg">

        <div class="modal-content">


            <div
                class="modal-header
                       bg-primary
                       text-white"
            >

                <h5 class="modal-title">

                    <i
                        class="bi
                               bi-plus-circle
                               me-2"
                    ></i>

                    New Admission Notice

                </h5>


                <button
                    type="button"
                    class="btn-close
                           btn-close-white"
                    data-bs-dismiss="modal"
                ></button>

            </div>


            <form method="POST">


                <div class="modal-body">


                    <input
                        type="hidden"
                        name="create"
                        value="1"
                    >


                    <!-- NOTICE TYPE -->

                    <div class="mb-3">

                        <label
                            class="form-label
                                   fw-bold"
                        >

                            Notice Type

                            <span class="text-danger">
                                *
                            </span>

                        </label>


                        <select
                            name="notice_type"
                            id="noticeType"
                            class="form-select"
                            required
                        >

                            <option value="Text">
                                Text Notice
                            </option>

                            <option value="Link">
                                Link Notice
                            </option>

                            <option value="Merit List">
                                Merit List
                            </option>

                            <option value="Waiting List">
                                Waiting List
                            </option>

                            <option value="Department Vacancy">
                                Department Vacancy
                            </option>

                        </select>

                    </div>


                    <!-- TITLE -->

                    <div class="mb-3">

                        <label
                            class="form-label
                                   fw-bold"
                        >

                            Title

                            <span
                                id="titleOptional"
                                class="text-muted
                                       fw-normal"
                                style="display:none;"
                            >

                                (optional — generated automatically)

                            </span>

                        </label>


                        <input
                            type="text"
                            name="title"
                            id="noticeTitle"
                            class="form-control"
                        >

                    </div>


                    <!-- DEPARTMENT -->

                    <div class="mb-3">

                        <label
                            class="form-label
                                   fw-bold"
                        >

                            Department

                        </label>


                        <select
                            name="dept_id"
                            class="form-select"
                        >

                            <option value="">

                                All Departments

                            </option>


                            <?php foreach (
                                $departments as $dept
                            ): ?>

                                <option
                                    value="<?= (int)
                                        $dept[
                                            'dept_id'
                                        ]
                                    ?>"
                                >

                                    <?= noticeE(
                                        $dept[
                                            'dept_name'
                                        ]
                                    ) ?>

                                </option>

                            <?php endforeach; ?>

                        </select>

                    </div>


                    <!-- MERIT CALL -->

                    <div
                        class="mb-3"
                        id="meritCallGroup"
                        style="display:none;"
                    >

                        <label
                            class="form-label
                                   fw-bold"
                        >

                            Merit Call

                            <span class="text-danger">
                                *
                            </span>

                        </label>


                        <select
                            name="merit_call_id"
                            id="meritCallId"
                            class="form-select"
                        >

                            <option value="">

                                Select Merit Call

                            </option>


                            <?php foreach (
                                $meritCalls as $call
                            ): ?>

                                <option
                                    value="<?= (int)
                                        $call[
                                            'merit_call_id'
                                        ]
                                    ?>"
                                >

                                    <?= noticeE(
                                        $call[
                                            'call_title'
                                        ]
                                    ) ?>

                                    —

                                    <?= noticeE(
                                        $call[
                                            'academic_year'
                                        ]
                                    ) ?>

                                </option>

                            <?php endforeach; ?>

                        </select>


                        <div
                            class="form-text"
                            id="databaseHelp"
                        >

                            The Notice Board will fetch the data
                            directly from the database.

                        </div>

                    </div>


                    <!-- LINK -->

                    <div
                        class="mb-3"
                        id="linkGroup"
                        style="display:none;"
                    >

                        <label
                            class="form-label
                                   fw-bold"
                        >

                            Link URL

                            <span class="text-danger">
                                *
                            </span>

                        </label>


                        <input
                            type="text"
                            name="notice_link"
                            id="noticeLink"
                            class="form-control"
                            placeholder="/jkkniu-admission/... or https://..."
                        >

                    </div>


                    <!-- DATES -->

                    <div class="row">


                        <div class="col-md-6 mb-3">

                            <label
                                class="form-label
                                       fw-bold"
                            >

                                Start Date

                            </label>


                            <input
                                type="date"
                                name="start_date"
                                class="form-control"
                                value="<?= date(
                                    'Y-m-d'
                                ) ?>"
                            >

                        </div>


                        <div class="col-md-6 mb-3">

                            <label class="form-label">

                                End Date
                                (optional)

                            </label>


                            <input
                                type="date"
                                name="end_date"
                                class="form-control"
                            >

                        </div>


                    </div>


                    <!-- BODY -->

                    <div
                        class="mb-3"
                        id="bodyGroup"
                    >

                        <label
                            class="form-label
                                   fw-bold"
                        >

                            Notice Body

                            <span
                                id="bodyOptional"
                                class="text-danger"
                            >
                                *
                            </span>

                        </label>


                        <textarea
                            name="body"
                            id="noticeBody"
                            class="form-control"
                            rows="5"
                        ></textarea>


                        <div
                            class="form-text"
                            id="bodyHelp"
                        ></div>

                    </div>


                </div>


                <div class="modal-footer">


                    <button
                        type="button"
                        class="btn btn-secondary"
                        data-bs-dismiss="modal"
                    >

                        Cancel

                    </button>


                    <button
                        type="submit"
                        class="btn btn-primary"
                    >

                        <i
                            class="bi
                                   bi-check-circle
                                   me-1"
                        ></i>

                        Publish Notice

                    </button>


                </div>


            </form>


        </div>

    </div>

</div>


<script>

document.addEventListener(
    'DOMContentLoaded',
    function () {

        const type =
            document.getElementById(
                'noticeType'
            );

        const callGroup =
            document.getElementById(
                'meritCallGroup'
            );

        const callInput =
            document.getElementById(
                'meritCallId'
            );

        const linkGroup =
            document.getElementById(
                'linkGroup'
            );

        const linkInput =
            document.getElementById(
                'noticeLink'
            );

        const title =
            document.getElementById(
                'noticeTitle'
            );

        const titleOptional =
            document.getElementById(
                'titleOptional'
            );

        const body =
            document.getElementById(
                'noticeBody'
            );

        const bodyOptional =
            document.getElementById(
                'bodyOptional'
            );

        const bodyHelp =
            document.getElementById(
                'bodyHelp'
            );


        function updateForm() {

            const value =
                type.value;


            const databaseType =
                value === 'Merit List'
                ||
                value === 'Waiting List'
                ||
                value === 'Department Vacancy';


            /*
             * Merit Call selector
             */

            callGroup.style.display =
                databaseType
                    ? ''
                    : 'none';

            callInput.required =
                databaseType;


            /*
             * Link
             */

            const linkType =
                value === 'Link';

            linkGroup.style.display =
                linkType
                    ? ''
                    : 'none';

            linkInput.required =
                linkType;


            /*
             * Title
             */

            title.required =
                !databaseType;

            titleOptional.style.display =
                databaseType
                    ? ''
                    : 'none';


            /*
             * Body
             */

            if (databaseType) {

                body.required =
                    false;

                bodyOptional.textContent =
                    '(optional — generated automatically)';

                bodyOptional.className =
                    'text-muted fw-normal';

                bodyHelp.textContent =
                    'The actual list/vacancy information is not typed here. It will be fetched from the database when the notice is viewed.';

            } else {

                body.required =
                    true;

                bodyOptional.textContent =
                    '*';

                bodyOptional.className =
                    'text-danger';

                bodyHelp.textContent =
                    '';
            }
        }


        type.addEventListener(
            'change',
            updateForm
        );


        updateForm();

    }
);

</script>


<?php require_once '../includes/footer.php'; ?>

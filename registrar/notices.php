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

function isMeritProcessNotice(array $notice): bool
{
    $title = strtolower(
        trim((string) ($notice['notice_title'] ?? ''))
    );

    return
        str_contains($title, 'merit call')
        || str_contains($title, 'merit process');
}


/*
|--------------------------------------------------------------------------
| Flash message
|--------------------------------------------------------------------------
*/

$message = '';
$messageType = 'success';


/*
|--------------------------------------------------------------------------
| Handle actions
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    try {

        /*
        |--------------------------------------------------------------------------
        | Delete
        |--------------------------------------------------------------------------
        */

        if (isset($_POST['delete'])) {

            $noticeId =
                (int) ($_POST['notice_id'] ?? 0);

            if ($noticeId <= 0) {
                throw new RuntimeException(
                    'Invalid notice id.'
                );
            }


            $stmt = $pdo->prepare("
                DELETE FROM admission_notices
                WHERE notice_id = ?
            ");

            $stmt->execute([
                $noticeId
            ]);


            if ($stmt->rowCount() !== 1) {
                throw new RuntimeException(
                    'Notice was not found or could not be deleted.'
                );
            }


            $message =
                'Notice deleted successfully.';
        }


        /*
        |--------------------------------------------------------------------------
        | Create manual notice
        |--------------------------------------------------------------------------
        */

        elseif (isset($_POST['create'])) {

            $title =
                trim(
                    (string) ($_POST['title'] ?? '')
                );

            $body =
                trim(
                    (string) ($_POST['body'] ?? '')
                );

            $deptId =
                (($_POST['dept_id'] ?? '') !== '')
                    ? (int) $_POST['dept_id']
                    : null;

            $startDate =
                trim(
                    (string) ($_POST['start_date'] ?? '')
                );

            $endDate =
                trim(
                    (string) ($_POST['end_date'] ?? '')
                );

            $endDate =
                $endDate !== ''
                    ? $endDate
                    : null;


            if (
                $title === ''
                || $body === ''
                || $startDate === ''
            ) {
                throw new RuntimeException(
                    'Please fill Title, Body, and Start Date.'
                );
            }


            if (
                $endDate !== null
                && $endDate < $startDate
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


            $stmt = $pdo->prepare("
                INSERT INTO admission_notices (
                    dept_id,
                    notice_title,
                    notice_body,
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
                    NOW()
                )
            ");


            $stmt->execute([
                $deptId,
                $title,
                $body,
                $startDate,
                $endDate,
                $publishedBy > 0
                    ? $publishedBy
                    : null
            ]);


            $message =
                'Notice published successfully!';
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
| Fetch notices
|--------------------------------------------------------------------------
|
| Automatic Merit Call / Merit Process notices inserted by the Merit Round
| workflow use this same table, so they appear here automatically.
|--------------------------------------------------------------------------
*/

$notices =
    $pdo->query("
        SELECT
            n.*,
            d.dept_name

        FROM admission_notices n

        LEFT JOIN departments d
            ON n.dept_id = d.dept_id

        ORDER BY
            n.published_at DESC,
            n.admission_start_date DESC
    ")->fetchAll(PDO::FETCH_ASSOC);


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
    ")->fetchAll(PDO::FETCH_ASSOC);


/*
|--------------------------------------------------------------------------
| Load HTML only after all possible redirects/actions
|--------------------------------------------------------------------------
*/

require_once '../includes/header.php';
require_once '../includes/navbar.php';

?>

<div class="container py-4">


    <div class="d-flex justify-content-between align-items-center mb-4">

        <div>

            <h2 class="mb-1">

                <i class="bi bi-megaphone text-primary me-2"></i>

                Admission Notices

            </h2>


            <div class="small text-muted">

                Merit Call process notices published by the Registrar
                workflow also appear here automatically.

            </div>

        </div>


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
                   alert-<?= noticeE($messageType) ?>
                   alert-dismissible
                   fade
                   show"
            role="alert"
        >

            <?= noticeE($message) ?>

            <button
                type="button"
                class="btn-close"
                data-bs-dismiss="alert"
            ></button>

        </div>

    <?php endif; ?>


    <!-- ================================================================
         NOTICES TABLE
    ================================================================= -->

    <div class="card shadow-sm">

        <div class="table-responsive">

            <table class="table table-hover mb-0">

                <thead class="table-dark">

                    <tr>

                        <th>Title</th>

                        <th>Type</th>

                        <th>Department</th>

                        <th>Period</th>

                        <th>Published</th>

                        <th>Actions</th>

                    </tr>

                </thead>


                <tbody>


                <?php if (empty($notices)): ?>

                    <tr>

                        <td
                            colspan="6"
                            class="text-center
                                   py-4
                                   text-muted"
                        >

                            No notices yet

                        </td>

                    </tr>

                <?php endif; ?>


                <?php foreach ($notices as $notice): ?>

                    <?php
                    $meritNotice =
                        isMeritProcessNotice(
                            $notice
                        );
                    ?>

                    <tr>


                        <td>

                            <strong>

                                <?= noticeE(
                                    mb_strimwidth(
                                        (string) $notice['notice_title'],
                                        0,
                                        60,
                                        '...'
                                    )
                                ) ?>

                            </strong>


                            <?php if ($meritNotice): ?>

                                <div class="small text-muted mt-1">

                                    Generated from the Merit Call process.

                                </div>

                            <?php endif; ?>

                        </td>


                        <td>

                            <?php if ($meritNotice): ?>

                                <span class="badge bg-primary">

                                    <i class="bi bi-award-fill me-1"></i>

                                    Merit Process

                                </span>

                            <?php else: ?>

                                <span class="badge bg-secondary">

                                    General

                                </span>

                            <?php endif; ?>

                        </td>


                        <td>

                            <?php if (!empty($notice['dept_id'])): ?>

                                <?= noticeE(
                                    $notice['dept_name']
                                    ?? '—'
                                ) ?>

                            <?php else: ?>

                                <span class="badge bg-secondary">

                                    All Depts

                                </span>

                            <?php endif; ?>

                        </td>


                        <td>

                            <?= !empty(
                                $notice['admission_start_date']
                            )
                                ? noticeE(
                                    date(
                                        'M j, Y',
                                        strtotime(
                                            (string)
                                            $notice['admission_start_date']
                                        )
                                    )
                                )
                                : '—'
                            ?>


                            <?php if (
                                !empty(
                                    $notice['admission_end_date']
                                )
                            ): ?>

                                &nbsp;—&nbsp;

                                <?= noticeE(
                                    date(
                                        'M j, Y',
                                        strtotime(
                                            (string)
                                            $notice['admission_end_date']
                                        )
                                    )
                                ) ?>

                            <?php endif; ?>

                        </td>


                        <td>

                            <?= !empty(
                                $notice['published_at']
                            )
                                ? noticeE(
                                    date(
                                        'M j, H:i',
                                        strtotime(
                                            (string)
                                            $notice['published_at']
                                        )
                                    )
                                )
                                : '—'
                            ?>

                        </td>


                        <td>

                            <div
                                class="btn-group
                                       btn-group-sm"
                                role="group"
                            >


                                <a
                                    href="../registrar/view_notices.php?notice_id=<?= (int) $notice['notice_id'] ?>"
                                    class="btn btn-outline-primary"
                                    target="_blank"
                                    title="View"
                                >

                                    <i class="bi bi-eye"></i>

                                </a>


                                <form
                                    method="POST"
                                    style="display:inline;"
                                    onsubmit="return confirm(
                                        'Delete this notice?'
                                    );"
                                >

                                    <input
                                        type="hidden"
                                        name="notice_id"
                                        value="<?= (int) $notice['notice_id'] ?>"
                                    >

                                    <input
                                        type="hidden"
                                        name="delete"
                                        value="1"
                                    >


                                    <button
                                        type="submit"
                                        class="btn btn-outline-danger"
                                        title="Delete"
                                    >

                                        <i class="bi bi-trash"></i>

                                    </button>

                                </form>


                            </div>

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


            <div class="modal-header bg-primary text-white">

                <h5 class="modal-title">

                    <i class="bi bi-plus-circle me-2"></i>

                    New Admission Notice

                </h5>


                <button
                    type="button"
                    class="btn-close btn-close-white"
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


                    <div class="mb-3">

                        <label class="form-label fw-bold">

                            Title

                            <span class="text-danger">*</span>

                        </label>


                        <input
                            type="text"
                            name="title"
                            class="form-control"
                            required
                        >

                    </div>


                    <div class="mb-3">

                        <label class="form-label fw-bold">

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
                                    value="<?= (int) $dept['dept_id'] ?>"
                                >

                                    <?= noticeE(
                                        $dept['dept_name']
                                    ) ?>

                                </option>

                            <?php endforeach; ?>


                        </select>

                    </div>


                    <div class="row">


                        <div class="col-md-6 mb-3">

                            <label class="form-label fw-bold">

                                Start Date

                                <span class="text-danger">*</span>

                            </label>


                            <input
                                type="date"
                                name="start_date"
                                class="form-control"
                                required
                            >

                        </div>


                        <div class="col-md-6 mb-3">

                            <label class="form-label">

                                End Date (optional)

                            </label>


                            <input
                                type="date"
                                name="end_date"
                                class="form-control"
                            >

                        </div>


                    </div>


                    <div class="mb-3">

                        <label class="form-label fw-bold">

                            Notice Body

                            <span class="text-danger">*</span>

                        </label>


                        <textarea
                            name="body"
                            class="form-control"
                            rows="6"
                            required
                        ></textarea>

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

                        <i class="bi bi-check-circle me-1"></i>

                        Publish Notice

                    </button>


                </div>


            </form>


        </div>

    </div>

</div>


<?php require_once '../includes/footer.php'; ?>

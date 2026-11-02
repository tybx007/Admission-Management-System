<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Public Dynamic Notice Content Endpoint
|--------------------------------------------------------------------------
|
| Path:
|   api/notice_dynamic.php
|
| Loads ONLY the extra content for one published notice:
|   Link
|   Merit List
|   Waiting List
|   Department Vacancy
|--------------------------------------------------------------------------
*/

require_once '../config/db.php';
require_once '../includes/notice_dynamic_content.php';

$pdo->setAttribute(
    PDO::ATTR_ERRMODE,
    PDO::ERRMODE_EXCEPTION
);

$noticeId =
    (int) ($_GET['notice_id'] ?? 0);

if ($noticeId <= 0) {
    http_response_code(400);

    echo '
        <div class="alert alert-danger mb-0">
            Invalid notice.
        </div>
    ';

    exit;
}


try {

    $stmt =
        $pdo->prepare("
            SELECT
                n.*,
                d.dept_name

            FROM admission_notices n

            LEFT JOIN departments d
                ON d.dept_id = n.dept_id

            WHERE n.notice_id = ?
              AND n.published_at IS NOT NULL

            LIMIT 1
        ");


    $stmt->execute([
        $noticeId
    ]);


    $notice =
        $stmt->fetch(
            PDO::FETCH_ASSOC
        );


    if (!$notice) {
        http_response_code(404);

        echo '
            <div class="alert alert-warning mb-0">
                Notice was not found.
            </div>
        ';

        exit;
    }


    /*
    |--------------------------------------------------------------------------
    | Renderer decides what to show according to notice_type:
    |
    | Text
    |   -> nothing
    |
    | Link
    |   -> Open Link button
    |
    | Merit List
    |   -> fetch from merit_allocations
    |
    | Waiting List
    |   -> fetch from waiting_list
    |
    | Department Vacancy
    |   -> calculate from departments + merit_allocations
    |--------------------------------------------------------------------------
    */

    renderDynamicNoticeContent(
        $pdo,
        $notice
    );


} catch (Throwable $exception) {

    error_log(
        'Dynamic notice error: '
        . $exception->getMessage()
    );


    http_response_code(500);


    echo '
        <div class="alert alert-danger mb-0">
            Notice content could not be loaded.
        </div>
    ';
}

<?php

declare(strict_types=1);

$pageTitle =
    'JKKNIU Admission Management System';

require_once 'includes/auth.php';
require_once 'config/db.php';
require_once 'includes/admission_session.php';

/*
|--------------------------------------------------------------------------
| Redirect BEFORE HTML output
|--------------------------------------------------------------------------
*/

redirectIfLoggedIn();

date_default_timezone_set(
    'Asia/Dhaka'
);

$pdo->setAttribute(
    PDO::ATTR_ERRMODE,
    PDO::ERRMODE_EXCEPTION
);


$currentAcademicYear =
    admissionSessionCurrentYear(
        $pdo
    );


function publicE(mixed $value): string
{
    return htmlspecialchars(
        (string) ($value ?? ''),
        ENT_QUOTES,
        'UTF-8'
    );
}


/*
|--------------------------------------------------------------------------
| Latest published notices
|--------------------------------------------------------------------------
*/

$notices =
    $pdo->query("
        SELECT
            n.*,
            d.dept_name

        FROM admission_notices n

        LEFT JOIN departments d
            ON d.dept_id =
               n.dept_id

        ORDER BY
            n.published_at DESC

        LIMIT 10
    ")->fetchAll(
        PDO::FETCH_ASSOC
    );


/*
|--------------------------------------------------------------------------
| Real public totals
|--------------------------------------------------------------------------
|
| The homepage loads only a limited number of rows for display.
| These separate COUNT queries provide the real database totals.
|--------------------------------------------------------------------------
*/

$totalPublishedNotices =
    (int) $pdo->query("
        SELECT COUNT(*)
        FROM admission_notices
        WHERE published_at IS NOT NULL
    ")->fetchColumn();

$totalPublishedMeritCallsStmt =
    $pdo->prepare("
        SELECT COUNT(*)
        FROM merit_calls
        WHERE published_at IS NOT NULL
          AND academic_year = ?
    ");

$totalPublishedMeritCallsStmt->execute([
    $currentAcademicYear
]);

$totalPublishedMeritCalls =
    (int) $totalPublishedMeritCallsStmt->fetchColumn();


/*
|--------------------------------------------------------------------------
| Public Merit Calls
|--------------------------------------------------------------------------
*/

$meritCallsStmt =
    $pdo->prepare("
        SELECT
            merit_call_id,
            academic_year,
            call_number,
            call_title,
            registration_start,
            registration_deadline,
            published_at,
            processed_at

        FROM merit_calls

        WHERE published_at
              IS NOT NULL

          AND academic_year = ?

        ORDER BY
            call_number DESC

        LIMIT 6
    ");

$meritCallsStmt->execute([
    $currentAcademicYear
]);

$meritCalls =
    $meritCallsStmt->fetchAll(
        PDO::FETCH_ASSOC
    );


$timezone =
    new DateTimeZone(
        'Asia/Dhaka'
    );

$now =
    new DateTimeImmutable(
        'now',
        $timezone
    );


$currentCall = null;
$currentCallStatus = 'None';


/*
| Priority:
| 1. Open call
| 2. Nearest scheduled call
| 3. Latest closed call
*/

foreach ($meritCalls as $call) {

    $start =
        new DateTimeImmutable(
            $call[
                'registration_start'
            ],
            $timezone
        );

    $deadline =
        new DateTimeImmutable(
            $call[
                'registration_deadline'
            ],
            $timezone
        );


    if (
        $now >= $start
        &&
        $now <= $deadline
    ) {
        $currentCall =
            $call;

        $currentCallStatus =
            'Open';

        break;
    }
}


if (!$currentCall) {

    $scheduled =
        array_filter(
            $meritCalls,

            static function (
                array $call
            ) use ($now, $timezone): bool {

                $start =
                    new DateTimeImmutable(
                        $call[
                            'registration_start'
                        ],
                        $timezone
                    );

                return $now < $start;
            }
        );


    if ($scheduled) {

        usort(
            $scheduled,

            static function (
                array $a,
                array $b
            ): int {

                return
                    strcmp(
                        $a[
                            'registration_start'
                        ],
                        $b[
                            'registration_start'
                        ]
                    );
            }
        );


        $currentCall =
            $scheduled[0];

        $currentCallStatus =
            'Scheduled';

    } elseif ($meritCalls) {

        $currentCall =
            $meritCalls[0];

        $currentCallStatus =
            'Closed';
    }
}


$registrationIsOpen =
    $currentCall !== null
    &&
    $currentCallStatus ===
        'Open';


require_once 'includes/header.php';
require_once 'includes/navbar.php';

?>


<style>
:root {
    --home-navy: #1a3c5e;
    --home-blue: #2d6a9f;
    --home-gold: #f4b942;
    --home-soft: #f5f8fb;
}

body {
    background: var(--home-soft);
}

.home-hero {
    position: relative;
    overflow: hidden;
    background:
        radial-gradient(circle at 12% 18%, rgba(255,255,255,.14), transparent 28%),
        radial-gradient(circle at 88% 10%, rgba(244,185,66,.18), transparent 24%),
        linear-gradient(135deg, var(--home-navy), var(--home-blue));
}

.home-hero::before,
.home-hero::after {
    content: "";
    position: absolute;
    border-radius: 50%;
    border: 1px solid rgba(255,255,255,.12);
    pointer-events: none;
}

.home-hero::before {
    width: 340px;
    height: 340px;
    right: -120px;
    bottom: -210px;
}

.home-hero::after {
    width: 210px;
    height: 210px;
    right: 80px;
    bottom: -145px;
}

.hero-logo-box {
    width: 96px;
    height: 96px;
    display: grid;
    place-items: center;
    background: rgba(255,255,255,.97);
    border-radius: 22px;
    box-shadow: 0 14px 34px rgba(0,0,0,.18);
}

.hero-logo-box img {
    max-height: 76px;
    width: auto;
}

.live-status-card {
    background: rgba(255,255,255,.10);
    border: 1px solid rgba(255,255,255,.18);
    backdrop-filter: blur(8px);
    border-radius: 18px;
}

.portal-access-card {
    display: flex;
    align-items: center;
    gap: .65rem;
    min-width: 180px;
    padding: .8rem 1rem;
    text-decoration: none;
    color: #495057;
    background: #fff;
    border: 1px solid #e1e6ea;
    border-radius: 12px;
    box-shadow: 0 3px 12px rgba(0,0,0,.035);
    transition: transform .18s ease, box-shadow .18s ease, border-color .18s ease;
}

.portal-access-card:hover {
    color: #1a3c5e;
    border-color: #aac6de;
    transform: translateY(-2px);
    box-shadow: 0 8px 20px rgba(26,60,94,.08);
}

.portal-access-card.danger:hover {
    color: #dc3545;
    border-color: #f0aeb5;
}

.home-section-label {
    text-transform: uppercase;
    letter-spacing: .08em;
    font-size: .72rem;
    font-weight: 700;
    color: #6c757d;
}

.home-dynamic-card {
    border: 0 !important;
    border-radius: 16px !important;
    box-shadow: 0 8px 26px rgba(25,60,94,.07) !important;
    transition: transform .2s ease, box-shadow .2s ease;
}

.home-dynamic-card:hover {
    transform: translateY(-3px);
    box-shadow: 0 14px 32px rgba(25,60,94,.11) !important;
}

#meritCallCountdown > div > div {
    border-radius: 12px !important;
    border-color: #e5ebf0 !important;
    box-shadow: 0 3px 10px rgba(25,60,94,.035);
}

.notice-card {
    border: 1px solid #e5e9ed !important;
    border-radius: 14px !important;
    transition: transform .18s ease, box-shadow .18s ease, border-color .18s ease;
}

.notice-card:hover {
    transform: translateY(-3px);
    border-color: #b7ccdf !important;
    box-shadow: 0 10px 24px rgba(0,0,0,.07);
}

.featured-notice {
    border-left: 4px solid var(--home-gold) !important;
}

.admission-step-card {
    border: 1px solid #e5e9ed;
    border-radius: 16px;
    background: #fff;
    height: 100%;
    padding: 1.5rem;
    transition: transform .18s ease, border-color .18s ease;
}

.admission-step-card:hover {
    transform: translateY(-3px);
    border-color: #aac6de;
}

.admission-step-number {
    width: 42px;
    height: 42px;
    display: inline-grid;
    place-items: center;
    border-radius: 50%;
    background: #e8f1f9;
    color: var(--home-blue);
    font-weight: 800;
}

.home-reveal {
    opacity: 0;
    transform: translateY(14px);
    transition: opacity .45s ease, transform .45s ease;
}

.home-reveal.is-visible {
    opacity: 1;
    transform: translateY(0);
}

@media (prefers-reduced-motion: reduce) {
    .home-reveal,
    .portal-access-card,
    .home-dynamic-card,
    .notice-card,
    .admission-step-card {
        transition: none !important;
        transform: none !important;
    }
}

@media (max-width: 767.98px) {
    .hero-logo-box {
        width: 84px;
        height: 84px;
        margin-inline: auto;
    }

    .hero-logo-box img {
        max-height: 66px;
    }

    .portal-access-card {
        width: 100%;
        min-width: 0;
    }
}
</style>


<!-- Hero Section -->
<section class="home-hero py-5 text-white">

    <div class="container position-relative" style="z-index:2;">

        <div class="row align-items-center g-4">

            <div class="col-lg-7 text-center text-lg-start">

                <div class="hero-logo-box mb-4 mx-lg-0">
                    <img
                        src="/jkkniu-admission/assets/img/logo.png"
                        alt="JKKNIU Logo"
                    >
                </div>

                <div class="mb-2">
                    <span class="badge bg-light text-primary px-3 py-2">
                        Admission Session:
                        <?= publicE($currentAcademicYear) ?>
                    </span>
                </div>

                <h1 class="display-5 fw-bold mb-3">
                    Jatiya Kabi Kazi Nazrul Islam University
                </h1>

                <p class="lead text-white-50 mb-4">
                    Admission Management System — GST Post-Viva Enrollment
                </p>

                <div class="d-flex justify-content-center justify-content-lg-start gap-2 flex-wrap">

                    <a
                        href="/jkkniu-admission/student/login.php"
                        class="btn btn-warning btn-lg fw-bold px-4"
                    >
                        <i class="bi bi-person-fill me-1"></i>
                        Student Login
                    </a>

                    <?php if ($registrationIsOpen): ?>

                        <a
                            href="/jkkniu-admission/student/signup.php"
                            class="btn btn-outline-light btn-lg px-4"
                        >
                            <i class="bi bi-person-plus-fill me-1"></i>
                            New Registration
                        </a>

                    <?php else: ?>

                        <button
                            type="button"
                            class="btn btn-outline-light btn-lg px-4"
                            disabled
                        >
                            <i class="bi bi-lock-fill me-1"></i>
                            Registration Closed
                        </button>

                    <?php endif; ?>

                </div>

            </div>


            <div class="col-lg-5">

                <div class="live-status-card p-4">

                    <div class="small text-uppercase fw-semibold text-white-50 mb-2">
                        Live Admission Status
                    </div>

                    <?php if ($currentCall): ?>

                        <?php
                        $heroStart =
                            new DateTimeImmutable(
                                $currentCall['registration_start'],
                                $timezone
                            );

                        $heroDeadline =
                            new DateTimeImmutable(
                                $currentCall['registration_deadline'],
                                $timezone
                            );

                        if ($currentCallStatus === 'Open') {
                            $heroBadge = 'bg-success';
                            $heroLabel = 'Registration Open';
                            $heroIcon = 'bi-unlock-fill';
                        } elseif ($currentCallStatus === 'Scheduled') {
                            $heroBadge = 'bg-info text-dark';
                            $heroLabel = 'Scheduled';
                            $heroIcon = 'bi-calendar-event-fill';
                        } else {
                            $heroBadge = 'bg-secondary';
                            $heroLabel = 'Registration Closed';
                            $heroIcon = 'bi-lock-fill';
                        }
                        ?>

                        <div class="d-flex justify-content-between align-items-start gap-3 mb-3">

                            <div>
                                <h4 class="fw-bold mb-1">
                                    <?= publicE($currentCall['call_title']) ?>
                                </h4>

                                <div class="text-white-50 small">
                                    Current published Merit Call
                                </div>
                            </div>

                            <span class="badge <?= $heroBadge ?> px-3 py-2">
                                <i class="bi <?= $heroIcon ?> me-1"></i>
                                <?= publicE($heroLabel) ?>
                            </span>

                        </div>

                        <div class="row g-2">

                            <div class="col-6">
                                <div class="bg-white bg-opacity-10 rounded-3 p-3 h-100">
                                    <div class="small text-white-50">Starts</div>
                                    <strong>
                                        <?= publicE($heroStart->format('d M, h:i A')) ?>
                                    </strong>
                                </div>
                            </div>

                            <div class="col-6">
                                <div class="bg-white bg-opacity-10 rounded-3 p-3 h-100">
                                    <div class="small text-white-50">Deadline</div>
                                    <strong>
                                        <?= publicE($heroDeadline->format('d M, h:i A')) ?>
                                    </strong>
                                </div>
                            </div>

                        </div>

                    <?php else: ?>

                        <div class="d-flex align-items-center gap-3">

                            <i class="bi bi-hourglass-split fs-2 text-warning"></i>

                            <div>
                                <h5 class="fw-bold mb-1">
                                    Waiting for Merit Call
                                </h5>

                                <div class="small text-white-50">
                                    Registration will become available after the Registrar publishes a Merit Call.
                                </div>
                            </div>

                        </div>

                    <?php endif; ?>


                    <div class="d-flex flex-wrap gap-2 mt-3">

                        <span class="badge bg-white bg-opacity-10 px-3 py-2">
                            <i class="bi bi-megaphone me-1"></i>
                            <?= $totalPublishedNotices ?> Notice<?= $totalPublishedNotices === 1 ? '' : 's' ?>
                        </span>

                        <span class="badge bg-white bg-opacity-10 px-3 py-2">
                            <i class="bi bi-clock-history me-1"></i>
                            <?= $totalPublishedMeritCalls ?> Merit Call<?= $totalPublishedMeritCalls === 1 ? '' : 's' ?>
                        </span>

                    </div>

                </div>

            </div>

        </div>

    </div>

</section>


<!-- Admin Login Cards -->
<section class="py-3 bg-white border-bottom">

    <div class="container">

        <div class="d-flex flex-wrap justify-content-center gap-2">

            <a
                href="/jkkniu-admission/dept_admin/login.php"
                class="portal-access-card"
            >
                <i class="bi bi-building text-primary fs-5"></i>
                <span>Department Admin</span>
            </a>

            <a
                href="/jkkniu-admission/hall_admin/login.php"
                class="portal-access-card"
            >
                <i class="bi bi-house-door text-primary fs-5"></i>
                <span>Hall Admin</span>
            </a>

            <a
                href="/jkkniu-admission/registrar/login.php"
                class="portal-access-card"
            >
                <i class="bi bi-person-badge text-primary fs-5"></i>
                <span>Registrar</span>
            </a>

            <a
                href="/jkkniu-admission/super_admin/login.php"
                class="portal-access-card danger"
            >
                <i class="bi bi-shield-lock text-danger fs-5"></i>
                <span>Super Admin</span>
            </a>

        </div>

    </div>

</section>


<!-- =========================================================
     MERIT CALL + COUNTDOWN
========================================================== -->

<section class="py-4 bg-light border-bottom">

    <div class="container">


        <?php if (!$currentCall): ?>

            <div class="alert alert-info mb-0">

                <div
                    class="d-flex
                           align-items-center
                           gap-3"
                >

                    <i
                        class="bi bi-info-circle-fill
                               fs-3"
                    ></i>

                    <div>

                        <strong>
                            Merit Call has not been published yet.
                        </strong>

                        <div class="small">
                            Registration will open after the Registrar publishes the 1st Merit Call.
                        </div>

                    </div>

                </div>

            </div>


        <?php else: ?>

            <?php

            $callStart =
                new DateTimeImmutable(
                    $currentCall[
                        'registration_start'
                    ],
                    $timezone
                );

            $callDeadline =
                new DateTimeImmutable(
                    $currentCall[
                        'registration_deadline'
                    ],
                    $timezone
                );


            if (
                $currentCallStatus ===
                'Open'
            ) {
                $callBadge =
                    'bg-success';
                $callLabel =
                    'Registration Open';
                $countdownLabel =
                    'Registration closes in';
                $countdownTarget =
                    $callDeadline
                        ->format(
                            DATE_ATOM
                        );
            } elseif (
                $currentCallStatus ===
                'Scheduled'
            ) {
                $callBadge =
                    'bg-info text-dark';
                $callLabel =
                    'Scheduled';
                $countdownLabel =
                    'Registration opens in';
                $countdownTarget =
                    $callStart
                        ->format(
                            DATE_ATOM
                        );
            } else {
                $callBadge =
                    'bg-secondary';
                $callLabel =
                    'Registration Closed';
                $countdownLabel =
                    'Registration period ended';
                $countdownTarget =
                    '';
            }

            ?>


            <div class="card shadow-sm border-0 home-dynamic-card home-reveal">

                <div class="card-body p-4">


                    <div
                        class="d-flex
                               justify-content-between
                               align-items-start
                               flex-wrap
                               gap-3
                               mb-3"
                    >

                        <div>

                            <div
                                class="text-muted
                                       small
                                       text-uppercase
                                       fw-semibold"
                            >
                                Current Admission Update
                            </div>

                            <h3 class="fw-bold mb-1">

                                <?= publicE(
                                    $currentCall[
                                        'call_title'
                                    ]
                                ) ?>

                            </h3>

                            <div class="text-muted">

                                <i
                                    class="bi bi-calendar-event me-1"
                                ></i>

                                <?= publicE(
                                    $callStart
                                        ->format(
                                            'd M Y, h:i A'
                                        )
                                ) ?>

                                —

                                <?= publicE(
                                    $callDeadline
                                        ->format(
                                            'd M Y, h:i A'
                                        )
                                ) ?>

                            </div>

                        </div>


                        <span
                            class="badge
                                   <?= $callBadge ?>
                                   fs-6
                                   px-3
                                   py-2"
                        >

                            <?= publicE(
                                $callLabel
                            ) ?>

                        </span>

                    </div>


                    <?php if (
                        $countdownTarget !== ''
                    ): ?>

                        <div
                            class="alert
                                   alert-warning
                                   mb-3"
                        >

                            <div
                                class="text-center
                                       fw-semibold
                                       mb-2"
                            >

                                <?= publicE(
                                    $countdownLabel
                                ) ?>

                            </div>


                            <div
                                id="meritCallCountdown"
                                data-target="<?= publicE(
                                    $countdownTarget
                                ) ?>"
                                class="row
                                       g-2
                                       justify-content-center
                                       text-center"
                            >

                                <?php

                                $countdownBoxes = [
                                    [
                                        'id' =>
                                            'countDays',
                                        'label' =>
                                            'Days'
                                    ],
                                    [
                                        'id' =>
                                            'countHours',
                                        'label' =>
                                            'Hours'
                                    ],
                                    [
                                        'id' =>
                                            'countMinutes',
                                        'label' =>
                                            'Minutes'
                                    ],
                                    [
                                        'id' =>
                                            'countSeconds',
                                        'label' =>
                                            'Seconds'
                                    ],
                                ];

                                ?>

                                <?php foreach (
                                    $countdownBoxes as
                                    $box
                                ): ?>

                                    <div
                                        class="col-6
                                               col-sm-3
                                               col-lg-2"
                                    >

                                        <div
                                            class="bg-white
                                                   rounded
                                                   border
                                                   py-3"
                                        >

                                            <div
                                                id="<?= publicE(
                                                    $box[
                                                        'id'
                                                    ]
                                                ) ?>"
                                                class="h2
                                                       fw-bold
                                                       text-primary
                                                       mb-0"
                                            >
                                                00
                                            </div>

                                            <small
                                                class="text-muted"
                                            >
                                                <?= publicE(
                                                    $box[
                                                        'label'
                                                    ]
                                                ) ?>
                                            </small>

                                        </div>

                                    </div>

                                <?php endforeach; ?>

                            </div>

                        </div>

                    <?php else: ?>

                        <div
                            class="alert
                                   alert-secondary
                                   mb-3"
                        >

                            <i
                                class="bi bi-lock-fill me-1"
                            ></i>

                            Registration for this Merit Call has ended.

                        </div>

                    <?php endif; ?>


                    <?php if (
                        $registrationIsOpen
                    ): ?>

                        <div class="text-center">

                            <a
                                href="/jkkniu-admission/student/signup.php"
                                class="btn
                                       btn-success
                                       btn-lg
                                       fw-bold"
                            >

                                <i
                                    class="bi bi-person-plus-fill
                                           me-1"
                                ></i>

                                Register for
                                <?= publicE(
                                    $currentCall[
                                        'call_title'
                                    ]
                                ) ?>

                            </a>

                            <div
                                class="small
                                       text-muted
                                       mt-2"
                            >
                                Only candidates allocated in this Merit Call can register.
                            </div>

                        </div>

                    <?php endif; ?>


                </div>

            </div>

        <?php endif; ?>


        <?php if ($meritCalls): ?>

            <div class="mt-4">

                <h6 class="fw-bold mb-3">

                    <i
                        class="bi bi-clock-history
                               text-primary
                               me-1"
                    ></i>

                    Merit Call History

                </h6>


                <div class="row g-3">

                    <?php foreach (
                        $meritCalls as
                        $call
                    ): ?>

                        <?php

                        $historyStart =
                            new DateTimeImmutable(
                                $call[
                                    'registration_start'
                                ],
                                $timezone
                            );

                        $historyDeadline =
                            new DateTimeImmutable(
                                $call[
                                    'registration_deadline'
                                ],
                                $timezone
                            );


                        if (
                            $now <
                            $historyStart
                        ) {
                            $historyStatus =
                                'Scheduled';
                            $historyClass =
                                'bg-info text-dark';
                        } elseif (
                            $now <=
                            $historyDeadline
                        ) {
                            $historyStatus =
                                'Open';
                            $historyClass =
                                'bg-success';
                        } else {
                            $historyStatus =
                                'Closed';
                            $historyClass =
                                'bg-secondary';
                        }

                        ?>


                        <div class="col-md-6 col-lg-4">

                            <div
                                class="card
                                       h-100
                                       border-0
                                       shadow-sm
                                       home-dynamic-card"
                            >

                                <div class="card-body">

                                    <div
                                        class="d-flex
                                               justify-content-between
                                               gap-2"
                                    >

                                        <strong>

                                            <?= publicE(
                                                $call[
                                                    'call_title'
                                                ]
                                            ) ?>

                                        </strong>

                                        <span
                                            class="badge
                                                   <?= $historyClass ?>"
                                        >
                                            <?= $historyStatus ?>
                                        </span>

                                    </div>


                                    <div
                                        class="small
                                               text-muted
                                               mt-2"
                                    >

                                        Deadline:

                                        <strong>

                                            <?= publicE(
                                                $historyDeadline
                                                    ->format(
                                                        'd M Y, h:i A'
                                                    )
                                            ) ?>

                                        </strong>

                                    </div>

                                </div>

                            </div>

                        </div>


                    <?php endforeach; ?>

                </div>

            </div>

        <?php endif; ?>

    </div>

</section>




<!-- Notices Section -->
<section class="py-5 bg-white">

    <div class="container">

        <div class="d-flex align-items-end justify-content-between flex-wrap gap-2 mb-4 home-reveal">

            <div>

                <div class="home-section-label">
                    Latest Updates
                </div>

                <h3 class="fw-bold mb-0">

                    <i class="bi bi-megaphone-fill text-warning me-1"></i>
                    Admission Notices

                </h3>

            </div>

            <span class="badge bg-light text-primary border px-3 py-2">
                <?= $totalPublishedNotices ?> Published
            </span>

        </div>


        <?php if (empty($notices)): ?>

            <div class="alert alert-info">
                No notices published yet.
            </div>

        <?php else: ?>

            <?php
            $featuredNotice =
                $notices[0];

            $remainingNotices =
                array_slice(
                    $notices,
                    1
                );
            ?>


            <div
                class="card notice-card featured-notice notice-open-card mb-3 home-reveal"
                role="button"
                tabindex="0"
                data-notice-id="<?= (int) $featuredNotice['notice_id'] ?>"
                data-bs-toggle="modal"
                data-bs-target="#homeNoticeModal"
                aria-label="Open notice: <?= publicE($featuredNotice['notice_title']) ?>"
            >

                <div class="card-body p-4">

                    <span class="badge bg-warning text-dark mb-2">
                        Latest Notice
                    </span>

                    <h5 class="fw-bold mb-2">
                        <?= publicE(
                            $featuredNotice[
                                'notice_title'
                            ]
                        ) ?>
                    </h5>

                    <div class="small text-muted mb-3">

                        <i class="bi bi-building me-1"></i>

                        <?= publicE(
                            $featuredNotice[
                                'dept_name'
                            ]
                            ?? 'All Departments'
                        ) ?>

                        <span class="mx-2">•</span>

                        <i class="bi bi-calendar me-1"></i>

                        Admission starts:

                        <strong>
                            <?= publicE(
                                $featuredNotice[
                                    'admission_start_date'
                                ]
                            ) ?>
                        </strong>

                    </div>

                    <p class="mb-0 text-muted">
                        <?= nl2br(
                            publicE(
                                mb_substr(
                                    (string)
                                    $featuredNotice[
                                        'notice_body'
                                    ],
                                    0,
                                    220
                                )
                            )
                        ) ?><?= mb_strlen(
                            (string)
                            $featuredNotice[
                                'notice_body'
                            ]
                        ) > 220
                            ? '...'
                            : ''
                        ?>
                    </p>

                    <div class="small text-primary fw-semibold mt-3">
                        Click to view full notice
                        <i class="bi bi-arrow-right ms-1"></i>
                    </div>

                </div>

            </div>


            <?php if ($remainingNotices): ?>

                <div class="row g-3">

                    <?php foreach (
                        $remainingNotices
                        as $notice
                    ): ?>

                        <div class="col-md-6 col-lg-4 home-reveal">

                            <div
                                class="card notice-card notice-open-card h-100"
                                role="button"
                                tabindex="0"
                                data-notice-id="<?= (int) $notice['notice_id'] ?>"
                                data-bs-toggle="modal"
                                data-bs-target="#homeNoticeModal"
                                aria-label="Open notice: <?= publicE($notice['notice_title']) ?>"
                            >

                                <div class="card-body">

                                    <div class="small text-muted mb-2">

                                        <i class="bi bi-building me-1"></i>

                                        <?= publicE(
                                            $notice[
                                                'dept_name'
                                            ]
                                            ?? 'All Departments'
                                        ) ?>

                                    </div>

                                    <h6 class="fw-bold mb-2">
                                        <?= publicE(
                                            $notice[
                                                'notice_title'
                                            ]
                                        ) ?>
                                    </h6>

                                    <p class="small text-muted mb-2">
                                        <?= nl2br(
                                            publicE(
                                                mb_substr(
                                                    (string)
                                                    $notice[
                                                        'notice_body'
                                                    ],
                                                    0,
                                                    110
                                                )
                                            )
                                        ) ?><?= mb_strlen(
                                            (string)
                                            $notice[
                                                'notice_body'
                                            ]
                                        ) > 110
                                            ? '...'
                                            : ''
                                        ?>
                                    </p>

                                    <div class="small text-muted">
                                        <i class="bi bi-calendar me-1"></i>
                                        <?= publicE(
                                            $notice[
                                                'admission_start_date'
                                            ]
                                        ) ?>
                                    </div>

                                </div>

                            </div>

                        </div>

                    <?php endforeach; ?>

                </div>

            <?php endif; ?>

        <?php endif; ?>

    </div>

</section>


<!-- =========================================================
     NOTICE DATA + MODAL
========================================================== -->

<script
    type="application/json"
    id="homeNoticeData"
><?= json_encode(
    $notices,
    JSON_UNESCAPED_UNICODE
    | JSON_UNESCAPED_SLASHES
    | JSON_HEX_TAG
    | JSON_HEX_AMP
    | JSON_HEX_APOS
    | JSON_HEX_QUOT
) ?></script>


<div
    class="modal fade"
    id="homeNoticeModal"
    tabindex="-1"
    aria-labelledby="homeNoticeModalLabel"
    aria-hidden="true"
>

    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">

        <div class="modal-content border-0 shadow">

            <div class="modal-header bg-primary text-white">

                <div>
                    <div class="small text-white-50 mb-1" id="homeNoticeDept">
                        Admission Notice
                    </div>

                    <h5 class="modal-title fw-bold" id="homeNoticeModalLabel">
                        Notice
                    </h5>
                </div>

                <button
                    type="button"
                    class="btn-close btn-close-white"
                    data-bs-dismiss="modal"
                    aria-label="Close"
                ></button>

            </div>


            <div class="modal-body p-4">

                <div class="d-flex flex-wrap gap-3 small text-muted mb-3">

                    <span>
                        <i class="bi bi-calendar-event me-1"></i>
                        Starts:
                        <strong class="text-dark" id="homeNoticeStart">—</strong>
                    </span>

                    <span>
                        <i class="bi bi-calendar-check me-1"></i>
                        Ends:
                        <strong class="text-dark" id="homeNoticeEnd">—</strong>
                    </span>

                    <span>
                        <i class="bi bi-clock-history me-1"></i>
                        Published:
                        <strong class="text-dark" id="homeNoticePublished">—</strong>
                    </span>

                </div>

                <div
                    id="homeNoticeBody"
                    class="notice-modal-body-text mb-3"
                ></div>

                <div class="small text-muted border-top pt-3">
                    <i class="bi bi-database-check me-1"></i>
                    This is the exact published notice stored in the Notice Board.
                </div>

                <div id="homeNoticeLinkWrap" class="mb-3 d-none">
                    <a
                        id="homeNoticeLink"
                        href="#"
                        class="btn btn-outline-primary"
                        target="_blank"
                        rel="noopener noreferrer"
                    >
                        <i class="bi bi-box-arrow-up-right me-1"></i>
                        Open Link
                    </a>
                </div>


            </div>

            <div class="modal-footer">
                <button
                    type="button"
                    class="btn btn-secondary"
                    data-bs-dismiss="modal"
                >
                    Close
                </button>
            </div>

        </div>

    </div>

</div>


<!-- How It Works -->
<section class="py-5">

    <div class="container">

        <div class="text-center mb-4 home-reveal">

            <div class="home-section-label">
                Enrollment Workflow
            </div>

            <h3 class="fw-bold mb-1">
                Admission Steps
            </h3>

            <p class="text-muted mb-0">
                Complete the required offices in sequence after Viva selection.
            </p>

        </div>


        <?php

        $steps = [
            [
                'icon' => 'bi-building',
                'title' => 'Department',
                'desc' => 'Visit Department — Submit transcripts, biodata & pay dept fee online'
            ],
            [
                'icon' => 'bi-house-door',
                'title' => 'Hall',
                'desc' => 'Visit Hall — Fill biodata & pay hall fee online'
            ],
            [
                'icon' => 'bi-patch-check',
                'title' => 'Registrar',
                'desc' => 'Fill admission form, pay admission fee online & get final approval'
            ],
        ];

        ?>


        <div class="row g-3">

            <?php foreach (
                $steps as $index => $step
            ): ?>

                <div class="col-md-4 home-reveal">

                    <div class="admission-step-card text-center">

                        <div class="admission-step-number">
                            <?= $index + 1 ?>
                        </div>

                        <div class="mt-2">
                            <i
                                class="bi
                                       <?= publicE(
                                           $step[
                                               'icon'
                                           ]
                                       ) ?>
                                       fs-2
                                       text-primary"
                            ></i>
                        </div>

                        <h5 class="fw-bold mt-3 mb-2">
                            <?= publicE(
                                $step[
                                    'title'
                                ]
                            ) ?>
                        </h5>

                        <p class="small text-muted mb-0">
                            <?= publicE(
                                $step[
                                    'desc'
                                ]
                            ) ?>
                        </p>

                    </div>

                </div>

            <?php endforeach; ?>

        </div>

    </div>

</section>


<script>
(function () {

    /*
    |--------------------------------------------------------------------------
    | Clickable Admission Notices
    |--------------------------------------------------------------------------
    */

    const noticeDataEl =
        document.getElementById(
            'homeNoticeData'
        );

    const noticeModalEl =
        document.getElementById(
            'homeNoticeModal'
        );

    if (
        noticeDataEl
        &&
        noticeModalEl
    ) {

        let noticeRows = [];

        try {
            noticeRows =
                JSON.parse(
                    noticeDataEl.textContent
                    || '[]'
                );
        } catch (error) {
            noticeRows = [];
        }

        const noticeMap = new Map(
            noticeRows.map(
                function (notice) {
                    return [
                        String(notice.notice_id),
                        notice
                    ];
                }
            )
        );

        function displayDate(value) {

            if (!value) {
                return '—';
            }

            const parsed = new Date(
                String(value).replace(
                    ' ',
                    'T'
                )
            );

            if (
                Number.isNaN(
                    parsed.getTime()
                )
            ) {
                return String(value);
            }

            return parsed.toLocaleString(
                'en-GB',
                {
                    day: '2-digit',
                    month: 'short',
                    year: 'numeric',
                    hour: value.length > 10
                        ? '2-digit'
                        : undefined,
                    minute: value.length > 10
                        ? '2-digit'
                        : undefined
                }
            );
        }

        noticeModalEl.addEventListener(
            'show.bs.modal',
            function (event) {

                const trigger =
                    event.relatedTarget;

                if (!trigger) {
                    return;
                }

                const noticeId =
                    String(
                        trigger.dataset.noticeId
                        || ''
                    );

                const notice =
                    noticeMap.get(
                        noticeId
                    );

                if (!notice) {
                    return;
                }

                document.getElementById(
                    'homeNoticeModalLabel'
                ).textContent =
                    notice.notice_title
                    || 'Admission Notice';

                document.getElementById(
                    'homeNoticeDept'
                ).textContent =
                    notice.dept_name
                    || 'All Departments';

                document.getElementById(
                    'homeNoticeStart'
                ).textContent =
                    displayDate(
                        notice.admission_start_date
                    );

                document.getElementById(
                    'homeNoticeEnd'
                ).textContent =
                    displayDate(
                        notice.admission_end_date
                    );

                document.getElementById(
                    'homeNoticePublished'
                ).textContent =
                    displayDate(
                        notice.published_at
                    );

                document.getElementById(
                    'homeNoticeBody'
                ).textContent =
                    notice.notice_body
                    || '';

                const linkWrap =
                    document.getElementById(
                        'homeNoticeLinkWrap'
                    );

                const link =
                    document.getElementById(
                        'homeNoticeLink'
                    );

                const noticeLink =
                    String(
                        notice.notice_link
                        || ''
                    ).trim();

                if (noticeLink !== '') {
                    link.href = noticeLink;
                    linkWrap.classList.remove(
                        'd-none'
                    );
                } else {
                    link.href = '#';
                    linkWrap.classList.add(
                        'd-none'
                    );
                }

            }
        );

        document.querySelectorAll(
            '.notice-open-card'
        ).forEach(
            function (card) {
                card.addEventListener(
                    'keydown',
                    function (event) {
                        if (
                            event.key === 'Enter'
                            ||
                            event.key === ' '
                        ) {
                            event.preventDefault();
                            card.click();
                        }
                    }
                );
            }
        );
    }


    /*
    |--------------------------------------------------------------------------
    | Merit Call Countdown
    |--------------------------------------------------------------------------
    */

    const countdown =
        document.getElementById(
            'meritCallCountdown'
        );

    if (countdown) {

        const target =
            new Date(
                countdown.dataset.target
            ).getTime();

        const daysEl =
            document.getElementById(
                'countDays'
            );

        const hoursEl =
            document.getElementById(
                'countHours'
            );

        const minutesEl =
            document.getElementById(
                'countMinutes'
            );

        const secondsEl =
            document.getElementById(
                'countSeconds'
            );


        function pad(value) {
            return String(value)
                .padStart(2, '0');
        }


        function updateCountdown() {

            const distance =
                target - Date.now();

            if (distance <= 0) {

                daysEl.textContent = '00';
                hoursEl.textContent = '00';
                minutesEl.textContent = '00';
                secondsEl.textContent = '00';

                window.setTimeout(
                    function () {
                        window.location.reload();
                    },
                    1000
                );

                return;
            }


            const days =
                Math.floor(
                    distance /
                    (1000 * 60 * 60 * 24)
                );

            const hours =
                Math.floor(
                    (
                        distance %
                        (1000 * 60 * 60 * 24)
                    )
                    /
                    (1000 * 60 * 60)
                );

            const minutes =
                Math.floor(
                    (
                        distance %
                        (1000 * 60 * 60)
                    )
                    /
                    (1000 * 60)
                );

            const seconds =
                Math.floor(
                    (
                        distance %
                        (1000 * 60)
                    )
                    /
                    1000
                );


            daysEl.textContent = pad(days);
            hoursEl.textContent = pad(hours);
            minutesEl.textContent = pad(minutes);
            secondsEl.textContent = pad(seconds);
        }


        updateCountdown();

        window.setInterval(
            updateCountdown,
            1000
        );
    }


    /*
    |--------------------------------------------------------------------------
    | Subtle reveal animation
    |--------------------------------------------------------------------------
    */

    const revealItems =
        document.querySelectorAll(
            '.home-reveal'
        );

    if (
        'IntersectionObserver'
        in window
    ) {

        const observer =
            new IntersectionObserver(
                function (entries) {

                    entries.forEach(
                        function (entry) {

                            if (
                                entry.isIntersecting
                            ) {
                                entry.target
                                    .classList
                                    .add(
                                        'is-visible'
                                    );

                                observer.unobserve(
                                    entry.target
                                );
                            }
                        }
                    );
                },
                {
                    threshold: 0.08
                }
            );

        revealItems.forEach(
            function (item) {
                observer.observe(item);
            }
        );

    } else {

        revealItems.forEach(
            function (item) {
                item.classList.add(
                    'is-visible'
                );
            }
        );
    }

})();
</script>


<?php require_once 'includes/footer.php'; ?>
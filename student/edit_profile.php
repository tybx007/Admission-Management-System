<?php

declare(strict_types=1);

$pageTitle = 'Edit Profile';

require_once '../includes/auth.php';
require_once '../config/db.php';

/*
|--------------------------------------------------------------------------
| Authentication BEFORE HTML output
|--------------------------------------------------------------------------
*/

if (
    !isset($_SESSION['user_id']) ||
    ($_SESSION['role'] ?? '') !== 'student'
) {
    header('Location: /jkkniu-admission/student/login.php');
    exit;
}

$applicantId = (int) $_SESSION['user_id'];

if ($applicantId <= 0) {
    header('Location: /jkkniu-admission/student/login.php');
    exit;
}

$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}


/*
|--------------------------------------------------------------------------
| Helpers
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

function hasValue(mixed $value): bool
{
    return $value !== null &&
        trim((string) $value) !== '';
}

function effectiveValue(
    mixed $applicantValue,
    mixed $importedValue,
    string $fallback = 'N/A'
): string {
    if (hasValue($applicantValue)) {
        return trim((string) $applicantValue);
    }

    if (hasValue($importedValue)) {
        return trim((string) $importedValue);
    }

    return $fallback;
}

function normalizeBdPhone(string $phone): string
{
    $phone = preg_replace(
        '/[\s\-\(\)]/',
        '',
        trim($phone)
    ) ?? '';

    if (str_starts_with($phone, '+880')) {
        $phone = '0' . substr($phone, 4);
    } elseif (str_starts_with($phone, '880')) {
        $phone = '0' . substr($phone, 3);
    }

    if (!preg_match('/^01[3-9][0-9]{8}$/', $phone)) {
        throw new RuntimeException(
            'Enter a valid Bangladeshi phone number (01XXXXXXXXX).'
        );
    }

    return $phone;
}


/**
 * Safely save an uploaded image.
 *
 * Does not delete the old file. The caller deletes the old file
 * only AFTER the database update succeeds.
 */
function saveReplacementImage(
    array $file,
    string $directory,
    string $prefix,
    int $maxBytes
): ?array {

    $error = (int) (
        $file['error']
        ?? UPLOAD_ERR_NO_FILE
    );

    if ($error === UPLOAD_ERR_NO_FILE) {
        return null;
    }

    if ($error !== UPLOAD_ERR_OK) {
        throw new RuntimeException(
            ucfirst($prefix) . ' upload failed.'
        );
    }

    $size = (int) (
        $file['size']
        ?? 0
    );

    if ($size <= 0 || $size > $maxBytes) {
        throw new RuntimeException(
            ucfirst($prefix) .
            ' exceeds the maximum allowed file size.'
        );
    }

    $tmpName = (string) (
        $file['tmp_name']
        ?? ''
    );

    if (
        $tmpName === '' ||
        !is_uploaded_file($tmpName)
    ) {
        throw new RuntimeException(
            ucfirst($prefix) .
            ' upload could not be verified.'
        );
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);

    $mime = $finfo->file($tmpName);

    $allowed = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
    ];

    if (!isset($allowed[$mime])) {
        throw new RuntimeException(
            ucfirst($prefix) .
            ' must be a JPG or PNG image.'
        );
    }

    if (!is_dir($directory)) {
        if (
            !mkdir($directory, 0775, true) &&
            !is_dir($directory)
        ) {
            throw new RuntimeException(
                'Upload directory could not be created.'
            );
        }
    }

    $filename =
        $prefix . '_' .
        date('YmdHis') . '_' .
        bin2hex(random_bytes(6)) .
        '.' .
        $allowed[$mime];

    $destination =
        rtrim(
            $directory,
            DIRECTORY_SEPARATOR
        ) .
        DIRECTORY_SEPARATOR .
        $filename;

    if (!move_uploaded_file($tmpName, $destination)) {
        throw new RuntimeException(
            ucfirst($prefix) .
            ' could not be saved.'
        );
    }

    return [
        'filename' => $filename,
        'path' => $destination,
    ];
}


/*
|--------------------------------------------------------------------------
| Fetch applicant + imported GST information
|--------------------------------------------------------------------------
*/

$profileStmt = $pdo->prepare("
    SELECT
        ga.applicant_id,
        ga.gst_roll AS applicant_gst_roll,
        ga.full_name AS applicant_full_name,
        ga.email,
        ga.phone AS applicant_phone,
        ga.date_of_birth AS applicant_date_of_birth,
        ga.gender AS applicant_gender,
        ga.nationality AS applicant_nationality,
        ga.quota AS applicant_quota,
        ga.gst_score AS applicant_gst_score,
        ga.father_name,
        ga.mother_name,
        ga.photo,
        ga.signature,

        gi.import_candidate_id,
        gi.gst_roll,
        gi.gst_applicant_id,
        gi.full_name,
        gi.gst_unit,
        gi.gst_score,
        gi.merit_position,
        gi.phone AS imported_phone,
        gi.date_of_birth AS imported_date_of_birth,
        gi.gender AS imported_gender,
        gi.nationality AS imported_nationality,
        gi.quota AS imported_quota,
        gi.academic_year,
        gi.selection_status

    FROM gst_applicants ga

    INNER JOIN gst_imported_candidates gi
        ON gi.applicant_id = ga.applicant_id

    WHERE ga.applicant_id = ?

    LIMIT 1
");

$profileStmt->execute([
    $applicantId
]);

$profile = $profileStmt->fetch(
    PDO::FETCH_ASSOC
);

if (!$profile) {
    http_response_code(409);

    exit(
        'Your applicant account is not linked to an imported GST candidate.'
    );
}


/*
|--------------------------------------------------------------------------
| Effective locked values
|--------------------------------------------------------------------------
*/

$fullName = effectiveValue(
    $profile['applicant_full_name'],
    $profile['full_name']
);

$gstRoll = effectiveValue(
    $profile['applicant_gst_roll'],
    $profile['gst_roll']
);

$dateOfBirth = effectiveValue(
    $profile['applicant_date_of_birth'],
    $profile['imported_date_of_birth']
);

$gender = effectiveValue(
    $profile['applicant_gender'],
    $profile['imported_gender']
);

$nationality = effectiveValue(
    $profile['applicant_nationality'],
    $profile['imported_nationality'],
    'Bangladeshi'
);

$quota = effectiveValue(
    $profile['applicant_quota'],
    $profile['imported_quota'],
    'General'
);

$gstScore = effectiveValue(
    $profile['applicant_gst_score'],
    $profile['gst_score']
);


/*
|--------------------------------------------------------------------------
| Phone rule
|--------------------------------------------------------------------------
|
| If GST supplied a phone number, it is treated as authoritative and locked.
| If GST did NOT supply a phone number, the applicant may edit/add it here.
|--------------------------------------------------------------------------
*/

$importedPhoneExists =
    hasValue(
        $profile['imported_phone']
    );

$currentPhone =
    $importedPhoneExists
        ? (string) $profile['imported_phone']
        : (string) (
            $profile['applicant_phone']
            ?? ''
        );


/*
|--------------------------------------------------------------------------
| POST
|--------------------------------------------------------------------------
*/

$errors = [];
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $newPhoto = null;
    $newSignature = null;

    try {

        /*
        |--------------------------------------------------------------------------
        | CSRF
        |--------------------------------------------------------------------------
        */

        $submittedToken =
            $_POST['csrf_token']
            ?? '';

        if (
            !is_string($submittedToken) ||
            !hash_equals(
                (string) $_SESSION['csrf_token'],
                $submittedToken
            )
        ) {
            throw new RuntimeException(
                'Invalid request token. Refresh the page and try again.'
            );
        }


        /*
        |--------------------------------------------------------------------------
        | Email is optional
        |--------------------------------------------------------------------------
        */

        $email = strtolower(
            trim(
                (string) (
                    $_POST['email']
                    ?? ''
                )
            )
        );

        if ($email !== '') {

            if (
                !filter_var(
                    $email,
                    FILTER_VALIDATE_EMAIL
                )
            ) {
                throw new RuntimeException(
                    'Enter a valid email address.'
                );
            }

            if (
                mb_strlen(
                    $email,
                    'UTF-8'
                ) > 100
            ) {
                throw new RuntimeException(
                    'Email address is too long.'
                );
            }


            $emailCheck =
                $pdo->prepare("
                    SELECT applicant_id
                    FROM gst_applicants
                    WHERE email = ?
                      AND applicant_id <> ?
                    LIMIT 1
                ");

            $emailCheck->execute([
                $email,
                $applicantId
            ]);

            if (
                $emailCheck->fetchColumn()
            ) {
                throw new RuntimeException(
                    'This email address is already used by another applicant.'
                );
            }

        } else {
            $email = null;
        }


        /*
        |--------------------------------------------------------------------------
        | Phone is editable only if GST did not provide it
        |--------------------------------------------------------------------------
        */

        if ($importedPhoneExists) {

            $phone =
                normalizeBdPhone(
                    (string)
                    $profile[
                        'imported_phone'
                    ]
                );

        } else {

            $postedPhone =
                trim(
                    (string) (
                        $_POST['phone']
                        ?? ''
                    )
                );

            if ($postedPhone === '') {
                $phone = null;
            } else {
                $phone =
                    normalizeBdPhone(
                        $postedPhone
                    );
            }
        }


        /*
        |--------------------------------------------------------------------------
        | Optional phone uniqueness check
        |--------------------------------------------------------------------------
        |
        | Safe when phone is nullable. If you already added a UNIQUE index,
        | this gives a friendly error before the UPDATE.
        |--------------------------------------------------------------------------
        */

        if ($phone !== null) {

            $phoneCheck =
                $pdo->prepare("
                    SELECT applicant_id
                    FROM gst_applicants
                    WHERE phone = ?
                      AND applicant_id <> ?
                    LIMIT 1
                ");

            $phoneCheck->execute([
                $phone,
                $applicantId
            ]);

            if (
                $phoneCheck->fetchColumn()
            ) {
                throw new RuntimeException(
                    'This phone number is already used by another applicant.'
                );
            }
        }


        /*
        |--------------------------------------------------------------------------
        | Replacement photo
        |--------------------------------------------------------------------------
        */

        $newPhoto =
            saveReplacementImage(
                $_FILES['photo']
                    ?? [],
                '../uploads/photos',
                'photo',
                2 * 1024 * 1024
            );


        /*
        |--------------------------------------------------------------------------
        | Replacement signature
        |--------------------------------------------------------------------------
        */

        $newSignature =
            saveReplacementImage(
                $_FILES['signature']
                    ?? [],
                '../uploads/signatures',
                'signature',
                1 * 1024 * 1024
            );


        /*
        |--------------------------------------------------------------------------
        | Prepare filenames
        |--------------------------------------------------------------------------
        */

        $photoFilename =
            $newPhoto !== null
                ? $newPhoto['filename']
                : (
                    $profile['photo']
                    ?: null
                );

        $signatureFilename =
            $newSignature !== null
                ? $newSignature['filename']
                : (
                    $profile['signature']
                    ?: null
                );


        /*
        |--------------------------------------------------------------------------
        | DB transaction
        |--------------------------------------------------------------------------
        */

        $pdo->beginTransaction();


        /*
        |--------------------------------------------------------------------------
        | Lock applicant row
        |--------------------------------------------------------------------------
        */

        $lockStmt =
            $pdo->prepare("
                SELECT
                    applicant_id,
                    photo,
                    signature
                FROM gst_applicants
                WHERE applicant_id = ?
                LIMIT 1
                FOR UPDATE
            ");

        $lockStmt->execute([
            $applicantId
        ]);

        $locked =
            $lockStmt->fetch(
                PDO::FETCH_ASSOC
            );

        if (!$locked) {
            throw new RuntimeException(
                'Applicant profile could not be locked.'
            );
        }


        /*
        |--------------------------------------------------------------------------
        | Update ONLY editable values
        |--------------------------------------------------------------------------
        */

        $update =
            $pdo->prepare("
                UPDATE gst_applicants
                SET
                    email = ?,
                    phone = ?,
                    photo = ?,
                    signature = ?
                WHERE applicant_id = ?
            ");

        $update->execute([
            $email,
            $phone,
            $photoFilename,
            $signatureFilename,
            $applicantId
        ]);


        $pdo->commit();


        /*
        |--------------------------------------------------------------------------
        | Delete old files only after successful DB commit
        |--------------------------------------------------------------------------
        */

        if (
            $newPhoto !== null &&
            hasValue(
                $locked['photo']
            )
        ) {

            $oldPhoto =
                '../uploads/photos/' .
                basename(
                    (string)
                    $locked['photo']
                );

            if (
                is_file($oldPhoto) &&
                basename(
                    (string)
                    $locked['photo']
                )
                !==
                $newPhoto['filename']
            ) {
                @unlink($oldPhoto);
            }
        }


        if (
            $newSignature !== null &&
            hasValue(
                $locked['signature']
            )
        ) {

            $oldSignature =
                '../uploads/signatures/' .
                basename(
                    (string)
                    $locked['signature']
                );

            if (
                is_file($oldSignature) &&
                basename(
                    (string)
                    $locked['signature']
                )
                !==
                $newSignature['filename']
            ) {
                @unlink($oldSignature);
            }
        }


        $_SESSION['edit_profile_success'] =
            'Profile updated successfully.';


        header(
            'Location: ' .
            basename(
                $_SERVER['PHP_SELF']
            )
        );

        exit;


    } catch (Throwable $exception) {

        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }


        /*
        |--------------------------------------------------------------------------
        | Remove newly uploaded files if DB save failed
        |--------------------------------------------------------------------------
        */

        if (
            $newPhoto !== null &&
            isset($newPhoto['path']) &&
            is_file(
                $newPhoto['path']
            )
        ) {
            @unlink(
                $newPhoto['path']
            );
        }


        if (
            $newSignature !== null &&
            isset(
                $newSignature['path']
            ) &&
            is_file(
                $newSignature['path']
            )
        ) {
            @unlink(
                $newSignature['path']
            );
        }


        $errors[] =
            $exception->getMessage();
    }
}


/*
|--------------------------------------------------------------------------
| Success flash + refresh
|--------------------------------------------------------------------------
*/

if (
    !empty(
        $_SESSION[
            'edit_profile_success'
        ]
    )
) {

    $success =
        (string)
        $_SESSION[
            'edit_profile_success'
        ];

    unset(
        $_SESSION[
            'edit_profile_success'
        ]
    );


    $profileStmt->execute([
        $applicantId
    ]);

    $profile =
        $profileStmt->fetch(
            PDO::FETCH_ASSOC
        );


    if (!$profile) {
        http_response_code(409);
        exit(
            'Applicant profile could not be refreshed.'
        );
    }


    $fullName = effectiveValue(
        $profile[
            'applicant_full_name'
        ],
        $profile['full_name']
    );

    $gstRoll = effectiveValue(
        $profile[
            'applicant_gst_roll'
        ],
        $profile['gst_roll']
    );

    $dateOfBirth = effectiveValue(
        $profile[
            'applicant_date_of_birth'
        ],
        $profile[
            'imported_date_of_birth'
        ]
    );

    $gender = effectiveValue(
        $profile[
            'applicant_gender'
        ],
        $profile[
            'imported_gender'
        ]
    );

    $nationality =
        effectiveValue(
            $profile[
                'applicant_nationality'
            ],
            $profile[
                'imported_nationality'
            ],
            'Bangladeshi'
        );

    $quota =
        effectiveValue(
            $profile[
                'applicant_quota'
            ],
            $profile[
                'imported_quota'
            ],
            'General'
        );

    $gstScore =
        effectiveValue(
            $profile[
                'applicant_gst_score'
            ],
            $profile[
                'gst_score'
            ]
        );

    $importedPhoneExists =
        hasValue(
            $profile[
                'imported_phone'
            ]
        );

    $currentPhone =
        $importedPhoneExists
            ? (string)
                $profile[
                    'imported_phone'
                ]
            : (string) (
                $profile[
                    'applicant_phone'
                ]
                ?? ''
            );
}


/*
|--------------------------------------------------------------------------
| HTML after all redirects
|--------------------------------------------------------------------------
*/

require_once '../includes/header.php';
require_once '../includes/navbar.php';

?>

<div class="container py-4">

    <div class="row justify-content-center">

        <div class="col-xl-9 col-lg-10">


            <!-- =========================================================
                 PAGE HEADER
            ========================================================== -->

            <div
                class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4"
            >

                <div>

                    <h2 class="fw-bold mb-1">

                        <i
                            class="bi bi-person-gear text-primary me-2"
                        ></i>

                        Edit Profile

                    </h2>

                    <p class="text-muted mb-0">

                        Update contact information,
                        photo and signature.

                    </p>

                </div>


                <a
                    href="/jkkniu-admission/student/dashboard.php"
                    class="btn btn-outline-secondary"
                >

                    <i class="bi bi-arrow-left me-1"></i>
                    Dashboard

                </a>

            </div>


            <!-- =========================================================
                 SUCCESS
            ========================================================== -->

            <?php if ($success !== ''): ?>

                <div
                    class="alert alert-success d-flex align-items-center gap-2"
                >

                    <i
                        class="bi bi-check-circle-fill fs-5"
                    ></i>

                    <div>

                        <strong>Saved.</strong>

                        <?= e($success) ?>

                    </div>

                </div>

            <?php endif; ?>


            <!-- =========================================================
                 ERRORS
            ========================================================== -->

            <?php if ($errors): ?>

                <div class="alert alert-danger">

                    <strong>

                        <i
                            class="bi bi-exclamation-triangle-fill me-1"
                        ></i>

                        Please correct the following:

                    </strong>

                    <ul class="mb-0 mt-2">

                        <?php foreach (
                            $errors as $error
                        ): ?>

                            <li>
                                <?= e($error) ?>
                            </li>

                        <?php endforeach; ?>

                    </ul>

                </div>

            <?php endif; ?>


            <!-- =========================================================
                 GST LOCKED DATA
            ========================================================== -->

            <div class="card shadow-sm mb-4">

                <div
                    class="card-header bg-dark text-white py-3"
                >

                    <div
                        class="d-flex align-items-center gap-2"
                    >

                        <i class="bi bi-shield-lock-fill"></i>

                        <div>

                            <div class="fw-bold">
                                GST Verified Information
                            </div>

                            <small
                                class="text-white-50"
                            >
                                These fields come from the
                                imported GST candidate record.
                            </small>

                        </div>

                    </div>

                </div>


                <div class="card-body">

                    <div class="row g-3">


                        <?php

                        $lockedFields = [

                            'Full Name' =>
                                $fullName,

                            'GST Roll' =>
                                $gstRoll,

                            'GST Applicant ID' =>
                                $profile[
                                    'gst_applicant_id'
                                ],

                            'GST Unit' =>
                                $profile[
                                    'gst_unit'
                                ],

                            'GST Score' =>
                                $gstScore,

                            'GST Merit Position' =>
                                hasValue(
                                    $profile[
                                        'merit_position'
                                    ]
                                )
                                    ? '#'
                                        . (int)
                                        $profile[
                                            'merit_position'
                                        ]
                                    : 'N/A',

                            'Date of Birth' =>
                                $dateOfBirth !== 'N/A'
                                    ? (
                                        strtotime(
                                            $dateOfBirth
                                        )
                                        !== false
                                            ? date(
                                                'd M Y',
                                                strtotime(
                                                    $dateOfBirth
                                                )
                                            )
                                            : $dateOfBirth
                                    )
                                    : 'N/A',

                            'Gender' =>
                                $gender,

                            'Nationality' =>
                                $nationality,

                            'Quota' =>
                                $quota,

                            'Academic Year' =>
                                $profile[
                                    'academic_year'
                                ]
                                ?? 'N/A',
                        ];

                        foreach (
                            $lockedFields as
                            $label => $value
                        ):

                        ?>

                            <div class="col-md-4">

                                <label
                                    class="form-label small text-muted mb-1"
                                >

                                    <?= e($label) ?>

                                </label>


                                <div
                                    class="form-control bg-light"
                                    style="
                                        min-height:38px;
                                        cursor:not-allowed;
                                    "
                                >

                                    <?= e(
                                        hasValue($value)
                                            ? $value
                                            : 'N/A'
                                    ) ?>

                                </div>

                            </div>

                        <?php endforeach; ?>

                    </div>


                    <div
                        class="alert alert-info py-2 small mt-3 mb-0"
                    >

                        <i class="bi bi-info-circle me-1"></i>

                        Corrections to GST-supplied information
                        should be handled administratively rather
                        than changed from this page.

                    </div>

                </div>

            </div>


            <!-- =========================================================
                 EDITABLE FORM
            ========================================================== -->

            <form
                method="POST"
                enctype="multipart/form-data"
                id="editProfileForm"
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


                <!-- =====================================================
                     CONTACT INFORMATION
                ====================================================== -->

                <div class="card shadow-sm mb-4">

                    <div class="card-header py-3">

                        <strong>

                            <i
                                class="bi bi-envelope-at text-primary me-1"
                            ></i>

                            Contact Information

                        </strong>

                    </div>


                    <div class="card-body">

                        <div class="row g-3">


                            <!-- EMAIL -->

                            <div class="col-md-6">

                                <label
                                    for="email"
                                    class="form-label fw-semibold"
                                >

                                    Email Address

                                    <span
                                        class="badge bg-secondary ms-1"
                                    >
                                        Optional
                                    </span>

                                </label>


                                <input
                                    type="email"
                                    name="email"
                                    id="email"
                                    class="form-control"
                                    maxlength="100"
                                    value="<?= e(
                                        $_POST['email']
                                        ?? $profile['email']
                                        ?? ''
                                    ) ?>"
                                    placeholder="you@example.com"
                                >


                                <div class="form-text">

                                    You may add or update
                                    your email address.

                                </div>

                            </div>


                            <!-- PHONE -->

                            <div class="col-md-6">

                                <label
                                    for="phone"
                                    class="form-label fw-semibold"
                                >

                                    Phone Number

                                    <?php if (
                                        $importedPhoneExists
                                    ): ?>

                                        <span
                                            class="badge bg-success ms-1"
                                        >
                                            GST Imported
                                        </span>

                                    <?php endif; ?>

                                </label>


                                <?php if (
                                    $importedPhoneExists
                                ): ?>

                                    <input
                                        type="text"
                                        id="phone"
                                        class="form-control bg-light"
                                        value="<?= e(
                                            $currentPhone
                                        ) ?>"
                                        readonly
                                    >


                                    <div class="form-text">

                                        This number came from
                                        the GST candidate file
                                        and is locked.

                                    </div>


                                <?php else: ?>


                                    <input
                                        type="text"
                                        name="phone"
                                        id="phone"
                                        class="form-control"
                                        maxlength="11"
                                        value="<?= e(
                                            $_POST['phone']
                                            ?? $currentPhone
                                        ) ?>"
                                        placeholder="01XXXXXXXXX"
                                    >


                                    <div class="form-text">

                                        Enter a Bangladeshi
                                        mobile number.

                                    </div>

                                <?php endif; ?>

                            </div>

                        </div>

                    </div>

                </div>


                <!-- =====================================================
                     PHOTO / SIGNATURE
                ====================================================== -->

                <div class="card shadow-sm mb-4">

                    <div class="card-header py-3">

                        <strong>

                            <i
                                class="bi bi-images text-primary me-1"
                            ></i>

                            Photo & Signature

                        </strong>

                    </div>


                    <div class="card-body">

                        <div class="row g-4">


                            <!-- PHOTO -->

                            <div class="col-md-6">

                                <label
                                    class="form-label fw-semibold"
                                >

                                    Applicant Photo

                                </label>


                                <div
                                    class="border rounded bg-light p-3 mb-3 text-center"
                                >

                                    <img
                                        id="photoPreview"
                                        src="<?= hasValue(
                                            $profile['photo']
                                        )
                                            ? '/jkkniu-admission/uploads/photos/' .
                                                e(
                                                    basename(
                                                        (string)
                                                        $profile[
                                                            'photo'
                                                        ]
                                                    )
                                                )
                                            : '/jkkniu-admission/assets/img/default-avatar.png'
                                        ?>"
                                        alt="Applicant Photo"
                                        class="rounded border bg-white"
                                        style="
                                            width:105px;
                                            height:125px;
                                            object-fit:cover;
                                        "
                                    >

                                </div>


                                <input
                                    type="file"
                                    name="photo"
                                    id="photoInput"
                                    class="form-control"
                                    accept=".jpg,.jpeg,.png,image/jpeg,image/png"
                                >


                                <div class="form-text">

                                    JPG or PNG.
                                    Maximum 2 MB.
                                    Leave empty to keep
                                    the current photo.

                                </div>

                            </div>


                            <!-- SIGNATURE -->

                            <div class="col-md-6">

                                <label
                                    class="form-label fw-semibold"
                                >

                                    Applicant Signature

                                </label>


                                <div
                                    class="border rounded bg-light p-3 mb-3 text-center"
                                    style="min-height:159px;"
                                >

                                    <?php if (
                                        hasValue(
                                            $profile[
                                                'signature'
                                            ]
                                        )
                                    ): ?>

                                        <img
                                            id="signaturePreview"
                                            src="/jkkniu-admission/uploads/signatures/<?= e(
                                                basename(
                                                    (string)
                                                    $profile[
                                                        'signature'
                                                    ]
                                                )
                                            ) ?>"
                                            alt="Applicant Signature"
                                            class="bg-white border rounded p-2"
                                            style="
                                                max-width:220px;
                                                height:75px;
                                                object-fit:contain;
                                            "
                                        >

                                    <?php else: ?>

                                        <img
                                            id="signaturePreview"
                                            src=""
                                            alt="Signature Preview"
                                            class="bg-white border rounded p-2 d-none"
                                            style="
                                                max-width:220px;
                                                height:75px;
                                                object-fit:contain;
                                            "
                                        >

                                        <div
                                            id="signaturePlaceholder"
                                            class="text-muted small py-4"
                                        >
                                            No signature uploaded
                                        </div>

                                    <?php endif; ?>

                                </div>


                                <input
                                    type="file"
                                    name="signature"
                                    id="signatureInput"
                                    class="form-control"
                                    accept=".jpg,.jpeg,.png,image/jpeg,image/png"
                                >


                                <div class="form-text">

                                    JPG or PNG.
                                    Maximum 1 MB.
                                    Leave empty to keep
                                    the current signature.

                                </div>

                            </div>

                        </div>

                    </div>

                </div>


                <!-- =====================================================
                     FATHER / MOTHER
                ====================================================== -->

                <div class="card shadow-sm mb-4">

                    <div class="card-header py-3">

                        <strong>

                            <i
                                class="bi bi-lock-fill text-secondary me-1"
                            ></i>

                            Submitted Applicant Information

                        </strong>

                    </div>


                    <div class="card-body">

                        <div class="row g-3">


                            <div class="col-md-6">

                                <label
                                    class="form-label small text-muted"
                                >
                                    Father's Name
                                </label>

                                <input
                                    type="text"
                                    class="form-control bg-light"
                                    value="<?= e(
                                        $profile[
                                            'father_name'
                                        ]
                                        ?: 'N/A'
                                    ) ?>"
                                    readonly
                                >

                            </div>


                            <div class="col-md-6">

                                <label
                                    class="form-label small text-muted"
                                >
                                    Mother's Name
                                </label>

                                <input
                                    type="text"
                                    class="form-control bg-light"
                                    value="<?= e(
                                        $profile[
                                            'mother_name'
                                        ]
                                        ?: 'N/A'
                                    ) ?>"
                                    readonly
                                >

                            </div>

                        </div>


                        <div
                            class="form-text mt-2"
                        >

                            These fields were completed during
                            applicant profile completion and are
                            not edited from this page.

                        </div>

                    </div>

                </div>


                <!-- =====================================================
                     ACTIONS
                ====================================================== -->

                <div
                    class="d-flex flex-wrap justify-content-between gap-2 mb-5"
                >

                    <a
                        href="/jkkniu-admission/student/dashboard.php"
                        class="btn btn-outline-secondary"
                    >

                        Cancel

                    </a>


                    <button
                        type="submit"
                        class="btn btn-primary px-4"
                    >

                        <i class="bi bi-save-fill me-1"></i>

                        Save Changes

                    </button>

                </div>

            </form>

        </div>

    </div>

</div>


<script>

function previewLocalImage(
    input,
    previewId,
    maxBytes,
    emptyPlaceholderId = null
) {

    if (
        !input.files ||
        !input.files[0]
    ) {
        return;
    }

    const file = input.files[0];

    if (
        ![
            'image/jpeg',
            'image/png'
        ].includes(file.type)
    ) {

        alert(
            'Only JPG and PNG images are allowed.'
        );

        input.value = '';

        return;
    }

    if (file.size > maxBytes) {

        alert(
            'Selected image exceeds the allowed size.'
        );

        input.value = '';

        return;
    }


    const preview =
        document.getElementById(
            previewId
        );

    if (!preview) {
        return;
    }


    const reader =
        new FileReader();


    reader.onload =
        function (event) {

            preview.src =
                event.target.result;

            preview.classList.remove(
                'd-none'
            );


            if (emptyPlaceholderId) {

                const placeholder =
                    document.getElementById(
                        emptyPlaceholderId
                    );

                if (placeholder) {
                    placeholder.style.display =
                        'none';
                }
            }
        };


    reader.readAsDataURL(
        file
    );
}


const photoInput =
    document.getElementById(
        'photoInput'
    );

if (photoInput) {

    photoInput.addEventListener(
        'change',
        function () {

            previewLocalImage(
                this,
                'photoPreview',
                2 * 1024 * 1024
            );
        }
    );
}


const signatureInput =
    document.getElementById(
        'signatureInput'
    );

if (signatureInput) {

    signatureInput.addEventListener(
        'change',
        function () {

            previewLocalImage(
                this,
                'signaturePreview',
                1 * 1024 * 1024,
                'signaturePlaceholder'
            );
        }
    );
}

</script>


<?php require_once '../includes/footer.php'; ?>
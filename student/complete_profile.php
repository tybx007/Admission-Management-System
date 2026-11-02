<?php

declare(strict_types=1);

$pageTitle = 'Complete Applicant Profile';

require_once '../includes/auth.php';
require_once '../config/db.php';

requireLogin('student');

$applicantId = (int) ($_SESSION['user_id'] ?? 0);

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

function hasProfileValue(mixed $value): bool
{
    return $value !== null &&
        trim((string) $value) !== '';
}

function effectiveValue(
    array $profile,
    ?string $importedKey,
    string $applicantKey
): mixed {
    if (
        $importedKey !== null &&
        array_key_exists($importedKey, $profile) &&
        hasProfileValue($profile[$importedKey])
    ) {
        return $profile[$importedKey];
    }

    return $profile[$applicantKey] ?? null;
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
            'Enter a valid Bangladeshi phone number.'
        );
    }

    return $phone;
}

function cleanText(
    mixed $value,
    int $maxLength,
    string $label,
    bool $required = true
): ?string {
    $value = trim((string) $value);

    if ($value === '') {
        if ($required) {
            throw new RuntimeException(
                $label . ' is required.'
            );
        }

        return null;
    }

    if (mb_strlen($value, 'UTF-8') > $maxLength) {
        throw new RuntimeException(
            $label . ' cannot exceed ' .
            $maxLength . ' characters.'
        );
    }

    return $value;
}

function cleanEmail(
    mixed $value,
    bool $required = false
): ?string {
    $value = strtolower(trim((string) $value));

    if ($value === '') {
        if ($required) {
            throw new RuntimeException(
                'Email address is required.'
            );
        }

        return null;
    }

    if (
        !filter_var(
            $value,
            FILTER_VALIDATE_EMAIL
        )
    ) {
        throw new RuntimeException(
            'Enter a valid email address.'
        );
    }

    if (mb_strlen($value, 'UTF-8') > 100) {
        throw new RuntimeException(
            'Email address cannot exceed 100 characters.'
        );
    }

    return $value;
}

function cleanDate(
    mixed $value,
    string $label
): string {
    $value = trim((string) $value);

    $date = DateTime::createFromFormat(
        '!Y-m-d',
        $value
    );

    $errors = DateTime::getLastErrors();

    if (
        !$date ||
        (
            $errors !== false &&
            (
                $errors['warning_count'] > 0 ||
                $errors['error_count'] > 0
            )
        ) ||
        $date->format('Y-m-d') !== $value
    ) {
        throw new RuntimeException(
            $label . ' is invalid.'
        );
    }

    return $value;
}

function cleanYear(
    mixed $value,
    string $label
): int {
    $value = trim((string) $value);

    if (
        filter_var(
            $value,
            FILTER_VALIDATE_INT
        ) === false
    ) {
        throw new RuntimeException(
            $label . ' must be a valid year.'
        );
    }

    $year = (int) $value;

    if ($year < 1901 || $year > 2155) {
        throw new RuntimeException(
            $label . ' is outside the allowed range.'
        );
    }

    return $year;
}

function cleanGpa(
    mixed $value,
    string $label
): float {
    $value = trim((string) $value);

    if (!is_numeric($value)) {
        throw new RuntimeException(
            $label . ' must be numeric.'
        );
    }

    $gpa = (float) $value;

    if ($gpa < 0 || $gpa > 5) {
        throw new RuntimeException(
            $label . ' must be between 0 and 5.'
        );
    }

    return $gpa;
}


/**
 * Save a required profile image.
 */
function saveRequiredImage(
    array $file,
    string $directory,
    string $prefix
): array {
    $error = (int) (
        $file['error'] ?? UPLOAD_ERR_NO_FILE
    );

    if ($error === UPLOAD_ERR_NO_FILE) {
        throw new RuntimeException(
            ucfirst($prefix) . ' is required.'
        );
    }

    if ($error !== UPLOAD_ERR_OK) {
        throw new RuntimeException(
            ucfirst($prefix) . ' upload failed.'
        );
    }

    $size = (int) ($file['size'] ?? 0);

    if (
        $size <= 0 ||
        $size > 2 * 1024 * 1024
    ) {
        throw new RuntimeException(
            ucfirst($prefix) .
            ' must be 2 MB or smaller.'
        );
    }

    $temporaryPath =
        (string) ($file['tmp_name'] ?? '');

    if (
        $temporaryPath === '' ||
        !is_uploaded_file($temporaryPath)
    ) {
        throw new RuntimeException(
            ucfirst($prefix) .
            ' upload could not be verified.'
        );
    }

    $finfo = new finfo(
        FILEINFO_MIME_TYPE
    );

    $mime = $finfo->file(
        $temporaryPath
    );

    $allowed = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
    ];

    if (!isset($allowed[$mime])) {
        throw new RuntimeException(
            ucfirst($prefix) .
            ' must be JPG or PNG.'
        );
    }

    if (!is_dir($directory)) {
        if (
            !mkdir(
                $directory,
                0775,
                true
            ) &&
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

    if (
        !move_uploaded_file(
            $temporaryPath,
            $destination
        )
    ) {
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
| Fetch account + imported GST data
|--------------------------------------------------------------------------
*/

$profileStmt = $pdo->prepare("
    SELECT
        ga.applicant_id,
        ga.gst_roll,
        ga.full_name,
        ga.email,
        ga.phone,
        ga.date_of_birth,
        ga.gender,
        ga.nationality,
        ga.quota,
        ga.father_name,
        ga.mother_name,

        ga.hsc_roll,
        ga.hsc_reg,
        ga.hsc_group,
        ga.hsc_board,
        ga.hsc_year,
        ga.hsc_gpa,
        ga.hsc_institute,

        ga.ssc_roll,
        ga.ssc_reg,
        ga.ssc_board,
        ga.ssc_year,
        ga.ssc_gpa,
        ga.ssc_institute,

        ga.photo,
        ga.signature,

        gi.import_candidate_id,
        gi.gst_applicant_id,
        gi.gst_unit,
        gi.gst_score AS imported_gst_score,
        gi.merit_position,
        gi.full_name AS imported_full_name,
        gi.phone AS imported_phone,

        gi.date_of_birth AS imported_date_of_birth,
        gi.gender AS imported_gender,
        gi.nationality AS imported_nationality,
        gi.quota AS imported_quota,

        gi.hsc_roll AS imported_hsc_roll,
        gi.hsc_reg AS imported_hsc_reg,
        gi.hsc_group AS imported_hsc_group,
        gi.hsc_board AS imported_hsc_board,
        gi.hsc_year AS imported_hsc_year,
        gi.hsc_gpa AS imported_hsc_gpa,
        gi.hsc_institute AS imported_hsc_institute,

        gi.ssc_roll AS imported_ssc_roll,
        gi.ssc_reg AS imported_ssc_reg,
        gi.ssc_board AS imported_ssc_board,
        gi.ssc_year AS imported_ssc_year,
        gi.ssc_gpa AS imported_ssc_gpa,
        gi.ssc_institute AS imported_ssc_institute,

        gi.physics_mark,
        gi.chemistry_mark,
        gi.mathematics_mark,
        gi.biology_mark,
        gi.bangla_mark,
        gi.english_mark,
        gi.general_knowledge_mark,

        gi.academic_year,
        gi.selection_status,
        gi.selected_university_name

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
        'Your registered account is not linked ' .
        'to an imported GST candidate.'
    );
}

$importCandidateId =
    (int) $profile['import_candidate_id'];


/*
|--------------------------------------------------------------------------
| Fetch imported department choices
|--------------------------------------------------------------------------
|
| These are NEVER edited here.
|--------------------------------------------------------------------------
*/

$choiceStmt = $pdo->prepare("
    SELECT
        gcc.preference_order,
        d.dept_code,
        d.dept_name

    FROM gst_candidate_choices gcc

    INNER JOIN departments d
        ON d.dept_id = gcc.dept_id

    WHERE gcc.import_candidate_id = ?

    ORDER BY gcc.preference_order ASC
");

$choiceStmt->execute([
    $importCandidateId
]);

$departmentChoices =
    $choiceStmt->fetchAll(
        PDO::FETCH_ASSOC
    );


/*
|--------------------------------------------------------------------------
| Dynamic field definitions
|--------------------------------------------------------------------------
|
| A field is shown only when BOTH:
| 1. GST imported value is empty
| 2. gst_applicants value is empty
|--------------------------------------------------------------------------
*/

$fieldGroups = [

    'Personal Information' => [

        'father_name' => [
            'label' => "Father's Name",
            'type' => 'text',
            'required' => true,
            'imported' => null,
            'max' => 150,
        ],

        'mother_name' => [
            'label' => "Mother's Name",
            'type' => 'text',
            'required' => true,
            'imported' => null,
            'max' => 150,
        ],

        'date_of_birth' => [
            'label' => 'Date of Birth',
            'type' => 'date',
            'required' => true,
            'imported' =>
                'imported_date_of_birth',
        ],

        'gender' => [
            'label' => 'Gender',
            'type' => 'select',
            'required' => true,
            'imported' =>
                'imported_gender',
            'options' => [
                'Male',
                'Female',
                'Other',
            ],
        ],

        'nationality' => [
            'label' => 'Nationality',
            'type' => 'text',
            'required' => true,
            'imported' =>
                'imported_nationality',
            'max' => 50,
        ],

        'phone' => [
            'label' => 'Phone Number',
            'type' => 'tel',
            'required' => true,
            'imported' =>
                'imported_phone',
            'max' => 20,
        ],

    ],


    'HSC Information' => [

        'hsc_roll' => [
            'label' => 'HSC Roll',
            'type' => 'text',
            'required' => true,
            'imported' =>
                'imported_hsc_roll',
            'max' => 20,
        ],

        'hsc_reg' => [
            'label' => 'HSC Registration',
            'type' => 'text',
            'required' => true,
            'imported' =>
                'imported_hsc_reg',
            'max' => 20,
        ],

        'hsc_group' => [
            'label' => 'HSC Group',
            'type' => 'text',
            'required' => true,
            'imported' =>
                'imported_hsc_group',
            'max' => 50,
        ],

        'hsc_board' => [
            'label' => 'HSC Board',
            'type' => 'text',
            'required' => true,
            'imported' =>
                'imported_hsc_board',
            'max' => 100,
        ],

        'hsc_year' => [
            'label' => 'HSC Year',
            'type' => 'number',
            'required' => true,
            'imported' =>
                'imported_hsc_year',
        ],

        'hsc_gpa' => [
            'label' => 'HSC GPA',
            'type' => 'number',
            'required' => true,
            'imported' =>
                'imported_hsc_gpa',
        ],

        'hsc_institute' => [
            'label' => 'HSC Institute',
            'type' => 'text',
            'required' => true,
            'imported' =>
                'imported_hsc_institute',
            'max' => 200,
        ],

    ],


    'SSC Information' => [

        'ssc_roll' => [
            'label' => 'SSC Roll',
            'type' => 'text',
            'required' => true,
            'imported' =>
                'imported_ssc_roll',
            'max' => 20,
        ],

        'ssc_reg' => [
            'label' => 'SSC Registration',
            'type' => 'text',
            'required' => true,
            'imported' =>
                'imported_ssc_reg',
            'max' => 20,
        ],

        'ssc_board' => [
            'label' => 'SSC Board',
            'type' => 'text',
            'required' => true,
            'imported' =>
                'imported_ssc_board',
            'max' => 100,
        ],

        'ssc_year' => [
            'label' => 'SSC Year',
            'type' => 'number',
            'required' => true,
            'imported' =>
                'imported_ssc_year',
        ],

        'ssc_gpa' => [
            'label' => 'SSC GPA',
            'type' => 'number',
            'required' => true,
            'imported' =>
                'imported_ssc_gpa',
        ],

        'ssc_institute' => [
            'label' => 'SSC Institute',
            'type' => 'text',
            'required' => true,
            'imported' =>
                'imported_ssc_institute',
            'max' => 200,
        ],

    ],

];


/*
|--------------------------------------------------------------------------
| Optional contact information
|--------------------------------------------------------------------------
|
| Email is optional because phone signup is allowed.
|--------------------------------------------------------------------------
*/

$optionalFields = [];

if (!hasProfileValue($profile['email'])) {
    $optionalFields['email'] = [
        'label' => 'Email Address',
        'type' => 'email',
        'required' => false,
    ];
}


/*
|--------------------------------------------------------------------------
| Determine currently missing required fields
|--------------------------------------------------------------------------
*/

$missingGroups = [];

$totalRequiredFields = 0;
$completedRequiredFields = 0;

foreach ($fieldGroups as $groupName => $fields) {

    foreach ($fields as $column => $config) {

        $totalRequiredFields++;

        $value = effectiveValue(
            $profile,
            $config['imported'],
            $column
        );

        if (hasProfileValue($value)) {
            $completedRequiredFields++;
            continue;
        }

        $missingGroups[$groupName][$column] =
            $config;
    }
}


/*
|--------------------------------------------------------------------------
| Photo + signature completion
|--------------------------------------------------------------------------
*/

$totalRequiredFields += 2;

$photoMissing =
    !hasProfileValue(
        $profile['photo']
    );

$signatureMissing =
    !hasProfileValue(
        $profile['signature']
    );

if (!$photoMissing) {
    $completedRequiredFields++;
}

if (!$signatureMissing) {
    $completedRequiredFields++;
}

$completionPercentage =
    $totalRequiredFields > 0
        ? (int) round(
            (
                $completedRequiredFields /
                $totalRequiredFields
            ) * 100
        )
        : 100;


/*
|--------------------------------------------------------------------------
| Handle dynamic form submission
|--------------------------------------------------------------------------
*/

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $newFiles = [];

    try {

        $submittedToken =
            $_POST['csrf_token'] ?? '';

        if (
            !is_string($submittedToken) ||
            !hash_equals(
                $_SESSION['csrf_token'],
                $submittedToken
            )
        ) {
            throw new RuntimeException(
                'Invalid request token.'
            );
        }


        /*
        |--------------------------------------------------------------------------
        | Build UPDATE only from fields that were genuinely missing
        |--------------------------------------------------------------------------
        */

        $updates = [];


        foreach (
            $missingGroups as
            $groupName => $fields
        ) {

            foreach (
                $fields as
                $column => $config
            ) {

                $posted =
                    $_POST[$column] ?? '';

                switch ($column) {

                    case 'phone':

                        $updates[$column] =
                            normalizeBdPhone(
                                (string) $posted
                            );

                        break;


                    case 'date_of_birth':

                        $updates[$column] =
                            cleanDate(
                                $posted,
                                $config['label']
                            );

                        break;


                    case 'gender':

                        $gender =
                            cleanText(
                                $posted,
                                10,
                                'Gender'
                            );

                        if (
                            !in_array(
                                $gender,
                                [
                                    'Male',
                                    'Female',
                                    'Other'
                                ],
                                true
                            )
                        ) {
                            throw new RuntimeException(
                                'Invalid gender.'
                            );
                        }

                        $updates[$column] =
                            $gender;

                        break;


                    case 'hsc_year':
                    case 'ssc_year':

                        $updates[$column] =
                            cleanYear(
                                $posted,
                                $config['label']
                            );

                        break;


                    case 'hsc_gpa':
                    case 'ssc_gpa':

                        $updates[$column] =
                            cleanGpa(
                                $posted,
                                $config['label']
                            );

                        break;


                    default:

                        $updates[$column] =
                            cleanText(
                                $posted,
                                (int) (
                                    $config['max']
                                    ?? 255
                                ),
                                $config['label'],
                                (bool)
                                $config['required']
                            );
                }
            }
        }


        /*
        |--------------------------------------------------------------------------
        | Optional email
        |--------------------------------------------------------------------------
        */

        if (
            isset($optionalFields['email']) &&
            array_key_exists(
                'email',
                $_POST
            )
        ) {

            $email =
                cleanEmail(
                    $_POST['email'],
                    false
                );

            if ($email !== null) {

                $duplicateEmail =
                    $pdo->prepare("
                        SELECT applicant_id
                        FROM gst_applicants
                        WHERE email = ?
                          AND applicant_id <> ?
                        LIMIT 1
                    ");

                $duplicateEmail->execute([
                    $email,
                    $applicantId
                ]);

                if (
                    $duplicateEmail->fetchColumn()
                ) {
                    throw new RuntimeException(
                        'This email address is already used by another account.'
                    );
                }

                $updates['email'] =
                    $email;
            }
        }


        /*
        |--------------------------------------------------------------------------
        | Missing photo
        |--------------------------------------------------------------------------
        */

        if ($photoMissing) {

            $photo =
                saveRequiredImage(
                    $_FILES['photo'] ?? [],
                    '../uploads/photos',
                    'photo'
                );

            $updates['photo'] =
                $photo['filename'];

            $newFiles[] =
                $photo['path'];
        }


        /*
        |--------------------------------------------------------------------------
        | Missing signature
        |--------------------------------------------------------------------------
        */

        if ($signatureMissing) {

            $signature =
                saveRequiredImage(
                    $_FILES['signature']
                        ?? [],
                    '../uploads/signatures',
                    'signature'
                );

            $updates['signature'] =
                $signature['filename'];

            $newFiles[] =
                $signature['path'];
        }


        if (!$updates) {

            $_SESSION[
                'complete_profile_success'
            ] =
                'There was no missing required information to save.';

            header(
                'Location: ' .
                basename(
                    $_SERVER['PHP_SELF']
                )
            );

            exit;
        }


        /*
        |--------------------------------------------------------------------------
        | Save only dynamic missing fields
        |--------------------------------------------------------------------------
        */

        $pdo->beginTransaction();


        $setParts = [];
        $parameters = [];


        foreach (
            $updates as
            $column => $value
        ) {

            /*
             * Column names only come from our own
             * server-side configuration.
             */

            $setParts[] =
                "`{$column}` = ?";

            $parameters[] =
                $value;
        }


        $parameters[] =
            $applicantId;


        $updateSql =
            "UPDATE gst_applicants SET " .
            implode(', ', $setParts) .
            " WHERE applicant_id = ?";


        $updateStmt =
            $pdo->prepare(
                $updateSql
            );


        $updateStmt->execute(
            $parameters
        );


        $pdo->commit();


        $_SESSION[
            'complete_profile_success'
        ] =
            'Your missing applicant information has been completed successfully.';


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


        foreach ($newFiles as $path) {

            if (
                is_string($path) &&
                is_file($path)
            ) {
                @unlink($path);
            }
        }


        $errors[] =
            $exception->getMessage();
    }
}


/*
|--------------------------------------------------------------------------
| Success flash
|--------------------------------------------------------------------------
*/

$successMessage = '';

if (
    !empty(
        $_SESSION[
            'complete_profile_success'
        ]
    )
) {

    $successMessage =
        (string)
        $_SESSION[
            'complete_profile_success'
        ];

    unset(
        $_SESSION[
            'complete_profile_success'
        ]
    );


    /*
     * Refresh after save.
     */

    $profileStmt->execute([
        $applicantId
    ]);

    $profile =
        $profileStmt->fetch(
            PDO::FETCH_ASSOC
        );


    /*
     * Rebuild missing state.
     */

    $missingGroups = [];

    $totalRequiredFields = 0;
    $completedRequiredFields = 0;


    foreach (
        $fieldGroups as
        $groupName => $fields
    ) {

        foreach (
            $fields as
            $column => $config
        ) {

            $totalRequiredFields++;

            $value =
                effectiveValue(
                    $profile,
                    $config['imported'],
                    $column
                );

            if (
                hasProfileValue(
                    $value
                )
            ) {

                $completedRequiredFields++;

            } else {

                $missingGroups[
                    $groupName
                ][
                    $column
                ] =
                    $config;
            }
        }
    }


    $totalRequiredFields += 2;

    $photoMissing =
        !hasProfileValue(
            $profile['photo']
        );

    $signatureMissing =
        !hasProfileValue(
            $profile['signature']
        );


    if (!$photoMissing) {
        $completedRequiredFields++;
    }

    if (!$signatureMissing) {
        $completedRequiredFields++;
    }


    $completionPercentage =
        (int) round(
            (
                $completedRequiredFields /
                $totalRequiredFields
            ) * 100
        );


    $optionalFields = [];

    if (
        !hasProfileValue(
            $profile['email']
        )
    ) {

        $optionalFields['email'] = [
            'label' =>
                'Email Address',
            'type' =>
                'email',
            'required' =>
                false,
        ];
    }
}


/*
|--------------------------------------------------------------------------
| Imported-data summary
|--------------------------------------------------------------------------
*/

$importedSummary = [

    'GST Roll' =>
        $profile['gst_roll'],

    'GST Applicant ID' =>
        $profile['gst_applicant_id'],

    'Full Name' =>
        $profile['imported_full_name'],

    'GST Unit' =>
        $profile['gst_unit'],

    'GST Score' =>
        $profile['imported_gst_score'],

    'Merit Position' =>
        $profile['merit_position'],

    'Academic Year' =>
        $profile['academic_year'],

    'Phone' =>
        $profile['imported_phone'],

    'Date of Birth' =>
        $profile[
            'imported_date_of_birth'
        ],

    'Gender' =>
        $profile[
            'imported_gender'
        ],

    'Nationality' =>
        $profile[
            'imported_nationality'
        ],
];


/*
|--------------------------------------------------------------------------
| Determine whether there is anything left to complete
|--------------------------------------------------------------------------
*/

$hasRequiredMissing =
    !empty($missingGroups) ||
    $photoMissing ||
    $signatureMissing;


/*
|--------------------------------------------------------------------------
| Load HTML only after all redirect logic
|--------------------------------------------------------------------------
*/

require_once '../includes/header.php';
require_once '../includes/navbar.php';

?>

<div class="container py-4">


    <!-- ============================================================
         HEADER
    ============================================================= -->

    <div
        class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4"
    >

        <div>

            <h2 class="fw-bold mb-1">

                <i
                    class="bi bi-person-vcard text-primary me-2"
                ></i>

                Complete Applicant Profile

            </h2>

            <p class="text-muted mb-0">

                Only information missing from your
                GST record and applicant account is shown.

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


    <!-- ============================================================
         SUCCESS
    ============================================================= -->

    <?php if ($successMessage !== ''): ?>

        <div class="alert alert-success">

            <i
                class="bi bi-check-circle-fill me-1"
            ></i>

            <?= e($successMessage) ?>

        </div>

    <?php endif; ?>


    <!-- ============================================================
         ERRORS
    ============================================================= -->

    <?php if ($errors): ?>

        <div class="alert alert-danger">

            <strong>
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


    <!-- ============================================================
         COMPLETION
    ============================================================= -->

    <div class="card shadow-sm mb-4">

        <div class="card-body">

            <div
                class="d-flex justify-content-between align-items-center mb-2"
            >

                <strong>
                    Required profile completion
                </strong>

                <span class="fw-bold">
                    <?= $completionPercentage ?>%
                </span>

            </div>


            <div
                class="progress"
                style="height:24px;"
            >

                <div
                    class="progress-bar"
                    role="progressbar"
                    style="width:<?= $completionPercentage ?>%;"
                    aria-valuenow="<?= $completionPercentage ?>"
                    aria-valuemin="0"
                    aria-valuemax="100"
                >

                    <?= $completionPercentage ?>%

                </div>

            </div>

        </div>

    </div>


    <!-- ============================================================
         IMPORTED GST INFORMATION
    ============================================================= -->

    <div class="card shadow-sm mb-4">

        <div class="card-header bg-dark text-white">

            <strong>

                <i
                    class="bi bi-cloud-check-fill me-1"
                ></i>

                GST Imported Information

            </strong>

        </div>


        <div class="card-body">

            <div class="row g-3">

                <?php foreach (
                    $importedSummary as
                    $label => $value
                ): ?>

                    <?php if (
                        hasProfileValue($value)
                    ): ?>

                        <div class="col-md-4">

                            <small
                                class="text-muted d-block"
                            >
                                <?= e($label) ?>
                            </small>

                            <span class="fw-semibold">

                                <?= e($value) ?>

                            </span>

                        </div>

                    <?php endif; ?>

                <?php endforeach; ?>

            </div>


            <div
                class="alert alert-light border small mt-3 mb-0"
            >

                <i
                    class="bi bi-lock-fill me-1"
                ></i>

                GST-imported information is authoritative
                and is not requested again.

            </div>

        </div>

    </div>


    <!-- ============================================================
         IMPORTED DEPARTMENT PREFERENCES
    ============================================================= -->

    <div class="card shadow-sm mb-4">

        <div
            class="card-header bg-success text-white"
        >

            <strong>

                <i
                    class="bi bi-list-ol me-1"
                ></i>

                GST Department Preferences

            </strong>

        </div>


        <div class="card-body p-0">

            <?php if ($departmentChoices): ?>

                <div class="list-group list-group-flush">

                    <?php foreach (
                        $departmentChoices as
                        $choice
                    ): ?>

                        <div
                            class="list-group-item d-flex justify-content-between align-items-center gap-3"
                        >

                            <div>

                                <div class="fw-semibold">

                                    <?= e(
                                        $choice[
                                            'dept_name'
                                        ]
                                    ) ?>

                                </div>

                                <?php if (
                                    hasProfileValue(
                                        $choice[
                                            'dept_code'
                                        ]
                                    )
                                ): ?>

                                    <small
                                        class="text-muted"
                                    >

                                        <?= e(
                                            $choice[
                                                'dept_code'
                                            ]
                                        ) ?>

                                    </small>

                                <?php endif; ?>

                            </div>


                            <span
                                class="badge bg-primary rounded-pill"
                            >

                                Choice
                                <?= (int)
                                    $choice[
                                        'preference_order'
                                    ]
                                ?>

                            </span>

                        </div>

                    <?php endforeach; ?>

                </div>

            <?php else: ?>

                <div class="p-3 text-muted">

                    No imported department preferences
                    were found.

                </div>

            <?php endif; ?>

        </div>

    </div>


    <!-- ============================================================
         PROFILE ALREADY COMPLETE
    ============================================================= -->

    <?php if (!$hasRequiredMissing): ?>

        <div
            class="card shadow-sm border-success mb-4"
        >

            <div class="card-body text-center py-5">

                <i
                    class="bi bi-check-circle-fill text-success display-4"
                ></i>


                <h4 class="fw-bold mt-3">

                    Required Profile Information Complete

                </h4>


                <p class="text-muted">

                    No required information is currently missing.

                </p>


                <a
                    href="/jkkniu-admission/student/dashboard.php"
                    class="btn btn-success"
                >

                    Continue to Dashboard

                </a>

            </div>

        </div>

    <?php endif; ?>


    <!-- ============================================================
         DYNAMIC MISSING INFORMATION FORM
    ============================================================= -->

    <?php if (
        $hasRequiredMissing ||
        $optionalFields
    ): ?>

        <form
            method="POST"
            enctype="multipart/form-data"
            id="dynamicProfileForm"
        >

            <input
                type="hidden"
                name="csrf_token"
                value="<?= e(
                    $_SESSION['csrf_token']
                ) ?>"
            >


            <?php foreach (
                $missingGroups as
                $groupName => $fields
            ): ?>

                <div
                    class="card shadow-sm border-warning mb-4"
                >

                    <div
                        class="card-header bg-success text-white"
                    >

                        <strong>

                            <i
                                class="bi bi-exclamation-circle me-1"
                            ></i>

                            Missing:
                            <?= e($groupName) ?>

                        </strong>

                    </div>


                    <div class="card-body">

                        <div class="row g-3">

                            <?php foreach (
                                $fields as
                                $column => $config
                            ): ?>

                                <div class="col-md-6">

                                    <label
                                        class="form-label fw-semibold"
                                        for="<?= e($column) ?>"
                                    >

                                        <?= e(
                                            $config['label']
                                        ) ?>

                                        <?php if (
                                            $config[
                                                'required'
                                            ]
                                        ): ?>

                                            <span
                                                class="text-danger"
                                            >
                                                *
                                            </span>

                                        <?php endif; ?>

                                    </label>


                                    <?php if (
                                        $config['type']
                                        === 'select'
                                    ): ?>

                                        <select
                                            name="<?= e($column) ?>"
                                            id="<?= e($column) ?>"
                                            class="form-select"
                                            required
                                        >

                                            <option value="">
                                                Select
                                                <?= e(
                                                    $config[
                                                        'label'
                                                    ]
                                                ) ?>
                                            </option>

                                            <?php foreach (
                                                $config[
                                                    'options'
                                                ] as
                                                $option
                                            ): ?>

                                                <option
                                                    value="<?= e($option) ?>"
                                                    <?= (
                                                        ($_POST[
                                                            $column
                                                        ] ?? '')
                                                        ===
                                                        $option
                                                    )
                                                        ? 'selected'
                                                        : ''
                                                    ?>
                                                >

                                                    <?= e($option) ?>

                                                </option>

                                            <?php endforeach; ?>

                                        </select>


                                    <?php else: ?>

                                        <input
                                            type="<?= e(
                                                $config['type']
                                            ) ?>"
                                            name="<?= e($column) ?>"
                                            id="<?= e($column) ?>"
                                            class="form-control"
                                            value="<?= e(
                                                $_POST[$column]
                                                ?? ''
                                            ) ?>"
                                            <?= isset(
                                                $config['max']
                                            )
                                                ? 'maxlength="' .
                                                    (int)
                                                    $config[
                                                        'max'
                                                    ] .
                                                    '"'
                                                : ''
                                            ?>
                                            <?= $column ===
                                                'hsc_gpa' ||
                                                $column ===
                                                'ssc_gpa'
                                                ? 'step="0.01" min="0" max="5"'
                                                : ''
                                            ?>
                                            <?= $column ===
                                                'hsc_year' ||
                                                $column ===
                                                'ssc_year'
                                                ? 'min="1901" max="2155"'
                                                : ''
                                            ?>
                                            <?= $config[
                                                'required'
                                            ]
                                                ? 'required'
                                                : ''
                                            ?>
                                        >

                                    <?php endif; ?>


                                    <div
                                        class="form-text text-warning-emphasis"
                                    >

                                        This field was not found
                                        in the GST import or your
                                        applicant account.

                                    </div>

                                </div>

                            <?php endforeach; ?>

                        </div>

                    </div>

                </div>

            <?php endforeach; ?>


            <!-- ====================================================
                 DOCUMENTS
            ===================================================== -->

            <?php if (
                $photoMissing ||
                $signatureMissing
            ): ?>

                <div
                    class="card shadow-sm border-warning mb-4"
                >

                    <div
                        class="card-header bg-success text-white"
                    >

                        <strong>

                            <i
                                class="bi bi-image-fill me-1"
                            ></i>

                            Missing Documents

                        </strong>

                    </div>


                    <div class="card-body">

                        <div class="row g-4">


                            <?php if (
                                $photoMissing
                            ): ?>

                                <div class="col-md-6">

                                    <label
                                        class="form-label fw-semibold"
                                    >

                                        Passport Photo

                                        <span
                                            class="text-danger"
                                        >
                                            *
                                        </span>

                                    </label>

                                    <input
                                        type="file"
                                        name="photo"
                                        class="form-control"
                                        accept=".jpg,.jpeg,.png,image/jpeg,image/png"
                                        required
                                    >

                                    <div class="form-text">

                                        JPG or PNG,
                                        maximum 2 MB.

                                    </div>

                                </div>

                            <?php endif; ?>


                            <?php if (
                                $signatureMissing
                            ): ?>

                                <div class="col-md-6">

                                    <label
                                        class="form-label fw-semibold"
                                    >

                                        Signature

                                        <span
                                            class="text-danger"
                                        >
                                            *
                                        </span>

                                    </label>

                                    <input
                                        type="file"
                                        name="signature"
                                        class="form-control"
                                        accept=".jpg,.jpeg,.png,image/jpeg,image/png"
                                        required
                                    >

                                    <div class="form-text">

                                        JPG or PNG,
                                        maximum 2 MB.

                                    </div>

                                </div>

                            <?php endif; ?>

                        </div>

                    </div>

                </div>

            <?php endif; ?>


            <!-- ====================================================
                 OPTIONAL EMAIL
            ===================================================== -->

            <?php if (
                isset(
                    $optionalFields['email']
                )
            ): ?>

                <div
                    class="card shadow-sm mb-4"
                >

                    <div class="card-header">

                        <strong>

                            <i
                                class="bi bi-envelope me-1"
                            ></i>

                            Optional Contact Information

                        </strong>

                    </div>


                    <div class="card-body">

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
                                ?? ''
                            ) ?>"
                            placeholder="you@example.com"
                        >


                        <div class="form-text">

                            Phone-based accounts may add
                            an email address later.

                        </div>

                    </div>

                </div>

            <?php endif; ?>


            <div
                class="d-flex justify-content-between gap-2 mb-5"
            >

                <a
                    href="/jkkniu-admission/student/dashboard.php"
                    class="btn btn-outline-secondary"
                >

                    Cancel

                </a>


                <button
                    type="submit"
                    class="btn btn-primary btn-lg"
                >

                    <i
                        class="bi bi-check2-circle me-1"
                    ></i>

                    Save Missing Information

                </button>

            </div>

        </form>

    <?php endif; ?>

</div>


<script>

const dynamicProfileForm =
    document.getElementById(
        'dynamicProfileForm'
    );


if (dynamicProfileForm) {

    dynamicProfileForm.addEventListener(
        'submit',
        function (event) {

            if (
                !dynamicProfileForm
                    .checkValidity()
            ) {

                event.preventDefault();
                event.stopPropagation();
            }

            dynamicProfileForm
                .classList
                .add(
                    'was-validated'
                );
        }
    );
}

</script>


<?php require_once '../includes/footer.php'; ?>
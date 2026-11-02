<?php

declare(strict_types=1);

$pageTitle = 'Import GST Selected Candidates';

require_once '../includes/auth.php';
require_once '../config/db.php';
require_once '../includes/admission_session.php';

requireLogin('registrar');

$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

/**
 * Convert an empty CSV value to NULL.
 */
function csvNullableString(mixed $value, int $maxLength, string $field): ?string
{
    $value = trim((string) $value);

    if ($value === '') {
        return null;
    }

    if (mb_strlen($value, 'UTF-8') > $maxLength) {
        throw new RuntimeException(
            "{$field} is longer than {$maxLength} characters."
        );
    }

    return $value;
}

/**
 * Validate a required CSV string.
 */
function csvRequiredString(mixed $value, int $maxLength, string $field): string
{
    $result = csvNullableString($value, $maxLength, $field);

    if ($result === null) {
        throw new RuntimeException("{$field} is required.");
    }

    return $result;
}

/**
 * Convert an optional numeric CSV value to float.
 */
function csvNullableFloat(
    mixed $value,
    string $field,
    ?float $minimum = null,
    ?float $maximum = null
): ?float {
    $value = trim((string) $value);

    if ($value === '') {
        return null;
    }

    if (!is_numeric($value)) {
        throw new RuntimeException("{$field} must be numeric.");
    }

    $number = (float) $value;

    if ($minimum !== null && $number < $minimum) {
        throw new RuntimeException(
            "{$field} cannot be less than {$minimum}."
        );
    }

    if ($maximum !== null && $number > $maximum) {
        throw new RuntimeException(
            "{$field} cannot be greater than {$maximum}."
        );
    }

    return $number;
}

/**
 * Validate a required numeric CSV value.
 */
function csvRequiredFloat(
    mixed $value,
    string $field,
    ?float $minimum = null,
    ?float $maximum = null
): float {
    $number = csvNullableFloat(
        $value,
        $field,
        $minimum,
        $maximum
    );

    if ($number === null) {
        throw new RuntimeException("{$field} is required.");
    }

    return $number;
}

/**
 * Convert an optional integer CSV value.
 */
function csvNullableInt(
    mixed $value,
    string $field,
    ?int $minimum = null,
    ?int $maximum = null
): ?int {
    $value = trim((string) $value);

    if ($value === '') {
        return null;
    }

    if (filter_var($value, FILTER_VALIDATE_INT) === false) {
        throw new RuntimeException("{$field} must be an integer.");
    }

    $number = (int) $value;

    if ($minimum !== null && $number < $minimum) {
        throw new RuntimeException(
            "{$field} cannot be less than {$minimum}."
        );
    }

    if ($maximum !== null && $number > $maximum) {
        throw new RuntimeException(
            "{$field} cannot be greater than {$maximum}."
        );
    }

    return $number;
}

/**
 * Convert and validate a YYYY-MM-DD date.
 */
function csvNullableDate(mixed $value, string $field): ?string
{
    $value = trim((string) $value);

    if ($value === '') {
        return null;
    }

    $date = DateTime::createFromFormat('!Y-m-d', $value);
    $errors = DateTime::getLastErrors();

    if (
        !$date ||
        ($errors !== false && (
            $errors['warning_count'] > 0 ||
            $errors['error_count'] > 0
        )) ||
        $date->format('Y-m-d') !== $value
    ) {
        throw new RuntimeException(
            "{$field} must use YYYY-MM-DD format."
        );
    }

    return $value;
}

/**
 * Normalize supported gender values.
 */
function csvNullableGender(mixed $value): ?string
{
    $value = strtolower(trim((string) $value));

    if ($value === '') {
        return null;
    }

    return match ($value) {
        'male'   => 'Male',
        'female' => 'Female',
        'other'  => 'Other',
        default  => throw new RuntimeException(
            'gender must be Male, Female, or Other.'
        ),
    };
}

/**
 * Remove UTF-8 BOM and normalize a CSV column name.
 */
function normalizeCsvHeader(string $header): string
{
    $header = preg_replace('/^\xEF\xBB\xBF/', '', $header);

    /*
    | Extra protection for CSV files where quote characters accidentally
    | remain around a parsed header.
    */
    $header = trim(
        (string) $header,
        " \t\n\r\0\x0B\"'"
    );

    $header = strtolower($header);
    $header = preg_replace('/\s+/', '_', $header);

    return $header;
}

/**
 * Choose the most likely CSV delimiter.
 */
function detectCsvDelimiter(string $line): string
{
    $delimiters = [',', ';', "\t"];
    $bestDelimiter = ',';
    $bestCount = -1;

    foreach ($delimiters as $delimiter) {
        $count = substr_count($line, $delimiter);

        if ($count > $bestCount) {
            $bestDelimiter = $delimiter;
            $bestCount = $count;
        }
    }

    return $bestDelimiter;
}

/**
 * Convert a blank string into NULL without changing zero.
 */
function nullableRaw(array $record, string $column): mixed
{
    if (!array_key_exists($column, $record)) {
        return null;
    }

    $value = trim((string) $record[$column]);

    return $value === '' ? null : $value;
}


/**
 * Validate the admission session selected by the Registrar.
 *
 * Example:
 *   2026-2027
 *
 * HSC year is intentionally NOT used to determine admission session because
 * GST may include candidates with a one-year study gap.
 */
function validateAdmissionAcademicYear(mixed $value): string
{
    $value = trim((string) $value);

    if ($value === '') {
        throw new RuntimeException(
            'Admission Session is required.'
        );
    }

    if (
        !preg_match(
            '/^(\d{4})-(\d{4})$/',
            $value,
            $matches
        )
    ) {
        throw new RuntimeException(
            'Admission Session must use YYYY-YYYY format, for example 2026-2027.'
        );
    }

    $startYear = (int) $matches[1];
    $endYear = (int) $matches[2];

    if ($endYear !== $startYear + 1) {
        throw new RuntimeException(
            'Admission Session must contain consecutive years, for example 2026-2027.'
        );
    }

    return $value;
}

/*
|--------------------------------------------------------------------------
| CSV and database columns
|--------------------------------------------------------------------------
|
| The uploaded full template may contain an "index" column. It is ignored.
|--------------------------------------------------------------------------
*/

$databaseColumns = [
    'gst_roll',
    'gst_applicant_id',
    'full_name',
    'gst_unit',
    'gst_score',
    'merit_position',
    'faculty_id',
    'date_of_birth',
    'gender',
    'nationality',
    'quota',
    'phone',
    'hsc_roll',
    'hsc_reg',
    'hsc_group',
    'hsc_board',
    'hsc_year',
    'hsc_gpa',
    'hsc_institute',
    'ssc_roll',
    'ssc_reg',
    'ssc_board',
    'ssc_year',
    'ssc_gpa',
    'ssc_institute',
    'physics_mark',
    'chemistry_mark',
    'mathematics_mark',
    'biology_mark',
    'bangla_mark',
    'english_mark',
    'general_knowledge_mark',
    'academic_year',
    'selected_university_code',
    'selected_university_name',
    'selection_status',
];

$choiceColumns = [];

for ($preference = 1; $preference <= 10; $preference++) {
    $choiceColumns[] = 'choice_' . $preference;
}

/*
| Every preference header must exist in the CSV template so the file format
| stays consistent. Only choice_1 must contain a value; choice_2-choice_10
| may be blank for candidates who submit fewer than 10 preferences.
*/
$requiredCsvColumns = [
    'gst_roll',
    'gst_applicant_id',
    'full_name',
    'gst_score',
    'faculty_id',
    ...$choiceColumns,
];

/*
|--------------------------------------------------------------------------
| Import CSV
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $summary = [
        'type' => 'danger',
        'message' => '',
        'inserted' => 0,
        'updated' => 0,
        'choices_saved' => 0,
        'invalid' => 0,
        'processed' => 0,
        'errors' => [],
        'academic_year' => '',
    ];

    try {
        $submittedToken = $_POST['csrf_token'] ?? '';

        if (
            !is_string($submittedToken) ||
            !hash_equals($_SESSION['csrf_token'], $submittedToken)
        ) {
            throw new RuntimeException(
                'Invalid request token. Refresh the page and try again.'
            );
        }


        $action =
            trim(
                (string) (
                    $_POST['action']
                    ?? 'import_candidates'
                )
            );

        $registrarUserId =
            !empty(
                $_SESSION[
                    'user_id'
                ]
            )
                ? (int)
                  $_SESSION[
                      'user_id'
                  ]
                : null;


        /*
        |--------------------------------------------------------------------------
        | START NEW ADMISSION SESSION
        |--------------------------------------------------------------------------
        */

        if (
            $action ===
            'start_session'
        ) {

            $newAcademicYear =
                trim(
                    (string) (
                        $_POST[
                            'new_academic_year'
                        ]
                        ?? ''
                    )
                );


            $started =
                admissionSessionStart(
                    $pdo,
                    $newAcademicYear,
                    $registrarUserId
                );


            $summary = [
                'type' =>
                    'success',

                'message' =>
                    'Admission Session '
                    . $started[
                        'academic_year'
                    ]
                    . ' started successfully. All current-session counters are now zero until the new GST CSV is imported.',

                'inserted' =>
                    0,

                'updated' =>
                    0,

                'choices_saved' =>
                    0,

                'invalid' =>
                    0,

                'processed' =>
                    0,

                'errors' =>
                    [],

                'academic_year' =>
                    (string)
                    $started[
                        'academic_year'
                    ],
            ];


            $_SESSION[
                'csv_import_summary'
            ] = $summary;


            header(
                'Location: '
                . basename(
                    $_SERVER[
                        'PHP_SELF'
                    ]
                )
            );

            exit;
        }


        /*
        |--------------------------------------------------------------------------
        | CLOSE CURRENT ADMISSION SESSION
        |--------------------------------------------------------------------------
        */

        if (
            $action ===
            'close_session'
        ) {

            $confirmation =
                trim(
                    (string) (
                        $_POST[
                            'close_confirmation'
                        ]
                        ?? ''
                    )
                );


            if (
                $confirmation !==
                'CLOSE'
            ) {
                throw new RuntimeException(
                    'Session close confirmation failed.'
                );
            }


            $openSession =
                admissionSessionGetOpen(
                    $pdo
                );


            if (!$openSession) {
                throw new RuntimeException(
                    'There is no Open admission session to close.'
                );
            }


            $closed =
                admissionSessionClose(
                    $pdo,
                    (string)
                    $openSession[
                        'academic_year'
                    ],
                    $registrarUserId
                );


            $summary = [
                'type' =>
                    'success',

                'message' =>
                    'Admission Session '
                    . $closed[
                        'academic_year'
                    ]
                    . ' has been CLOSED permanently for operational processing. '
                    . (int)
                    $closed[
                        'expired_waiting'
                    ]
                    . ' remaining waiting/called record(s) were archived as Expired. '
                    . 'No candidate, applicant, merit, viva, or admitted-student record was deleted.',

                'inserted' =>
                    0,

                'updated' =>
                    0,

                'choices_saved' =>
                    0,

                'invalid' =>
                    0,

                'processed' =>
                    0,

                'errors' =>
                    [],

                'academic_year' =>
                    (string)
                    $closed[
                        'academic_year'
                    ],
            ];


            $_SESSION[
                'csv_import_summary'
            ] = $summary;


            header(
                'Location: '
                . basename(
                    $_SERVER[
                        'PHP_SELF'
                    ]
                )
            );

            exit;
        }


        if (
            $action !==
            'import_candidates'
        ) {
            throw new RuntimeException(
                'Unknown action.'
            );
        }


        /*
        |--------------------------------------------------------------------------
        | CURRENT OPEN Admission Session
        |--------------------------------------------------------------------------
        |
        | A CSV can only be imported into the database-global Open session.
        | The Registrar cannot accidentally import into a Closed historical year.
        |--------------------------------------------------------------------------
        */

        $openSession =
            admissionSessionGetOpen(
                $pdo
            );


        if (!$openSession) {
            throw new RuntimeException(
                'Start a new Admission Session before importing GST candidates.'
            );
        }


        $selectedAcademicYear =
            (string)
            $openSession[
                'academic_year'
            ];


        $submittedAcademicYear =
            trim(
                (string) (
                    $_POST[
                        'academic_year'
                    ]
                    ?? ''
                )
            );


        if (
            $submittedAcademicYear
            !==
            $selectedAcademicYear
        ) {
            throw new RuntimeException(
                'The active Admission Session changed. Refresh the page and try again.'
            );
        }


        $summary[
            'academic_year'
        ] =
            $selectedAcademicYear;

        if (
            !isset($_FILES['candidate_csv']) ||
            !is_array($_FILES['candidate_csv'])
        ) {
            throw new RuntimeException('Select a CSV file.');
        }

        $upload = $_FILES['candidate_csv'];

        if (($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            $uploadErrors = [
                UPLOAD_ERR_INI_SIZE =>
                    'The file exceeds the server upload limit.',
                UPLOAD_ERR_FORM_SIZE =>
                    'The file exceeds the form upload limit.',
                UPLOAD_ERR_PARTIAL =>
                    'The file was only partially uploaded.',
                UPLOAD_ERR_NO_FILE =>
                    'Select a CSV file.',
                UPLOAD_ERR_NO_TMP_DIR =>
                    'The server temporary directory is missing.',
                UPLOAD_ERR_CANT_WRITE =>
                    'The server could not write the uploaded file.',
                UPLOAD_ERR_EXTENSION =>
                    'A PHP extension stopped the upload.',
            ];

            throw new RuntimeException(
                $uploadErrors[$upload['error']]
                ?? 'The CSV upload failed.'
            );
        }

        $originalName = (string) ($upload['name'] ?? '');
        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

        if ($extension !== 'csv') {
            throw new RuntimeException(
                'Only a .csv file can be imported.'
            );
        }

        $fileSize = (int) ($upload['size'] ?? 0);

        if ($fileSize <= 0) {
            throw new RuntimeException('The uploaded CSV file is empty.');
        }

        if ($fileSize > 10 * 1024 * 1024) {
            throw new RuntimeException(
                'The CSV file cannot be larger than 10 MB.'
            );
        }

        $temporaryPath = (string) ($upload['tmp_name'] ?? '');

        if (
            $temporaryPath === '' ||
            !is_uploaded_file($temporaryPath)
        ) {
            throw new RuntimeException(
                'The uploaded file could not be verified.'
            );
        }

        $handle = fopen($temporaryPath, 'rb');

        if ($handle === false) {
            throw new RuntimeException(
                'The uploaded CSV file could not be opened.'
            );
        }

        $firstLine = fgets($handle);

        if ($firstLine === false) {
            fclose($handle);
            throw new RuntimeException('The CSV file is empty.');
        }

        /*
        |--------------------------------------------------------------------------
        | Remove UTF-8 BOM before parsing the CSV header
        |--------------------------------------------------------------------------
        |
        | When a quoted CSV starts with a BOM, str_getcsv() may return the first
        | header as: \"gst_roll\" including the quote characters. Removing the
        | BOM before str_getcsv() ensures the first column becomes gst_roll.
        |--------------------------------------------------------------------------
        */
        if (substr($firstLine, 0, 3) === "\xEF\xBB\xBF") {
            $firstLine = substr($firstLine, 3);
        }

        $delimiter = detectCsvDelimiter($firstLine);
        $rawHeaders = str_getcsv(
            rtrim($firstLine, "\r\n"),
            $delimiter,
            '"',
            '\\'
        );

        $headers = array_map(
            static fn(mixed $header): string =>
                normalizeCsvHeader((string) $header),
            $rawHeaders
        );

        if (count($headers) !== count(array_unique($headers))) {
            fclose($handle);
            throw new RuntimeException(
                'The CSV contains duplicate column names.'
            );
        }

        $missingColumns = array_values(array_diff(
            $requiredCsvColumns,
            $headers
        ));

        if ($missingColumns) {
            fclose($handle);
            throw new RuntimeException(
                'Missing required CSV columns: ' .
                implode(', ', $missingColumns)
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Load valid faculty IDs
        |--------------------------------------------------------------------------
        */

        $facultyIds = $pdo->query("
            SELECT faculty_id
            FROM faculties
        ")->fetchAll(PDO::FETCH_COLUMN);

        $validFacultyIds = [];

        foreach ($facultyIds as $facultyId) {
            $validFacultyIds[(int) $facultyId] = true;
        }

        if (!$validFacultyIds) {
            fclose($handle);
            throw new RuntimeException(
                'No faculties exist in the database.'
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Load department codes used by choice_1-choice_10
        |--------------------------------------------------------------------------
        */

        $departmentRows = $pdo->query("
            SELECT
                dept_id,
                dept_code,
                faculty_id,
                dept_name
            FROM departments
            WHERE dept_code IS NOT NULL
              AND TRIM(dept_code) <> ''
        ")->fetchAll(PDO::FETCH_ASSOC);

        $departmentsByCode = [];

        foreach ($departmentRows as $department) {
            $deptCode = strtoupper(trim((string) $department['dept_code']));

            if ($deptCode === '') {
                continue;
            }

            if (isset($departmentsByCode[$deptCode])) {
                fclose($handle);
                throw new RuntimeException(
                    "Duplicate department code {$deptCode} exists in departments."
                );
            }

            $departmentsByCode[$deptCode] = [
                'dept_id' => (int) $department['dept_id'],
                'faculty_id' => (int) $department['faculty_id'],
                'dept_name' => (string) $department['dept_name'],
            ];
        }

        if (!$departmentsByCode) {
            fclose($handle);
            throw new RuntimeException(
                'No department codes are configured. Set departments.dept_code before importing preferences.'
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Prepare reusable SQL statements
        |--------------------------------------------------------------------------
        */

        $quotedInsertColumns = implode(
            ', ',
            array_map(
                static fn(string $column): string => "`{$column}`",
                $databaseColumns
            )
        );

        $insertPlaceholders = implode(
            ', ',
            array_fill(0, count($databaseColumns), '?')
        );

        $insertStmt = $pdo->prepare("
            INSERT INTO gst_imported_candidates (
                {$quotedInsertColumns}
            )
            VALUES (
                {$insertPlaceholders}
            )
        ");

        $updateColumns = array_values(array_filter(
            $databaseColumns,
            static fn(string $column): bool =>
                !in_array(
                    $column,
                    [
                        'gst_roll',
                        'gst_applicant_id',
                        'academic_year'
                    ],
                    true
                )
        ));

        $updateSet = implode(
            ', ',
            array_map(
                static fn(string $column): string =>
                    "`{$column}` = ?",
                $updateColumns
            )
        );

        $updateStmt = $pdo->prepare("
            UPDATE gst_imported_candidates
            SET {$updateSet}
            WHERE import_candidate_id = ?
        ");

        $findExistingStmt = $pdo->prepare("
            SELECT
                import_candidate_id,
                gst_roll,
                gst_applicant_id,
                academic_year,
                is_registered,
                applicant_id

            FROM gst_imported_candidates

            WHERE academic_year = ?

              AND (
                    gst_roll = ?
                    OR gst_applicant_id = ?
              )

            FOR UPDATE
        ");

        $deleteChoicesStmt = $pdo->prepare("
            DELETE FROM gst_candidate_choices
            WHERE import_candidate_id = ?
        ");

        $insertChoiceStmt = $pdo->prepare("
            INSERT INTO gst_candidate_choices (
                import_candidate_id,
                dept_id,
                preference_order
            )
            VALUES (?, ?, ?)
        ");

        $existingChoicesStmt = $pdo->prepare("
            SELECT d.dept_code
            FROM gst_candidate_choices gcc
            INNER JOIN departments d
                ON d.dept_id = gcc.dept_id
            WHERE gcc.import_candidate_id = ?
            ORDER BY gcc.preference_order ASC
        ");

        $candidateProcessStmt = $pdo->prepare("
            SELECT (
                EXISTS(
                    SELECT 1
                    FROM merit_allocations
                    WHERE import_candidate_id = ?
                )
                OR
                EXISTS(
                    SELECT 1
                    FROM waiting_list
                    WHERE import_candidate_id = ?
                )
            ) AS has_process_data
        ");

        $pdo->beginTransaction();

        $lineNumber = 1;
        $seenRolls = [];
        $seenApplicantIds = [];
        $maximumRows = 50000;

        while (
            ($row = fgetcsv(
                $handle,
                0,
                $delimiter,
                '"',
                '\\'
            )) !== false
        ) {
            $lineNumber++;

            $nonEmptyValues = array_filter(
                $row,
                static fn(mixed $value): bool =>
                    trim((string) $value) !== ''
            );

            if (!$nonEmptyValues) {
                continue;
            }

            if ($summary['processed'] >= $maximumRows) {
                throw new RuntimeException(
                    "The CSV exceeds the {$maximumRows}-row limit."
                );
            }

            $summary['processed']++;

            try {
                /*
                 * Each row gets a savepoint so a validation/database failure
                 * cannot leave a partially inserted candidate or preference set.
                 */
                $pdo->exec('SAVEPOINT csv_candidate_row');

                if (count($row) > count($headers)) {
                    throw new RuntimeException(
                        'The row contains more values than the header.'
                    );
                }

                if (count($row) < count($headers)) {
                    $row = array_pad(
                        $row,
                        count($headers),
                        ''
                    );
                }

                $record = array_combine($headers, $row);

                if ($record === false) {
                    throw new RuntimeException(
                        'The row could not be mapped to CSV columns.'
                    );
                }

                /*
                |--------------------------------------------------------------------------
                | Validate and normalize one candidate row
                |--------------------------------------------------------------------------
                */

                $gstRoll = csvRequiredString(
                    nullableRaw($record, 'gst_roll'),
                    20,
                    'gst_roll'
                );

                $gstApplicantId = csvRequiredString(
                    nullableRaw($record, 'gst_applicant_id'),
                    30,
                    'gst_applicant_id'
                );

                if (isset($seenRolls[$gstRoll])) {
                    throw new RuntimeException(
                        "Duplicate gst_roll in this file; first seen on line " .
                        $seenRolls[$gstRoll] . '.'
                    );
                }

                if (isset($seenApplicantIds[$gstApplicantId])) {
                    throw new RuntimeException(
                        "Duplicate gst_applicant_id in this file; first seen on line " .
                        $seenApplicantIds[$gstApplicantId] . '.'
                    );
                }

                $seenRolls[$gstRoll] = $lineNumber;
                $seenApplicantIds[$gstApplicantId] = $lineNumber;

                $facultyId = csvNullableInt(
                    nullableRaw($record, 'faculty_id'),
                    'faculty_id',
                    1
                );

                if ($facultyId === null) {
                    throw new RuntimeException(
                        'faculty_id is required.'
                    );
                }

                if (!isset($validFacultyIds[$facultyId])) {
                    throw new RuntimeException(
                        "faculty_id {$facultyId} does not exist in faculties."
                    );
                }

                $selectionStatus = csvNullableString(
                    nullableRaw($record, 'selection_status'),
                    30,
                    'selection_status'
                ) ?? 'Selected';

                $nationality = csvNullableString(
                    nullableRaw($record, 'nationality'),
                    50,
                    'nationality'
                ) ?? 'Bangladeshi';

                $data = [
                    'gst_roll' => $gstRoll,
                    'gst_applicant_id' => $gstApplicantId,
                    'full_name' => csvRequiredString(
                        nullableRaw($record, 'full_name'),
                        150,
                        'full_name'
                    ),
                    'gst_unit' => csvNullableString(
                        nullableRaw($record, 'gst_unit'),
                        10,
                        'gst_unit'
                    ),
                    'gst_score' => csvRequiredFloat(
                        nullableRaw($record, 'gst_score'),
                        'gst_score',
                        0,
                        9999.99
                    ),
                    'merit_position' => csvNullableInt(
                        nullableRaw($record, 'merit_position'),
                        'merit_position',
                        1
                    ),
                    'faculty_id' => $facultyId,
                    'date_of_birth' => csvNullableDate(
                        nullableRaw($record, 'date_of_birth'),
                        'date_of_birth'
                    ),
                    'gender' => csvNullableGender(
                        nullableRaw($record, 'gender')
                    ),
                    'nationality' => $nationality,
                    'quota' => csvNullableString(
                        nullableRaw($record, 'quota'),
                        100,
                        'quota'
                    ),
                    'phone' => csvNullableString(
                        nullableRaw($record, 'phone'),
                        20,
                        'phone'
                    ),
                    'hsc_roll' => csvNullableString(
                        nullableRaw($record, 'hsc_roll'),
                        20,
                        'hsc_roll'
                    ),
                    'hsc_reg' => csvNullableString(
                        nullableRaw($record, 'hsc_reg'),
                        30,
                        'hsc_reg'
                    ),
                    'hsc_group' => csvNullableString(
                        nullableRaw($record, 'hsc_group'),
                        50,
                        'hsc_group'
                    ),
                    'hsc_board' => csvNullableString(
                        nullableRaw($record, 'hsc_board'),
                        100,
                        'hsc_board'
                    ),
                    'hsc_year' => csvNullableInt(
                        nullableRaw($record, 'hsc_year'),
                        'hsc_year',
                        1901,
                        2155
                    ),
                    'hsc_gpa' => csvNullableFloat(
                        nullableRaw($record, 'hsc_gpa'),
                        'hsc_gpa',
                        0,
                        5
                    ),
                    'hsc_institute' => csvNullableString(
                        nullableRaw($record, 'hsc_institute'),
                        200,
                        'hsc_institute'
                    ),
                    'ssc_roll' => csvNullableString(
                        nullableRaw($record, 'ssc_roll'),
                        20,
                        'ssc_roll'
                    ),
                    'ssc_reg' => csvNullableString(
                        nullableRaw($record, 'ssc_reg'),
                        30,
                        'ssc_reg'
                    ),
                    'ssc_board' => csvNullableString(
                        nullableRaw($record, 'ssc_board'),
                        100,
                        'ssc_board'
                    ),
                    'ssc_year' => csvNullableInt(
                        nullableRaw($record, 'ssc_year'),
                        'ssc_year',
                        1901,
                        2155
                    ),
                    'ssc_gpa' => csvNullableFloat(
                        nullableRaw($record, 'ssc_gpa'),
                        'ssc_gpa',
                        0,
                        5
                    ),
                    'ssc_institute' => csvNullableString(
                        nullableRaw($record, 'ssc_institute'),
                        200,
                        'ssc_institute'
                    ),
                    'physics_mark' => csvNullableFloat(
                        nullableRaw($record, 'physics_mark'),
                        'physics_mark',
                        0
                    ),
                    'chemistry_mark' => csvNullableFloat(
                        nullableRaw($record, 'chemistry_mark'),
                        'chemistry_mark',
                        0
                    ),
                    'mathematics_mark' => csvNullableFloat(
                        nullableRaw($record, 'mathematics_mark'),
                        'mathematics_mark',
                        0
                    ),
                    'biology_mark' => csvNullableFloat(
                        nullableRaw($record, 'biology_mark'),
                        'biology_mark',
                        0
                    ),
                    'bangla_mark' => csvNullableFloat(
                        nullableRaw($record, 'bangla_mark'),
                        'bangla_mark',
                        0
                    ),
                    'english_mark' => csvNullableFloat(
                        nullableRaw($record, 'english_mark'),
                        'english_mark',
                        0
                    ),
                    'general_knowledge_mark' => csvNullableFloat(
                        nullableRaw($record, 'general_knowledge_mark'),
                        'general_knowledge_mark',
                        0
                    ),
                    /*
                    | The Registrar-selected session is authoritative.
                    | A CSV academic_year column, if present, is intentionally
                    | ignored so one import cannot mix multiple admission years.
                    */
                    'academic_year' => $selectedAcademicYear,
                    'selected_university_code' => csvNullableString(
                        nullableRaw(
                            $record,
                            'selected_university_code'
                        ),
                        30,
                        'selected_university_code'
                    ),
                    'selected_university_name' => csvNullableString(
                        nullableRaw(
                            $record,
                            'selected_university_name'
                        ),
                        200,
                        'selected_university_name'
                    ),
                    'selection_status' => $selectionStatus,
                ];

                /*
                |--------------------------------------------------------------------------
                | Validate choice_1-choice_10
                |--------------------------------------------------------------------------
                |
                | - choice_1 is mandatory.
                | - Later choices are optional.
                | - No gaps are allowed (choice_3 cannot exist if choice_2 is blank).
                | - A department cannot appear twice for one candidate.
                | - CSV values use departments.dept_code, not department names/IDs.
                |--------------------------------------------------------------------------
                */

                $choiceCodes = [];
                $choiceDeptIds = [];
                $seenDepartmentCodes = [];
                $blankChoiceSeen = false;

                for ($preference = 1; $preference <= 10; $preference++) {
                    $column = 'choice_' . $preference;
                    $rawChoice = nullableRaw($record, $column);

                    if ($rawChoice === null) {
                        if ($preference === 1) {
                            throw new RuntimeException(
                                'choice_1 is required.'
                            );
                        }

                        $blankChoiceSeen = true;
                        continue;
                    }

                    if ($blankChoiceSeen) {
                        throw new RuntimeException(
                            "{$column} cannot be filled after an earlier blank choice. Preferences must be continuous."
                        );
                    }

                    $deptCode = strtoupper(csvRequiredString(
                        $rawChoice,
                        20,
                        $column
                    ));

                    if (!isset($departmentsByCode[$deptCode])) {
                        throw new RuntimeException(
                            "{$column} contains unknown department code '{$deptCode}'."
                        );
                    }

                    if (isset($seenDepartmentCodes[$deptCode])) {
                        throw new RuntimeException(
                            "Department code '{$deptCode}' is repeated in the preference list."
                        );
                    }

                    $seenDepartmentCodes[$deptCode] = true;
                    $choiceCodes[$preference] = $deptCode;
                    $choiceDeptIds[$preference] =
                        (int) $departmentsByCode[$deptCode]['dept_id'];
                }

                /*
                |--------------------------------------------------------------------------
                | Insert new candidate or update the same imported identity
                |--------------------------------------------------------------------------
                */

                $findExistingStmt->execute([
                    $selectedAcademicYear,
                    $gstRoll,
                    $gstApplicantId
                ]);

                $existingRows = $findExistingStmt->fetchAll(
                    PDO::FETCH_ASSOC
                );

                if (count($existingRows) > 1) {
                    throw new RuntimeException(
                        'gst_roll and gst_applicant_id already belong to different database records.'
                    );
                }

                if (!$existingRows) {
                    $insertValues = [];

                    foreach ($databaseColumns as $column) {
                        $insertValues[] = $data[$column];
                    }

                    $insertStmt->execute($insertValues);
                    $importCandidateId = (int) $pdo->lastInsertId();
                    $summary['inserted']++;
                } else {
                    $existing = $existingRows[0];

                    if (
                        (string) $existing['gst_roll'] !== $gstRoll ||
                        (string) $existing['gst_applicant_id'] !==
                            $gstApplicantId
                    ) {
                        throw new RuntimeException(
                            'GST Roll or GST Applicant ID conflicts with another candidate in admission session '
                            . $selectedAcademicYear
                            . '.'
                        );
                    }

                    $importCandidateId =
                        (int) $existing['import_candidate_id'];

                    $updateValues = [];

                    foreach ($updateColumns as $column) {
                        $updateValues[] = $data[$column];
                    }

                    $updateValues[] = $importCandidateId;

                    $updateStmt->execute($updateValues);
                    $summary['updated']++;
                }

                /*
                |--------------------------------------------------------------------------
                | Save the candidate's 1-10 department preferences
                |--------------------------------------------------------------------------
                |
                | Before merit/waiting generation: a re-import replaces the old
                | preferences with the CSV values.
                |
                | After merit/waiting generation: preferences are locked. Re-importing
                | the same values is allowed, but changing them is rejected so an
                | existing allocation cannot become inconsistent with the official CSV.
                |--------------------------------------------------------------------------
                */

                $existingChoicesStmt->execute([$importCandidateId]);
                $existingChoiceCodes = array_map(
                    static fn(mixed $code): string =>
                        strtoupper(trim((string) $code)),
                    $existingChoicesStmt->fetchAll(PDO::FETCH_COLUMN)
                );

                $newChoiceCodes = array_values($choiceCodes);

                $candidateProcessStmt->execute([
                    $importCandidateId,
                    $importCandidateId,
                ]);

                $hasProcessData =
                    (int) $candidateProcessStmt->fetchColumn() === 1;

                if ($hasProcessData) {
                    if ($existingChoiceCodes !== $newChoiceCodes) {
                        throw new RuntimeException(
                            'Department preferences cannot be changed after merit/waiting generation. Reset the merit process first if this correction is intentional.'
                        );
                    }
                } else {
                    $deleteChoicesStmt->execute([$importCandidateId]);

                    foreach ($choiceDeptIds as $preference => $deptId) {
                        $insertChoiceStmt->execute([
                            $importCandidateId,
                            $deptId,
                            (int) $preference,
                        ]);

                        $summary['choices_saved']++;
                    }
                }

                $pdo->exec('RELEASE SAVEPOINT csv_candidate_row');
            } catch (Throwable $rowError) {
                try {
                    $pdo->exec('ROLLBACK TO SAVEPOINT csv_candidate_row');
                    $pdo->exec('RELEASE SAVEPOINT csv_candidate_row');
                } catch (Throwable) {
                    // The outer transaction handler will still protect the import.
                }

                $summary['invalid']++;

                if (count($summary['errors']) < 100) {
                    $summary['errors'][] =
                        "Line {$lineNumber}: " .
                        $rowError->getMessage();
                }
            }
        }

        fclose($handle);

        if ($summary['processed'] === 0) {
            throw new RuntimeException(
                'The CSV does not contain any candidate rows.'
            );
        }

        $pdo->commit();

        $summary['type'] =
            $summary['invalid'] > 0 ? 'warning' : 'success';

        /*
        | The imported academic_year remains the database source of truth.
        | This runtime value is only a convenience for this Registrar session.
        */
        $_SESSION['academic_year'] =
            $selectedAcademicYear;

        $summary['message'] = sprintf(
            'Admission Session %s: %d inserted, %d updated, %d preference rows saved, %d invalid.',
            $selectedAcademicYear,
            $summary['inserted'],
            $summary['updated'],
            $summary['choices_saved'],
            $summary['invalid']
        );
    } catch (Throwable $exception) {
        if (isset($handle) && is_resource($handle)) {
            fclose($handle);
        }

        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        $summary['type'] = 'danger';
        $summary['message'] = $exception->getMessage();
    }

    $_SESSION['csv_import_summary'] = $summary;

    header('Location: ' . basename($_SERVER['PHP_SELF']));
    exit;
}

/*
|--------------------------------------------------------------------------
| Page data
|--------------------------------------------------------------------------
*/

$summary =
    $_SESSION['csv_import_summary']
    ?? null;

unset(
    $_SESSION['csv_import_summary']
);


/*
|--------------------------------------------------------------------------
| Current OPEN Admission Session
|--------------------------------------------------------------------------
*/

$openAdmissionSession =
    admissionSessionGetOpen(
        $pdo
    );


$displayAcademicYear =
    $openAdmissionSession
        ? (string)
          $openAdmissionSession[
              'academic_year'
          ]
        : '';


$suggestedNextAcademicYear =
    admissionSessionSuggestedNextYear(
        $pdo
    );


$sessionHistory =
    admissionSessionHistory(
        $pdo
    );


/*
|--------------------------------------------------------------------------
| Statistics — ONE admission session only
|--------------------------------------------------------------------------
*/

$stats = [
    'total_candidates' => 0,
    'registered_candidates' => 0,
    'unregistered_candidates' => 0,
    'latest_import' => null,
];

$recentCandidates = [];

if ($displayAcademicYear !== '') {

    $statsStmt = $pdo->prepare("
        SELECT
            COUNT(*) AS total_candidates,
            SUM(is_registered = 1) AS registered_candidates,
            SUM(is_registered = 0) AS unregistered_candidates,
            MAX(imported_at) AS latest_import

        FROM gst_imported_candidates

        WHERE academic_year = ?
    ");

    $statsStmt->execute([
        $displayAcademicYear
    ]);

    $stats =
        $statsStmt->fetch(
            PDO::FETCH_ASSOC
        )
        ?: $stats;


    $recentStmt = $pdo->prepare("
        SELECT
            gi.import_candidate_id,
            gi.gst_roll,
            gi.gst_applicant_id,
            gi.full_name,
            gi.gst_unit,
            gi.gst_score,
            gi.merit_position,
            gi.academic_year,
            gi.selection_status,
            gi.is_registered,
            gi.imported_at,

            f.faculty_name,

            (
                SELECT GROUP_CONCAT(
                    d2.dept_code
                    ORDER BY gcc.preference_order ASC
                    SEPARATOR ' → '
                )

                FROM gst_candidate_choices gcc

                INNER JOIN departments d2
                    ON d2.dept_id =
                       gcc.dept_id

                WHERE gcc.import_candidate_id =
                      gi.import_candidate_id
            ) AS preference_codes

        FROM gst_imported_candidates gi

        LEFT JOIN faculties f
            ON f.faculty_id =
               gi.faculty_id

        WHERE gi.academic_year = ?

        ORDER BY
            gi.imported_at DESC,
            gi.import_candidate_id DESC

        LIMIT 15
    ");

    $recentStmt->execute([
        $displayAcademicYear
    ]);

    $recentCandidates =
        $recentStmt->fetchAll(
            PDO::FETCH_ASSOC
        );
}


require_once '../includes/header.php';
require_once '../includes/navbar.php';
?>

<div class="container py-4">

    <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
        <div>
            <h2 class="fw-bold mb-1">
                <i class="bi bi-file-earmark-arrow-up text-primary me-2"></i>
                Import GST Selected Candidates
            </h2>

            <p class="text-muted mb-2">
                Upload the official GST selected-candidate CSV file.
            </p>

            <?php if ($displayAcademicYear !== ''): ?>
                <span class="badge bg-primary">
                    Current Admission Session:
                    <?= htmlspecialchars(
                        $displayAcademicYear,
                        ENT_QUOTES,
                        'UTF-8'
                    ) ?>
                </span>

                <span class="badge bg-success ms-1">
                    OPEN
                </span>
            <?php else: ?>
                <span class="badge bg-secondary">
                    No Active Admission Session
                </span>
            <?php endif; ?>
        </div>

        <a href="../registrar/dashboard.php" class="btn btn-outline-secondary">
            <i class="bi bi-arrow-left me-1"></i>
            Registrar Dashboard
        </a>
    </div>

    <?php if (is_array($summary)): ?>
        <div class="alert alert-<?= htmlspecialchars(
            (string) $summary['type'],
            ENT_QUOTES,
            'UTF-8'
        ) ?> alert-dismissible fade show">

            <strong>
                <?= htmlspecialchars(
                    (string) $summary['message'],
                    ENT_QUOTES,
                    'UTF-8'
                ) ?>
            </strong>

            <?php if (!empty($summary['errors'])): ?>
                <hr>

                <div class="fw-semibold mb-2">
                    Validation errors
                    <?php if (($summary['invalid'] ?? 0) > 100): ?>
                        — showing the first 100
                    <?php endif; ?>
                </div>

                <div
                    class="small bg-white border rounded p-3"
                    style="max-height: 260px; overflow-y: auto;"
                >
                    <?php foreach ($summary['errors'] as $error): ?>
                        <div class="text-danger mb-1">
                            <?= htmlspecialchars(
                                (string) $error,
                                ENT_QUOTES,
                                'UTF-8'
                            ) ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <button
                type="button"
                class="btn-close"
                data-bs-dismiss="alert"
            ></button>
        </div>
    <?php endif; ?>

    <div class="card shadow-sm mb-4">
        <div class="card-header bg-dark text-white">
            <h5 class="mb-0">
                <i class="bi bi-calendar-check me-2"></i>
                Admission Session Control
            </h5>
        </div>

        <div class="card-body">

            <?php if ($openAdmissionSession): ?>

                <div class="row align-items-center g-3">

                    <div class="col-lg-8">

                        <div class="small text-muted">
                            Current Operational Session
                        </div>

                        <div class="fs-4 fw-bold text-primary">
                            <?= htmlspecialchars(
                                (string)
                                $openAdmissionSession[
                                    'academic_year'
                                ],
                                ENT_QUOTES,
                                'UTF-8'
                            ) ?>
                        </div>

                        <div class="small text-muted mt-1">
                            Started:
                            <?= htmlspecialchars(
                                date(
                                    'd M Y, h:i A',
                                    strtotime(
                                        (string)
                                        $openAdmissionSession[
                                            'started_at'
                                        ]
                                    )
                                ),
                                ENT_QUOTES,
                                'UTF-8'
                            ) ?>
                        </div>

                        <div class="alert alert-warning small mt-3 mb-0">
                            Close the session only after the admission process is
                            completely finished. Closing does not delete old data.
                            It prevents this session from being used for new
                            operational processing.
                        </div>

                    </div>

                    <div class="col-lg-4">

                        <form
                            method="POST"
                            onsubmit="return confirmSessionClose();"
                        >
                            <input
                                type="hidden"
                                name="csrf_token"
                                value="<?= htmlspecialchars(
                                    (string)
                                    $_SESSION[
                                        'csrf_token'
                                    ],
                                    ENT_QUOTES,
                                    'UTF-8'
                                ) ?>"
                            >

                            <input
                                type="hidden"
                                name="action"
                                value="close_session"
                            >

                            <input
                                type="hidden"
                                name="close_confirmation"
                                value="CLOSE"
                            >

                            <button
                                type="submit"
                                class="btn btn-danger w-100"
                            >
                                <i class="bi bi-lock-fill me-1"></i>
                                Close
                                <?= htmlspecialchars(
                                    (string)
                                    $openAdmissionSession[
                                        'academic_year'
                                    ],
                                    ENT_QUOTES,
                                    'UTF-8'
                                ) ?>
                            </button>
                        </form>

                    </div>

                </div>

            <?php else: ?>

                <div class="row align-items-end g-3">

                    <div class="col-lg-8">

                        <div class="alert alert-info mb-3">
                            The previous admission session is closed.
                            Start the next session before importing a new GST
                            candidate list.
                        </div>

                        <form
                            method="POST"
                            id="startSessionForm"
                        >

                            <input
                                type="hidden"
                                name="csrf_token"
                                value="<?= htmlspecialchars(
                                    (string)
                                    $_SESSION[
                                        'csrf_token'
                                    ],
                                    ENT_QUOTES,
                                    'UTF-8'
                                ) ?>"
                            >

                            <input
                                type="hidden"
                                name="action"
                                value="start_session"
                            >

                            <label class="form-label fw-semibold">
                                New Admission Session
                                <span class="text-danger">*</span>
                            </label>

                            <input
                                type="text"
                                name="new_academic_year"
                                class="form-control"
                                value="<?= htmlspecialchars(
                                    $suggestedNextAcademicYear,
                                    ENT_QUOTES,
                                    'UTF-8'
                                ) ?>"
                                pattern="[0-9]{4}-[0-9]{4}"
                                maxlength="9"
                                required
                            >

                            <div class="form-text">
                                Example:
                                <code>2027-2028</code>.
                                A new session begins with zero current candidate
                                counters.
                            </div>

                            <button
                                type="submit"
                                class="btn btn-success mt-3"
                                onclick="return confirm(
                                    'Start this new Admission Session? The previous session records will remain archived.'
                                );"
                            >
                                <i class="bi bi-play-circle me-1"></i>
                                Start New Admission Session
                            </button>

                        </form>

                    </div>

                    <div class="col-lg-4">
                        <div class="border rounded p-3 bg-light">
                            <div class="small text-muted">
                                New-session counters
                            </div>

                            <div class="fw-bold mt-2">
                                Imported Candidates:
                                <span class="text-primary">0</span>
                            </div>

                            <div class="fw-bold">
                                Registered Applicants:
                                <span class="text-success">0</span>
                            </div>

                            <div class="fw-bold">
                                Eligible but Unregistered:
                                <span class="text-warning">0</span>
                            </div>
                        </div>
                    </div>

                </div>

            <?php endif; ?>

        </div>
    </div>


    <div class="row g-3 mb-4">

        <div class="col-md-4">
            <div class="card shadow-sm h-100 border-primary">
                <div class="card-body">
                    <div class="text-muted small">
                        Imported candidates
                    </div>
                    <div class="display-6 fw-bold text-primary">
                        <?= (int) ($stats['total_candidates'] ?? 0) ?>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-md-4">
            <div class="card shadow-sm h-100 border-success">
                <div class="card-body">
                    <div class="text-muted small">
                        Registered applicants
                    </div>
                    <div class="display-6 fw-bold text-success">
                        <?= (int) ($stats['registered_candidates'] ?? 0) ?>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-md-4">
            <div class="card shadow-sm h-100 border-warning">
                <div class="card-body">
                    <div class="text-muted small">
                        Eligible but unregistered
                    </div>
                    <div class="display-6 fw-bold text-warning">
                        <?= (int) ($stats['unregistered_candidates'] ?? 0) ?>
                    </div>
                </div>
            </div>
        </div>

    </div>

    <div class="row g-4">

        <div class="col-lg-5">
            <div class="card shadow-sm">

                <div class="card-header bg-primary text-white">
                    <h5 class="mb-0">
                        <i class="bi bi-upload me-2"></i>
                        Upload CSV
                    </h5>
                </div>

                <div class="card-body">

                    <?php if (!$openAdmissionSession): ?>
                        <div class="alert alert-warning">
                            Start a new Admission Session before importing
                            candidates.
                        </div>
                    <?php endif; ?>

                    <form
                        method="POST"
                        enctype="multipart/form-data"
                        onsubmit="return confirmCsvImport();"
                    >
                        <input
                            type="hidden"
                            name="csrf_token"
                            value="<?= htmlspecialchars(
                                $_SESSION['csrf_token'],
                                ENT_QUOTES,
                                'UTF-8'
                            ) ?>"
                        >

                        <input
                            type="hidden"
                            name="action"
                            value="import_candidates"
                        >

                        <div class="mb-3">
                            <label class="form-label fw-semibold">
                                Admission Session
                                <span class="text-danger">*</span>
                            </label>

                            <input
                                type="text"
                                name="academic_year"
                                id="academicYear"
                                class="form-control"
                                value="<?= htmlspecialchars(
                                    $displayAcademicYear,
                                    ENT_QUOTES,
                                    'UTF-8'
                                ) ?>"
                                readonly
                                required
                            >

                            <div class="form-text">
                                This value comes from the currently
                                <strong>Open Admission Session</strong>.
                                It cannot be changed from the CSV import form.
                            </div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label fw-semibold">
                                GST candidate CSV
                                <span class="text-danger">*</span>
                            </label>

                            <input
                                type="file"
                                name="candidate_csv"
                                class="form-control"
                                accept=".csv,text/csv"
                                required
                                <?= !$openAdmissionSession
                                    ? 'disabled'
                                    : ''
                                ?>
                            >

                            <div class="form-text">
                                CSV only, maximum size 10 MB.
                                Maximum 50,000 candidate rows.
                            </div>
                        </div>

                        <div class="alert alert-info small">
                            <strong>Required candidate columns:</strong>
                            <div class="mt-1">
                                gst_roll, gst_applicant_id, full_name,
                                gst_score, faculty_id
                            </div>

                            <strong class="d-block mt-2">Preference columns:</strong>
                            <div class="mt-1">
                                choice_1, choice_2, choice_3, choice_4,
                                choice_5, choice_6, choice_7, choice_8,
                                choice_9, choice_10
                            </div>

                            <div class="mt-2">
                                <code>choice_1</code> must contain a department
                                code. Choices 2-10 may be blank.
                            </div>

                            <div class="mt-2">
                                <strong>Admission Session:</strong>
                                the currently Open Admission Session is saved to
                                every imported candidate. A CSV
                                <code>academic_year</code> column, if present,
                                is ignored.
                            </div>
                        </div>

                        <div class="alert alert-warning small">
                            Existing candidates are matched only inside the
                            current Open Admission Session. Previous-year candidates
                            are not updated or mixed with the new session.
                            Preferences may be replaced only before merit/waiting
                            generation. Registration links and registration status
                            are not reset.
                        </div>

                        <button
                            type="submit"
                            class="btn btn-primary w-100"
                            <?= !$openAdmissionSession
                                ? 'disabled'
                                : ''
                            ?>
                        >
                            <i class="bi bi-database-add me-1"></i>
                            Validate and Import Candidates
                        </button>
                    </form>

                </div>
            </div>

            <div class="card shadow-sm mt-4">
                <div class="card-header">
                    <strong>CSV formatting notes</strong>
                </div>

                <div class="card-body small">
                    <ul class="mb-0">
                        <li>
                            The Registrar must select one
                            <strong>Admission Session</strong> for the complete
                            import, for example <code>2026-2027</code>.
                        </li>
                        <li>
                            The optional <code>index</code> column is ignored.
                        </li>
                        <li>
                            Empty optional values are stored as
                            <code>NULL</code>.
                        </li>
                        <li>
                            Date format must be
                            <code>YYYY-MM-DD</code>.
                        </li>
                        <li>
                            Gender must be Male, Female, or Other.
                        </li>
                        <li>
                            Preserve leading zeroes in phone, roll, and
                            registration numbers by formatting those CSV
                            columns as text.
                        </li>
                        <li>
                            Every faculty_id must already exist in the
                            faculties table.
                        </li>
                        <li>
                            Use department codes such as
                            <code>CSE</code>, <code>EEE</code>,
                            <code>STAT</code>, <code>AIS</code>, etc. in
                            <code>choice_1</code>-<code>choice_10</code>.
                        </li>
                        <li>
                            <code>choice_1</code> is required. Choices after
                            the last selected department should remain blank.
                        </li>
                        <li>
                            Preference gaps are not allowed. For example,
                            <code>choice_3</code> cannot be filled when
                            <code>choice_2</code> is blank.
                        </li>
                        <li>
                            The same department code cannot appear twice for
                            one candidate.
                        </li>
                    </ul>
                </div>
            </div>
        </div>

        <div class="col-lg-7">
            <div class="card shadow-sm">

                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="mb-0">
                        <i class="bi bi-clock-history me-2"></i>
                        Recently Imported Candidates
                        <?php if ($displayAcademicYear !== ''): ?>
                            —
                            <?= htmlspecialchars(
                                $displayAcademicYear,
                                ENT_QUOTES,
                                'UTF-8'
                            ) ?>
                        <?php endif; ?>
                    </h5>

                    <span class="badge bg-dark">
                        <?= count($recentCandidates) ?> shown
                    </span>
                </div>

                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">

                        <thead class="table-light">
                            <tr>
                                <th>GST Identity</th>
                                <th>Candidate</th>
                                <th>Score</th>
                                <th>Faculty</th>
                                <th>Status</th>
                            </tr>
                        </thead>

                        <tbody>
                        <?php if ($recentCandidates): ?>

                            <?php foreach ($recentCandidates as $candidate): ?>
                                <tr>
                                    <td>
                                        <strong>
                                            <?= htmlspecialchars(
                                                (string) $candidate['gst_roll'],
                                                ENT_QUOTES,
                                                'UTF-8'
                                            ) ?>
                                        </strong>

                                        <div class="small text-muted">
                                            ID:
                                            <?= htmlspecialchars(
                                                (string) $candidate['gst_applicant_id'],
                                                ENT_QUOTES,
                                                'UTF-8'
                                            ) ?>
                                        </div>

                                        <div class="small text-primary">
                                            Session:
                                            <?= htmlspecialchars(
                                                (string) $candidate['academic_year'],
                                                ENT_QUOTES,
                                                'UTF-8'
                                            ) ?>
                                        </div>
                                    </td>

                                    <td>
                                        <?= htmlspecialchars(
                                            (string) $candidate['full_name'],
                                            ENT_QUOTES,
                                            'UTF-8'
                                        ) ?>

                                        <div class="small text-muted">
                                            Unit:
                                            <?= htmlspecialchars(
                                                (string) (
                                                    $candidate['gst_unit']
                                                    ?? 'N/A'
                                                ),
                                                ENT_QUOTES,
                                                'UTF-8'
                                            ) ?>
                                            · Merit:
                                            <?= $candidate['merit_position']
                                                !== null
                                                ? (int) $candidate['merit_position']
                                                : 'N/A'
                                            ?>
                                        </div>

                                        <div class="small text-primary mt-1">
                                            Choices:
                                            <?= htmlspecialchars(
                                                (string) (
                                                    $candidate['preference_codes']
                                                    ?? 'N/A'
                                                ),
                                                ENT_QUOTES,
                                                'UTF-8'
                                            ) ?>
                                        </div>
                                    </td>

                                    <td>
                                        <strong>
                                            <?= number_format(
                                                (float) $candidate['gst_score'],
                                                2
                                            ) ?>
                                        </strong>
                                    </td>

                                    <td>
                                        <?= htmlspecialchars(
                                            (string) (
                                                $candidate['faculty_name']
                                                ?? 'Unknown'
                                            ),
                                            ENT_QUOTES,
                                            'UTF-8'
                                        ) ?>
                                    </td>

                                    <td>
                                        <span class="badge bg-<?=
                                            (int) $candidate['is_registered'] === 1
                                                ? 'success'
                                                : 'secondary'
                                        ?>">
                                            <?=
                                                (int) $candidate['is_registered'] === 1
                                                    ? 'Registered'
                                                    : 'Not Registered'
                                            ?>
                                        </span>

                                        <div class="small mt-1">
                                            <?= htmlspecialchars(
                                                (string) $candidate['selection_status'],
                                                ENT_QUOTES,
                                                'UTF-8'
                                            ) ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>

                        <?php else: ?>

                            <tr>
                                <td colspan="5" class="text-center py-5">
                                    <i class="bi bi-inbox fs-1 text-muted d-block mb-3"></i>
                                    <h6 class="text-muted">
                                        No candidates imported yet
                                    </h6>
                                </td>
                            </tr>

                        <?php endif; ?>
                        </tbody>

                    </table>
                </div>

            </div>
        </div>

    </div>

    <div class="card shadow-sm mt-4">
        <div class="card-header">
            <strong>
                <i class="bi bi-archive me-1"></i>
                Admission Session History
            </strong>
        </div>

        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Session</th>
                        <th>Status</th>
                        <th>Started</th>
                        <th>Closed</th>
                    </tr>
                </thead>

                <tbody>
                <?php if ($sessionHistory): ?>

                    <?php foreach ($sessionHistory as $historySession): ?>
                        <tr>
                            <td class="fw-semibold">
                                <?= htmlspecialchars(
                                    (string)
                                    $historySession[
                                        'academic_year'
                                    ],
                                    ENT_QUOTES,
                                    'UTF-8'
                                ) ?>
                            </td>

                            <td>
                                <span class="badge bg-<?=
                                    (string)
                                    $historySession[
                                        'session_status'
                                    ]
                                    === 'Open'
                                        ? 'success'
                                        : 'secondary'
                                ?>">
                                    <?= htmlspecialchars(
                                        (string)
                                        $historySession[
                                            'session_status'
                                        ],
                                        ENT_QUOTES,
                                        'UTF-8'
                                    ) ?>
                                </span>
                            </td>

                            <td>
                                <?= htmlspecialchars(
                                    date(
                                        'd M Y',
                                        strtotime(
                                            (string)
                                            $historySession[
                                                'started_at'
                                            ]
                                        )
                                    ),
                                    ENT_QUOTES,
                                    'UTF-8'
                                ) ?>
                            </td>

                            <td>
                                <?php if (
                                    !empty(
                                        $historySession[
                                            'closed_at'
                                        ]
                                    )
                                ): ?>
                                    <?= htmlspecialchars(
                                        date(
                                            'd M Y',
                                            strtotime(
                                                (string)
                                                $historySession[
                                                    'closed_at'
                                                ]
                                            )
                                        ),
                                        ENT_QUOTES,
                                        'UTF-8'
                                    ) ?>
                                <?php else: ?>
                                    —
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>

                <?php else: ?>

                    <tr>
                        <td
                            colspan="4"
                            class="text-center text-muted py-3"
                        >
                            No admission-session history yet.
                        </td>
                    </tr>

                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

</div>

<script>
function confirmSessionClose() {
    return confirm(
        'Close the current Admission Session?\n\n' +
        'This does NOT delete old records.\n' +
        'The session will become historical and cannot be used for new admission processing.\n\n' +
        'Continue only after admission is completely finished.'
    );
}

function confirmCsvImport() {
    const academicYearInput =
        document.getElementById('academicYear');

    const academicYear =
        academicYearInput
            ? academicYearInput.value.trim()
            : '';

    if (!academicYear) {
        alert('Start an Admission Session before importing candidates.');
        return false;
    }

    return confirm(
        'Import this GST candidate CSV?\n\n' +
        'Admission Session: ' + academicYear + '\n\n' +
        '• Every imported candidate will belong to this session.\n' +
        '• Previous-year candidates will not be updated.\n' +
        '• New candidates will be inserted.\n' +
        '• choice_1-choice_10 will be saved as department preferences.\n' +
        '• Existing matching candidates in this session will be updated.\n' +
        '• Preferences cannot be changed after merit/waiting generation.\n' +
        '• Registration links will not be reset.'
    );
}
</script>

<?php require_once '../includes/footer.php'; ?>
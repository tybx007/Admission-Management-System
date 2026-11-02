<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| JKKNIU Admission Session Lifecycle Helper
|--------------------------------------------------------------------------
|
| Intended path:
|   includes/admission_session.php
|
| The database-level source of truth for the CURRENT operational session is:
|
|   admission_sessions.session_status = 'Open'
|
| Candidate ownership still comes from:
|
|   gst_imported_candidates.academic_year
|--------------------------------------------------------------------------
*/


function admissionSessionValidateFormat(
    string $academicYear
): bool {

    $academicYear =
        trim($academicYear);


    if (
        !preg_match(
            '/^(\d{4})-(\d{4})$/',
            $academicYear,
            $matches
        )
    ) {
        return false;
    }


    return
        (int) $matches[2]
        ===
        ((int) $matches[1] + 1);
}


/*
|--------------------------------------------------------------------------
| Current OPEN admission session
|--------------------------------------------------------------------------
*/

function admissionSessionGetOpen(
    PDO $pdo
): ?array {

    $stmt =
        $pdo->query("
            SELECT
                session_id,
                academic_year,
                session_status,
                started_at,
                started_by,
                closed_at,
                closed_by

            FROM admission_sessions

            WHERE session_status =
                  'Open'

            ORDER BY
                session_id DESC

            LIMIT 1
        ");


    $row =
        $stmt->fetch(
            PDO::FETCH_ASSOC
        );


    return $row ?: null;
}


/*
|--------------------------------------------------------------------------
| Current operational academic year
|--------------------------------------------------------------------------
|
| IMPORTANT:
| PHP $_SESSION is not authoritative.
| It is synchronized from the database Open session.
|--------------------------------------------------------------------------
*/

function admissionSessionCurrentYear(
    PDO $pdo
): string {

    $openSession =
        admissionSessionGetOpen(
            $pdo
        );


    if (!$openSession) {

        unset(
            $_SESSION[
                'academic_year'
            ]
        );


        throw new RuntimeException(
            'No active admission session is open. The Registrar must start a new admission session.'
        );
    }


    $academicYear =
        trim(
            (string)
            $openSession[
                'academic_year'
            ]
        );


    if (
        !admissionSessionValidateFormat(
            $academicYear
        )
    ) {
        throw new RuntimeException(
            'The active admission session has an invalid academic-year format.'
        );
    }


    $_SESSION[
        'academic_year'
    ] = $academicYear;


    return $academicYear;
}


/*
|--------------------------------------------------------------------------
| Compatibility: available session years
|--------------------------------------------------------------------------
*/

function admissionSessionAvailableYears(
    PDO $pdo
): array {

    $stmt =
        $pdo->query("
            SELECT academic_year

            FROM admission_sessions

            ORDER BY
                CAST(
                    SUBSTRING_INDEX(
                        academic_year,
                        '-',
                        1
                    ) AS UNSIGNED
                ) DESC
        ");


    return array_map(
        static fn(mixed $year): string =>
            trim(
                (string) $year
            ),
        $stmt->fetchAll(
            PDO::FETCH_COLUMN
        )
    );
}


/*
|--------------------------------------------------------------------------
| Compatibility: set operational year
|--------------------------------------------------------------------------
|
| An operational module may only use the globally Open admission session.
|--------------------------------------------------------------------------
*/

function admissionSessionSetYear(
    PDO $pdo,
    string $academicYear
): string {

    $academicYear =
        trim($academicYear);


    $openSession =
        admissionSessionGetOpen(
            $pdo
        );


    if (
        !$openSession
        ||
        (string)
        $openSession[
            'academic_year'
        ]
        !==
        $academicYear
    ) {
        throw new RuntimeException(
            'Only the currently Open admission session can be used for operational processing.'
        );
    }


    $_SESSION[
        'academic_year'
    ] = $academicYear;


    return $academicYear;
}


/*
|--------------------------------------------------------------------------
| Session history
|--------------------------------------------------------------------------
*/

function admissionSessionHistory(
    PDO $pdo
): array {

    return
        $pdo->query("
            SELECT
                session_id,
                academic_year,
                session_status,
                started_at,
                started_by,
                closed_at,
                closed_by

            FROM admission_sessions

            ORDER BY
                CAST(
                    SUBSTRING_INDEX(
                        academic_year,
                        '-',
                        1
                    ) AS UNSIGNED
                ) DESC,
                session_id DESC
        ")->fetchAll(
            PDO::FETCH_ASSOC
        );
}


/*
|--------------------------------------------------------------------------
| Suggested NEXT academic year
|--------------------------------------------------------------------------
*/

function admissionSessionSuggestedNextYear(
    PDO $pdo
): string {

    $stmt =
        $pdo->query("
            SELECT academic_year

            FROM admission_sessions

            ORDER BY
                CAST(
                    SUBSTRING_INDEX(
                        academic_year,
                        '-',
                        1
                    ) AS UNSIGNED
                ) DESC

            LIMIT 1
        ");


    $latest =
        trim(
            (string) (
                $stmt->fetchColumn()
                ?: ''
            )
        );


    if (
        $latest === ''
        ||
        !admissionSessionValidateFormat(
            $latest
        )
    ) {

        $currentYear =
            (int) date('Y');


        return
            $currentYear
            . '-'
            . ($currentYear + 1);
    }


    [$start, $end] =
        array_map(
            'intval',
            explode(
                '-',
                $latest
            )
        );


    return
        $end
        . '-'
        . ($end + 1);
}


/*
|--------------------------------------------------------------------------
| Start a NEW admission session
|--------------------------------------------------------------------------
|
| Rules:
| - There must be no currently Open session.
| - Academic year must be valid.
| - The same session cannot already exist.
| - The new session must not already contain imported candidate data.
|--------------------------------------------------------------------------
*/

function admissionSessionStart(
    PDO $pdo,
    string $academicYear,
    ?int $startedBy = null
): array {

    $academicYear =
        trim($academicYear);


    if (
        !admissionSessionValidateFormat(
            $academicYear
        )
    ) {
        throw new InvalidArgumentException(
            'Admission Session must use consecutive years, for example 2027-2028.'
        );
    }


    if ($pdo->inTransaction()) {
        throw new RuntimeException(
            'Cannot start an admission session inside another transaction.'
        );
    }


    $pdo->beginTransaction();


    try {

        /*
        | Lock all session records while checking global Open state.
        */
        $sessionRows =
            $pdo->query("
                SELECT
                    session_id,
                    academic_year,
                    session_status

                FROM admission_sessions

                FOR UPDATE
            ")->fetchAll(
                PDO::FETCH_ASSOC
            );


        foreach (
            $sessionRows as
            $sessionRow
        ) {

            if (
                (string)
                $sessionRow[
                    'session_status'
                ]
                === 'Open'
            ) {
                throw new RuntimeException(
                    'Close the current admission session before starting a new one.'
                );
            }


            if (
                (string)
                $sessionRow[
                    'academic_year'
                ]
                === $academicYear
            ) {
                throw new RuntimeException(
                    "Admission Session {$academicYear} already exists."
                );
            }
        }


        /*
        | A newly started production session must begin empty.
        */
        $candidateStmt =
            $pdo->prepare("
                SELECT COUNT(*)

                FROM gst_imported_candidates

                WHERE academic_year = ?
            ");

        $candidateStmt->execute([
            $academicYear
        ]);


        if (
            (int)
            $candidateStmt->fetchColumn()
            > 0
        ) {
            throw new RuntimeException(
                "Candidate data already exists for {$academicYear}. A new admission session must start empty."
            );
        }


        $insert =
            $pdo->prepare("
                INSERT INTO admission_sessions (
                    academic_year,
                    session_status,
                    started_at,
                    started_by
                )
                VALUES (
                    ?,
                    'Open',
                    NOW(),
                    ?
                )
            ");

        $insert->execute([
            $academicYear,
            $startedBy
        ]);


        $sessionId =
            (int)
            $pdo->lastInsertId();


        $pdo->commit();


        $_SESSION[
            'academic_year'
        ] = $academicYear;


        return [
            'session_id' =>
                $sessionId,

            'academic_year' =>
                $academicYear,

            'session_status' =>
                'Open'
        ];


    } catch (Throwable $exception) {

        if (
            $pdo->inTransaction()
        ) {
            $pdo->rollBack();
        }


        throw $exception;
    }
}


/*
|--------------------------------------------------------------------------
| Close CURRENT admission session
|--------------------------------------------------------------------------
|
| Closing NEVER deletes:
| - imported candidates
| - applicant accounts
| - merit history
| - viva history
| - admitted student records
|
| Safety:
| - unresolved Allocated candidates block closure
| - unprocessed Merit Calls block closure
| - an Open Viva blocks closure
| - remaining Waiting/Called records are expired when admission finishes
|--------------------------------------------------------------------------
*/

function admissionSessionClose(
    PDO $pdo,
    string $academicYear,
    ?int $closedBy = null
): array {

    $academicYear =
        trim($academicYear);


    if (
        !admissionSessionValidateFormat(
            $academicYear
        )
    ) {
        throw new InvalidArgumentException(
            'Invalid admission session.'
        );
    }


    if ($pdo->inTransaction()) {
        throw new RuntimeException(
            'Cannot close an admission session inside another transaction.'
        );
    }


    $pdo->beginTransaction();


    try {

        $sessionStmt =
            $pdo->prepare("
                SELECT
                    session_id,
                    academic_year,
                    session_status

                FROM admission_sessions

                WHERE academic_year = ?

                LIMIT 1

                FOR UPDATE
            ");

        $sessionStmt->execute([
            $academicYear
        ]);


        $session =
            $sessionStmt->fetch(
                PDO::FETCH_ASSOC
            );


        if (!$session) {
            throw new RuntimeException(
                'Admission session was not found.'
            );
        }


        if (
            (string)
            $session[
                'session_status'
            ]
            !== 'Open'
        ) {
            throw new RuntimeException(
                'This admission session is already closed.'
            );
        }


        /*
        |--------------------------------------------------------------------------
        | Unresolved active allocations
        |--------------------------------------------------------------------------
        */

        $stmt =
            $pdo->prepare("
                SELECT COUNT(*)

                FROM merit_allocations ma

                INNER JOIN gst_imported_candidates gic
                    ON gic.import_candidate_id =
                       ma.import_candidate_id

                WHERE gic.academic_year = ?

                  AND ma.allocation_status =
                      'Allocated'
            ");

        $stmt->execute([
            $academicYear
        ]);


        $activeAllocations =
            (int)
            $stmt->fetchColumn();


        if (
            $activeAllocations > 0
        ) {
            throw new RuntimeException(
                "Session cannot be closed: {$activeAllocations} candidate allocation(s) are still active."
            );
        }


        /*
        |--------------------------------------------------------------------------
        | Registration deadlines still not processed
        |--------------------------------------------------------------------------
        */

        $stmt =
            $pdo->prepare("
                SELECT COUNT(*)

                FROM merit_calls

                WHERE academic_year = ?

                  AND processed_at IS NULL
            ");

        $stmt->execute([
            $academicYear
        ]);


        $unprocessedCalls =
            (int)
            $stmt->fetchColumn();


        if (
            $unprocessedCalls > 0
        ) {
            throw new RuntimeException(
                "Session cannot be closed: {$unprocessedCalls} Merit Call(s) still require deadline processing."
            );
        }


        /*
        |--------------------------------------------------------------------------
        | Viva still Open
        |--------------------------------------------------------------------------
        */

        $stmt =
            $pdo->prepare("
                SELECT COUNT(*)

                FROM merit_calls

                WHERE academic_year = ?

                  AND viva_status =
                      'Open'
            ");

        $stmt->execute([
            $academicYear
        ]);


        $openVivaCount =
            (int)
            $stmt->fetchColumn();


        if (
            $openVivaCount > 0
        ) {
            throw new RuntimeException(
                "Session cannot be closed: {$openVivaCount} Merit Call Viva session(s) are still open."
            );
        }


        /*
        |--------------------------------------------------------------------------
        | Admission is over:
        | remaining active waiting candidates become historical Expired rows.
        |--------------------------------------------------------------------------
        */

        $expireWaiting =
            $pdo->prepare("
                UPDATE waiting_list wl

                INNER JOIN gst_imported_candidates gic
                    ON gic.import_candidate_id =
                       wl.import_candidate_id

                SET wl.waiting_status =
                    'Expired'

                WHERE gic.academic_year = ?

                  AND wl.waiting_status IN (
                      'Waiting',
                      'Called'
                  )
            ");

        $expireWaiting->execute([
            $academicYear
        ]);


        $expiredWaiting =
            $expireWaiting->rowCount();


        /*
        |--------------------------------------------------------------------------
        | Close the session
        |--------------------------------------------------------------------------
        */

        $closeStmt =
            $pdo->prepare("
                UPDATE admission_sessions

                SET
                    session_status =
                        'Closed',

                    closed_at =
                        NOW(),

                    closed_by =
                        ?

                WHERE session_id = ?

                  AND session_status =
                      'Open'
            ");

        $closeStmt->execute([
            $closedBy,
            (int)
            $session[
                'session_id'
            ]
        ]);


        if (
            $closeStmt->rowCount()
            !== 1
        ) {
            throw new RuntimeException(
                'The admission session could not be closed.'
            );
        }


        $pdo->commit();


        unset(
            $_SESSION[
                'academic_year'
            ]
        );


        return [
            'academic_year' =>
                $academicYear,

            'session_status' =>
                'Closed',

            'expired_waiting' =>
                (int)
                $expiredWaiting
        ];


    } catch (Throwable $exception) {

        if (
            $pdo->inTransaction()
        ) {
            $pdo->rollBack();
        }


        throw $exception;
    }
}
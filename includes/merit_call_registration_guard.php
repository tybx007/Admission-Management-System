<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Session-safe Merit Call Registration Guard
|--------------------------------------------------------------------------
|
| Intended path:
|   includes/merit_call_registration_guard.php
|--------------------------------------------------------------------------
*/


function getOpenMeritCallForCandidate(
    PDO $pdo,
    int $importCandidateId,
    string $academicYear
): ?array {

    if (
        $importCandidateId <= 0
        ||
        trim($academicYear) === ''
    ) {
        return null;
    }


    $stmt = $pdo->prepare("
        SELECT
            ma.allocation_id,
            ma.import_candidate_id,
            ma.dept_id,
            ma.merit_rank,
            ma.allocation_status,

            mc.merit_call_id,
            mc.academic_year,
            mc.call_number,
            mc.call_title,
            mc.registration_start,
            mc.registration_deadline

        FROM merit_allocations ma

        INNER JOIN merit_calls mc
            ON mc.merit_call_id =
               ma.merit_call_id

        INNER JOIN gst_imported_candidates gic
            ON gic.import_candidate_id =
               ma.import_candidate_id

        WHERE ma.import_candidate_id = ?

          AND gic.academic_year = ?

          AND mc.academic_year = ?

          AND ma.allocation_status =
              'Allocated'

          AND NOW() >=
              mc.registration_start

          AND NOW() <=
              mc.registration_deadline

        ORDER BY
            ma.allocation_id DESC

        LIMIT 1
    ");

    $stmt->execute([
        $importCandidateId,
        $academicYear,
        $academicYear
    ]);


    $row =
        $stmt->fetch(
            PDO::FETCH_ASSOC
        );


    return $row ?: null;
}


function requireOpenMeritCallForCandidate(
    PDO $pdo,
    int $importCandidateId,
    string $academicYear
): array {

    $call =
        getOpenMeritCallForCandidate(
            $pdo,
            $importCandidateId,
            $academicYear
        );


    if (!$call) {
        throw new RuntimeException(
            'Registration is not open for this candidate in the current admission session.'
        );
    }


    return $call;
}
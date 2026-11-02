<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Session-aware Initial Merit Generator
|--------------------------------------------------------------------------
|
| Intended path:
|   includes/session_merit_engine.php
|
| Only initial merit generation is handled here.
| Vacancy/promotion/final admission still use admission_engine.php.
|--------------------------------------------------------------------------
*/

function generateMeritAndWaitingForSession(
    PDO $pdo,
    string $academicYear,
    bool $forceReset = false
): array {

    $academicYear =
        trim($academicYear);

    if (
        !preg_match(
            '/^\d{4}-\d{4}$/',
            $academicYear
        )
    ) {
        throw new InvalidArgumentException(
            'Invalid admission academic year.'
        );
    }

    if ($pdo->inTransaction()) {
        throw new RuntimeException(
            'Merit generation must start outside another transaction.'
        );
    }

    $pdo->beginTransaction();

    try {

        /*
        |--------------------------------------------------------------------------
        | Existing CURRENT-SESSION merit/waiting data
        |--------------------------------------------------------------------------
        */

        $stmt = $pdo->prepare("
            SELECT
                (
                    SELECT COUNT(*)

                    FROM merit_allocations ma

                    INNER JOIN gst_imported_candidates gic
                        ON gic.import_candidate_id =
                           ma.import_candidate_id

                    WHERE gic.academic_year = ?
                )
                +
                (
                    SELECT COUNT(*)

                    FROM waiting_list wl

                    INNER JOIN gst_imported_candidates gic
                        ON gic.import_candidate_id =
                           wl.import_candidate_id

                    WHERE gic.academic_year = ?
                )
        ");

        $stmt->execute([
            $academicYear,
            $academicYear
        ]);

        $existingCount =
            (int) $stmt->fetchColumn();

        if (
            $existingCount > 0
            &&
            !$forceReset
        ) {
            throw new RuntimeException(
                "Existing merit/waiting data exists for {$academicYear}."
            );
        }


        /*
        |--------------------------------------------------------------------------
        | Optional CURRENT-SESSION development reset
        |--------------------------------------------------------------------------
        */

        if ($forceReset) {

            $stmt = $pdo->prepare("
                DELETE wl

                FROM waiting_list wl

                INNER JOIN gst_imported_candidates gic
                    ON gic.import_candidate_id =
                       wl.import_candidate_id

                WHERE gic.academic_year = ?
            ");

            $stmt->execute([
                $academicYear
            ]);


            $stmt = $pdo->prepare("
                DELETE ma

                FROM merit_allocations ma

                INNER JOIN gst_imported_candidates gic
                    ON gic.import_candidate_id =
                       ma.import_candidate_id

                WHERE gic.academic_year = ?
            ");

            $stmt->execute([
                $academicYear
            ]);
        }


        /*
        |--------------------------------------------------------------------------
        | Department capacities
        |--------------------------------------------------------------------------
        */

        $departments = [];

        $departmentRows =
            $pdo->query("
                SELECT
                    dept_id,
                    dept_name,
                    total_seats

                FROM departments

                ORDER BY dept_id
            ")->fetchAll(
                PDO::FETCH_ASSOC
            );


        foreach (
            $departmentRows as $row
        ) {

            $deptId =
                (int) $row['dept_id'];

            $departments[$deptId] = [
                'dept_name' =>
                    (string) $row['dept_name'],

                'total_seats' =>
                    max(
                        0,
                        (int) $row['total_seats']
                    ),

                'allocated_count' =>
                    0,

                'next_merit_rank' =>
                    1
            ];
        }


        if (!$departments) {
            throw new RuntimeException(
                'No departments were found.'
            );
        }


        /*
        |--------------------------------------------------------------------------
        | CURRENT-SESSION selected GST candidates + choices
        |--------------------------------------------------------------------------
        */

        $stmt = $pdo->prepare("
            SELECT
                gic.import_candidate_id,
                gic.gst_roll,
                gic.full_name,
                gic.gst_score,
                gic.merit_position,

                gcc.dept_id,
                gcc.preference_order

            FROM gst_imported_candidates gic

            INNER JOIN gst_candidate_choices gcc
                ON gcc.import_candidate_id =
                   gic.import_candidate_id

            INNER JOIN departments d
                ON d.dept_id =
                   gcc.dept_id

            WHERE gic.selection_status =
                  'Selected'

              AND gic.academic_year = ?

            ORDER BY
                CASE
                    WHEN gic.merit_position IS NULL
                    THEN 1
                    ELSE 0
                END ASC,

                gic.merit_position ASC,
                gic.gst_score DESC,
                gic.import_candidate_id ASC,
                gcc.preference_order ASC
        ");

        $stmt->execute([
            $academicYear
        ]);

        $choiceRows =
            $stmt->fetchAll(
                PDO::FETCH_ASSOC
            );


        if (!$choiceRows) {
            throw new RuntimeException(
                "No selected GST candidates with department choices were found for {$academicYear}."
            );
        }


        /*
        |--------------------------------------------------------------------------
        | Build candidates
        |--------------------------------------------------------------------------
        */

        $candidates = [];
        $candidateOrder = [];


        foreach (
            $choiceRows as $row
        ) {

            $importCandidateId =
                (int)
                $row['import_candidate_id'];

            $deptId =
                (int)
                $row['dept_id'];

            $preferenceOrder =
                (int)
                $row['preference_order'];


            if (
                !isset(
                    $candidates[
                        $importCandidateId
                    ]
                )
            ) {

                $candidates[
                    $importCandidateId
                ] = [
                    'import_candidate_id' =>
                        $importCandidateId,

                    'gst_roll' =>
                        (string)
                        $row['gst_roll'],

                    'full_name' =>
                        (string)
                        $row['full_name'],

                    'gst_score' =>
                        (float)
                        $row['gst_score'],

                    'merit_position' =>
                        $row['merit_position']
                        !== null
                            ? (int)
                              $row['merit_position']
                            : null,

                    'choices' =>
                        [],

                    'allocated' =>
                        false
                ];


                $candidateOrder[] =
                    $importCandidateId;
            }


            $candidates[
                $importCandidateId
            ]['choices'][
                $preferenceOrder
            ] = $deptId;
        }


        /*
        |--------------------------------------------------------------------------
        | Allocate globally by GST merit
        |--------------------------------------------------------------------------
        */

        $insertAllocation =
            $pdo->prepare("
                INSERT INTO merit_allocations (
                    import_candidate_id,
                    applicant_id,
                    dept_id,
                    merit_rank,
                    allocation_status
                )
                VALUES (
                    ?,
                    NULL,
                    ?,
                    ?,
                    'Allocated'
                )
            ");


        $allocationCount = 0;
        $allocationSummary = [];


        foreach (
            $candidateOrder as
            $importCandidateId
        ) {

            $candidate =&
                $candidates[
                    $importCandidateId
                ];

            ksort(
                $candidate['choices']
            );


            foreach (
                $candidate['choices']
                as $deptId
            ) {

                $deptId =
                    (int) $deptId;


                if (
                    !isset(
                        $departments[
                            $deptId
                        ]
                    )
                ) {
                    continue;
                }


                if (
                    $departments[
                        $deptId
                    ]['allocated_count']
                    >=
                    $departments[
                        $deptId
                    ]['total_seats']
                ) {
                    continue;
                }


                $rank =
                    $departments[
                        $deptId
                    ]['next_merit_rank'];


                $insertAllocation->execute([
                    $importCandidateId,
                    $deptId,
                    $rank
                ]);


                $candidate['allocated'] =
                    true;


                $departments[
                    $deptId
                ]['allocated_count']++;


                $departments[
                    $deptId
                ]['next_merit_rank']++;


                $allocationCount++;


                $allocationSummary[
                    $deptId
                ] =
                    (
                        $allocationSummary[
                            $deptId
                        ]
                        ?? 0
                    ) + 1;


                break;
            }


            unset($candidate);
        }


        /*
        |--------------------------------------------------------------------------
        | Build canonical waiting list
        |--------------------------------------------------------------------------
        */

        $waitingByDepartment = [];


        foreach (
            $candidateOrder as
            $importCandidateId
        ) {

            $candidate =
                $candidates[
                    $importCandidateId
                ];


            if (
                $candidate['allocated']
                ||
                !$candidate['choices']
            ) {
                continue;
            }


            ksort(
                $candidate['choices']
            );


            $firstChoiceDeptId =
                (int)
                reset(
                    $candidate['choices']
                );


            if (
                !isset(
                    $departments[
                        $firstChoiceDeptId
                    ]
                )
            ) {
                continue;
            }


            $waitingByDepartment[
                $firstChoiceDeptId
            ][] = [
                'import_candidate_id' =>
                    $importCandidateId,

                'gst_score' =>
                    $candidate['gst_score'],

                'merit_position' =>
                    $candidate['merit_position']
            ];
        }


        $insertWaiting =
            $pdo->prepare("
                INSERT INTO waiting_list (
                    import_candidate_id,
                    applicant_id,
                    dept_id,
                    waiting_rank,
                    waiting_status,
                    called_at
                )
                VALUES (
                    ?,
                    NULL,
                    ?,
                    ?,
                    'Waiting',
                    NULL
                )
            ");


        $waitingCount = 0;
        $waitingSummary = [];


        foreach (
            $waitingByDepartment
            as $deptId =>
            $waitingCandidates
        ) {

            usort(
                $waitingCandidates,

                static function (
                    array $a,
                    array $b
                ): int {

                    $aMerit =
                        $a['merit_position'];

                    $bMerit =
                        $b['merit_position'];


                    if (
                        $aMerit !== null
                        &&
                        $bMerit !== null
                        &&
                        $aMerit !== $bMerit
                    ) {
                        return
                            $aMerit
                            <=>
                            $bMerit;
                    }


                    if (
                        $aMerit !== null
                        &&
                        $bMerit === null
                    ) {
                        return -1;
                    }


                    if (
                        $aMerit === null
                        &&
                        $bMerit !== null
                    ) {
                        return 1;
                    }


                    $scoreCompare =
                        $b['gst_score']
                        <=>
                        $a['gst_score'];


                    if (
                        $scoreCompare !== 0
                    ) {
                        return $scoreCompare;
                    }


                    return
                        $a['import_candidate_id']
                        <=>
                        $b['import_candidate_id'];
                }
            );


            $waitingRank = 1;


            foreach (
                $waitingCandidates as
                $candidate
            ) {

                $insertWaiting->execute([
                    (int)
                    $candidate[
                        'import_candidate_id'
                    ],
                    (int) $deptId,
                    $waitingRank
                ]);


                $waitingCount++;


                $waitingSummary[
                    (int) $deptId
                ] =
                    (
                        $waitingSummary[
                            (int) $deptId
                        ]
                        ?? 0
                    ) + 1;


                $waitingRank++;
            }
        }


        $pdo->commit();


        return [
            'academic_year' =>
                $academicYear,

            'total_candidates' =>
                count($candidates),

            'allocated_count' =>
                $allocationCount,

            'waiting_count' =>
                $waitingCount,

            'allocation_summary' =>
                $allocationSummary,

            'waiting_summary' =>
                $waitingSummary
        ];


    } catch (Throwable $exception) {

        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        throw $exception;
    }
}
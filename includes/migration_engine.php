<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| JKKNIU Migration Engine — Round Aware
|--------------------------------------------------------------------------
|
| Migration may run ONLY after a Merit Call is closed by the Registrar.
| The calling Merit-Round engine enforces that lifecycle.
|--------------------------------------------------------------------------
*/


function migrationYear(string $year): string
{
    $year = trim($year);

    if (!preg_match('/^\d{4}-\d{4}$/', $year)) {
        throw new InvalidArgumentException(
            'Invalid admission session.'
        );
    }

    return $year;
}


function migrationRerankDepartment(
    PDO $pdo,
    int $departmentId,
    string $academicYear
): int {
    if (!$pdo->inTransaction()) {
        throw new RuntimeException(
            'Migration re-ranking requires an active transaction.'
        );
    }

    $stmt = $pdo->prepare("
        SELECT ma.allocation_id
        FROM merit_allocations ma
        INNER JOIN gst_imported_candidates gic
            ON gic.import_candidate_id = ma.import_candidate_id
        WHERE ma.dept_id = ?
          AND gic.academic_year = ?
          AND ma.allocation_status IN ('Allocated', 'Admitted')
        ORDER BY
            ma.merit_rank ASC,
            ma.allocation_id ASC
        FOR UPDATE
    ");

    $stmt->execute([
        $departmentId,
        $academicYear
    ]);

    $allocationIds =
        $stmt->fetchAll(PDO::FETCH_COLUMN);

    $update = $pdo->prepare("
        UPDATE merit_allocations
        SET merit_rank = ?
        WHERE allocation_id = ?
          AND dept_id = ?
    ");

    $rank = 1;

    foreach ($allocationIds as $allocationId) {
        $update->execute([
            $rank,
            (int) $allocationId,
            $departmentId
        ]);

        $rank++;
    }

    return count($allocationIds);
}


function getCandidateMigrationProfile(
    PDO $pdo,
    int $applicantId,
    string $academicYear
): ?array {
    $academicYear = migrationYear($academicYear);

    $stmt = $pdo->prepare("
        SELECT
            ga.applicant_id,
            ga.full_name,

            gic.import_candidate_id,
            gic.gst_roll,
            gic.gst_score,
            gic.merit_position,
            gic.academic_year,

            ap.final_status,
            ap.migration_status,
            ap.migration_opted_out_at,

            ma.allocation_id,
            ma.dept_id,
            ma.merit_rank,
            ma.allocation_status,

            d.dept_name,

            gcc.preference_order AS current_preference_order

        FROM gst_applicants ga

        INNER JOIN gst_imported_candidates gic
            ON gic.applicant_id = ga.applicant_id

        INNER JOIN admission_progress ap
            ON ap.applicant_id = ga.applicant_id

        INNER JOIN merit_allocations ma
            ON ma.import_candidate_id = gic.import_candidate_id

        INNER JOIN departments d
            ON d.dept_id = ma.dept_id

        LEFT JOIN gst_candidate_choices gcc
            ON gcc.import_candidate_id = gic.import_candidate_id
           AND gcc.dept_id = ma.dept_id

        WHERE ga.applicant_id = ?
          AND gic.academic_year = ?
          AND ma.allocation_status = 'Admitted'

        ORDER BY ma.allocation_id DESC
        LIMIT 1
    ");

    $stmt->execute([
        $applicantId,
        $academicYear
    ]);

    $profile =
        $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$profile) {
        return null;
    }

    $choiceStmt = $pdo->prepare("
        SELECT
            gcc.preference_order,
            gcc.dept_id,
            d.dept_name
        FROM gst_candidate_choices gcc
        INNER JOIN departments d
            ON d.dept_id = gcc.dept_id
        WHERE gcc.import_candidate_id = ?
        ORDER BY gcc.preference_order ASC
    ");

    $choiceStmt->execute([
        (int) $profile['import_candidate_id']
    ]);

    $profile['choices'] =
        $choiceStmt->fetchAll(PDO::FETCH_ASSOC);

    return $profile;
}


function optOutMigration(
    PDO $pdo,
    int $applicantId,
    string $academicYear
): void {
    $academicYear = migrationYear($academicYear);

    if ($pdo->inTransaction()) {
        throw new RuntimeException(
            'Migration Opt Out cannot start inside another transaction.'
        );
    }

    $pdo->beginTransaction();

    try {
        $stmt = $pdo->prepare("
            SELECT
                ap.final_status,
                ap.migration_status,
                gcc.preference_order

            FROM admission_progress ap

            INNER JOIN gst_imported_candidates gic
                ON gic.applicant_id = ap.applicant_id

            INNER JOIN merit_allocations ma
                ON ma.import_candidate_id = gic.import_candidate_id

            LEFT JOIN gst_candidate_choices gcc
                ON gcc.import_candidate_id = gic.import_candidate_id
               AND gcc.dept_id = ma.dept_id

            WHERE ap.applicant_id = ?
              AND gic.academic_year = ?
              AND ma.allocation_status = 'Admitted'

            ORDER BY ma.allocation_id DESC
            LIMIT 1
            FOR UPDATE
        ");

        $stmt->execute([
            $applicantId,
            $academicYear
        ]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            throw new RuntimeException(
                'Migration is available only after Final Admission.'
            );
        }

        if ((string) $row['final_status'] !== 'Admitted') {
            throw new RuntimeException(
                'Final Admission must be completed first.'
            );
        }

        if ((string) $row['migration_status'] === 'Opted Out') {
            throw new RuntimeException(
                'You have already permanently opted out of Migration.'
            );
        }

        if ((int) ($row['preference_order'] ?? 0) <= 1) {
            throw new RuntimeException(
                'You are already admitted to your first choice.'
            );
        }

        $update = $pdo->prepare("
            UPDATE admission_progress
            SET
                migration_status = 'Opted Out',
                migration_opted_out_at = NOW()
            WHERE applicant_id = ?
              AND final_status = 'Admitted'
              AND migration_status = 'Enabled'
        ");

        $update->execute([$applicantId]);

        if ($update->rowCount() !== 1) {
            throw new RuntimeException(
                'Migration could not be turned off.'
            );
        }

        $pdo->commit();

    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        throw $e;
    }
}


function migrationDepartmentMap(
    PDO $pdo,
    string $academicYear
): array {
    $academicYear = migrationYear($academicYear);

    $stmt = $pdo->prepare("
        SELECT
            d.dept_id,
            d.dept_name,
            d.total_seats,

            COUNT(
                DISTINCT CASE
                    WHEN gic.academic_year = ?
                     AND ma.allocation_status IN ('Allocated', 'Admitted')
                    THEN ma.import_candidate_id
                END
            ) AS occupied_seats

        FROM departments d

        LEFT JOIN merit_allocations ma
            ON ma.dept_id = d.dept_id

        LEFT JOIN gst_imported_candidates gic
            ON gic.import_candidate_id = ma.import_candidate_id

        GROUP BY
            d.dept_id,
            d.dept_name,
            d.total_seats

        ORDER BY d.dept_id
    ");

    $stmt->execute([$academicYear]);

    $map = [];

    foreach (
        $stmt->fetchAll(PDO::FETCH_ASSOC)
        as $row
    ) {
        $deptId = (int) $row['dept_id'];
        $total = max(0, (int) $row['total_seats']);
        $occupied = max(0, (int) $row['occupied_seats']);

        $map[$deptId] = [
            'dept_id' => $deptId,
            'dept_name' => (string) $row['dept_name'],
            'total_seats' => $total,
            'occupied_seats' => $occupied,
            'vacant_seats' => max(0, $total - $occupied),
        ];
    }

    return $map;
}


function migrationEnabledCandidates(
    PDO $pdo,
    string $academicYear,
    bool $forUpdate = false
): array {
    $academicYear = migrationYear($academicYear);

    $sql = "
        SELECT
            ma.allocation_id,
            ma.import_candidate_id,
            ma.applicant_id,
            ma.dept_id,

            gic.gst_roll,
            gic.full_name,
            gic.gst_score,
            gic.merit_position,

            current_choice.preference_order
                AS current_preference_order

        FROM merit_allocations ma

        INNER JOIN gst_imported_candidates gic
            ON gic.import_candidate_id = ma.import_candidate_id

        INNER JOIN admission_progress ap
            ON ap.applicant_id = gic.applicant_id

        INNER JOIN gst_candidate_choices current_choice
            ON current_choice.import_candidate_id = ma.import_candidate_id
           AND current_choice.dept_id = ma.dept_id

        WHERE gic.academic_year = ?
          AND gic.applicant_id IS NOT NULL
          AND ma.allocation_status = 'Admitted'
          AND ap.final_status = 'Admitted'
          AND ap.migration_status = 'Enabled'
          AND current_choice.preference_order > 1

          AND ma.allocation_id = (
              SELECT MAX(ma2.allocation_id)
              FROM merit_allocations ma2
              WHERE ma2.import_candidate_id = ma.import_candidate_id
                AND ma2.allocation_status = 'Admitted'
          )

        ORDER BY
            CASE
                WHEN gic.merit_position IS NULL
                THEN 1
                ELSE 0
            END,
            gic.merit_position ASC,
            gic.gst_score DESC,
            ma.import_candidate_id ASC
    ";

    if ($forUpdate) {
        $sql .= " FOR UPDATE";
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute([$academicYear]);

    $candidates =
        $stmt->fetchAll(PDO::FETCH_ASSOC);

    $choiceStmt = $pdo->prepare("
        SELECT
            gcc.dept_id,
            gcc.preference_order,
            d.dept_name
        FROM gst_candidate_choices gcc
        INNER JOIN departments d
            ON d.dept_id = gcc.dept_id
        WHERE gcc.import_candidate_id = ?
        ORDER BY gcc.preference_order ASC
    ");

    foreach ($candidates as &$candidate) {
        $choiceStmt->execute([
            (int) $candidate['import_candidate_id']
        ]);

        $candidate['choices'] =
            $choiceStmt->fetchAll(PDO::FETCH_ASSOC);
    }

    unset($candidate);

    return $candidates;
}


function buildMigrationPlan(
    array $candidates,
    array $departments
): array {
    $state = [];

    foreach ($candidates as $candidate) {
        $id =
            (int) $candidate['import_candidate_id'];

        $state[$id] = $candidate;
        $state[$id]['current_dept_id'] =
            (int) $candidate['dept_id'];

        $state[$id]['current_preference_order'] =
            (int) $candidate['current_preference_order'];
    }

    $plan = [];

    /*
     * After every move, restart from the highest GST merit candidate.
     * This is necessary because a migration can create a new vacancy
     * that a higher-merit candidate should receive before a lower-merit one.
     */
    while (true) {
        $moved = false;

        foreach ($candidates as $ordered) {
            $id =
                (int) $ordered['import_candidate_id'];

            $candidate = $state[$id];

            $currentPreference =
                (int) $candidate['current_preference_order'];

            if ($currentPreference <= 1) {
                continue;
            }

            $target = null;

            foreach ($candidate['choices'] as $choice) {
                $preference =
                    (int) $choice['preference_order'];

                if ($preference >= $currentPreference) {
                    break;
                }

                $deptId =
                    (int) $choice['dept_id'];

                if (
                    isset($departments[$deptId])
                    && (int) $departments[$deptId]['vacant_seats'] > 0
                ) {
                    $target = $choice;
                    break;
                }
            }

            if (!$target) {
                continue;
            }

            $fromDept =
                (int) $candidate['current_dept_id'];

            $toDept =
                (int) $target['dept_id'];

            $departments[$toDept]['vacant_seats']--;

            if (isset($departments[$fromDept])) {
                $departments[$fromDept]['vacant_seats']++;
            }

            $plan[] = [
                'allocation_id' =>
                    (int) $candidate['allocation_id'],

                'applicant_id' =>
                    (int) $candidate['applicant_id'],

                'import_candidate_id' =>
                    $id,

                'gst_roll' =>
                    (string) $candidate['gst_roll'],

                'full_name' =>
                    (string) $candidate['full_name'],

                'merit_position' =>
                    $candidate['merit_position'] !== null
                        ? (int) $candidate['merit_position']
                        : null,

                'from_dept_id' =>
                    $fromDept,

                'from_department' =>
                    (string) $departments[$fromDept]['dept_name'],

                'from_preference_order' =>
                    $currentPreference,

                'to_dept_id' =>
                    $toDept,

                'to_department' =>
                    (string) $departments[$toDept]['dept_name'],

                'to_preference_order' =>
                    (int) $target['preference_order'],
            ];

            $state[$id]['current_dept_id'] =
                $toDept;

            $state[$id]['current_preference_order'] =
                (int) $target['preference_order'];

            $moved = true;
            break;
        }

        if (!$moved) {
            break;
        }
    }

    return $plan;
}


function previewMigration(
    PDO $pdo,
    string $academicYear
): array {
    $departments =
        migrationDepartmentMap($pdo, $academicYear);

    $candidates =
        migrationEnabledCandidates($pdo, $academicYear);

    return buildMigrationPlan(
        $candidates,
        $departments
    );
}


/*
|--------------------------------------------------------------------------
| Run Migration
|--------------------------------------------------------------------------
|
| If $manageTransaction = false, the caller MUST already own a transaction.
|--------------------------------------------------------------------------
*/

function runMigration(
    PDO $pdo,
    string $academicYear,
    ?int $processedBy = null,
    ?int $migrationCallId = null,
    bool $manageTransaction = true
): array {
    $academicYear = migrationYear($academicYear);

    if ($manageTransaction) {
        if ($pdo->inTransaction()) {
            throw new RuntimeException(
                'Migration cannot start inside another transaction.'
            );
        }

        $pdo->beginTransaction();

    } elseif (!$pdo->inTransaction()) {
        throw new RuntimeException(
            'An active transaction is required.'
        );
    }

    try {
        /*
         * Serialize seat changes.
         */
        $pdo->query("
            SELECT dept_id
            FROM departments
            ORDER BY dept_id
            FOR UPDATE
        ")->fetchAll(PDO::FETCH_COLUMN);

        $candidates =
            migrationEnabledCandidates(
                $pdo,
                $academicYear,
                true
            );

        $departments =
            migrationDepartmentMap(
                $pdo,
                $academicYear
            );

        $plan =
            buildMigrationPlan(
                $candidates,
                $departments
            );

        $update = $pdo->prepare("
            UPDATE merit_allocations
            SET dept_id = ?
            WHERE allocation_id = ?
              AND import_candidate_id = ?
              AND dept_id = ?
              AND allocation_status = 'Admitted'
        ");

        $history = $pdo->prepare("
            INSERT INTO migration_history (
                applicant_id,
                import_candidate_id,
                allocation_id,
                academic_year,
                migration_call_id,
                from_dept_id,
                to_dept_id,
                from_preference_order,
                to_preference_order,
                gst_merit_position,
                processed_by
            )
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");

        $affectedDepartments = [];

        foreach ($plan as $move) {
            $update->execute([
                $move['to_dept_id'],
                $move['allocation_id'],
                $move['import_candidate_id'],
                $move['from_dept_id'],
            ]);

            if ($update->rowCount() !== 1) {
                throw new RuntimeException(
                    'Migration data changed during processing. Nothing was saved.'
                );
            }

            $history->execute([
                $move['applicant_id'],
                $move['import_candidate_id'],
                $move['allocation_id'],
                $academicYear,
                $migrationCallId,
                $move['from_dept_id'],
                $move['to_dept_id'],
                $move['from_preference_order'],
                $move['to_preference_order'],
                $move['merit_position'],
                $processedBy,
            ]);

            $affectedDepartments[
                (int) $move['from_dept_id']
            ] = true;

            $affectedDepartments[
                (int) $move['to_dept_id']
            ] = true;
        }

        /*
         * merit_rank is a DEPARTMENTAL active rank.
         * Moving department therefore requires both sides to be re-ranked.
         */
        foreach (
            array_keys($affectedDepartments)
            as $departmentId
        ) {
            migrationRerankDepartment(
                $pdo,
                (int) $departmentId,
                $academicYear
            );
        }

        if ($manageTransaction) {
            $pdo->commit();
        }

        return $plan;

    } catch (Throwable $e) {
        if ($manageTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }

        throw $e;
    }
}

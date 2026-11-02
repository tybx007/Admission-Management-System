<?php

declare(strict_types=1);

require_once __DIR__ . '/migration_engine.php';
require_once __DIR__ . '/migration_notifications.php';

/*
|--------------------------------------------------------------------------
| JKKNIU Merit Round Engine
|--------------------------------------------------------------------------
|
| Registrar's enforced sequence:
|
| 1. Close Current Merit Process
| 2. Execute Migration
| 3. Prepare + Publish Next Merit Call
|
| Waiting candidates are never promoted before Migration.
|--------------------------------------------------------------------------
*/


function meritRoundOrdinal(int $number): string
{
    $mod100 = $number % 100;

    if ($mod100 >= 11 && $mod100 <= 13) {
        return $number . 'th';
    }

    return $number . match ($number % 10) {
        1 => 'st',
        2 => 'nd',
        3 => 'rd',
        default => 'th',
    };
}


function meritRoundParseDateTime(
    string $value
): DateTimeImmutable {
    $date = DateTimeImmutable::createFromFormat(
        'Y-m-d\TH:i',
        trim($value),
        new DateTimeZone('Asia/Dhaka')
    );

    if (!$date) {
        throw new RuntimeException(
            'Enter a valid date and time.'
        );
    }

    return $date;
}


function meritRoundLatestCall(
    PDO $pdo,
    string $academicYear,
    bool $forUpdate = false
): ?array {
    $sql = "
        SELECT
            merit_call_id,
            academic_year,
            call_number,
            call_title,
            registration_start,
            registration_deadline,
            process_end_at,
            published_at,
            processed_at,
            process_closed_at,
            migration_processed_at,
            next_call_prepared_at,
            viva_status,
            viva_started_at,
            viva_closed_at
        FROM merit_calls
        WHERE academic_year = ?
        ORDER BY call_number DESC
        LIMIT 1
    ";

    if ($forUpdate) {
        $sql .= " FOR UPDATE";
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute([$academicYear]);

    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return $row ?: null;
}


function meritRoundPublishNotice(
    PDO $pdo,
    array $call,
    int $publishedBy
): int {
    $processEnd = new DateTimeImmutable(
        (string) $call['process_end_at'],
        new DateTimeZone('Asia/Dhaka')
    );

    $title =
        (string) $call['call_title']
        . ' Process End Notice — '
        . (string) $call['academic_year'];

    $body =
        'The admission activities under the '
        . (string) $call['call_title']
        . ' will end on '
        . $processEnd->format('d M Y, h:i A')
        . '. Candidates who complete final admission and wish to remain '
        . 'eligible for department Migration must keep Migration Enabled. '
        . 'After this Merit Call process closes, Migration will be executed '
        . 'before candidates are called for the next Merit Call.';

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
            NULL,
            ?,
            ?,
            ?,
            ?,
            ?,
            NOW()
        )
    ");

    $stmt->execute([
        $title,
        $body,
        date('Y-m-d'),
        $processEnd->format('Y-m-d'),
        $publishedBy > 0 ? $publishedBy : null
    ]);

    return (int) $pdo->lastInsertId();
}


/*
|--------------------------------------------------------------------------
| Setup first/current call Process End + Notice
|--------------------------------------------------------------------------
*/

function meritRoundSetProcessEnd(
    PDO $pdo,
    int $callId,
    string $academicYear,
    DateTimeImmutable $processEnd,
    int $publishedBy
): array {
    if ($pdo->inTransaction()) {
        throw new RuntimeException(
            'Cannot set Process End inside another transaction.'
        );
    }

    $pdo->beginTransaction();

    try {
        $stmt = $pdo->prepare("
            SELECT *
            FROM merit_calls
            WHERE merit_call_id = ?
              AND academic_year = ?
            LIMIT 1
            FOR UPDATE
        ");

        $stmt->execute([
            $callId,
            $academicYear
        ]);

        $call = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$call) {
            throw new RuntimeException(
                'Merit Call was not found.'
            );
        }

        if (!empty($call['process_closed_at'])) {
            throw new RuntimeException(
                'This Merit Call process is already closed.'
            );
        }

        $deadline = new DateTimeImmutable(
            (string) $call['registration_deadline'],
            new DateTimeZone('Asia/Dhaka')
        );

        if ($processEnd <= $deadline) {
            throw new RuntimeException(
                'Merit Process End must be after the registration deadline.'
            );
        }

        $stmt = $pdo->prepare("
            UPDATE merit_calls
            SET process_end_at = ?
            WHERE merit_call_id = ?
              AND academic_year = ?
              AND process_closed_at IS NULL
        ");

        $stmt->execute([
            $processEnd->format('Y-m-d H:i:s'),
            $callId,
            $academicYear
        ]);

        $call['process_end_at'] =
            $processEnd->format('Y-m-d H:i:s');

        $noticeId = meritRoundPublishNotice(
            $pdo,
            $call,
            $publishedBy
        );

        $pdo->commit();

        return [
            'process_end_at' => $call['process_end_at'],
            'notice_id' => $noticeId,
        ];

    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        throw $e;
    }
}


/*
|--------------------------------------------------------------------------
| STEP 1 — Close Current Merit Process
|--------------------------------------------------------------------------
|
| At closure:
| - any candidate still provisionally Allocated in THIS call and not finally
|   Admitted loses that allocation;
| - those released seats become available;
| - NO waiting candidate is called;
| - Migration will receive first priority.
|--------------------------------------------------------------------------
*/

function meritRoundCloseProcess(
    PDO $pdo,
    int $callId,
    string $academicYear
): array {
    if ($pdo->inTransaction()) {
        throw new RuntimeException(
            'Cannot close Merit Process inside another transaction.'
        );
    }

    $pdo->beginTransaction();

    try {
        $stmt = $pdo->prepare("
            SELECT *
            FROM merit_calls
            WHERE merit_call_id = ?
              AND academic_year = ?
            LIMIT 1
            FOR UPDATE
        ");

        $stmt->execute([
            $callId,
            $academicYear
        ]);

        $call = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$call) {
            throw new RuntimeException(
                'Merit Call was not found.'
            );
        }

        if (empty($call['process_end_at'])) {
            throw new RuntimeException(
                'Set the Merit Process End date/time first.'
            );
        }

        if (!empty($call['process_closed_at'])) {
            throw new RuntimeException(
                'This Merit Process is already closed.'
            );
        }

        $now = new DateTimeImmutable(
            'now',
            new DateTimeZone('Asia/Dhaka')
        );

        $processEnd = new DateTimeImmutable(
            (string) $call['process_end_at'],
            new DateTimeZone('Asia/Dhaka')
        );

        if ($now < $processEnd) {
            throw new RuntimeException(
                'The announced Merit Process End time has not arrived yet.'
            );
        }

        if (empty($call['processed_at'])) {
            throw new RuntimeException(
                'Process the registration deadline before closing the Merit Process.'
            );
        }

        if ((string) $call['viva_status'] !== 'Closed') {
            throw new RuntimeException(
                'Close the Merit Call Viva before closing the Merit Process.'
            );
        }

        /*
         * Candidates still Allocated at process end did not finish Final
         * Admission. Final-admitted candidates should already have
         * allocation_status = Admitted.
         */
        $candidateStmt = $pdo->prepare("
            SELECT
                ma.allocation_id,
                ma.import_candidate_id,
                ma.applicant_id,
                ma.dept_id,

                gic.applicant_id AS linked_applicant_id,

                ap.final_status

            FROM merit_allocations ma

            INNER JOIN gst_imported_candidates gic
                ON gic.import_candidate_id = ma.import_candidate_id

            LEFT JOIN admission_progress ap
                ON ap.applicant_id = gic.applicant_id

            WHERE ma.merit_call_id = ?
              AND gic.academic_year = ?
              AND ma.allocation_status = 'Allocated'
              AND (
                    ap.final_status IS NULL
                    OR ap.final_status <> 'Admitted'
              )

            ORDER BY
                ma.dept_id,
                ma.merit_rank

            FOR UPDATE
        ");

        $candidateStmt->execute([
            $callId,
            $academicYear
        ]);

        $unfinished =
            $candidateStmt->fetchAll(PDO::FETCH_ASSOC);

        $vacateStmt = $pdo->prepare("
            UPDATE merit_allocations
            SET allocation_status = 'Vacated'
            WHERE allocation_id = ?
              AND allocation_status = 'Allocated'
        ");

        $expireCalledStmt = $pdo->prepare("
            UPDATE waiting_list
            SET waiting_status = 'Expired'
            WHERE import_candidate_id = ?
              AND waiting_status = 'Called'
        ");

        $progressStmt = $pdo->prepare("
            INSERT INTO admission_progress (
                applicant_id,
                final_status
            )
            VALUES (?, 'Rejected')
            ON DUPLICATE KEY UPDATE
                final_status = 'Rejected'
        ");

        $affectedDepartments = [];
        $removed = 0;

        foreach ($unfinished as $candidate) {
            $vacateStmt->execute([
                (int) $candidate['allocation_id']
            ]);

            if ($vacateStmt->rowCount() !== 1) {
                throw new RuntimeException(
                    'An unfinished allocation could not be closed.'
                );
            }

            $expireCalledStmt->execute([
                (int) $candidate['import_candidate_id']
            ]);

            $applicantId = !empty($candidate['linked_applicant_id'])
                ? (int) $candidate['linked_applicant_id']
                : (int) ($candidate['applicant_id'] ?? 0);

            if ($applicantId > 0) {
                $progressStmt->execute([$applicantId]);
            }

            $affectedDepartments[
                (int) $candidate['dept_id']
            ] = true;

            $removed++;
        }

        /*
         * Departmental active ranks must stay continuous.
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

        $stmt = $pdo->prepare("
            UPDATE merit_calls
            SET process_closed_at = NOW()
            WHERE merit_call_id = ?
              AND academic_year = ?
              AND process_closed_at IS NULL
        ");

        $stmt->execute([
            $callId,
            $academicYear
        ]);

        if ($stmt->rowCount() !== 1) {
            throw new RuntimeException(
                'The Merit Process could not be closed.'
            );
        }

        $pdo->commit();

        $departmentMap =
            migrationDepartmentMap(
                $pdo,
                $academicYear
            );

        $vacantSeats = 0;

        foreach ($departmentMap as $department) {
            $vacantSeats +=
                (int) $department['vacant_seats'];
        }

        return [
            'unfinished_removed' => $removed,
            'vacant_seats' => $vacantSeats,
        ];

    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        throw $e;
    }
}


/*
|--------------------------------------------------------------------------
| STEP 2 — Execute Migration
|--------------------------------------------------------------------------
*/

function meritRoundExecuteMigration(
    PDO $pdo,
    int $callId,
    string $academicYear,
    ?int $processedBy
): array {
    if ($pdo->inTransaction()) {
        throw new RuntimeException(
            'Cannot execute Migration inside another transaction.'
        );
    }

    $pdo->beginTransaction();

    try {
        $stmt = $pdo->prepare("
            SELECT *
            FROM merit_calls
            WHERE merit_call_id = ?
              AND academic_year = ?
            LIMIT 1
            FOR UPDATE
        ");

        $stmt->execute([
            $callId,
            $academicYear
        ]);

        $call = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$call) {
            throw new RuntimeException(
                'Merit Call was not found.'
            );
        }

        if (empty($call['process_closed_at'])) {
            throw new RuntimeException(
                'Close the current Merit Process before Migration.'
            );
        }

        if (!empty($call['migration_processed_at'])) {
            throw new RuntimeException(
                'Migration has already been processed for this Merit Call.'
            );
        }

        $moves = runMigration(
            $pdo,
            $academicYear,
            $processedBy,
            $callId,
            false
        );

        $stmt = $pdo->prepare("
            UPDATE merit_calls
            SET migration_processed_at = NOW()
            WHERE merit_call_id = ?
              AND academic_year = ?
              AND process_closed_at IS NOT NULL
              AND migration_processed_at IS NULL
        ");

        $stmt->execute([
            $callId,
            $academicYear
        ]);

        if ($stmt->rowCount() !== 1) {
            throw new RuntimeException(
                'Migration completion could not be saved.'
            );
        }

        $pdo->commit();


        /*
        |--------------------------------------------------------------------------
        | Migration notifications — AFTER COMMIT only
        |--------------------------------------------------------------------------
        */

        $notificationSummary = [
            'candidate_count' => 0,
            'email_sent' => 0,
            'email_failed' => 0,
            'sms_sent' => 0,
            'sms_failed' => 0,
            'results' => [],
        ];


        if (!empty($moves)) {

            try {

                $notificationSummary =
                    sendMigrationNotifications(
                        $pdo,
                        $moves,
                        $academicYear,
                        $callId
                    );

            } catch (Throwable $notificationError) {

                error_log(
                    'Migration notification batch error: '
                    . $notificationError->getMessage()
                );
            }
        }


        $departmentMap =
            migrationDepartmentMap(
                $pdo,
                $academicYear
            );

        $remainingVacancies = 0;

        foreach ($departmentMap as $department) {
            $remainingVacancies +=
                (int) $department['vacant_seats'];
        }

        return [
            'moves' => $moves,
            'migration_count' => count($moves),
            'migrated_candidate_count' =>
                (int) ($notificationSummary['candidate_count'] ?? 0),
            'remaining_vacancies' => $remainingVacancies,
            'notifications' => $notificationSummary,
        ];

    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        throw $e;
    }
}


/*
|--------------------------------------------------------------------------
| Waiting pool ordered GLOBALLY by GST merit
|--------------------------------------------------------------------------
*/

function meritRoundWaitingCandidates(
    PDO $pdo,
    string $academicYear,
    bool $forUpdate = false
): array {
    $sql = "
        SELECT
            wl.waiting_id,
            wl.import_candidate_id,
            wl.waiting_rank,

            gic.applicant_id,
            gic.gst_roll,
            gic.full_name,
            gic.gst_score,
            gic.merit_position

        FROM waiting_list wl

        INNER JOIN gst_imported_candidates gic
            ON gic.import_candidate_id = wl.import_candidate_id

        WHERE gic.academic_year = ?
          AND wl.waiting_status = 'Waiting'

          AND wl.waiting_id = (
              SELECT MIN(wl2.waiting_id)
              FROM waiting_list wl2
              WHERE wl2.import_candidate_id = wl.import_candidate_id
                AND wl2.waiting_status = 'Waiting'
          )

          AND NOT EXISTS (
              SELECT 1
              FROM merit_allocations ma
              WHERE ma.import_candidate_id = wl.import_candidate_id
                AND ma.allocation_status IN ('Allocated', 'Admitted')
          )

        ORDER BY
            CASE
                WHEN gic.merit_position IS NULL
                THEN 1
                ELSE 0
            END,
            gic.merit_position ASC,
            gic.gst_score DESC,
            wl.waiting_rank ASC,
            wl.waiting_id ASC
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
            d.dept_name,
            d.dept_code
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


/*
|--------------------------------------------------------------------------
| Build NEXT Merit Call allocation plan
|--------------------------------------------------------------------------
|
| One candidate can receive only one department.
| Candidates are processed globally by GST merit.
| For each candidate: Choice 1 -> Choice 2 -> ... until a vacancy is found.
|--------------------------------------------------------------------------
*/

function meritRoundBuildNextCallPlan(
    array $waitingCandidates,
    array $departmentMap
): array {
    $plan = [];

    foreach ($waitingCandidates as $candidate) {
        foreach ($candidate['choices'] as $choice) {
            $deptId =
                (int) $choice['dept_id'];

            if (
                !isset($departmentMap[$deptId])
                || (int) $departmentMap[$deptId]['vacant_seats'] <= 0
            ) {
                continue;
            }

            $plan[] = [
                'waiting_id' =>
                    (int) $candidate['waiting_id'],

                'import_candidate_id' =>
                    (int) $candidate['import_candidate_id'],

                'applicant_id' =>
                    !empty($candidate['applicant_id'])
                        ? (int) $candidate['applicant_id']
                        : null,

                'gst_roll' =>
                    (string) $candidate['gst_roll'],

                'full_name' =>
                    (string) $candidate['full_name'],

                'merit_position' =>
                    $candidate['merit_position'] !== null
                        ? (int) $candidate['merit_position']
                        : null,

                'dept_id' =>
                    $deptId,

                'dept_name' =>
                    (string) $departmentMap[$deptId]['dept_name'],

                'preference_order' =>
                    (int) $choice['preference_order'],
            ];

            $departmentMap[$deptId]['vacant_seats']--;

            break;
        }
    }

    return $plan;
}


/*
|--------------------------------------------------------------------------
| STEP 3 — Prepare + Publish Next Merit Call
|--------------------------------------------------------------------------
*/

function meritRoundPublishNextCall(
    PDO $pdo,
    int $currentCallId,
    string $academicYear,
    DateTimeImmutable $registrationStart,
    DateTimeImmutable $registrationDeadline,
    DateTimeImmutable $processEnd,
    ?int $createdBy
): array {
    if ($registrationDeadline <= $registrationStart) {
        throw new RuntimeException(
            'Registration deadline must be after the start time.'
        );
    }

    if ($processEnd <= $registrationDeadline) {
        throw new RuntimeException(
            'Next Merit Process End must be after the registration deadline.'
        );
    }

    if ($pdo->inTransaction()) {
        throw new RuntimeException(
            'Cannot prepare next Merit Call inside another transaction.'
        );
    }

    $pdo->beginTransaction();

    try {
        /*
         * Lock current call and verify the enforced order.
         */
        $stmt = $pdo->prepare("
            SELECT *
            FROM merit_calls
            WHERE merit_call_id = ?
              AND academic_year = ?
            LIMIT 1
            FOR UPDATE
        ");

        $stmt->execute([
            $currentCallId,
            $academicYear
        ]);

        $currentCall =
            $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$currentCall) {
            throw new RuntimeException(
                'Current Merit Call was not found.'
            );
        }

        if (empty($currentCall['process_closed_at'])) {
            throw new RuntimeException(
                'Close the current Merit Process first.'
            );
        }

        if (empty($currentCall['migration_processed_at'])) {
            throw new RuntimeException(
                'Execute Migration before calling the next Merit List.'
            );
        }

        if (!empty($currentCall['next_call_prepared_at'])) {
            throw new RuntimeException(
                'The next Merit Call has already been prepared.'
            );
        }

        /*
         * Lock departments while calculating seats.
         */
        $pdo->query("
            SELECT dept_id
            FROM departments
            ORDER BY dept_id
            FOR UPDATE
        ")->fetchAll(PDO::FETCH_COLUMN);

        $departmentMap =
            migrationDepartmentMap(
                $pdo,
                $academicYear
            );

        $waitingCandidates =
            meritRoundWaitingCandidates(
                $pdo,
                $academicYear,
                true
            );

        $plan =
            meritRoundBuildNextCallPlan(
                $waitingCandidates,
                $departmentMap
            );

        /*
         * No remaining eligible waiting candidate can fill a vacancy.
         * Step 3 is still considered complete.
         */
        if (!$plan) {
            $stmt = $pdo->prepare("
                UPDATE merit_calls
                SET next_call_prepared_at = NOW()
                WHERE merit_call_id = ?
                  AND academic_year = ?
                  AND next_call_prepared_at IS NULL
            ");

            $stmt->execute([
                $currentCallId,
                $academicYear
            ]);

            $pdo->commit();

            return [
                'published' => false,
                'next_call_id' => null,
                'candidate_count' => 0,
                'call_title' => null,
            ];
        }

        $nextCallNumber =
            (int) $currentCall['call_number']
            + 1;

        $callTitle =
            meritRoundOrdinal($nextCallNumber)
            . ' Merit Call';

        /*
         * Database trigger also enforces Closed + Migration.
         */
        $stmt = $pdo->prepare("
            INSERT INTO merit_calls (
                academic_year,
                call_number,
                call_title,
                registration_start,
                registration_deadline,
                process_end_at,
                published_at,
                created_by
            )
            VALUES (
                ?,
                ?,
                ?,
                ?,
                ?,
                ?,
                NOW(),
                ?
            )
        ");

        $stmt->execute([
            $academicYear,
            $nextCallNumber,
            $callTitle,
            $registrationStart->format('Y-m-d H:i:s'),
            $registrationDeadline->format('Y-m-d H:i:s'),
            $processEnd->format('Y-m-d H:i:s'),
            $createdBy
        ]);

        $newCallId =
            (int) $pdo->lastInsertId();

        /*
         * Get current active departmental counts.
         * Because all affected departments were re-ranked after closure
         * and migration, next rank = occupied + 1.
         */
        $nextRank = [];

        foreach ($departmentMap as $deptId => $department) {
            $nextRank[(int) $deptId] =
                (int) $department['occupied_seats']
                + 1;
        }

        $callWaitingStmt = $pdo->prepare("
            UPDATE waiting_list
            SET
                waiting_status = 'Called',
                called_at = NOW()
            WHERE waiting_id = ?
              AND waiting_status = 'Waiting'
        ");

        $allocationStmt = $pdo->prepare("
            INSERT INTO merit_allocations (
                import_candidate_id,
                applicant_id,
                dept_id,
                merit_rank,
                allocation_status,
                allocated_at,
                merit_call_id
            )
            VALUES (
                ?,
                ?,
                ?,
                ?,
                'Allocated',
                NOW(),
                ?
            )
        ");

        foreach ($plan as $candidate) {
            $callWaitingStmt->execute([
                (int) $candidate['waiting_id']
            ]);

            if ($callWaitingStmt->rowCount() !== 1) {
                throw new RuntimeException(
                    'A waiting candidate changed during next-call preparation.'
                );
            }

            $deptId =
                (int) $candidate['dept_id'];

            $allocationStmt->execute([
                (int) $candidate['import_candidate_id'],
                $candidate['applicant_id'],
                $deptId,
                $nextRank[$deptId],
                $newCallId
            ]);

            $nextRank[$deptId]++;
        }

        $stmt = $pdo->prepare("
            UPDATE merit_calls
            SET next_call_prepared_at = NOW()
            WHERE merit_call_id = ?
              AND academic_year = ?
              AND next_call_prepared_at IS NULL
        ");

        $stmt->execute([
            $currentCallId,
            $academicYear
        ]);

        /*
         * Automatically create Notice Board notice for this newly-published
         * Merit Call and its announced Process End.
         */
        $newCall = [
            'call_title' => $callTitle,
            'academic_year' => $academicYear,
            'process_end_at' =>
                $processEnd->format('Y-m-d H:i:s'),
        ];

        meritRoundPublishNotice(
            $pdo,
            $newCall,
            (int) ($createdBy ?? 0)
        );

        $pdo->commit();

        return [
            'published' => true,
            'next_call_id' => $newCallId,
            'candidate_count' => count($plan),
            'call_title' => $callTitle,
            'plan' => $plan,
        ];

    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        throw $e;
    }
}

<?php

declare(strict_types=1);

/**
 * Resolve a student's current admission context using the authoritative
 * gst_imported_candidates -> import_candidate_id relationship.
 */
function studentSubmissionContext(
    PDO $pdo,
    int $applicantId,
    string $academicYear
): array {
    if ($applicantId <= 0) {
        throw new RuntimeException('Invalid student session.');
    }

    /*
    |--------------------------------------------------------------------------
    | Registered GST candidate
    |--------------------------------------------------------------------------
    */
    $stmt = $pdo->prepare("
        SELECT
            import_candidate_id,
            applicant_id,
            gst_roll,
            full_name,
            academic_year
        FROM gst_imported_candidates
        WHERE applicant_id = ?
          AND academic_year = ?
        LIMIT 1
    ");

    $stmt->execute([
        $applicantId,
        $academicYear
    ]);

    $imported =
        $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$imported) {
        throw new RuntimeException(
            'Your registered account is not linked with the current GST admission record.'
        );
    }

    $importCandidateId =
        (int) $imported['import_candidate_id'];

    /*
    |--------------------------------------------------------------------------
    | Current active department allocation
    |--------------------------------------------------------------------------
    */
    $stmt = $pdo->prepare("
        SELECT
            ma.*,
            d.dept_name,
            d.dept_id,
            f.faculty_name
        FROM merit_allocations ma
        INNER JOIN departments d
            ON d.dept_id = ma.dept_id
        INNER JOIN faculties f
            ON f.faculty_id = d.faculty_id
        WHERE ma.import_candidate_id = ?
          AND ma.allocation_status IN (
              'Allocated',
              'Admitted'
          )
        ORDER BY ma.allocation_id DESC
        LIMIT 1
    ");

    $stmt->execute([
        $importCandidateId
    ]);

    $merit =
        $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$merit) {
        throw new RuntimeException(
            'No active department allocation was found for this admission session.'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Repair legacy applicant_id link
    |--------------------------------------------------------------------------
    */
    if (
        empty($merit['applicant_id'])
        ||
        (int) $merit['applicant_id']
        !== $applicantId
    ) {
        $stmt = $pdo->prepare("
            UPDATE merit_allocations
            SET applicant_id = ?
            WHERE allocation_id = ?
        ");

        $stmt->execute([
            $applicantId,
            (int) $merit['allocation_id']
        ]);

        $merit['applicant_id'] =
            $applicantId;
    }

    /*
    |--------------------------------------------------------------------------
    | Viva must be Selected
    |--------------------------------------------------------------------------
    */
    $stmt = $pdo->prepare("
        SELECT *
        FROM viva_results
        WHERE applicant_id = ?
        ORDER BY viva_id DESC
        LIMIT 1
    ");

    $stmt->execute([
        $applicantId
    ]);

    $viva =
        $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$viva) {
        throw new RuntimeException(
            'Your Viva result has not been published yet.'
        );
    }

    if (
        (string) (
            $viva['viva_status']
            ?? ''
        )
        !== 'Selected'
    ) {
        throw new RuntimeException(
            'Only candidates selected in Viva can continue to Department, Hall and Registrar submissions.'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Admission progress
    |--------------------------------------------------------------------------
    */
    $stmt = $pdo->prepare("
        SELECT *
        FROM admission_progress
        WHERE applicant_id = ?
        LIMIT 1
    ");

    $stmt->execute([
        $applicantId
    ]);

    $progress =
        $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$progress) {

        $stmt = $pdo->prepare("
            INSERT INTO admission_progress (
                applicant_id
            )
            VALUES (?)
        ");

        $stmt->execute([
            $applicantId
        ]);

        $stmt = $pdo->prepare("
            SELECT *
            FROM admission_progress
            WHERE applicant_id = ?
            LIMIT 1
        ");

        $stmt->execute([
            $applicantId
        ]);

        $progress =
            $stmt->fetch(PDO::FETCH_ASSOC);
    }

    if (!$progress) {
        throw new RuntimeException(
            'Admission progress could not be initialized.'
        );
    }

    return [
        'imported' =>
            $imported,

        'import_candidate_id' =>
            $importCandidateId,

        'merit' =>
            $merit,

        'viva' =>
            $viva,

        'progress' =>
            $progress,
    ];
}

<?php

declare(strict_types=1);

/**
 * Fetch complete student biodata.
 *
 * Source priority:
 * 1. gst_applicants (registered/editable profile)
 * 2. gst_imported_candidates (GST import fallback)
 *
 * The imported record is resolved first by applicant_id.
 * If an older record is not linked, gst_roll is used as fallback.
 */
function fetchStudentBiodata(
    PDO $pdo,
    int $applicantId,
    string $academicYear
): ?array {

    /*
    |--------------------------------------------------------------------------
    | Registered applicant
    |--------------------------------------------------------------------------
    */
    $stmt = $pdo->prepare("
        SELECT *
        FROM gst_applicants
        WHERE applicant_id = ?
        LIMIT 1
    ");

    $stmt->execute([
        $applicantId
    ]);

    $applicant =
        $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$applicant) {
        return null;
    }

    $gstRoll =
        trim(
            (string) (
                $applicant['gst_roll']
                ?? ''
            )
        );

    /*
    |--------------------------------------------------------------------------
    | Imported GST record
    |--------------------------------------------------------------------------
    |
    | Prefer the proper applicant_id link.
    | gst_roll fallback supports older registrations where the link
    | was not written back to gst_imported_candidates.
    |--------------------------------------------------------------------------
    */
    $stmt = $pdo->prepare("
        SELECT *
        FROM gst_imported_candidates
        WHERE academic_year = ?
          AND (
              applicant_id = ?
              OR gst_roll = ?
          )
        ORDER BY
            CASE
                WHEN applicant_id = ? THEN 0
                ELSE 1
            END,
            import_candidate_id DESC
        LIMIT 1
    ");

    $stmt->execute([
        $academicYear,
        $applicantId,
        $gstRoll,
        $applicantId
    ]);

    $imported =
        $stmt->fetch(PDO::FETCH_ASSOC);

    /*
    |--------------------------------------------------------------------------
    | Repair missing imported applicant link
    |--------------------------------------------------------------------------
    */
    if (
        $imported
        &&
        empty(
            $imported['applicant_id']
        )
    ) {
        $linkStmt =
            $pdo->prepare("
                UPDATE gst_imported_candidates
                SET
                    applicant_id = ?,
                    is_registered = 1
                WHERE import_candidate_id = ?
            ");

        $linkStmt->execute([
            $applicantId,
            (int) $imported[
                'import_candidate_id'
            ]
        ]);

        $imported['applicant_id'] =
            $applicantId;
    }

    /*
    |--------------------------------------------------------------------------
    | Merge helper
    |--------------------------------------------------------------------------
    */
    $pick = static function (
        mixed $profileValue,
        mixed $importValue = null
    ): mixed {

        if (
            $profileValue !== null
            &&
            $profileValue !== ''
        ) {
            return $profileValue;
        }

        if (
            $importValue !== null
            &&
            $importValue !== ''
        ) {
            return $importValue;
        }

        return null;
    };

    /*
    |--------------------------------------------------------------------------
    | Final merged biodata
    |--------------------------------------------------------------------------
    */
    $student = $applicant;

    $student['full_name'] =
        $pick(
            $applicant['full_name'] ?? null,
            $imported['full_name'] ?? null
        );

    $student['phone'] =
        $pick(
            $applicant['phone'] ?? null,
            $imported['phone'] ?? null
        );

    $student['date_of_birth'] =
        $pick(
            $applicant['date_of_birth'] ?? null,
            $imported['date_of_birth'] ?? null
        );

    $student['gender'] =
        $pick(
            $applicant['gender'] ?? null,
            $imported['gender'] ?? null
        );

    $student['nationality'] =
        $pick(
            $applicant['nationality'] ?? null,
            $imported['nationality'] ?? null
        );

    $student['quota'] =
        $pick(
            $applicant['quota'] ?? null,
            $imported['quota'] ?? null
        );

    $student['gst_score'] =
        $pick(
            $applicant['gst_score'] ?? null,
            $imported['gst_score'] ?? null
        );

    $student['faculty_id'] =
        $pick(
            $applicant['faculty_id'] ?? null,
            $imported['faculty_id'] ?? null
        );

    /*
    |--------------------------------------------------------------------------
    | HSC
    |--------------------------------------------------------------------------
    */
    $student['hsc_roll'] =
        $pick(
            $applicant['hsc_roll'] ?? null,
            $imported['hsc_roll'] ?? null
        );

    $student['hsc_reg'] =
        $pick(
            $applicant['hsc_reg'] ?? null,
            $imported['hsc_reg'] ?? null
        );

    $student['hsc_group'] =
        $pick(
            $applicant['hsc_group'] ?? null,
            $imported['hsc_group'] ?? null
        );

    $student['hsc_board'] =
        $pick(
            $applicant['hsc_board'] ?? null,
            $imported['hsc_board'] ?? null
        );

    $student['hsc_year'] =
        $pick(
            $applicant['hsc_year'] ?? null,
            $imported['hsc_year'] ?? null
        );

    $student['hsc_gpa'] =
        $pick(
            $applicant['hsc_gpa'] ?? null,
            $imported['hsc_gpa'] ?? null
        );

    $student['hsc_institute'] =
        $pick(
            $applicant['hsc_institute'] ?? null,
            $imported['hsc_institute'] ?? null
        );

    /*
    |--------------------------------------------------------------------------
    | SSC
    |--------------------------------------------------------------------------
    */
    $student['ssc_roll'] =
        $pick(
            $applicant['ssc_roll'] ?? null,
            $imported['ssc_roll'] ?? null
        );

    $student['ssc_reg'] =
        $pick(
            $applicant['ssc_reg'] ?? null,
            $imported['ssc_reg'] ?? null
        );

    $student['ssc_board'] =
        $pick(
            $applicant['ssc_board'] ?? null,
            $imported['ssc_board'] ?? null
        );

    $student['ssc_year'] =
        $pick(
            $applicant['ssc_year'] ?? null,
            $imported['ssc_year'] ?? null
        );

    $student['ssc_gpa'] =
        $pick(
            $applicant['ssc_gpa'] ?? null,
            $imported['ssc_gpa'] ?? null
        );

    $student['ssc_institute'] =
        $pick(
            $applicant['ssc_institute'] ?? null,
            $imported['ssc_institute'] ?? null
        );

    /*
    |--------------------------------------------------------------------------
    | Imported metadata
    |--------------------------------------------------------------------------
    */
    $student['import_candidate_id'] =
        $imported['import_candidate_id']
        ?? null;

    $student['gst_unit'] =
        $imported['gst_unit']
        ?? null;

    $student['merit_position'] =
        $imported['merit_position']
        ?? null;

    $student['academic_year'] =
        $imported['academic_year']
        ?? $academicYear;

    $student['selection_status'] =
        $imported['selection_status']
        ?? null;

    return $student;
}

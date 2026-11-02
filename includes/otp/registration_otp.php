<?php

declare(strict_types=1);

require_once __DIR__ . '/../mail/send_mail.php';
require_once __DIR__ . '/../sms/send_sms.php';

/*
|--------------------------------------------------------------------------
| OTP CONFIGURATION
|--------------------------------------------------------------------------
*/

const OTP_RESEND_COOLDOWN = 60;
const OTP_MAX_REQUESTS = 5;
const OTP_RATE_WINDOW = 900;
const OTP_VALIDITY = 300;
const OTP_MAX_ATTEMPTS = 5;


/*
|--------------------------------------------------------------------------
| PHONE NORMALIZATION
|--------------------------------------------------------------------------
*/

function normalizeRegistrationPhone(string $phone): string
{
    $phone = preg_replace('/[\s\-\(\)]/', '', trim($phone));

    if (!is_string($phone)) {
        throw new RuntimeException('Invalid phone number.');
    }

    if (str_starts_with($phone, '+88')) {
        $phone = substr($phone, 1);
    } elseif (str_starts_with($phone, '01')) {
        $phone = '88' . $phone;
    }

    if (!preg_match('/^8801[3-9][0-9]{8}$/', $phone)) {
        throw new RuntimeException(
            'Invalid Bangladesh mobile number.'
        );
    }

    return $phone;
}


/*
|--------------------------------------------------------------------------
| MASK PHONE
|--------------------------------------------------------------------------
*/

function maskPhone(string $phone): string
{
    $phone = normalizeRegistrationPhone($phone);

    return substr($phone, 0, 5)
        . '*****'
        . substr($phone, -2);
}


/*
|--------------------------------------------------------------------------
| MASK EMAIL
|--------------------------------------------------------------------------
*/

function maskEmail(string $email): string
{
    $parts = explode('@', $email, 2);

    if (count($parts) !== 2) {
        return $email;
    }

    $username = $parts[0];

    return substr($username, 0, 2)
        . str_repeat('*', max(2, strlen($username) - 2))
        . '@'
        . $parts[1];
}


/*
|--------------------------------------------------------------------------
| OTP COOLDOWN AND RATE LIMIT
|--------------------------------------------------------------------------
*/

function checkRegistrationOtpCooldown(
    PDO $pdo,
    int $importCandidateId
): void {

    $stmt = $pdo->prepare("
        SELECT
            COUNT(*) AS total_requests,
            MIN(created_at) AS oldest_request,
            MAX(created_at) AS latest_request

        FROM registration_otps

        WHERE import_candidate_id = ?

          AND created_at >= DATE_SUB(
              NOW(),
              INTERVAL " . OTP_RATE_WINDOW . " SECOND
          )
    ");

    $stmt->execute([$importCandidateId]);

    $data = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$data) {
        return;
    }

    $totalRequests = (int) $data['total_requests'];

    $latestRequest = $data['latest_request'];
    $oldestRequest = $data['oldest_request'];


    /*
    |--------------------------------------------------------------------------
    | 60-second cooldown
    |--------------------------------------------------------------------------
    */

    if ($latestRequest !== null) {

        $waitStmt = $pdo->prepare("
            SELECT GREATEST(
                0,
                " . OTP_RESEND_COOLDOWN . " -
                TIMESTAMPDIFF(
                    SECOND,
                    ?,
                    NOW()
                )
            )
        ");

        $waitStmt->execute([$latestRequest]);

        $remaining = (int) $waitStmt->fetchColumn();

        if ($remaining > 0) {
            throw new RuntimeException(
                "Please wait {$remaining} seconds before requesting another OTP."
            );
        }
    }


    /*
    |--------------------------------------------------------------------------
    | Maximum 5 requests in 15 minutes
    |--------------------------------------------------------------------------
    */

    if ($totalRequests >= OTP_MAX_REQUESTS) {

        $waitStmt = $pdo->prepare("
            SELECT GREATEST(
                1,
                TIMESTAMPDIFF(
                    SECOND,
                    NOW(),
                    DATE_ADD(
                        ?,
                        INTERVAL " . OTP_RATE_WINDOW . " SECOND
                    )
                )
            )
        ");

        $waitStmt->execute([$oldestRequest]);

        $remaining = (int) $waitStmt->fetchColumn();

        $minutes = (int) ceil($remaining / 60);

        throw new RuntimeException(
            "You have reached the OTP request limit. Please try again after {$minutes} minute(s)."
        );
    }
}


/*
|--------------------------------------------------------------------------
| CREATE AND SEND OTP
|--------------------------------------------------------------------------
*/

function createAndSendRegistrationOtp(
    PDO $pdo,
    int $importCandidateId,
    string $method,
    string $destination
): array {

    if ($importCandidateId <= 0) {
        throw new RuntimeException(
            'Invalid GST candidate.'
        );
    }

    $method = ucfirst(
        strtolower(trim($method))
    );

    if (!in_array($method, ['Email', 'Phone'], true)) {
        throw new RuntimeException(
            'Invalid OTP verification method.'
        );
    }


    /*
    |--------------------------------------------------------------------------
    | Validate destination
    |--------------------------------------------------------------------------
    */

    if ($method === 'Email') {

        $destination = strtolower(trim($destination));

        if (!filter_var($destination, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException(
                'Invalid email address.'
            );
        }

    } else {

        $destination = normalizeRegistrationPhone($destination);
    }


    /*
    |--------------------------------------------------------------------------
    | Generate secure OTP
    |--------------------------------------------------------------------------
    */

    $otp = (string) random_int(100000, 999999);

    $otpHash = password_hash(
        $otp,
        PASSWORD_DEFAULT
    );

    if ($otpHash === false) {
        throw new RuntimeException(
            'Unable to generate secure OTP.'
        );
    }


    /*
    |--------------------------------------------------------------------------
    | Database transaction and candidate lock
    |--------------------------------------------------------------------------
    */

    if ($pdo->inTransaction()) {
        throw new RuntimeException(
            'OTP generation cannot run inside another database transaction.'
        );
    }

    $pdo->beginTransaction();

    try {

        $candidateStmt = $pdo->prepare("
            SELECT import_candidate_id

            FROM gst_imported_candidates

            WHERE import_candidate_id = ?

            LIMIT 1

            FOR UPDATE
        ");

        $candidateStmt->execute([
            $importCandidateId
        ]);

        if (!$candidateStmt->fetchColumn()) {
            throw new RuntimeException(
                'GST candidate was not found.'
            );
        }


        /*
        |--------------------------------------------------------------------------
        | Enforce cooldown
        |--------------------------------------------------------------------------
        */

        checkRegistrationOtpCooldown(
            $pdo,
            $importCandidateId
        );


        /*
        |--------------------------------------------------------------------------
        | Invalidate previous unverified OTPs
        |--------------------------------------------------------------------------
        */

        $expireOld = $pdo->prepare("
            UPDATE registration_otps

            SET expires_at = NOW()

            WHERE import_candidate_id = ?

              AND verified_at IS NULL

              AND expires_at > NOW()
        ");

        $expireOld->execute([
            $importCandidateId
        ]);


        /*
        |--------------------------------------------------------------------------
        | Insert new OTP
        |--------------------------------------------------------------------------
        */

        $insert = $pdo->prepare("
            INSERT INTO registration_otps (
                import_candidate_id,
                verification_method,
                destination,
                otp_hash,
                expires_at,
                attempts,
                created_at
            )

            VALUES (
                ?,
                ?,
                ?,
                ?,
                DATE_ADD(NOW(), INTERVAL " . OTP_VALIDITY . " SECOND),
                0,
                NOW()
            )
        ");

        $insert->execute([
            $importCandidateId,
            $method,
            $destination,
            $otpHash
        ]);

        $otpId = (int) $pdo->lastInsertId();

        $pdo->commit();

    } catch (Throwable $exception) {

        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        throw $exception;
    }


    /*
    |--------------------------------------------------------------------------
    | Send email or SMS outside transaction
    |--------------------------------------------------------------------------
    */

    try {

        if ($method === 'Email') {

            $subject = 'JKKNIU Registration Verification OTP';

            $message = "
                <div style='font-family:Arial,sans-serif;max-width:600px;margin:auto;'>

                    <h2>JKKNIU Admission Management System</h2>

                    <p>Your registration verification code is:</p>

                    <h1 style='letter-spacing:8px;color:#0d6efd;'>
                        {$otp}
                    </h1>

                    <p>This OTP will expire in 5 minutes.</p>

                    <p>If you did not request this code, please ignore this email.</p>

                </div>
            ";

            $sent = sendMail(
                $destination,
                $subject,
                $message
            );

            if ($sent === false) {
                throw new RuntimeException(
                    'Email OTP could not be sent.'
                );
            }

        } else {

            $message = "Your JKKNIU registration OTP is {$otp}. It expires in 5 minutes.";

            $sent = sendSMS(
                $destination,
                $message
            );

            if ($sent === false) {
                throw new RuntimeException(
                    'SMS OTP could not be sent.'
                );
            }
        }

    } catch (Throwable $exception) {

        $expire = $pdo->prepare("
            UPDATE registration_otps

            SET expires_at = NOW()

            WHERE otp_id = ?

              AND verified_at IS NULL
        ");

        $expire->execute([$otpId]);

        throw new RuntimeException(
            'OTP delivery failed: ' . $exception->getMessage()
        );
    }


    return [
        'otp_id' => $otpId,

        'expires_at' => date(
            'Y-m-d H:i:s',
            time() + OTP_VALIDITY
        ),

        'resend_available_at' =>
            time() + OTP_RESEND_COOLDOWN
    ];
}


/*
|--------------------------------------------------------------------------
| VERIFY OTP
|--------------------------------------------------------------------------
*/

function verifyRegistrationOtp(
    PDO $pdo,
    int $importCandidateId,
    string $method,
    string $destination,
    string $otp
): bool {

    $method = ucfirst(
        strtolower(trim($method))
    );

    if (!in_array($method, ['Email', 'Phone'], true)) {
        throw new RuntimeException(
            'Invalid verification method.'
        );
    }

    if ($method === 'Email') {
        $destination = strtolower(trim($destination));
    } else {
        $destination = normalizeRegistrationPhone($destination);
    }

    if (!preg_match('/^[0-9]{6}$/', $otp)) {
        throw new RuntimeException(
            'Enter a valid 6-digit OTP.'
        );
    }


    $stmt = $pdo->prepare("
        SELECT
            otp_id,
            otp_hash,
            expires_at,
            attempts,
            verified_at

        FROM registration_otps

        WHERE import_candidate_id = ?

          AND verification_method = ?

          AND destination = ?

        ORDER BY otp_id DESC

        LIMIT 1

        FOR UPDATE
    ");

    $stmt->execute([
        $importCandidateId,
        $method,
        $destination
    ]);

    $record = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$record) {
        throw new RuntimeException(
            'No OTP request was found. Please request a new OTP.'
        );
    }

    if ($record['verified_at'] !== null) {
        throw new RuntimeException(
            'This OTP has already been used.'
        );
    }

    if (strtotime($record['expires_at']) <= time()) {
        throw new RuntimeException(
            'OTP has expired. Please request a new OTP.'
        );
    }

    if ((int) $record['attempts'] >= OTP_MAX_ATTEMPTS) {
        throw new RuntimeException(
            'Maximum OTP verification attempts exceeded.'
        );
    }


    if (!password_verify($otp, $record['otp_hash'])) {

        $updateAttempts = $pdo->prepare("
            UPDATE registration_otps

            SET attempts = attempts + 1

            WHERE otp_id = ?
        ");

        $updateAttempts->execute([
            (int) $record['otp_id']
        ]);

        throw new RuntimeException(
            'Incorrect OTP. Please try again.'
        );
    }


    $verify = $pdo->prepare("
        UPDATE registration_otps

        SET verified_at = NOW()

        WHERE otp_id = ?

          AND verified_at IS NULL
    ");

    $verify->execute([
        (int) $record['otp_id']
    ]);

    if ($verify->rowCount() !== 1) {
        throw new RuntimeException(
            'OTP verification failed.'
        );
    }

    return true;
}


/*
|--------------------------------------------------------------------------
| CHECK VERIFIED OTP
|--------------------------------------------------------------------------
*/

function hasVerifiedRegistrationOtp(
    PDO $pdo,
    int $importCandidateId,
    string $method,
    string $destination
): bool {

    $method = ucfirst(
        strtolower(trim($method))
    );

    if ($method === 'Email') {
        $destination = strtolower(trim($destination));
    } else {
        $destination = normalizeRegistrationPhone($destination);
    }

    $stmt = $pdo->prepare("
        SELECT otp_id

        FROM registration_otps

        WHERE import_candidate_id = ?

          AND verification_method = ?

          AND destination = ?

          AND verified_at IS NOT NULL

        ORDER BY otp_id DESC

        LIMIT 1
    ");

    $stmt->execute([
        $importCandidateId,
        $method,
        $destination
    ]);

    return (bool) $stmt->fetchColumn();
}
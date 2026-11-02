<?php

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require_once __DIR__ . '/../../vendor/phpmailer/src/Exception.php';
require_once __DIR__ . '/../../vendor/phpmailer/src/PHPMailer.php';
require_once __DIR__ . '/../../vendor/phpmailer/src/SMTP.php';

require_once __DIR__ . '/mail_config.php';

function sendMail(string $to, string $subject, string $body): bool
{
    $mail = new PHPMailer(true);

    try {

        $mail->isSMTP();

        $mail->Host = MAIL_HOST;
        $mail->SMTPAuth = true;
        $mail->Username = MAIL_USERNAME;
        $mail->Password = MAIL_PASSWORD;

        $mail->SMTPSecure =
            PHPMailer::ENCRYPTION_STARTTLS;

        $mail->Port = MAIL_PORT;

        $mail->setFrom(
            MAIL_FROM,
            MAIL_FROM_NAME
        );

        $mail->addAddress($to);

        $mail->isHTML(true);

        $mail->Subject = $subject;
        $mail->Body = $body;

        return $mail->send();

    } catch (Exception $e) {

        error_log(
            'PHPMailer Error: ' .
            $mail->ErrorInfo
        );

        return false;
    }
}
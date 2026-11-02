<?php

declare(strict_types=1);

require_once __DIR__ . '/sms_config.php';

$GLOBALS['SMS_LAST_ERROR'] = '';

function normalizeBdPhone(string $phone): string
{
    $phone = preg_replace(
        '/[\s\-\(\)]/',
        '',
        trim($phone)
    ) ?? '';

    if (str_starts_with($phone, '+880')) {
        $phone = '0' . substr($phone, 4);
    } elseif (str_starts_with($phone, '880')) {
        $phone = '0' . substr($phone, 3);
    }

    if (!preg_match('/^01[3-9][0-9]{8}$/', $phone)) {
        throw new InvalidArgumentException(
            'Invalid phone number: ' . $phone
        );
    }

    return $phone;
}

function getSmsLastError(): string
{
    return (string) ($GLOBALS['SMS_LAST_ERROR'] ?? '');
}

function sendSMS(string $phone, string $message): bool
{
    $GLOBALS['SMS_LAST_ERROR'] = '';

    try {

        if (!defined('SMS_DEMO_MODE') || !SMS_DEMO_MODE) {
            throw new RuntimeException(
                'SMS demo mode is not enabled.'
            );
        }

        $phone = normalizeBdPhone($phone);

        $message = trim($message);

        if ($message === '') {
            throw new InvalidArgumentException(
                'SMS message cannot be empty.'
            );
        }

        // Resolve log directory
        $directory = dirname(SMS_LOG_FILE);

        // Create directory if missing
        if (!is_dir($directory)) {

            if (!mkdir($directory, 0775, true) && !is_dir($directory)) {
                throw new RuntimeException(
                    'Cannot create SMS directory: ' . $directory
                );
            }
        }

        // Prepare log entry
        $logEntry = sprintf(
            "[%s] TO: %s | %s%s",
            date('Y-m-d H:i:s'),
            $phone,
            str_replace(["\r", "\n"], ' ', $message),
            PHP_EOL
        );

        // Attempt actual file writing
        $result = @file_put_contents(
            SMS_LOG_FILE,
            $logEntry,
            FILE_APPEND | LOCK_EX
        );

        if ($result === false) {

            $lastError = error_get_last();

            throw new RuntimeException(
                'SMS log writing failed. File: '
                . SMS_LOG_FILE
                . ' | PHP Error: '
                . ($lastError['message'] ?? 'Unknown error')
            );
        }

        return true;

    } catch (Throwable $exception) {

        $GLOBALS['SMS_LAST_ERROR'] = $exception->getMessage();

        error_log(
            'SMS Error: ' . $exception->getMessage()
        );

        return false;
    }
}
<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Payment (SSLCommerz) Configuration
|--------------------------------------------------------------------------
|
| Demo mode (PAYMENT_DEMO_MODE = true) behaves like the SMS demo logger:
| no real gateway call is made. Payment intents and fake transactions are
| written to storage/logs/payments.log so the full
| student -> pay -> submit flow can be tested before real credentials.
|
| To go live:
|   1. Set PAYMENT_DEMO_MODE = false
|   2. Fill SSLCOMMERZ_STORE_ID / SSLCOMMERZ_STORE_PASSWD
|   3. Set SSLCOMMERZ_SANDBOX = false
|   4. Set PAYMENT_SITE_BASE to your public HTTPS base URL (SSLCommerz
|      requires HTTPS URLs for the result/IPN callbacks)
|
| Fee amounts are defined here as the single source of truth. Students no
| longer enter amounts manually — the gateway always charges these values.
|--------------------------------------------------------------------------
*/

define('PAYMENT_DEMO_MODE', true);

define(
    'PAYMENT_LOG_FILE',
    dirname(__DIR__) . '/storage/logs/payments.log'
);

define(
    'PAYMENT_SITE_BASE',
    'https://your-public-domain.tld/jkkniu-admission'
);

define('SSLCOMMERZ_STORE_ID', 'YOUR_STORE_ID');
define('SSLCOMMERZ_STORE_PASSWD', 'YOUR_STORE_PASSWD');
define('SSLCOMMERZ_SANDBOX', true);

define(
    'SSLCOMMERZ_URL_SANDBOX',
    'https://sandbox.sslcommerz.com'
);

define(
    'SSLCOMMERZ_URL_LIVE',
    'https://securepay.sslcommerz.com.bd'
);

define(
    'SONALI_BANK_ADMISSION_URL',
    'https://billpay.sonalibank.com.bd/JKKNIU/AdmissionFee/Honours'
);

define(
    'SONALI_BANK_GET_STUDENT_URL',
    'https://billpay.sonalibank.com.bd/JKKNIU/AdmissionFee/GetAdmissionStudent'
);

define('DEPT_FEE_AMOUNT', 500.00);
define('HALL_FEE_AMOUNT', 800.00);
define('ADMISSION_FEE_AMOUNT', 1200.00);
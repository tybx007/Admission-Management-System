-- ============================================================
-- JKKNIU Admission - SSLCommerz Online Payment Migration
-- Run once against database `jkkniu_admission_process`.
-- ============================================================

CREATE TABLE IF NOT EXISTS payment_transactions (
    id                BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tran_id           VARCHAR(64)        NOT NULL,
    applicant_id      INT                NOT NULL,
    payment_step      ENUM('dept','hall','admission') NOT NULL,
    amount            DECIMAL(10,2)      NOT NULL DEFAULT 0.00,
    currency          CHAR(3)            NOT NULL DEFAULT 'BDT',
    gateway           VARCHAR(32)        NOT NULL DEFAULT 'SSLCommerz',
    status            ENUM('INITIATED','SUCCESS','FAILED','CANCELED','VERIFIED') NOT NULL DEFAULT 'INITIATED',
    trx_id            VARCHAR(64)        DEFAULT NULL,
    val_id            VARCHAR(64)        DEFAULT NULL,
    card_type         VARCHAR(32)        DEFAULT NULL,
    bank_trans_id     VARCHAR(64)        DEFAULT NULL,
    gateway_response  TEXT               DEFAULT NULL,
    paid_at           DATETIME           DEFAULT NULL,
    created_at        DATETIME           DEFAULT NULL,
    updated_at        DATETIME           DEFAULT NULL,
    UNIQUE KEY uq_payment_tran_id (tran_id),
    KEY idx_payment_applicant_step (applicant_id, payment_step),
    KEY idx_payment_status (status),
    CONSTRAINT fk_payment_applicant
        FOREIGN KEY (applicant_id)
        REFERENCES gst_applicants (applicant_id)
        ON UPDATE CASCADE
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
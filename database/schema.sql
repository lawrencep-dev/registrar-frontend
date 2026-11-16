CREATE DATABASE IF NOT EXISTS lcupay
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

USE lcupay;

CREATE TABLE IF NOT EXISTS students (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    student_number VARCHAR(32) NOT NULL,
    email VARCHAR(254) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    first_name VARCHAR(100) NOT NULL,
    last_name VARCHAR(100) NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_students_student_number (student_number),
    UNIQUE KEY uq_students_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS fee_types (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    code VARCHAR(64) NOT NULL,
    name VARCHAR(150) NOT NULL,
    default_amount DECIMAL(10,2) NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    PRIMARY KEY (id),
    UNIQUE KEY uq_fee_types_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS student_charges (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    student_id BIGINT UNSIGNED NOT NULL,
    fee_type_id BIGINT UNSIGNED NOT NULL,
    amount DECIMAL(10,2) NOT NULL,
    due_date DATE NOT NULL,
    status ENUM('unpaid', 'partial', 'paid') NOT NULL DEFAULT 'unpaid',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_student_charges_student_id (student_id),
    UNIQUE KEY uq_student_charges_student_fee (student_id, fee_type_id),
    CONSTRAINT fk_student_charges_student
        FOREIGN KEY (student_id) REFERENCES students (id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_student_charges_fee_type
        FOREIGN KEY (fee_type_id) REFERENCES fee_types (id)
        ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS payments (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    student_charge_id BIGINT UNSIGNED NOT NULL,
    reference_number VARCHAR(64) NOT NULL,
    amount DECIMAL(10,2) NOT NULL,
    convenience_fee DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    payment_method VARCHAR(64) NOT NULL,
    status ENUM('paid', 'failed') NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    paid_at DATETIME NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_payments_reference_number (reference_number),
    KEY idx_payments_student_charge_id (student_charge_id),
    CONSTRAINT fk_payments_student_charge
        FOREIGN KEY (student_charge_id) REFERENCES student_charges (id)
        ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
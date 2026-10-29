<?php
declare(strict_types=1);

/*
 * Početna šema (plan §4). Vremena: `*_at` kolone su UTC u obliku 'YYYY-MM-DD HH:MM:SS';
 * `receipts.sdc_datetime` je lokalno vreme PFR-a kao tekst 'YYYY-MM-DDTHH:MM:SS' (poređenje po stringu radi na oba drajvera).
 * Podešavanja: kolone `name`/`value` (reč `key` je rezervisana u MySQL-u).
 * `api_rate` čuva brojač zahteva po korisniku u tekućem minutu (rate limit API-ja za aplikaciju).
 */

$mysqlTail = ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

return [
    'mysql' => [
        'CREATE TABLE IF NOT EXISTS users (
            id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            username VARCHAR(64) NOT NULL,
            password_hash VARCHAR(255) NOT NULL,
            role VARCHAR(16) NOT NULL,
            totp_secret VARCHAR(64) NULL,
            active TINYINT(1) NOT NULL DEFAULT 1,
            created_by INT NULL,
            created_at DATETIME NOT NULL,
            last_login_at DATETIME NULL,
            UNIQUE KEY users_username_uq (username),
            CONSTRAINT users_created_by_fk FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL
        )' . $mysqlTail,
        'CREATE TABLE IF NOT EXISTS clients (
            id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(200) NOT NULL,
            slug CHAR(32) NOT NULL,
            api_key_prefix VARCHAR(16) NOT NULL,
            api_key_hash CHAR(64) NOT NULL,
            active TINYINT(1) NOT NULL DEFAULT 1,
            note TEXT NULL,
            created_at DATETIME NOT NULL,
            last_seen_at DATETIME NULL,
            UNIQUE KEY clients_slug_uq (slug),
            KEY clients_prefix_idx (api_key_prefix)
        )' . $mysqlTail,
        'CREATE TABLE IF NOT EXISTS receipts (
            id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            client_id INT NOT NULL,
            invoice_number VARCHAR(40) NOT NULL,
            verification_url TEXT NOT NULL,
            sdc_datetime VARCHAR(19) NOT NULL,
            total_amount_minor BIGINT NOT NULL,
            seller_name VARCHAR(200) NULL,
            seller_pib CHAR(9) NULL,
            buyer_pib CHAR(9) NULL,
            invoice_type VARCHAR(200) NULL,
            transaction_type VARCHAR(200) NULL,
            received_at DATETIME NOT NULL,
            raw_json TEXT NULL,
            UNIQUE KEY receipts_client_invoice_uq (client_id, invoice_number),
            KEY receipts_client_sdc_idx (client_id, sdc_datetime),
            CONSTRAINT receipts_client_fk FOREIGN KEY (client_id) REFERENCES clients (id) ON DELETE CASCADE
        )' . $mysqlTail,
        'CREATE TABLE IF NOT EXISTS settings (
            name VARCHAR(100) NOT NULL PRIMARY KEY,
            value TEXT NOT NULL
        )' . $mysqlTail,
        'CREATE TABLE IF NOT EXISTS audit_log (
            id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            user_id INT NULL,
            action VARCHAR(64) NOT NULL,
            subject_type VARCHAR(32) NULL,
            subject_id INT NULL,
            at DATETIME NOT NULL,
            ip VARCHAR(45) NOT NULL,
            details TEXT NULL,
            KEY audit_log_at_idx (at),
            CONSTRAINT audit_log_user_fk FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL
        )' . $mysqlTail,
        'CREATE TABLE IF NOT EXISTS login_attempts (
            id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            ip VARCHAR(45) NOT NULL,
            username VARCHAR(64) NOT NULL,
            at DATETIME NOT NULL,
            KEY login_attempts_ip_user_at_idx (ip, username, at),
            KEY login_attempts_at_idx (at)
        )' . $mysqlTail,
        'CREATE TABLE IF NOT EXISTS api_rate (
            client_id INT NOT NULL PRIMARY KEY,
            window_start INT NOT NULL,
            hits INT NOT NULL
        )' . $mysqlTail,
    ],
    'sqlite' => [
        'CREATE TABLE IF NOT EXISTS users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            username TEXT NOT NULL UNIQUE,
            password_hash TEXT NOT NULL,
            role TEXT NOT NULL,
            totp_secret TEXT NULL,
            active INTEGER NOT NULL DEFAULT 1,
            created_by INTEGER NULL REFERENCES users (id) ON DELETE SET NULL,
            created_at TEXT NOT NULL,
            last_login_at TEXT NULL
        )',
        'CREATE TABLE IF NOT EXISTS clients (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            slug TEXT NOT NULL UNIQUE,
            api_key_prefix TEXT NOT NULL,
            api_key_hash TEXT NOT NULL,
            active INTEGER NOT NULL DEFAULT 1,
            note TEXT NULL,
            created_at TEXT NOT NULL,
            last_seen_at TEXT NULL
        )',
        'CREATE INDEX IF NOT EXISTS clients_prefix_idx ON clients (api_key_prefix)',
        'CREATE TABLE IF NOT EXISTS receipts (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            client_id INTEGER NOT NULL REFERENCES clients (id) ON DELETE CASCADE,
            invoice_number TEXT NOT NULL,
            verification_url TEXT NOT NULL,
            sdc_datetime TEXT NOT NULL,
            total_amount_minor INTEGER NOT NULL,
            seller_name TEXT NULL,
            seller_pib TEXT NULL,
            buyer_pib TEXT NULL,
            invoice_type TEXT NULL,
            transaction_type TEXT NULL,
            received_at TEXT NOT NULL,
            raw_json TEXT NULL,
            UNIQUE (client_id, invoice_number)
        )',
        'CREATE INDEX IF NOT EXISTS receipts_client_sdc_idx ON receipts (client_id, sdc_datetime)',
        'CREATE TABLE IF NOT EXISTS settings (
            name TEXT NOT NULL PRIMARY KEY,
            value TEXT NOT NULL
        )',
        'CREATE TABLE IF NOT EXISTS audit_log (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NULL REFERENCES users (id) ON DELETE SET NULL,
            action TEXT NOT NULL,
            subject_type TEXT NULL,
            subject_id INTEGER NULL,
            at TEXT NOT NULL,
            ip TEXT NOT NULL,
            details TEXT NULL
        )',
        'CREATE INDEX IF NOT EXISTS audit_log_at_idx ON audit_log (at)',
        'CREATE TABLE IF NOT EXISTS login_attempts (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            ip TEXT NOT NULL,
            username TEXT NOT NULL,
            at TEXT NOT NULL
        )',
        'CREATE INDEX IF NOT EXISTS login_attempts_ip_user_at_idx ON login_attempts (ip, username, at)',
        'CREATE INDEX IF NOT EXISTS login_attempts_at_idx ON login_attempts (at)',
        'CREATE TABLE IF NOT EXISTS api_rate (
            client_id INTEGER NOT NULL PRIMARY KEY,
            window_start INTEGER NOT NULL,
            hits INTEGER NOT NULL
        )',
    ],
];

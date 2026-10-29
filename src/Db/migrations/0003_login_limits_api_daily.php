<?php
declare(strict_types=1);

/*
 * Ograničenja: indeks za brojanje neuspelih prijava po korisničkom imenu (sa svih adresa) i dnevni brojač prihvaćenih
 * računa po korisniku (`api_daily`: jedan red po korisniku — tabela ne raste; `day` je UTC datum 'YYYY-MM-DD',
 * `warned` 0 = bez upozorenja, 1 = upisano upozorenje za 50 % limita, 2 = upisano dostizanje limita).
 * MySQL nema `CREATE INDEX IF NOT EXISTS`, pa se indeks pravi uslovno preko information_schema.
 */

$mysqlTail = ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

return [
    'mysql' => [
        "SET @rp_sql = (SELECT IF(COUNT(*) = 0, 'CREATE INDEX login_attempts_user_at_idx ON login_attempts (username, at)', 'DO 0')"
            . " FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'login_attempts'"
            . " AND index_name = 'login_attempts_user_at_idx')",
        'PREPARE rp_stmt FROM @rp_sql',
        'EXECUTE rp_stmt',
        'DEALLOCATE PREPARE rp_stmt',
        'CREATE TABLE IF NOT EXISTS api_daily (
            client_id INT NOT NULL PRIMARY KEY,
            day CHAR(10) NOT NULL,
            accepted INT NOT NULL,
            warned TINYINT NOT NULL DEFAULT 0
        )' . $mysqlTail,
    ],
    'sqlite' => [
        'CREATE INDEX IF NOT EXISTS login_attempts_user_at_idx ON login_attempts (username, at)',
        'CREATE TABLE IF NOT EXISTS api_daily (
            client_id INTEGER NOT NULL PRIMARY KEY,
            day TEXT NOT NULL,
            accepted INTEGER NOT NULL,
            warned INTEGER NOT NULL DEFAULT 0
        )',
    ],
];

<?php
declare(strict_types=1);

/*
 * Verzija sesija naloga i šira kolona za šifrovanu TOTP tajnu.
 *  - `users.session_version` (podrazumevano 1): upisuje se u sesiju pri prijavi; povećava se pri promeni ili resetu
 *    lozinke, deaktivaciji, promeni 2FA i „Odjavi me sa svih uređaja" — sve ranije sesije tog naloga tada prestaju da važe.
 *  - `users.totp_secret` VARCHAR(255) na MySQL-u: šifrovan zapis (`enc:s1:…`) je duži od 64 znaka.
 * MySQL nema `ADD COLUMN IF NOT EXISTS` (MariaDB ima), pa se kolona dodaje uslovno preko information_schema i
 * pripremljene naredbe — ponovljeno pokretanje posle prekida prolazi bez greške.
 */

return [
    'mysql' => [
        "SET @rp_sql = (SELECT IF(COUNT(*) = 0, 'ALTER TABLE users ADD COLUMN session_version INT NOT NULL DEFAULT 1', 'DO 0')"
            . " FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'users' AND column_name = 'session_version')",
        'PREPARE rp_stmt FROM @rp_sql',
        'EXECUTE rp_stmt',
        'DEALLOCATE PREPARE rp_stmt',
        'ALTER TABLE users MODIFY totp_secret VARCHAR(255) NULL',
    ],
    'sqlite' => [
        'ALTER TABLE users ADD COLUMN session_version INTEGER NOT NULL DEFAULT 1',
    ],
];

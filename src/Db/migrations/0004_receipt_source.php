<?php
declare(strict_types=1);

/*
 * Izvor računa: `receipts.source` — 'qr' (račun iz QR koda; podrazumevano, važi i za sve ranije primljene račune) ili
 * 'pfr' (račun unet po PFR broju, link je https://suf.purs.gov.rs/verify).
 * MySQL nema `ADD COLUMN IF NOT EXISTS` (MariaDB ima), pa se kolona dodaje uslovno preko information_schema i
 * pripremljene naredbe — ponovljeno pokretanje posle prekida prolazi bez greške. Na SQLite-u (TEXT afinitet, isto kao
 * VARCHAR) migracija ide u transakciji.
 */

return [
    'mysql' => [
        "SET @rp_sql = (SELECT IF(COUNT(*) = 0, 'ALTER TABLE receipts ADD COLUMN source VARCHAR(8) NOT NULL DEFAULT ''qr''', 'DO 0')"
            . " FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'receipts' AND column_name = 'source')",
        'PREPARE rp_stmt FROM @rp_sql',
        'EXECUTE rp_stmt',
        'DEALLOCATE PREPARE rp_stmt',
    ],
    'sqlite' => [
        "ALTER TABLE receipts ADD COLUMN source TEXT NOT NULL DEFAULT 'qr'",
    ],
];

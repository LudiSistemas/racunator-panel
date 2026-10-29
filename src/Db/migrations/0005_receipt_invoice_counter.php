<?php
declare(strict_types=1);

/*
 * Podaci za proveru računa na zvaničnoj stranici https://suf.purs.gov.rs/verify (uz PFR broj, vreme i iznos):
 * `receipts.invoice_counter` — brojač računa `transactionTypeCounter/totalCounter` (npr. `123/456`) i
 * `receipts.invoice_counter_extension` — nastavak brojača (npr. `ПП`). Oba su opciona (NULL za ranije primljene račune
 * i za aplikacije koje ih ne šalju).
 * Kao u 0004: MySQL nema `ADD COLUMN IF NOT EXISTS` (MariaDB ima), pa se svaka kolona dodaje uslovno preko
 * information_schema i pripremljene naredbe — ponovljeno pokretanje posle prekida prolazi bez greške. Na SQLite-u
 * (TEXT afinitet, isto kao VARCHAR) migracija ide u transakciji.
 */

$addColumn = static fn (string $column, string $definition): array => [
    "SET @rp_sql = (SELECT IF(COUNT(*) = 0, 'ALTER TABLE receipts ADD COLUMN {$column} {$definition}', 'DO 0')"
        . " FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'receipts' AND column_name = '{$column}')",
    'PREPARE rp_stmt FROM @rp_sql',
    'EXECUTE rp_stmt',
    'DEALLOCATE PREPARE rp_stmt',
];

return [
    'mysql' => array_merge(
        $addColumn('invoice_counter', 'VARCHAR(32) NULL'),
        $addColumn('invoice_counter_extension', 'VARCHAR(8) NULL'),
    ),
    'sqlite' => [
        'ALTER TABLE receipts ADD COLUMN invoice_counter TEXT NULL',
        'ALTER TABLE receipts ADD COLUMN invoice_counter_extension TEXT NULL',
    ],
];

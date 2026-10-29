<?php
declare(strict_types=1);

namespace RacunatorPanel\Tests\Panel;

use PHPUnit\Framework\TestCase;
use RacunatorPanel\Controllers\Panel\Format;

final class FormatTest extends TestCase
{
    public function testIznos(): void
    {
        self::assertSame('1.234,56', Format::amount(123456));
        self::assertSame('1234,56', Format::amount(123456, false));
        self::assertSame('0,05', Format::amount(5));
        self::assertSame('-12,30', Format::amount(-1230));
        self::assertSame('1.000.000,00', Format::amount(100000000));
    }

    public function testDatumiIMeseci(): void
    {
        self::assertSame('24.10.2026. 21:42', Format::sdcDateTime('2026-10-24T21:42:31'));
        self::assertSame('oktobar 2026', Format::month('2026-10'));
        self::assertTrue(Format::isMonth('2026-01'));
        self::assertFalse(Format::isMonth('2026-13'));
        self::assertFalse(Format::isMonth('2026-1'));
        // UTC → Beograd (letnje vreme +2).
        self::assertSame('01.07.2026. 12:00', Format::utc('2026-07-01 10:00:00'));
        self::assertSame('—', Format::utc(null));
    }

    public function testVrsteISufLink(): void
    {
        self::assertSame('Promet – prodaja', Format::receiptKind('Normal', 'Sale'));
        self::assertSame('Avans – refundacija', Format::receiptKind('Advance', 'Refund'));
        self::assertTrue(Format::isSufUrl('https://suf.purs.gov.rs/v/?vl=abc'));
        self::assertFalse(Format::isSufUrl('https://suf.purs.gov.rs.zlo.rs/v'));
        self::assertFalse(Format::isSufUrl('http://suf.purs.gov.rs/v'));
    }

    public function testNaziviRadnjiUDnevniku(): void
    {
        self::assertSame('Instalacija', Format::auditAction('install'));
        self::assertSame('Ažuriranje pokrenuto', Format::auditAction('update.run'));
        self::assertSame('Ažuriranje nije uspelo', Format::auditAction('update.failed'));
        self::assertSame('Upozorenje pri ažuriranju', Format::auditAction('update.warning'));
        self::assertSame('Vraćena prethodna verzija', Format::auditAction('update.revert'));
        self::assertSame('Vraćanje nije uspelo', Format::auditAction('update.revert_failed'));
        self::assertSame('Isključen režim održavanja', Format::auditAction('update.maintenance_cleared'));
        self::assertSame('Preuzeta rezervna kopija', Format::auditAction('backup.download'));
        self::assertSame('nesto.nepoznato', Format::auditAction('nesto.nepoznato'));
    }
}

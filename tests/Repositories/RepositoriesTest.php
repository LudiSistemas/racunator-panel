<?php
declare(strict_types=1);

namespace RacunatorPanel\Tests\Repositories;

use PHPUnit\Framework\TestCase;
use RacunatorPanel\Auth\SecretCipher;
use RacunatorPanel\Models\User;
use RacunatorPanel\Repositories\PdoAuditLogRepository;
use RacunatorPanel\Repositories\PdoClientRepository;
use RacunatorPanel\Repositories\PdoReceiptRepository;
use RacunatorPanel\Repositories\PdoSettingsRepository;
use RacunatorPanel\Repositories\PdoUserRepository;
use RacunatorPanel\Services\KeyGenerator;
use RacunatorPanel\Tests\Support\TestDb;

final class RepositoriesTest extends TestCase
{
    private \PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = TestDb::pdo();
    }

    public function testKorisnickiNalozi(): void
    {
        $users = new PdoUserRepository($this->pdo);
        $admin = $users->create('  Admin ', 'hash1', User::ROLE_ADMIN, null);
        self::assertSame('admin', $admin->username);
        self::assertTrue($admin->isAdmin());
        self::assertTrue($admin->active);
        self::assertNull($admin->totpSecret);
        $staff = $users->create('pera', 'hash2', User::ROLE_STAFF, $admin->id);
        self::assertSame($admin->id, $staff->createdBy);

        self::assertSame($admin->id, $users->findByUsername('ADMIN')?->id);
        self::assertNull($users->findByUsername('nema'));
        self::assertSame(1, $users->countAdmins());
        self::assertCount(2, $users->all());

        $users->updatePassword($staff->id, 'novi');
        $users->updateTotpSecret($staff->id, 'JBSWY3DPEHPK3PXP');
        $users->setActive($staff->id, false);
        $users->touchLogin($staff->id);
        $s = $users->findById($staff->id);
        self::assertSame('novi', $s?->passwordHash);
        self::assertSame('JBSWY3DPEHPK3PXP', $s?->totpSecret);
        self::assertFalse($s?->active);
        self::assertNotNull($s?->lastLoginAt);

        $users->setActive($admin->id, false);
        self::assertSame(0, $users->countAdmins());
    }

    public function testVerzijaSesije(): void
    {
        $users = new PdoUserRepository($this->pdo);
        $u = $users->create('ana', 'h', User::ROLE_STAFF, null);
        self::assertSame(1, $u->sessionVersion);
        $users->bumpSessionVersion($u->id);
        $users->bumpSessionVersion($u->id);
        self::assertSame(3, $users->findById($u->id)?->sessionVersion);
        self::assertSame(3, $users->all()[0]->sessionVersion);
    }

    public function testTotpTajnaSeCuvaSifrovana(): void
    {
        $cipher = new SecretCipher(str_repeat('a', 64));
        $users = new PdoUserRepository($this->pdo, $cipher);
        $u = $users->create('ana', 'h', User::ROLE_STAFF, null);
        $users->updateTotpSecret($u->id, 'JBSWY3DPEHPK3PXP');
        $raw = (string) $this->pdo->query('SELECT totp_secret FROM users WHERE id = ' . $u->id)->fetchColumn();
        self::assertStringStartsWith('enc:', $raw);
        self::assertStringNotContainsString('JBSWY3DPEHPK3PXP', $raw);
        self::assertSame('JBSWY3DPEHPK3PXP', $users->findById($u->id)?->totpSecret);
        self::assertSame('JBSWY3DPEHPK3PXP', $users->findByUsername('ana')?->totpSecret);

        // Drugi app_key: tajna se ne dešifruje, ostaje šifrat (nijedan kod mu ne odgovara — 2FA se ne gasi tiho).
        $other = new PdoUserRepository($this->pdo, new SecretCipher(str_repeat('b', 64)));
        self::assertSame($raw, $other->findById($u->id)?->totpSecret);

        $users->updateTotpSecret($u->id, null);
        self::assertNull($this->pdo->query('SELECT totp_secret FROM users WHERE id = ' . $u->id)->fetchColumn());
        self::assertNull($users->findById($u->id)?->totpSecret);
    }

    public function testStaraTajnaUCistomTekstuSePrebacujeUSifrovanuPriPrijavi(): void
    {
        $plain = new PdoUserRepository($this->pdo);
        $u = $plain->create('ana', 'h', User::ROLE_STAFF, null);
        $plain->updateTotpSecret($u->id, 'JBSWY3DPEHPK3PXP');
        self::assertSame('JBSWY3DPEHPK3PXP', $this->pdo->query('SELECT totp_secret FROM users')->fetchColumn());

        $users = new PdoUserRepository($this->pdo, new SecretCipher(str_repeat('a', 64)));
        self::assertSame('JBSWY3DPEHPK3PXP', $users->findById($u->id)?->totpSecret, 'stari zapis se čita kao čist tekst');
        $users->touchLogin($u->id);
        self::assertStringStartsWith('enc:', (string) $this->pdo->query('SELECT totp_secret FROM users')->fetchColumn());
        self::assertSame('JBSWY3DPEHPK3PXP', $users->findById($u->id)?->totpSecret);
    }

    public function testAtomicanBrojac(): void
    {
        $s = new PdoSettingsRepository($this->pdo);
        self::assertTrue($s->advanceCounter('totp_last_step.1', 100));
        self::assertSame('100', $s->get('totp_last_step.1'));
        self::assertFalse($s->advanceCounter('totp_last_step.1', 100), 'isti korak drugi put');
        self::assertFalse($s->advanceCounter('totp_last_step.1', 99));
        self::assertTrue($s->advanceCounter('totp_last_step.1', 101));
        // Stara vrednost upisana kao običan tekst se poredi brojčano (ne kao string: '99' > '100').
        $s->set('totp_last_step.2', '99');
        self::assertTrue($s->advanceCounter('totp_last_step.2', 100));
        self::assertFalse($s->advanceCounter('totp_last_step.2', 99));
    }

    public function testDuplikatKorisnickogImena(): void
    {
        $users = new PdoUserRepository($this->pdo);
        $users->create('ana', 'h', User::ROLE_STAFF, null);
        $this->expectException(\PDOException::class);
        $users->create('ANA', 'h', User::ROLE_STAFF, null);
    }

    public function testNepoznataUloga(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new PdoUserRepository($this->pdo))->create('x', 'h', 'boss', null);
    }

    public function testKorisniciFirme(): void
    {
        $clients = new PdoClientRepository($this->pdo);
        $slug = KeyGenerator::slug();
        $key = KeyGenerator::apiKey();
        $c = $clients->create('Pera DOO', $slug, KeyGenerator::prefix($key), KeyGenerator::hash($key), '  ');
        self::assertNull($c->note);
        self::assertTrue($c->active);
        self::assertSame($c->id, $clients->findBySlug($slug)?->id);
        self::assertNull($clients->findBySlug('nepostojeci'));
        self::assertNull($clients->findBySlug(str_repeat('0', 32)));

        $key2 = KeyGenerator::apiKey();
        $clients->rotateKey($c->id, KeyGenerator::prefix($key2), KeyGenerator::hash($key2));
        $clients->update($c->id, 'Pera i sin DOO', 'napomena');
        $clients->setActive($c->id, false);
        $clients->touchSeen($c->id);
        $c2 = $clients->findById($c->id);
        self::assertSame('Pera i sin DOO', $c2?->name);
        self::assertSame('napomena', $c2?->note);
        self::assertTrue(KeyGenerator::verify($key2, (string) $c2?->apiKeyHash));
        self::assertFalse($c2?->active);
        self::assertNotNull($c2?->lastSeenAt);
        self::assertCount(1, $clients->all());
    }

    public function testRacuni(): void
    {
        $clients = new PdoClientRepository($this->pdo);
        $a = $clients->create('A', KeyGenerator::slug(), 'p', 'h', null);
        $b = $clients->create('B', KeyGenerator::slug(), 'p', 'h', null);
        $repo = new PdoReceiptRepository($this->pdo);

        self::assertTrue($repo->insertIfNew($a->id, self::receipt('AAAAAAAA-BBBBBBBB-1', '2026-10-24T21:42:31', 1000)));
        self::assertFalse($repo->insertIfNew($a->id, self::receipt('AAAAAAAA-BBBBBBBB-1', '2026-10-24T21:42:31', 1000)));
        self::assertTrue($repo->insertIfNew($b->id, self::receipt('AAAAAAAA-BBBBBBBB-1', '2026-10-24T21:42:31', 1000)));
        self::assertTrue($repo->insertIfNew($a->id, self::receipt('AAAAAAAA-BBBBBBBB-2', '2026-10-01T00:00:00', 500)));
        self::assertTrue($repo->insertIfNew($a->id, self::receipt('AAAAAAAA-BBBBBBBB-3', '2026-10-31T23:59:59', 1)));
        self::assertTrue($repo->insertIfNew($a->id, self::receipt('AAAAAAAA-BBBBBBBB-4', '2026-11-01T00:00:00', 7)));
        self::assertTrue($repo->insertIfNew($a->id, self::receipt('AAAAAAAA-BBBBBBBB-5', '2026-09-30T23:59:59', 9)));

        $oct = $repo->forClientMonth($a->id, '2026-10');
        self::assertSame(['AAAAAAAA-BBBBBBBB-2', 'AAAAAAAA-BBBBBBBB-1', 'AAAAAAAA-BBBBBBBB-3'], array_map(fn ($r) => $r->invoiceNumber, $oct));
        self::assertSame('ZORA MARKET DOO', $oct[0]->sellerName);
        self::assertSame(500, $oct[0]->totalAmountMinor);
        self::assertCount(1, $repo->forClientMonth($a->id, '2026-10', 1, 2));
        self::assertSame([], $repo->forClientMonth($a->id, '2026-13'));
        self::assertSame([], $repo->forClientMonth($a->id, 'x'));
        self::assertCount(1, $repo->forClientMonth($a->id, '2026-11'));

        $summary = $repo->monthlySummary($a->id);
        self::assertSame(['2026-09', '2026-10', '2026-11'], array_keys($summary));
        self::assertSame(['count' => 3, 'total' => 1501], $summary['2026-10']);
        self::assertSame([$a->id => 3, $b->id => 1], $repo->countsForMonth('2026-10'));
        self::assertSame([$a->id => 1], $repo->countsForMonth('2026-11'));

        $first = $oct[1];
        self::assertSame($first->invoiceNumber, $repo->findById($first->id)?->invoiceNumber);
        self::assertNull($repo->findById(9999));
    }

    public function testIzvorINadogradnjaPfrUQr(): void
    {
        $c = (new PdoClientRepository($this->pdo))->create('A', KeyGenerator::slug(), 'p', 'h', null);
        $repo = new PdoReceiptRepository($this->pdo);
        $pfr = ['source' => 'pfr', 'verificationUrl' => 'https://suf.purs.gov.rs/verify', 'rawJson' => '{"source":"pfr"}']
            + self::receipt('AAAAAAAA-BBBBBBBB-1', '2026-10-24T21:42:31', 1000);
        self::assertTrue($repo->insertIfNew($c->id, $pfr));
        self::assertFalse($repo->insertIfNew($c->id, $pfr), 'pfr → pfr je duplikat');
        $saved = $repo->forClientMonth($c->id, '2026-10')[0];
        self::assertSame('pfr', $saved->source);
        self::assertTrue($saved->isPfrEntry());
        $this->pdo->exec("UPDATE receipts SET received_at = '2026-10-24 20:00:00'");

        $qr = ['source' => 'qr', 'verificationUrl' => 'https://suf.purs.gov.rs/v/?vl=xyz', 'sellerName' => 'NOVI PRODAVAC',
            'buyerPib' => null, 'rawJson' => '{"source":"qr"}'] + self::receipt('AAAAAAAA-BBBBBBBB-1', '2026-10-24T21:42:31', 2000);
        self::assertTrue($repo->insertIfNew($c->id, $qr), 'pfr → qr sa linkom iz QR koda je nadogradnja');
        $up = $repo->findById($saved->id);
        self::assertNotNull($up);
        self::assertSame('qr', $up->source);
        self::assertFalse($up->isPfrEntry());
        self::assertSame('https://suf.purs.gov.rs/v/?vl=xyz', $up->verificationUrl);
        self::assertSame(2000, $up->totalAmountMinor);
        self::assertSame('NOVI PRODAVAC', $up->sellerName);
        self::assertNull($up->buyerPib);
        self::assertSame('2026-10-24 20:00:00', $up->receivedAt, 'vreme prijema ostaje prvobitno');
        self::assertSame('{"source":"qr"}', $this->pdo->query('SELECT raw_json FROM receipts')->fetchColumn());
        self::assertFalse($repo->insertIfNew($c->id, $qr), 'qr → qr je duplikat');
        self::assertFalse($repo->insertIfNew($c->id, $pfr), 'qr → pfr je duplikat');
        self::assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM receipts')->fetchColumn());

        // Bez polja `source` upis je 'qr'.
        self::assertTrue($repo->insertIfNew($c->id, self::receipt('AAAAAAAA-BBBBBBBB-2', '2026-10-25T10:00:00', 1)));
        self::assertSame('qr', $repo->forClientMonth($c->id, '2026-10')[1]->source);
    }

    public function testBrojacINastavakUpisCitanjeINadogradnja(): void
    {
        $c = (new PdoClientRepository($this->pdo))->create('A', KeyGenerator::slug(), 'p', 'h', null);
        $repo = new PdoReceiptRepository($this->pdo);
        $pfr = ['source' => 'pfr', 'verificationUrl' => 'https://suf.purs.gov.rs/verify'];
        $qr = ['source' => 'qr', 'verificationUrl' => 'https://suf.purs.gov.rs/v/?vl=xyz'];

        // Upis i čitanje; bez polja → null.
        self::assertTrue($repo->insertIfNew($c->id, ['invoiceCounter' => '123/456', 'invoiceCounterExtension' => 'ПП'] + $pfr
            + self::receipt('AAAAAAAA-BBBBBBBB-1', '2026-10-01T10:00:00', 1)));
        self::assertTrue($repo->insertIfNew($c->id, $pfr + self::receipt('AAAAAAAA-BBBBBBBB-2', '2026-10-02T10:00:00', 1)));
        [$first, $second] = $repo->forClientMonth($c->id, '2026-10');
        self::assertSame(['123/456', 'ПП'], [$first->invoiceCounter, $first->invoiceCounterExtension]);
        self::assertSame([null, null], [$second->invoiceCounter, $second->invoiceCounterExtension]);
        self::assertSame('ПП', $repo->findById($first->id)?->invoiceCounterExtension);

        // Nadogradnja pfr → qr bez brojača čuva sačuvani brojač i nastavak.
        self::assertTrue($repo->insertIfNew($c->id, $qr + self::receipt('AAAAAAAA-BBBBBBBB-1', '2026-10-01T10:00:00', 1)));
        $kept = $repo->findById($first->id);
        self::assertSame(['qr', '123/456', 'ПП'], [$kept?->source, $kept?->invoiceCounter, $kept?->invoiceCounterExtension]);

        // Nadogradnja pfr → qr sa brojačem ga zamenjuje (i popunjava kad ga ranije nije bilo).
        self::assertTrue($repo->insertIfNew($c->id, ['invoiceCounter' => '7/9', 'invoiceCounterExtension' => 'ПР'] + $qr
            + self::receipt('AAAAAAAA-BBBBBBBB-2', '2026-10-02T10:00:00', 1)));
        $filled = $repo->findById($second->id);
        self::assertSame(['qr', '7/9', 'ПР'], [$filled?->source, $filled?->invoiceCounter, $filled?->invoiceCounterExtension]);

        self::assertTrue($repo->insertIfNew($c->id, ['invoiceCounter' => '1/2', 'invoiceCounterExtension' => 'ПП'] + $pfr
            + self::receipt('AAAAAAAA-BBBBBBBB-3', '2026-10-03T10:00:00', 1)));
        self::assertTrue($repo->insertIfNew($c->id, ['invoiceCounter' => '3/4', 'invoiceCounterExtension' => 'КП'] + $qr
            + self::receipt('AAAAAAAA-BBBBBBBB-3', '2026-10-03T10:00:00', 1)));
        $replaced = $repo->forClientMonth($c->id, '2026-10')[2];
        self::assertSame(['3/4', 'КП'], [$replaced->invoiceCounter, $replaced->invoiceCounterExtension]);
        // Duplikat (qr → qr) ne menja brojač.
        self::assertFalse($repo->insertIfNew($c->id, ['invoiceCounter' => '5/6'] + $qr
            + self::receipt('AAAAAAAA-BBBBBBBB-3', '2026-10-03T10:00:00', 1)));
        self::assertSame('3/4', $repo->findById($replaced->id)?->invoiceCounter);
    }

    public function testDecembarPrelaziGodinu(): void
    {
        $c = (new PdoClientRepository($this->pdo))->create('A', KeyGenerator::slug(), 'p', 'h', null);
        $repo = new PdoReceiptRepository($this->pdo);
        $repo->insertIfNew($c->id, self::receipt('AAAAAAAA-BBBBBBBB-1', '2026-12-31T23:00:00', 1));
        $repo->insertIfNew($c->id, self::receipt('AAAAAAAA-BBBBBBBB-2', '2027-01-01T00:00:00', 1));
        self::assertCount(1, $repo->forClientMonth($c->id, '2026-12'));
    }

    public function testPodesavanja(): void
    {
        $s = new PdoSettingsRepository($this->pdo);
        self::assertNull($s->get('accountant_name'));
        self::assertSame('x', $s->get('accountant_name', 'x'));
        $s->set('accountant_name', 'Knjigovodstvo XY');
        $s->set('accountant_name', 'Knjigovodstvo XY');
        self::assertSame('Knjigovodstvo XY', $s->get('accountant_name'));
        $s->set('accountant_name', 'Drugo');
        self::assertSame('Drugo', $s->get('accountant_name'));
        $s->set('prazno', '');
        self::assertSame('', $s->get('prazno', 'd'));
        $s->delete('accountant_name');
        self::assertNull($s->get('accountant_name'));
    }

    public function testDnevnik(): void
    {
        $users = new PdoUserRepository($this->pdo);
        $u = $users->create('admin', 'h', User::ROLE_ADMIN, null);
        $log = new PdoAuditLogRepository($this->pdo);
        $log->log($u->id, 'login', 'user', $u->id, '1.2.3.4');
        $log->log(null, 'client.create', 'client', 5, '1.2.3.4', 'Pera DOO');
        $recent = $log->recent();
        self::assertCount(2, $recent);
        self::assertSame('client.create', $recent[0]['action']);
        self::assertNull($recent[0]['username']);
        self::assertSame('Pera DOO', $recent[0]['details']);
        self::assertSame('admin', $recent[1]['username']);
        self::assertSame($u->id, $recent[1]['user_id']);
        self::assertCount(1, $log->recent(1));
    }

    /** @return array<string,mixed> */
    private static function receipt(string $invoice, string $sdc, int $amount): array
    {
        return [
            'invoiceNumber' => $invoice,
            'verificationUrl' => 'https://suf.purs.gov.rs/v/?vl=abc',
            'sdcDateTime' => $sdc,
            'totalAmountMinor' => $amount,
            'sellerName' => 'ZORA MARKET DOO',
            'sellerPib' => '100000000',
            'buyerPib' => '101234567',
            'invoiceType' => 'Normal',
            'transactionType' => 'Sale',
        ];
    }
}

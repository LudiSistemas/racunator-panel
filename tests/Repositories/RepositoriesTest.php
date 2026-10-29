<?php
declare(strict_types=1);

namespace RacunatorPanel\Tests\Repositories;

use PHPUnit\Framework\TestCase;
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

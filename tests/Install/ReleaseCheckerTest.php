<?php
declare(strict_types=1);

namespace RacunatorPanel\Tests\Install;

use RacunatorPanel\Repositories\SettingsRepository;
use RacunatorPanel\Services\ReleaseChecker;
use RacunatorPanel\Services\ReleaseInfo;


final class ReleaseCheckerTest extends InstallTestCase
{
    private int $now = 1_800_000_000;

    private function release(string $tag = 'v1.2.0'): array
    {
        $v = ltrim($tag, 'v');
        $base = ReleaseChecker::DOWNLOAD_PREFIX . $tag . '/';
        return ['status' => 200, 'body' => json_encode([
            'tag_name' => $tag, 'name' => 'Računator panel ' . $v, 'body' => "## Izmene\n- <b>nova</b> stvar\n",
            'published_at' => '2026-10-20T10:00:00Z', 'html_url' => 'https://github.com/' . ReleaseChecker::REPO . '/releases/tag/' . $tag,
            'assets' => [
                ['name' => 'racunator-panel-' . $v . '.zip', 'browser_download_url' => $base . 'racunator-panel-' . $v . '.zip', 'size' => 123456],
                ['name' => 'racunator-panel-' . $v . '.zip.sig', 'browser_download_url' => $base . 'racunator-panel-' . $v . '.zip.sig', 'size' => 89],
                ['name' => 'SHA256SUMS', 'browser_download_url' => $base . 'SHA256SUMS', 'size' => 200],
                ['name' => 'racunator-panel-install.php', 'browser_download_url' => $base . 'racunator-panel-install.php', 'size' => 300000],
            ],
        ])];
    }

    private function checker(FakeHttpClient $http, MemorySettings $s, string $current = '1.1.0'): ReleaseChecker
    {
        return new ReleaseChecker($http, $s, $current, fn (): int => $this->now);
    }

    public function testCitaIzdanjeIKesiraDvanaestSati(): void
    {
        $http = new FakeHttpClient([ReleaseChecker::API => $this->release()]);
        $s = new MemorySettings();
        $c = $this->checker($http, $s);
        $r = $c->available();
        $this->assertInstanceOf(ReleaseInfo::class, $r);
        $this->assertSame('1.2.0', $r->version);
        $this->assertSame(123456, $r->zipSize);
        $this->assertStringEndsWith('/v1.2.0/racunator-panel-1.2.0.zip', (string) $r->zipUrl);
        $this->assertStringEndsWith('.zip.sig', (string) $r->sigUrl);
        $this->assertStringEndsWith('/SHA256SUMS', (string) $r->sumsUrl);
        $this->assertStringContainsString('<b>nova</b>', $r->body);
        $this->assertSame('racunator-panel/1.1.0', $http->calls[0]['headers']['User-Agent']);
        $this->assertSame((string) $this->now, $s->get('last_update_check'));

        $this->now += 3600;
        $this->assertSame('1.2.0', $c->latest()?->version);
        $this->assertCount(1, $http->calls, 'unutar 12 h nema novog poziva');
        $c->latest(true);
        $this->assertCount(2, $http->calls, '„Proveri sada" zaobilazi keš');
        $this->now += 30;
        $c->latest(true);
        $this->assertCount(2, $http->calls, 'najviše jednom u minutu');
        $this->now += 12 * 3600;
        $c->latest();
        $this->assertCount(3, $http->calls);
    }

    public function testNemaNovijeVerzije(): void
    {
        $c = $this->checker(new FakeHttpClient([ReleaseChecker::API => $this->release('v1.1.0')]), new MemorySettings());
        $this->assertNull($c->available());
        $this->assertSame('1.1.0', $c->latest()?->version);
    }

    public function testGreskeIBezIzdanja(): void
    {
        $s = new MemorySettings();
        $c = $this->checker(new FakeHttpClient([ReleaseChecker::API => ['status' => 404, 'body' => '{}']]), $s);
        $this->assertNull($c->latest());

        $s = new MemorySettings();
        $c = $this->checker(new FakeHttpClient([ReleaseChecker::API => ['status' => 403, 'body' => 'rate limit']]), $s);
        try {
            $c->latest();
            $this->fail('Očekivana greška');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('403', $e->getMessage());
        }
        $this->assertSame((string) $this->now, $s->get('last_update_check'), 'i neuspela provera se beleži');

        $c = $this->checker(new FakeHttpClient([ReleaseChecker::API => ['status' => 200, 'body' => '{"tag_name":"latest"}']]), new MemorySettings());
        $this->expectException(\RuntimeException::class);
        $c->latest();
    }

    public function testMreznaGreskaSeProsledjuje(): void
    {
        $c = $this->checker(new FakeHttpClient(), new MemorySettings());
        $this->expectException(\RuntimeException::class);
        $c->latest();
    }

    public function testVerzijeISume(): void
    {
        $this->assertSame('1.2.3', ReleaseChecker::versionFromTag('v1.2.3'));
        $this->assertSame('1.2.3-rc.1', ReleaseChecker::versionFromTag('1.2.3-rc.1'));
        $this->assertNull(ReleaseChecker::versionFromTag('v1.2'));
        $this->assertNull(ReleaseChecker::versionFromTag('../1.2.3'));
        $this->assertTrue(ReleaseChecker::isNewer('1.10.0', '1.9.9'));
        $this->assertFalse(ReleaseChecker::isNewer('1.0.0', '1.0.0'));
        $sums = str_repeat('a', 64) . "  racunator-panel-1.2.0.zip\n" . str_repeat('b', 64) . " *racunator-panel-install.php\n";
        $this->assertSame(str_repeat('a', 64), ReleaseChecker::sumFor($sums, 'racunator-panel-1.2.0.zip'));
        $this->assertSame(str_repeat('b', 64), ReleaseChecker::sumFor($sums, 'racunator-panel-install.php'));
        $this->assertNull(ReleaseChecker::sumFor($sums, 'drugo.zip'));
    }

    public function testPotpisEd25519(): void
    {
        if (!function_exists('sodium_crypto_sign_keypair')) {
            $this->markTestSkipped('nema sodium');
        }
        $kp = sodium_crypto_sign_keypair();
        $pk = base64_encode(sodium_crypto_sign_publickey($kp));
        $sig = base64_encode(sodium_crypto_sign_detached('paket', sodium_crypto_sign_secretkey($kp)));
        $this->assertTrue(ReleaseChecker::verifySignature('paket', $sig . "\n", $pk));
        $this->assertFalse(ReleaseChecker::verifySignature('paket2', $sig, $pk));
        $this->assertFalse(ReleaseChecker::verifySignature('paket', 'nije-base64!', $pk));
        $this->assertNull(ReleaseChecker::verifySignature('paket', $sig, 'kratak'));
        $this->assertFalse(ReleaseChecker::publicKeyConfigured(), 'placeholder javnog ključa');
    }
}

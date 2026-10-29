<?php
declare(strict_types=1);

namespace RacunatorPanel\Tests\Panel;

use PHPUnit\Framework\TestCase;
use RacunatorPanel\App;
use RacunatorPanel\Auth\ArraySession;
use RacunatorPanel\Auth\PasswordHasher;
use RacunatorPanel\Kernel\Config;
use RacunatorPanel\Kernel\Request;
use RacunatorPanel\Kernel\Response;
use RacunatorPanel\Tests\Support\TestDb;

/** Ceo tok kroz pravu aplikaciju jezgra (SQLite u memoriji, SessionGuard, sesija u memoriji). */
final class AppIntegrationTest extends TestCase
{
    private const PASSWORD = 'integracija-lozinka-1';
    private App $app;
    private ArraySession $session;
    private string $storage;
    private \PDO $pdo;
    private Config $config;

    protected function setUp(): void
    {
        $this->storage = sys_get_temp_dir() . '/rp-panel-' . bin2hex(random_bytes(4));
        mkdir($this->storage);
        $this->session = new ArraySession();
        $this->config = new Config(['public_url' => 'https://knjig.test/panel', 'storage' => $this->storage, 'app_key' => str_repeat('b', 64)]);
        $this->pdo = TestDb::pdo();
        $this->app = App::create(dirname(__DIR__, 2), $this->config, $this->pdo, $this->session);
        $this->app->users->create('admin', (new PasswordHasher())->hash(self::PASSWORD), 'admin', null);
        $this->app->settings->set('accountant_name', 'Knjigovodstvo XY');
    }

    protected function tearDown(): void
    {
        @rmdir($this->storage);
    }

    private function request(string $method, string $path, array $form = [], array $headers = [], array $query = [], ?App $app = null): Response
    {
        $app ??= $this->app;
        if ($method === 'POST' && !isset($form['_token'])) {
            $form['_token'] = $app->guard->csrfToken();
        }
        return $app->handle(new Request($method, $path, $query, $form, null, $headers, [], '192.0.2.10', true));
    }

    /** Nova „instanca zahteva" nad istom sesijom (kao sledeći HTTP zahtev istog pregledača). */
    private function next(ArraySession $session): App
    {
        return App::create(dirname(__DIR__, 2), $this->config, $this->pdo, $session);
    }

    public function testDvaKorakaIOdjavaDrugihSesija(): void
    {
        $admin = $this->app->users->findByUsername('admin');
        $secret = \RacunatorPanel\Auth\Totp::generateSecret();
        $this->app->users->updateTotpSecret($admin->id, $secret);
        self::assertStringStartsWith('enc:', (string) $this->pdo->query('SELECT totp_secret FROM users')->fetchColumn(),
            'TOTP tajna je u bazi šifrovana (app_key)');

        // Pregledač A: lozinka → korak za kod → kod.
        $step1 = $this->request('POST', '/prijava', ['korisnicko_ime' => 'admin', 'lozinka' => self::PASSWORD]);
        self::assertSame(200, $step1->status);
        self::assertStringContainsString('name="korak" value="kod"', $step1->body);
        $a = $this->next($this->session);
        $ok = $this->request('POST', '/prijava', ['korak' => 'kod', 'kod' => \RacunatorPanel\Auth\Totp::code($secret)], app: $a);
        self::assertSame('/panel/', $ok->headers['Location'] ?? null);

        // Pregledač B: isti nalog (sledeći kod, jer je tekući iskorišćen).
        $sessionB = new ArraySession();
        $b = $this->next($sessionB);
        $this->request('POST', '/prijava', ['korisnicko_ime' => 'admin', 'lozinka' => self::PASSWORD], app: $b);
        $b = $this->next($sessionB);
        $okB = $this->request('POST', '/prijava', ['korak' => 'kod', 'kod' => \RacunatorPanel\Auth\Totp::code($secret, time() + 30)], app: $b);
        self::assertSame('/panel/', $okB->headers['Location'] ?? null);
        self::assertSame(200, $this->request('GET', '/', app: $this->next($sessionB))->status);

        // A: „Odjavi me sa svih uređaja" → A ostaje prijavljen, B je odjavljen.
        $a = $this->next($this->session);
        self::assertSame(303, $this->request('POST', '/podesavanja/odjava-svuda', app: $a)->status);
        self::assertSame(200, $this->request('GET', '/', app: $this->next($this->session))->status);
        self::assertSame('/panel/prijava', $this->request('GET', '/', app: $this->next($sessionB))->headers['Location'] ?? null);
        self::assertContains('user.sessions_revoked', array_column($this->app->audit->recent(20), 'action'));
    }

    public function testPrijavaKreiranjeKorisnikaIUparivanje(): void
    {
        $login = $this->request('GET', '/prijava');
        self::assertSame(200, $login->status);
        self::assertArrayHasKey('Content-Security-Policy', $login->headers);
        self::assertStringNotContainsString('<script>', $login->body); // nema inline skripti (CSP)
        self::assertStringNotContainsString('onclick', $login->body);

        $this->assertSame('/panel/prijava', $this->request('GET', '/')->headers['Location'] ?? null);

        $ok = $this->request('POST', '/prijava', ['korisnicko_ime' => 'admin', 'lozinka' => self::PASSWORD]);
        self::assertSame(303, $ok->status);
        self::assertSame('/panel/', $ok->headers['Location']);
        self::assertSame(200, $this->request('GET', '/')->status);

        $form = $this->request('GET', '/korisnici/novi');
        preg_match('/name="_nonce" value="([^"]+)"/', $form->body, $m);
        $created = $this->request('POST', '/korisnici/novi', ['ime' => 'Pera DOO', '_nonce' => $m[1] ?? '']);
        self::assertSame(200, $created->status, substr($created->body, 0, 500));
        preg_match('/data-qr="([^"]+)"/', $created->body, $q);
        $payload = json_decode(html_entity_decode($q[1] ?? '', ENT_QUOTES), true);
        self::assertIsArray($payload);
        self::assertSame('Knjigovodstvo XY', $payload['accountant']);

        // Ključ iz QR koda radi na API-ju za aplikaciju (jezgro).
        $path = (string) parse_url($payload['endpoint'], PHP_URL_PATH);
        $path = substr($path, strlen('/panel'));
        $ping = $this->request('GET', $path . '/ping', [], ['authorization' => 'Bearer ' . $payload['key'], 'x-racunator-api' => '1']);
        self::assertSame(200, $ping->status, $ping->body);
        self::assertSame('Pera DOO', json_decode($ping->body, true)['client']);

        $actions = array_column($this->app->audit->recent(10), 'action');
        self::assertContains('login', $actions);
        self::assertContains('client.create', $actions);

        $logout = $this->request('POST', '/odjava');
        self::assertSame('/panel/prijava', $logout->headers['Location']);
        self::assertSame('/panel/prijava', $this->request('GET', '/korisnici')->headers['Location'] ?? null);
    }
}

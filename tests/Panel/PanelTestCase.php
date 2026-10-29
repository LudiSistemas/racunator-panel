<?php
declare(strict_types=1);

namespace RacunatorPanel\Tests\Panel;

use PHPUnit\Framework\TestCase;
use RacunatorPanel\App;
use RacunatorPanel\Auth\ArraySession;
use RacunatorPanel\Auth\Authorization;
use RacunatorPanel\Auth\PasswordHasher;
use RacunatorPanel\Kernel\Config;
use RacunatorPanel\Kernel\Request;
use RacunatorPanel\Kernel\Response;
use RacunatorPanel\Kernel\Router;
use RacunatorPanel\Kernel\View;
use RacunatorPanel\Models\User;
use RacunatorPanel\Routes\PanelRoutes;
use RacunatorPanel\Tests\Panel\Fake\FakeGuard;
use RacunatorPanel\Tests\Panel\Fake\InMemoryAuditLogRepository;
use RacunatorPanel\Tests\Panel\Fake\InMemoryClientRepository;
use RacunatorPanel\Tests\Panel\Fake\InMemoryReceiptRepository;
use RacunatorPanel\Tests\Panel\Fake\InMemorySettingsRepository;
use RacunatorPanel\Tests\Panel\Fake\InMemoryUserRepository;

/**
 * Osnova testova panela: prava `App` klasa (bez konstruktora — svojstva se postavljaju kroz refleksiju) sa repozitorijumima
 * u memoriji, lažnim Guard-om, sesijom u memoriji i pravim Router-om i View-om; rute iz `PanelRoutes::register`.
 */
abstract class PanelTestCase extends TestCase
{
    protected const PUBLIC_URL = 'https://knjig.test/panel';
    protected const PASSWORD = 'dugacka-lozinka-123';

    protected App $app;
    protected InMemoryUserRepository $users;
    protected InMemoryClientRepository $clients;
    protected InMemoryReceiptRepository $receipts;
    protected InMemorySettingsRepository $settings;
    protected InMemoryAuditLogRepository $audit;
    protected FakeGuard $guard;
    protected ArraySession $session;
    private static ?string $passwordHash = null;

    protected function setUp(): void
    {
        $this->users = new InMemoryUserRepository();
        $this->clients = new InMemoryClientRepository();
        $this->receipts = new InMemoryReceiptRepository();
        $this->settings = new InMemorySettingsRepository();
        $this->audit = new InMemoryAuditLogRepository();
        $this->guard = new FakeGuard($this->users);
        $this->session = new ArraySession();
        $this->settings->set('accountant_name', 'Knjigovodstvo Šušnjar');
        // Instaliran panel sa prihvaćenim važećim uslovima (gate za administratora testira TermsTest).
        $this->settings->set(\RacunatorPanel\Services\Terms::SETTING_VERSION, (string) \RacunatorPanel\Services\Terms::VERSION);

        // App::url koristi osnovnu putanju koju inače postavlja App::create.
        Authorization::setBasePath('/panel');
        $root = dirname(__DIR__, 2);
        $this->app = self::makeApp([
            'root' => $root,
            'version' => '0.1.0',
            'config' => new Config(['public_url' => self::PUBLIC_URL]),
            'db' => new \PDO('sqlite::memory:'),
            'users' => $this->users,
            'clients' => $this->clients,
            'receipts' => $this->receipts,
            'settings' => $this->settings,
            'audit' => $this->audit,
            'session' => $this->session,
            'guard' => $this->guard,
            'view' => new View($root . '/src/Views'),
            'router' => new Router(),
        ]);
        PanelRoutes::register($this->app);
    }

    /** @param array<string,mixed> $props */
    private static function makeApp(array $props): App
    {
        $ref = new \ReflectionClass(App::class);
        $app = $ref->newInstanceWithoutConstructor();
        foreach ($props as $name => $value) {
            if ($ref->hasProperty($name)) {
                $ref->getProperty($name)->setValue($app, $value);
            }
        }
        return $app;
    }

    protected function makeUser(string $username, string $role = User::ROLE_ADMIN): User
    {
        // Heš lozinke se pravi jednom (Argon2id je namerno spor).
        self::$passwordHash ??= (new PasswordHasher())->hash(self::PASSWORD);
        return $this->users->create($username, self::$passwordHash, $role, null);
    }

    protected function loginAs(string $role = User::ROLE_ADMIN, string $username = 'admin'): User
    {
        $user = $this->users->findByUsername($username) ?? $this->makeUser($username, $role);
        $this->guard->userId = $user->id;
        return $user;
    }

    /** @param array<string,string> $query */
    protected function get(string $path, array $query = []): Response
    {
        return $this->app->router->dispatch(new Request('GET', $path, $query, [], null, [], [], '10.0.0.1'));
    }

    /** @param array<string,mixed> $form */
    protected function post(string $path, array $form = [], bool $withToken = true): Response
    {
        if ($withToken) {
            $form += ['_token' => FakeGuard::TOKEN];
        }
        return $this->app->router->dispatch(new Request('POST', $path, [], $form, null, [], [], '10.0.0.1'));
    }

    protected function assertRedirect(Response $response, string $location): void
    {
        self::assertSame(303, $response->status, 'Očekivano preusmerenje, telo: ' . substr($response->body, 0, 300));
        self::assertSame($location, $response->headers['Location'] ?? null);
    }

    /** Vrednost skrivenog polja iz HTML-a (prvo pojavljivanje). */
    protected static function hiddenValue(string $html, string $name): string
    {
        self::assertMatchesRegularExpression('/name="' . preg_quote($name, '/') . '" value="([^"]*)"/', $html);
        preg_match('/name="' . preg_quote($name, '/') . '" value="([^"]*)"/', $html, $m);
        return html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    /** Sadržaj atributa data-qr (JSON ili otpauth URI). */
    protected static function qrData(string $html): string
    {
        self::assertMatchesRegularExpression('/data-qr="([^"]*)"/', $html);
        preg_match('/data-qr="([^"]*)"/', $html, $m);
        return html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    /** @param array<string,mixed> $overrides */
    protected function addReceipt(int $clientId, string $invoiceNumber, string $sdc, int $amount, array $overrides = []): void
    {
        $this->receipts->insertIfNew($clientId, $overrides + [
            'invoiceNumber' => $invoiceNumber,
            'verificationUrl' => 'https://suf.purs.gov.rs/v/?vl=' . rawurlencode(base64_encode($invoiceNumber)),
            'sdcDateTime' => $sdc,
            'totalAmountMinor' => $amount,
            'sellerName' => 'ZORA MARKET DOO',
            'sellerPib' => '100000000',
            'buyerPib' => '101234567',
            'invoiceType' => 'Normal',
            'transactionType' => 'Sale',
        ]);
    }
}

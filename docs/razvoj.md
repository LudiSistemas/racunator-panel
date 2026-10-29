# Razvoj

## Zahtevi
- PHP 8.1+ sa ekstenzijama `pdo_sqlite` (i `pdo_mysql` za MySQL), `mbstring`, `openssl`, `json`.
- Composer — samo za razvojne zavisnosti (PHPUnit). Panel u radu ne koristi `vendor/`; klase učitava `src/autoload.php`.

### Bez lokalnog PHP-a (Docker)
U `tools/dev/` su omotači koji pokreću PHP 8.1 CLI u Docker slici `racunator-panel-dev` (repo je montiran u `/app`;
ekstenzije pdo_mysql, pdo_sqlite, zip, sodium, openssl, mbstring, curl):
```sh
docker build -t racunator-panel-dev tools/dev     # jednom
tools/dev/composer install
tools/dev/composer test                            # ili: tools/dev/php vendor/bin/phpunit
tools/dev/composer lint
tools/dev/php -l src/App.php
```
Za ugrađeni server iz kontejnera port mora biti objavljen, npr.:
```sh
docker run --rm -it -v "$PWD":/app -w /app -p 8000:8000 racunator-panel-dev \
  php -S 0.0.0.0:8000 -t public public/index.php
```

## Pokretanje lokalno (SQLite)
1. Napravite `config.php` u korenu repoa (u `.gitignore` je, ne komituje se):
   ```php
   <?php
   return [
       'db' => ['driver' => 'sqlite', 'dsn' => 'sqlite:' . __DIR__ . '/storage/panel.sqlite', 'user' => '', 'pass' => ''],
       'public_url' => 'http://localhost:8000',
       'app_key' => '0000000000000000000000000000000000000000000000000000000000000000',
       'storage' => __DIR__ . '/storage',
   ];
   ```
   (Unutar Docker kontejnera putanje su `/app/…`; `__DIR__` to rešava sam.)
2. Migracije i prvi nalog (dok instaler nije pri ruci):
   ```sh
   php -r 'require "src/autoload.php";
     $c = RacunatorPanel\Kernel\Config::load("config.php");
     $pdo = RacunatorPanel\Db\Connection::open($c->get("db"));
     print_r((new RacunatorPanel\Db\Migrator($pdo))->migrate());
     (new RacunatorPanel\Repositories\PdoUserRepository($pdo))->create("admin",
       (new RacunatorPanel\Auth\PasswordHasher())->hash("lozinka-za-razvoj"), "admin", null);
     (new RacunatorPanel\Repositories\PdoSettingsRepository($pdo))->set("accountant_name", "Knjigovodstvo XY");'
   ```
   Nove migracije na već postojećoj bazi (posle izmene koda): `php install/cli.php migracije`.
3. Server: `php -S localhost:8000 -t public public/index.php` → <http://localhost:8000/prijava>, provera: `/health`.

Za MySQL: `'db' => ['driver' => 'mysql', 'dsn' => 'mysql:host=127.0.0.1;dbname=panel;charset=utf8mb4', 'user' => '…', 'pass' => '…']`.

Brzi test API-ja (slug i ključ iz panela, ključ se vidi samo pri kreiranju):
```sh
curl -s localhost:8000/u/<slug>/ping -H "Authorization: Bearer rk_…"
```

## Testovi
- `composer test` — PHPUnit 10, sve na SQLite bazi u memoriji (`tests/Support/TestDb.php`); bez mreže i bez MySQL-a.
- `composer lint` — `php -l` nad `src`, `public`, `install`, `tools`.
- Sesija u testovima: `RacunatorPanel\Auth\ArraySession`; cela aplikacija bez `config.php`:
  `App::create($root, new Config([...]), TestDb::pdo(), new ArraySession())`, pa `$app->handle(new Request('GET', '/health'))`.

| Folder | Šta pokriva |
|---|---|
| `tests/Kernel` | ruter (parametri, 404/405, middleware), `Request` (Bearer, JSON) |
| `tests/Db` | migracije: primena, idempotentnost, povraćaj neuspele migracije, strani ključevi |
| `tests/Repositories` | PDO repozitorijumi |
| `tests/Auth` | lozinke, CSRF, TOTP (RFC 6238 vektori), ograničenje prijava i API-ja, tok prijave sa 2FA |
| `tests/Services` | slug/ključ, QR sadržaj |
| `tests/Api` | `/u/{slug}/ping` i `/receipts` (401, validacija, duplikati, 200/413, 429), `/health`, zaglavlja, održavanje |

## Struktura
```
public/            index.php (front controller), .htaccess, assets/
src/App.php        sastavljanje aplikacije (config, baza, repozitorijumi, guard, ruter, middleware)
src/version.php    verzija aplikacije
src/Kernel/        Config, Request, Response, Router, View (fiksni ugovori)
src/Db/            Connection (PDO mysql/sqlite), Migrator, migrations/NNNN_naziv.php
src/Auth/          Guard (ugovor), SessionGuard, NativeSession/ArraySession, Csrf, PasswordHasher, Totp,
                   RateLimiter (prijava), ApiRateLimiter (API), Authorization
src/Models/        User, Client, Receipt (fiksni ugovori)
src/Repositories/  interfejsi (fiksni ugovori) + Pdo* implementacije
src/Services/      KeyGenerator, QrPayload, …
src/Controllers/   ClientApiController (API za aplikaciju), Panel/…
src/Routes/        PanelRoutes, UpdateRoutes (registracija ruta modula)
src/Views/         PHP šabloni
install/, tools/   instaler, izrada izdanja, razvojni alati
storage/           baza (SQLite), logovi, sesije, rezervne kopije — nikad dostupno preko weba
```

## Dogovori u kodu
- `declare(strict_types=1)`, namespace `RacunatorPanel\`, komentari i poruke na srpskom (latinica).
- SQL samo kroz prepared statements i bez dijalektnih specifičnosti (radi na MySQL-u i SQLite-u). Duplikat se prepoznaje
  sa `Connection::isUniqueViolation()`.
- Nova migracija: novi fajl `src/Db/migrations/NNNN_naziv.php` koji vraća `['mysql' => [...], 'sqlite' => [...]]`;
  postojeće migracije se ne menjaju. Za MySQL koristiti `IF NOT EXISTS` gde je moguće (DDL ne ide u transakciju).
- Vremena `*_at` su UTC (`YYYY-MM-DD HH:MM:SS`); `receipts.sdc_datetime` je lokalno vreme PFR-a (`YYYY-MM-DDTHH:MM:SS`).
- Ključevi i lozinke se nikad ne upisuju u log ni u dnevnik radnji.
- Rute modula: statička metoda `register(App $app)` u `src/Routes/*Routes.php`; `App` je poziva ako klasa postoji.
- Bezbednosna zaglavlja (CSP bez inline skripti i stilova, X-Frame-Options, nosniff, Referrer-Policy, HSTS na HTTPS-u)
  dodaje `App`; kontroler može da postavi sopstvenu vrednost zaglavlja i ona ima prednost.

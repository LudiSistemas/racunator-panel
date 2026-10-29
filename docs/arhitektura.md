# Arhitektura i dogovori

## Moduli i vlasništvo (faza A)
| Deo | Direktorijum | Opis |
|---|---|---|
| Jezgro | `src/Kernel`, `src/Db`, `src/Auth`, `src/Repositories` (PDO implementacije), `src/Services`, `src/Controllers/ClientApi*`, `public/index.php`, `public/.htaccess` | ruter, konfiguracija, baza i migracije, prijava/sesije/CSRF/TOTP/rate limit, repozitorijumi, generisanje ključeva, API za aplikaciju |
| Panel | `src/Controllers/Panel*`, `src/Views`, `public/assets` | ekrani knjigovođe (prijava, korisnici, QR, računi po mesecu, nalozi, podešavanja, dnevnik) |
| Instalacija i ažuriranje | `install/`, `tools/`, `.github/workflows` | čarobnjak, samoraspakujući install fajl, ažuriranje sa rezervnom kopijom i povratkom, CI i izdanja |

Fiksni ugovori (ne menjati bez dogovora): `src/Kernel/*`, `src/Models/*`, `src/Repositories/*Repository.php` (interfejsi), `src/Auth/Guard.php`.

## Dogovori
- PHP ≥ 8.1, `declare(strict_types=1)`, namespace `RacunatorPanel\`, PSR-4 preko `src/autoload.php` (bez Composer-a u radu).
- Bez frameworka i bez runtime zavisnosti. Jedina JS biblioteka: lokalna, pinovana verzija za crtanje QR koda.
- Šabloni su PHP fajlovi u `src/Views` (`View::render`), sav izlaz kroz `e()`.
- Sav tekst, komentari i dokumentacija na srpskom (latinica). Commitovi na srpskom.
- Konfiguracija: `config.php` u korenu instalacije (van `public/`), oblik u `src/Kernel/Config.php`; opcione stavke `trusted_proxies` (lista CIDR) i `force_https` (bool).
- `storage/` (van `public/`): `backups/`, `tmp/`, `logs/`, `panel.sqlite` (ako je SQLite).
- Baza: `src/Db/Connection.php` (PDO, `mysql`/`sqlite`), migracije `src/Db/migrations/NNNN_naziv.php` koje vraćaju SQL po drajveru;
  `Migrator::migrate()` je idempotentan i koristi tabelu `schema_version`.
- Vremena u bazi kao `DATETIME`/tekst ISO 8601, UTC za `received_at`, lokalno PFR vreme za `sdc_datetime`.

## Rute
| Metoda | Putanja | Ko | Opis |
|---|---|---|---|
| GET | `/` | Panel | kontrolna tabla (ili preusmerenje na prijavu) |
| GET/POST | `/prijava`, POST `/odjava` | Panel | prijava (+ TOTP), odjava |
| GET/POST | `/korisnici`, `/korisnici/novi`, `/korisnici/{id}`, POST `/korisnici/{id}/novi-kljuc`, POST `/korisnici/{id}/deaktiviraj` | Panel | korisnici i QR |
| GET | `/korisnici/{id}/racuni?mesec=YYYY-MM`, `/korisnici/{id}/racuni.csv?mesec=` | Panel | računi po mesecu, CSV |
| GET/POST | `/nalozi`, `/nalozi/novi`, POST `/nalozi/{id}/deaktiviraj`, `/aktiviraj`, `/lozinka`, `/resetuj-2fa` | Panel (admin) | zaposleni |
| GET/POST | `/podesavanja`, `/podesavanja/lozinka`, `/podesavanja/2fa`, `/podesavanja/api`, POST `/podesavanja/odjava-svuda` | Panel | podešavanja naloga i instalacije, API knjigovođe, odjava sa svih uređaja |
| GET | `/dnevnik` | Panel | dnevnik radnji |
| GET/POST | `/azuriranje`, POST `/azuriranje/pokreni`, POST `/azuriranje/vrati` | Instalacija (UI u panelu) | provera, changelog, ažuriranje, povratak |
| GET | `/health` | Jezgro | `{"ok":true}`; verzija samo prijavljenom adminu ili uz zaglavlje `App::HEALTH_TOKEN_HEADER` sa `App::healthToken(app_key)` (samoprovera ažuriranja) |
| GET | `/u/{slug}/ping` | Jezgro (API za app) | Bearer ključ; vraća `{"ok":true,"client":"Pera DOO","accountant":"Knjigovodstvo XY","api":1}` |
| POST | `/u/{slug}/receipts` | Jezgro (API za app) | Bearer ključ; telo `{"receipts":[…]}` |
| GET | `/api/{slug}/v1/ping`, `/clients`, `/clients/{id}/receipts?month=`, `/receipts/{id}`, `/export?month=&format=` | Jezgro (API za knjigovođu, `AccountantApiController`) | Bearer ključ knjigovođe iz settings; samo čitanje; vidi docs/api.md |
| GET | `/podesavanja/api/openapi.yaml` | Panel | preuzimanje `docs/openapi.yaml` (prijavljen nalog) |

## API za aplikaciju (v1)
Zaglavlja: `Authorization: Bearer <ključ>`, `Content-Type: application/json`, `X-Racunator-Api: 1`.
Telo `POST /u/{slug}/receipts`:
```json
{"receipts":[{"invoiceNumber":"RCVG4AFL-RCVG4AFL-234120","verificationUrl":"https://suf.purs.gov.rs/v/?vl=…",
  "sdcDateTime":"2026-10-24T21:42:31","totalAmountMinor":123456,"sellerName":"ZORA MARKET DOO","sellerPib":"100000000",
  "buyerPib":"101234567","invoiceType":"Normal","transactionType":"Sale"}]}
```
Odgovor 200: `{"accepted":["RCVG4AFL-RCVG4AFL-234120"],"duplicates":[],"rejected":[{"invoiceNumber":"…","reason":"…"}]}`.
Pravila: najviše 200 računa i 1 MB po zahtevu (413 pre čitanja tela); dnevni limit prihvaćenih računa po korisniku (`api_daily_limit`, podrazumevano 2000; 429 `daily_limit`); `invoiceNumber` oblika `XXXXXXXX-XXXXXXXX-N`; `verificationUrl` mora biti `https://suf.purs.gov.rs/…`;
401 za pogrešan ključ ili nepostojeći/neaktivan slug (isti odgovor, bez otkrivanja); 429 preko limita (60 zahteva/min po ključu); 413 za preveliko telo.

## QR za uparivanje (v1)
```json
{"v":1,"t":"racunator-knjigovodja","endpoint":"https://domen.rs/panel/u/<slug>","name":"Pera DOO","key":"<ključ>","accountant":"Knjigovodstvo XY"}
```
Ključ se prikazuje samo jednom (pri kreiranju ili pri „Nov ključ").

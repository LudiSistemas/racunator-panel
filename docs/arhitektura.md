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
- Konfiguracija: `config.php` u korenu instalacije (van `public/`), oblik u `src/Kernel/Config.php`.
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
| GET/POST | `/nalozi`, `/nalozi/novi`, POST `/nalozi/{id}/deaktiviraj`, POST `/nalozi/{id}/lozinka` | Panel (admin) | zaposleni |
| GET/POST | `/podesavanja`, `/podesavanja/lozinka`, `/podesavanja/2fa` | Panel | podešavanja naloga i instalacije |
| GET | `/dnevnik` | Panel | dnevnik radnji |
| GET/POST | `/azuriranje`, POST `/azuriranje/pokreni`, POST `/azuriranje/vrati` | Instalacija (UI u panelu) | provera, changelog, ažuriranje, povratak |
| GET | `/health` | Jezgro | `{"ok":true,"version":"…"}` |
| GET | `/u/{slug}/ping` | Jezgro (API za app) | Bearer ključ; vraća `{"ok":true,"client":"Pera DOO","accountant":"Knjigovodstvo XY","api":1}` |
| POST | `/u/{slug}/receipts` | Jezgro (API za app) | Bearer ključ; telo `{"receipts":[…]}` |

## API za aplikaciju (v1)
Zaglavlja: `Authorization: Bearer <ključ>`, `Content-Type: application/json`, `X-Racunator-Api: 1`.
Telo `POST /u/{slug}/receipts`:
```json
{"receipts":[{"invoiceNumber":"RCVG4AFL-RCVG4AFL-234120","verificationUrl":"https://suf.purs.gov.rs/v/?vl=…",
  "sdcDateTime":"2026-10-24T21:42:31","totalAmountMinor":123456,"sellerName":"ZORA MARKET DOO","sellerPib":"100000000",
  "buyerPib":"101234567","invoiceType":"Normal","transactionType":"Sale"}]}
```
Odgovor 200: `{"accepted":["RCVG4AFL-RCVG4AFL-234120"],"duplicates":[],"rejected":[{"invoiceNumber":"…","reason":"…"}]}`.
Pravila: najviše 200 računa i 1 MB po zahtevu; `invoiceNumber` oblika `XXXXXXXX-XXXXXXXX-N`; `verificationUrl` mora biti `https://suf.purs.gov.rs/…`;
401 za pogrešan ključ ili nepostojeći/neaktivan slug (isti odgovor, bez otkrivanja); 429 preko limita (60 zahteva/min po ključu); 413 za preveliko telo.

## QR za uparivanje (v1)
```json
{"v":1,"t":"racunator-knjigovodja","endpoint":"https://domen.rs/panel/u/<slug>","name":"Pera DOO","key":"<ključ>","accountant":"Knjigovodstvo XY"}
```
Ključ se prikazuje samo jednom (pri kreiranju ili pri „Nov ključ").

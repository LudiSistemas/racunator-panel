# API za aplikaciju Računator (v1)

Aplikacija Računator posle skeniranja QR koda šalje knjigovođi račune preko dva poziva. Svaki korisnik (firma/preduzetnik)
ima svoju adresu `https://<panel>/u/<slug>` i svoj API ključ. Mašinski opis: [openapi.yaml](openapi.yaml).

## Uparivanje (QR kod)
Panel prikazuje QR kod sa JSON sadržajem:
```json
{"v":1,"t":"racunator-knjigovodja","endpoint":"https://domen.rs/panel/u/0f3c…","name":"Pera DOO","key":"rk_…","accountant":"Knjigovodstvo XY"}
```
- `endpoint` — osnovna adresa korisnika (bez završne kose crte); pozivi su `endpoint + "/ping"` i `endpoint + "/receipts"`.
- `key` — API ključ (`rk_` + 43 znaka base64url, 256 bita). Panel ga prikazuje samo jednom; u bazi se čuva samo njegov SHA-256 heš.
  Posle „Nov ključ" u panelu stari ključ odmah prestaje da važi.
- `t` uvek `racunator-knjigovodja` (razlikuje ovaj QR od fiskalnog).

## Zajednička pravila
- Autentifikacija: `Authorization: Bearer <ključ>`. Pozivati isključivo preko HTTPS-a.
- Telo i odgovor: `application/json; charset=utf-8`. Aplikacija šalje `X-Racunator-Api: 1`; panel ga vraća u svakom odgovoru.
- Pogrešan ključ, nepostojeći slug i deaktiviran korisnik daju **isti** odgovor 401 (ne otkriva se da li korisnik postoji).
- Ograničenje: 60 zahteva u minutu po ključu; preko toga 429 `rate_limited` sa zaglavljem `Retry-After` (sekunde).
- Dnevni limit: najviše **2000 prihvaćenih računa** po korisniku u jednom danu (UTC; knjigovođa ga menja podešavanjem
  `api_daily_limit`, `0` = bez limita). Broje se samo `accepted` računi (ne duplikati ni odbijeni). Vidi „Dnevni limit".
- Tokom ažuriranja panela svi API pozivi vraćaju 503 sa `Retry-After: 120` — pokušati ponovo kasnije.

## GET /u/{slug}/ping
Provera uparivanja odmah posle skeniranja QR koda.

```sh
curl -s https://domen.rs/panel/u/0f3c…/ping -H "Authorization: Bearer rk_…" -H "X-Racunator-Api: 1"
```
Odgovor 200:
```json
{"ok":true,"client":"Pera DOO","accountant":"Knjigovodstvo XY","api":1}
```
`accountant` je naziv knjigovodstva iz podešavanja panela (`accountant_name`).

## POST /u/{slug}/receipts
Slanje do **200 računa** u jednom zahtevu; telo najviše **1 MB** (veće telo, ili `Content-Length` preko 1 MB, dobija 413
pre nego što panel pročita telo).

```sh
curl -s https://domen.rs/panel/u/0f3c…/receipts \
  -H "Authorization: Bearer rk_…" -H "Content-Type: application/json" -H "X-Racunator-Api: 1" \
  -d '{"receipts":[{"invoiceNumber":"RCVG4AFL-RCVG4AFL-234120","verificationUrl":"https://suf.purs.gov.rs/v/?vl=A1JD…",
       "sdcDateTime":"2026-10-24T21:42:31","totalAmountMinor":123456,"sellerName":"ZORA MARKET DOO",
       "sellerPib":"100000000","buyerPib":"101234567","invoiceType":"Normal","transactionType":"Sale"}]}'
```

### Polja računa
| Polje | Obavezno | Pravilo |
|---|---|---|
| `invoiceNumber` | da | PFR broj, oblik `^[A-Z0-9]{8}-[A-Z0-9]{8}-\d{1,10}$` (npr. `RCVG4AFL-RCVG4AFL-234120`) |
| `verificationUrl` | da | počinje sa `https://suf.purs.gov.rs/`, do 2048 znakova, bez razmaka |
| `sdcDateTime` | da | ISO 8601, npr. `2026-10-24T21:42:31` (dozvoljeni su delovi sekunde i zona — čuva se lokalno vreme PFR-a do sekunde) |
| `totalAmountMinor` | da | ceo broj ≥ 0, iznos u parama (1.234,56 RSD = `123456`) |
| `sellerName` | ne | tekst do 200 znakova ili `null` |
| `sellerPib`, `buyerPib` | ne | tačno 9 cifara (kao string) ili `null` |
| `invoiceType`, `transactionType` | ne | tekst do 200 znakova ili `null` (npr. `Normal`, `Sale`) |

Nepoznata polja se ignorišu. Mesec u panelu se određuje po `sdcDateTime`.

### Odgovor 200
```json
{"accepted":["RCVG4AFL-RCVG4AFL-234120"],"duplicates":[],"rejected":[{"invoiceNumber":"…","reason":"sdcDateTime: očekuje se ISO 8601, npr. 2026-10-24T21:42:31"}]}
```
- `accepted` — upisani računi.
- `duplicates` — računi koje panel već ima za ovog korisnika (isti `invoiceNumber`) ili koji se ponavljaju u istom zahtevu.
  Slanje je idempotentno: aplikacija može bezbedno ponoviti zahtev; duplikat treba smatrati uspešno poslatim.
- `rejected` — računi koji nisu prošli proveru, sa razlogom (`polje: opis`). `invoiceNumber` je `null` ako ga nije bilo.
  Ponovno slanje istog neispravnog računa neće uspeti.

### Dnevni limit
Ako bi zahtev prešao dnevni limit, panel ga odbija **u celosti** (ništa se ne upisuje) sa 429 i poljem `remaining`:
```json
{"error":"daily_limit","message":"Zahtev bi prešao dnevni limit od 2000 računa. Danas može još 37 — …","remaining":37}
```
- `remaining > 0` — pošaljite najviše toliko računa (ostatak sutra);
- `remaining = 0` — limit je dostignut; ponoviti posle `Retry-After` sekundi (do ponoći po UTC-u).
`GET /ping` radi i kad je limit dostignut. Kad korisnik pređe pola limita i kad ga dostigne, panel to jednom dnevno
upisuje u dnevnik radnji knjigovođe.

## Greške
Telo greške: `{"error":"<kod>","message":"<opis na srpskom>"}`.

| Status | `error` | Kada | Šta aplikacija radi |
|---|---|---|---|
| 400 | `invalid_body` | telo nije JSON objekat `{"receipts":[…]}` | greška u aplikaciji — ne ponavljati isto |
| 401 | `unauthorized` | pogrešan/opozvan ključ, nepostojeći ili deaktiviran korisnik | ponuditi ponovno uparivanje |
| 405 | — | pogrešna metoda (npr. GET na `/receipts`) | — |
| 413 | `payload_too_large` | telo veće od 1 MB | podeliti na manje pakete |
| 413 | `too_many_receipts` | više od 200 računa | podeliti na pakete do 200 |
| 429 | `rate_limited` | više od 60 zahteva u minutu | sačekati `Retry-After` sekundi |
| 429 | `daily_limit` | dnevni limit prihvaćenih računa bi bio pređen | poslati najviše `remaining` računa, ostalo posle `Retry-After` |
| 503 | `maintenance` | panel se ažurira | pokušati ponovo posle `Retry-After` |
| 500 | — | greška na serveru (HTML stranica) | pokušati ponovo kasnije |

## Provera rada
`GET /health` (bez ključa) vraća `{"ok":true}` — 503 i `"ok":false` ako baza nije dostupna. Verziju (`"version":"…"`)
dodaje samo za prijavljenog administratora i za samoproveru instalacije/ažuriranja (zaglavlje `X-Racunator-Health-Token`
sa vrednošću `App::healthToken(app_key)` = HMAC-SHA256 od `racunator-panel/health` ključem `app_key`).

## API za knjigovođu
Planiran (faza C): `/api/<slug>/v1/…`, uključuje se u panelu. Biće opisan ovde i u `openapi.yaml`.

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
| `source` | ne | `"qr"` (podrazumevano kad polje nedostaje ili je `null`) — račun iz QR koda; `"pfr"` — račun unet po PFR broju |
| `verificationUrl` | da | za `qr`: počinje sa `https://suf.purs.gov.rs/`, do 2048 znakova, bez razmaka; za `pfr`: **tačno** `https://suf.purs.gov.rs/verify` |
| `sdcDateTime` | da | ISO 8601, npr. `2026-10-24T21:42:31` (dozvoljeni su delovi sekunde i zona — čuva se lokalno vreme PFR-a do sekunde) |
| `totalAmountMinor` | da | ceo broj ≥ 0, iznos u parama (1.234,56 RSD = `123456`) |
| `sellerName` | ne | tekst do 200 znakova ili `null` |
| `sellerPib`, `buyerPib` | ne | tačno 9 cifara (kao string) ili `null` |
| `invoiceType`, `transactionType` | ne | tekst do 200 znakova ili `null` (npr. `Normal`, `Sale`) |

Nepoznata polja se ignorišu. Mesec u panelu se određuje po `sdcDateTime`.

### Računi uneti po PFR broju (`source: "pfr"`)
Račun koji korisnik u aplikaciji unese po PFR broju (bez QR koda) nema link sa stavkama, pa aplikacija šalje
`"source":"pfr"` i `"verificationUrl":"https://suf.purs.gov.rs/verify"` — zvaničnu stranicu na kojoj knjigovođa ukuca
PFR broj. Bilo koja druga vrednost `verificationUrl` uz `pfr` (i link `…/v/?vl=…`) se odbija. Drugi `source` od `qr`/`pfr`
se odbija razlogom `source: dozvoljeno je "qr" ili "pfr"`.
```json
{"invoiceNumber":"RCVG4AFL-RCVG4AFL-234120","source":"pfr","verificationUrl":"https://suf.purs.gov.rs/verify",
 "sdcDateTime":"2026-10-24T21:42:31","totalAmountMinor":123456,"sellerName":"ZORA MARKET DOO","sellerPib":"100000000",
 "buyerPib":"101234567","invoiceType":"Normal","transactionType":"Sale"}
```

**Nadogradnja zapisa (pfr → qr):** ako panel već ima račun tog korisnika sa istim `invoiceNumber` i `source = pfr`, a stigne
isti račun sa `source = qr` (ili bez polja) i pravim linkom koji počinje sa `https://suf.purs.gov.rs/v/?vl=`, panel ažurira
postojeći zapis (`verificationUrl`, `source`, sirovi JSON i ostala polja iz nove stavke; vreme prijema ostaje prvobitno) i
vraća ga u `accepted`. Svi drugi ponovljeni računi (qr → qr, pfr → pfr, qr → pfr, pfr → qr bez linka `…/v/?vl=…`) idu u
`duplicates` kao i ranije.

### Odgovor 200
```json
{"accepted":["RCVG4AFL-RCVG4AFL-234120"],"duplicates":[],"rejected":[{"invoiceNumber":"…","reason":"sdcDateTime: očekuje se ISO 8601, npr. 2026-10-24T21:42:31"}]}
```
- `accepted` — upisani računi (i računi uneti po PFR broju koji su upravo nadograđeni linkom iz QR koda).
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

## API za knjigovođu (v1, samo čitanje)
Knjigovodstveni program ili skripta knjigovođe čita korisnike i račune iz panela. API je isključen dok ga administrator
ne uključi u panelu (**Podešavanja → API za knjigovođu**); tada dobija nasumičnu osnovnu adresu i ključ, a na istoj stranici
su lista poziva, `curl` primeri i preuzimanje `openapi.yaml`. Mašinski opis: putanje `/api/{slug}/v1/…` u [openapi.yaml](openapi.yaml).

### Pravila
- Osnovna adresa: `https://<panel>/api/<slug>/v1`, gde je `<slug>` 32 heksadecimalna znaka iz panela. Svako uključivanje
  daje novu adresu i nov ključ; **Nov ključ** poništava stari odmah; **Isključi API** briše ključ.
- Autentifikacija: `Authorization: Bearer <ključ>` (`rk_` + 43 znaka base64url, prikazuje se samo jednom). Ključ korisnika
  (iz QR koda) ovde ne važi. Pozivati isključivo preko HTTPS-a, sa servera (CORS nije omogućen).
- Isključen API, pogrešan slug i pogrešan ključ daju **isti** odgovor 401 `unauthorized` (ne otkriva se šta je pogrešno).
- Posle **10 promašaja ključa** sa iste adrese (IPv6: isti /64) u 15 minuta adresa je blokirana do isteka prozora — svi
  pozivi, i sa ispravnim ključem, dobijaju 429 `auth_blocked` sa `Retry-After`. Svaki promašaj se upisuje u dnevnik radnji
  (`api.accountant_auth_fail`, bez ključa); uspešan poziv briše promašaje te adrese.
- Ograničenje: **120 zahteva u minutu** za ključ knjigovođe; preko toga 429 `rate_limited` sa `Retry-After` (sekunde).
- Svi odgovori imaju `X-Racunator-Api: 1` i `Cache-Control: no-store`. Samo `GET`; druga metoda daje 405
  `method_not_allowed`, nepoznata putanja 404 `not_found`. Tokom ažuriranja panela 503 `maintenance`.
- Vremena: `sdcDateTime` je vreme sa računa — lokalno (zidno) vreme PFR-a `YYYY-MM-DDTHH:MM:SS`, isto kao u aplikaciji;
  `receivedAt`, `createdAt`, `lastSeenAt` su UTC u ISO 8601 sa `Z` (npr. `2026-10-24T19:45:02Z`). Iznosi su u parama.
- Mesec (`month=YYYY-MM`) se određuje po `sdcDateTime`, kao u panelu.

### Pozivi
```sh
API="https://domen.rs/panel/api/0f3c…/v1"
KLJUC="rk_…"
curl -s "$API/ping" -H "Authorization: Bearer $KLJUC"
```

| Poziv | Odgovor 200 |
|---|---|
| `GET /ping` | `{"ok":true,"accountant":"Knjigovodstvo XY","api":1}` |
| `GET /clients` | `{"items":[{"id":12,"name":"Pera DOO","active":true,"createdAt":"2026-10-01T08:15:00Z","lastSeenAt":"2026-10-24T19:45:02Z","receiptsCount":318}]}` — svi korisnici (i deaktivirani), po nazivu; `receiptsCount` = ukupno računa |
| `GET /clients/{id}/receipts?month=YYYY-MM&page=1&perPage=100` | `{"items":[…računi…],"month":"2026-10","page":1,"perPage":100,"total":318}` — `month` obavezan; `page` ≥ 1 (podrazumevano 1); `perPage` 1–500 (podrazumevano 100); po `sdcDateTime` rastuće; stranica posle poslednje ima prazan `items` |
| `GET /receipts/{id}` | jedan račun; uz `?raw=1` i polje `rawJson` (JSON tekst kako ga je aplikacija poslala, do 8 KB, ili `null`) |
| `GET /export?month=YYYY-MM&format=csv\|json&clientId=` | izvoz meseca: `csv` (podrazumevano) ili `json` (niz računa); bez `clientId` svi korisnici (po nazivu, pa po vremenu) |

Račun u JSON-u:
```json
{"id":345,"clientId":12,"invoiceNumber":"RCVG4AFL-RCVG4AFL-234120","verificationUrl":"https://suf.purs.gov.rs/v/?vl=A1JD…",
 "sdcDateTime":"2026-10-24T21:42:31","totalAmountMinor":123456,"sellerName":"ZORA MARKET DOO","sellerPib":"100000000",
 "buyerPib":"101234567","invoiceType":"Normal","transactionType":"Sale","source":"qr","receivedAt":"2026-10-24T19:45:02Z"}
```
`source`: `qr` — račun iz QR koda; `pfr` — račun unet po PFR broju (`verificationUrl` je tada
`https://suf.purs.gov.rs/verify`; kad isti račun kasnije stigne iz QR koda, zapis se nadograđuje u `qr`).

CSV izvoz je isti kao **Preuzmi CSV** u panelu: UTF-8 sa BOM-om, razdvajač `;`, decimalni zarez, redovi CRLF, kolone
Datum; Prodavac; PIB prodavca; PIB kupca; Iznos; PFR broj; Vrsta računa; Vrsta transakcije; Link za proveru; Izvor
(`QR` ili `PFR broj`). Izvoz bez `clientId` ima i prvu kolonu **Korisnik**. Tekst koji počinje znakom formule (`=`, `+`,
`-`, `@`) dobija apostrof ispred.
Izvoz ima najviše 50000 računa (inače 413 `export_too_large` — izvezite korisnike pojedinačno) i upisuje se u dnevnik
radnji (`api.accountant_export`).

```sh
curl -s -o racuni-2026-10.csv "$API/export?month=2026-10&format=csv" -H "Authorization: Bearer $KLJUC"
curl -s "$API/clients/12/receipts?month=2026-10&page=2&perPage=500" -H "Authorization: Bearer $KLJUC"
```

### Greške
Telo: `{"error":"<kod>","message":"<opis na srpskom>"}`.

| Status | `error` | Kada |
|---|---|---|
| 400 | `invalid_parameter` | `month` nedostaje ili nije `YYYY-MM`, `page`/`perPage` van opsega, nepoznat `format` |
| 401 | `unauthorized` | API isključen, pogrešan slug ili ključ |
| 404 | `not_found` | korisnik ili račun ne postoji, nepoznata putanja |
| 405 | `method_not_allowed` | metoda različita od `GET` |
| 413 | `export_too_large` | izvoz bi imao više od 50000 računa |
| 429 | `auth_blocked` | adresa blokirana posle 10 promašaja ključa (čekati `Retry-After`) |
| 429 | `rate_limited` | više od 120 zahteva u minutu (čekati `Retry-After`) |
| 503 | `maintenance` | panel se ažurira |

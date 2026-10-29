# Izmene

## 0.3.0 — 2026-10-29

### Podaci za proveru računa na stranici Poreske uprave
- API za aplikaciju: nova opciona polja računa `invoiceCounter` (brojač `N/N`, npr. `123/456`) i
  `invoiceCounterExtension` (nastavak brojača, 1–4 velika slova ćirilicom ili latinicom, npr. `ПП`); neispravna vrednost
  odbija račun sa jasnim razlogom, nedostaje/`null` → prazno. Pri nadogradnji pfr → qr brojač se zamenjuje samo ako ga
  nova stavka ima.
- Migracija baze `0005_receipt_invoice_counter` (kolone `receipts.invoice_counter`, `receipts.invoice_counter_extension`).
- Pregled računa: uz račun unet po PFR broju blok „Podaci za proveru" — PFR broj, brojač sa nastavkom, PFR vreme
  (`20.10.2026. 15:51`) i iznos (`6.270,00`), svaki sa dugmetom za kopiranje.
- CSV (panel i API za knjigovođu): nove poslednje kolone „Brojač" i „Nastavak"; JSON računa API-ja za knjigovođu: polja
  `invoiceCounter` i `invoiceCounterExtension`.

### API za knjigovođu
- Read-only API na `/api/<slug>/v1` za knjigovodstvene programe: `ping`, lista korisnika, računi korisnika po mesecu sa
  stranicama, jedan račun (opciono sa izvornim JSON-om), izvoz meseca u CSV (isti format kao u panelu) ili JSON.
- Autentifikacija ključem knjigovođe iz podešavanja; isti 401 za isključen API, pogrešnu adresu i ključ; 120 zahteva u
  minutu; privremena blokada adrese posle 10 promašaja ključa u 15 minuta; promašaji i izvozi u dnevniku radnji.
- Stranica **Podešavanja → API za knjigovođu** sada ima dokumentaciju, `curl` primere i preuzimanje `openapi.yaml`.
- Dokumentacija: odeljak „API za knjigovođu" u `docs/api.md`, nove putanje u `docs/openapi.yaml`.
- CSV izvoz računa izdvojen u zajednički kod za panel i API; i API izvoz ima kolonu „Izvor" (QR / PFR broj),
  a JSON računa polje `source` (`qr` | `pfr`).
- `docs/openapi.yaml` opisuje oba API-ja; ispravljena šema računa API-ja za aplikaciju (uslov za `source: pfr` je bio
  umetnut usred `properties`, pa polja od `sdcDateTime` nadalje nisu bila opisana).

## 0.2.0 — 2026-10-29

- Prijem računa unetih u aplikaciji po PFR broju (bez QR koda): novo opciono polje `source` u API-ju (`qr` podrazumevano |
  `pfr`); za `pfr` link mora biti tačno `https://suf.purs.gov.rs/verify`. Kad isti račun kasnije stigne iz QR koda sa pravim
  linkom, panel nadograđuje zapis umesto da ga odbije kao duplikat.
- Migracija baze `0004_receipt_source` (kolona `receipts.source`, postojeći računi dobijaju `qr`).
- Pregled računa: oznaka „PFR broj" i dugme „Proveri na sajtu Poreske uprave" (stranica za proveru po PFR broju) uz dugme za
  kopiranje PFR broja; CSV ima novu poslednju kolonu „Izvor" (QR / PFR broj).
- Ispravna množina u pregledu po mesecima i u zbiru („1 račun", „21 račun", „2 računa", „11 računa").
- CLI: `php install/cli.php migracije` — primena novih migracija na instaliranom panelu.

## 0.1.0 — 2026-10-29

Prvo izdanje.

- Instalacija iz jednog fajla (`racunator-panel-install.php`) sa čarobnjakom: provere hostinga (PHP, ekstenzije, prava, HTTPS, mod_rewrite,
  dostupnost podataka preko veba), baza MySQL/MariaDB ili SQLite, javna adresa, folder za podatke po želji van web root-a, prvi nalog.
- Nalozi zaposlenih sa ulogama admin i zaposleni, prijava u dva koraka sa dvofaktorskom zaštitom, ograničenje pokušaja po nalogu i adresi,
  odjava sa svih uređaja, reset 2FA od strane administratora, dnevnik radnji.
- Korisnici (firme i preduzetnici) sa nasumičnim endpointom i ključem, QR kartica za štampu, nov ključ i deaktivacija.
- Prijem računa iz aplikacije Računator (API po korisniku, limiti po minutu i po danu), pregled po mesecu sa linkom ka sajtu Poreske uprave,
  izvoz u CSV.
- Ažuriranje iz panela samo posle potvrde: prikaz izmena, potpisani paketi (Ed25519), rezervna kopija fajlova i baze, povratak na prethodnu
  verziju; ručni put install fajlom i CLI.
- Dokumentacija na srpskom: instalacija, panel, API, ažuriranje, bezbednost, izdavanje, razvoj.

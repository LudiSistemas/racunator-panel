# Izmene

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

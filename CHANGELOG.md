# Izmene

## Neobjavljeno

### API za knjigovođu
- Read-only API na `/api/<slug>/v1` za knjigovodstvene programe: `ping`, lista korisnika, računi korisnika po mesecu sa
  stranicama, jedan račun (opciono sa izvornim JSON-om), izvoz meseca u CSV (isti format kao u panelu) ili JSON.
- Autentifikacija ključem knjigovođe iz podešavanja; isti 401 za isključen API, pogrešnu adresu i ključ; 120 zahteva u
  minutu; privremena blokada adrese posle 10 promašaja ključa u 15 minuta; promašaji i izvozi u dnevniku radnji.
- Stranica **Podešavanja → API za knjigovođu** sada ima dokumentaciju, `curl` primere i preuzimanje `openapi.yaml`.
- Dokumentacija: odeljak „API za knjigovođu" u `docs/api.md`, nove putanje u `docs/openapi.yaml`.
- CSV izvoz računa izdvojen u zajednički kod za panel i API.

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

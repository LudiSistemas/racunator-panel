# Računator panel

Mali panel za knjigovođe koji prima račune iz aplikacije **Računator** (Android i iPhone). Radi na običnom shared hostingu
(PHP 8.1+, Apache, MySQL/MariaDB ili SQLite), instalira se iz jednog fajla i sam se ažurira na zahtev.

## Šta radi
- Knjigovođa dodaje korisnika (firmu ili preduzetnika) i dobija QR kod. Korisnik ga skenira u Računatoru i od tada aplikacija šalje
  knjigovođi linkove računa sa Sistema za upravljanje fiskalizacijom (SUF) i osnovne podatke (PFR broj, datum, iznos, prodavac, PIB).
- Svaki korisnik ima svoj nasumični endpoint i svoj API ključ — podaci su razdvojeni po korisniku.
- Računi se pregledaju po korisniku i mesecu i otvaraju na sajtu Poreske uprave; izvoz u CSV.
- Više naloga za zaposlene, dnevnik radnji, dvofaktorska prijava.
- API za knjigovođu (dokumentovan, uključuje se u panelu).
- Ažuriranje iz panela: prikaz izmena u novoj verziji, potvrda, rezervna kopija i povratak na prethodnu verziju.

## Instalacija (kratko)
1. Preuzmite `racunator-panel-install.php` sa stranice izdanja.
2. Otpremite ga u folder na hostingu (npr. `public_html/panel/`) i otvorite u pregledaču.
3. Pratite čarobnjak: provera hostinga, baza, javna adresa, prvi nalog.

Detalji: [docs/instalacija.md](docs/instalacija.md). Bezbednost: [docs/bezbednost.md](docs/bezbednost.md). API: [docs/api.md](docs/api.md).

## Razvoj
Zahteva PHP 8.1+ i Composer (samo za testove). `composer install && composer test`. Vidi [docs/razvoj.md](docs/razvoj.md).

## Licenca
MIT — vidi [LICENSE](LICENSE).

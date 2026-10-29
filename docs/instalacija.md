# Instalacija Računator panela

Panel se instalira iz **jednog fajla** — `racunator-panel-install.php` sa stranice izdanja na GitHub-u. Ne treba vam
SSH, Composer ni poznavanje programiranja: dovoljni su FTP (ili File Manager u kontrolnom panelu hostinga) i pregledač.

## Šta vam treba

| Uslov | Najmanje | Gde se proverava |
|---|---|---|
| PHP | 8.1 | cPanel → „Select PHP Version" / „MultiPHP Manager" |
| PHP ekstenzije | pdo + pdo_mysql (ili pdo_sqlite), openssl, mbstring, json, session | isto mesto → „Extensions" |
| Za ažuriranje iz panela | zip, curl (ili `allow_url_fopen`), po mogućstvu sodium | isto |
| Baza | MySQL/MariaDB (preporučeno) ili SQLite | cPanel → „MySQL Databases" |
| Web server | Apache sa `mod_rewrite` i `AllowOverride All` (standard na shared hostingu) | podrška hostinga |
| Memorija | `memory_limit` ≥ 64 MB | „Select PHP Version → Options" |
| Prostor | ≥ 50 MB (plus mesto za rezervne kopije) | kontrolni panel |
| HTTPS | SSL sertifikat (besplatan Let's Encrypt) | cPanel → „SSL/TLS Status" |

Čarobnjak sve ovo sam proverava i za svaku stavku kaže šta tačno da tražite od podrške hostinga.

## Korak po korak

### 1. Napravite bazu (ako koristite MySQL)
U kontrolnom panelu otvorite **MySQL Databases** (ili „MySQL Database Wizard"):
1. napravite bazu, npr. `panel` (hosting obično doda prefiks naloga, pa je pravo ime npr. `mojnalog_panel`),
2. napravite korisnika baze sa jakom lozinkom,
3. dodelite korisniku **ALL PRIVILEGES** nad tom bazom.

Zapišite: ime baze, korisnika i lozinku. Server baze je skoro uvek `localhost`.
Baza treba da bude **prazna** (ili bar bez tabela panela). Ako hosting nema MySQL, preskočite ovaj korak i izaberite SQLite.

### 2. Otpremite install fajl
1. Preuzmite `racunator-panel-install.php` sa stranice poslednjeg izdanja.
2. Preko FTP-a ili File Manager-a napravite **novi, prazan folder**, npr. `public_html/panel/`.
3. Otpremite `racunator-panel-install.php` u taj folder. (U FTP programu je najsigurniji „binarni" režim prenosa;
   install fajl je otporan i na „tekstualni", ali binarni je pouzdaniji.)

*Slika ekrana (opis): File Manager sa otvorenim folderom `public_html/panel/` u kome je samo jedan fajl,
`racunator-panel-install.php`, veličine oko 250 KB.*

### 3. Otvorite install fajl u pregledaču
Idite na `https://vas-domen.rs/panel/racunator-panel-install.php`. Install fajl:
- proveri verziju PHP-a (ako je prestara, kaže vam to umesto prazne stranice),
- proveri da fajl nije oštećen pri prenosu (SHA-256),
- raspakuje panel pored sebe i proveri svaki fajl,
- preusmeri vas na čarobnjak `…/panel/install/`.

Ako folder nije prazan, install fajl odbija da radi i pokaže šta je u folderu — napravite novi folder.
Postojeći `.htaccess` (ako ga je hosting stavio) se preimenuje u `.htaccess.pre-racunator-<datum>`.

### 4. Čarobnjak: provera hostinga
*Slika ekrana (opis): lista stavki sa zelenim kvačicama, žutim uzvičnicima i crvenim krstićima; ispod svake stavke
kratko objašnjenje i, kod žutih i crvenih, rečenica „Šta tražiti od hostinga: …". Dole dugme „Dalje: baza".*

- **Crveno** mora biti rešeno pre nastavka (najčešće jednim zahtevom podršci hostinga).
- **Žuto** su upozorenja: instalacija radi, ali nešto neće (npr. bez ekstenzije `zip` nema ažuriranja iz panela,
  bez HTTPS-a aplikacija Računator neće slati račune).
- Test `mod_rewrite`-a upiše probni `.htaccess` i proba ga preko sopstvene adrese. Ako hosting ne dozvoljava da server
  pozove sam sebe, stavka je žuta i proverava se ponovo na kraju preko `/health`.

### 5. Čarobnjak: baza
Izaberite **MySQL / MariaDB** i upišite server (`localhost`), port (`3306`), ime baze, korisnika i lozinku, pa
kliknite **Testiraj vezu**. Poruka je na srpskom i kaže šta nije u redu (pogrešna lozinka, baza ne postoji, korisnik
nema prava…). Lozinka se nikad ne prikazuje ponovo; ako polje ostavite prazno, zadržava se ranije uneta.

Ili izaberite **SQLite** — baza je fajl `storage/panel.sqlite`, ništa ne treba podešavati.

### 6. Čarobnjak: javna adresa
Predlog je adresa iz pregledača, npr. `https://vas-domen.rs/panel`. To je adresa na koju aplikacija Računator šalje
račune — mora početi sa **https://**. Promenite je samo ako panel otvarate preko drugog domena.

### 7. Čarobnjak: prvi nalog
- **Naziv knjigovodstva** — vide ga vaši klijenti u aplikaciji posle skeniranja QR koda.
- **Korisničko ime** i **lozinka** administratora (najmanje 12 znakova; merač pokazuje jačinu; savet: fraza od nekoliko reči).

Klik na **Instaliraj**: panel upisuje `config.php` (prava 0600, sa nasumičnim tajnim ključem instalacije), pravi
`storage/` (zaštićen od pristupa preko veba), pravi tabele u bazi, nalog administratora i zaključava instaler.

### 8. Kraj
*Slika ekrana (opis): zelena poruka „Panel je instaliran", ispod nje „Panel odgovara na …/health", i dugme „Otvori prijavu".*

Install fajl se sam briše. Folder `install/` ostaje: koristi se samo za ručno ažuriranje i zaključan je dok postoji
`config.php`. Posle prve prijave uključite dvofaktorsku prijavu (Podešavanja).

## Raspored fajlova posle instalacije
```
public_html/panel/
  .htaccess        prosleđuje sve u public/ (vaš je — ažuriranje ga ne menja)
  config.php       podešavanja instalacije (lozinka baze, tajni ključ) — 0600, nedostupan preko veba
  public/          front controller i CSS/JS
  src/             kod panela (nedostupan preko veba)
  install/         čarobnjak (zaključan) i ažuriranje
  docs/            uputstva
  storage/         SQLite baza, rezervne kopije, dnevnici, sesije (nedostupno preko veba)
```

## Tipični problemi na hostingu

**Prazna stranica ili „Parse error" kod install fajla** — PHP je prestar. Install fajl na PHP 7 prikazuje poruku; na PHP 5
izaberite noviju verziju u kontrolnom panelu („Select PHP Version"), pa osvežite stranicu.

**Posle instalacije svaka stranica je 404 ili 500 („Internal Server Error")** — `mod_rewrite` nije uključen ili hosting ne
dozvoljava `.htaccess` (`AllowOverride None`). Napišite podršci: *„Molim uključite mod_rewrite i AllowOverride All za folder
public_html/panel."* Proverite i da ste otpremili ceo sadržaj (fajl `.htaccess` je skriven — u File Manager-u uključite
„Show hidden files").

**„Nema prava pisanja" / „PHP ne može da piše u folder"** — PHP radi pod drugim korisnikom od vlasnika fajlova. Postavite
folder na 755 (vlasnik je vaš nalog) ili zatražite od hostinga da PHP radi pod vašim korisnikom (PHP-FPM/suPHP — standard
na većini hostinga). Prava 777 ne koristite.

**„open_basedir restriction in effect"** — hosting ograničava PHP na određene foldere. Panel koristi samo sopstveni folder
(i `storage/` u njemu), pa je dovoljno da je folder instalacije unutar dozvoljenih putanja (obično ceo vaš nalog). Ako se
greška javi, pošaljite je podršci i tražite da folder instalacije bude u `open_basedir`.

**„Veza sa bazom nije uspela"** — proverite tačno ime baze sa prefiksom (`mojnalog_panel`), korisnika sa prefiksom i da je
korisnik dodat bazi sa „ALL PRIVILEGES". Server je `localhost` osim ako hosting kaže drugačije.

**„Ova baza već sadrži instaliran Računator panel"** — koristite praznu bazu. Ako ste obrisali `config.php`, a želite da
zadržite podatke, vratite `config.php` iz rezervne kopije umesto ponovne instalacije.

**Samoprovera `/health` nije prošla, a panel se otvara** — neki hostinzi ne dozvoljavaju serveru da pozove sam sebe. Otvorite
`https://vas-domen.rs/panel/health` u pregledaču — ako piše `{"ok":true,…}`, sve je u redu.

**Install fajl kaže „oštećen"** — preuzmite ga ponovo i otpremite u binarnom režimu.

## Bez pregledača (SSH) i posebni slučajevi
- Instalacija iz komandne linije:
  ```
  php racunator-panel-install.php
  RACUNATOR_ADMIN_PASSWORD='…' RACUNATOR_DB_PASS='…' php install/cli.php instaliraj --db=mysql --db-host=localhost \
      --db-name=panel --db-user=panel --url=https://vas-domen.rs/panel --admin=marko --naziv="Knjigovodstvo XY"
  ```
  (`--db=sqlite` za SQLite; `php install/cli.php provera` samo pokreće provere.)
- **Document root = `public/`** (VPS, sopstveni virtual host): koreni `.htaccess` tada nije potreban, a čarobnjak `install/` nije
  dostupan preko veba — instalirajte preko `install/cli.php` (gore). Ručno ažuriranje u tom slučaju: `php install/cli.php azuriraj`.
- **nginx**: sve zahteve prosledite na `public/index.php` (`try_files $uri /index.php?$query_string;` sa root-om na `public/`),
  a zabranite pristup svemu van `public/`. Instalacija i ažuriranje idu preko `install/cli.php`.

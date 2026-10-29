# Bezbednost Računator panela

Ovaj dokument je za **knjigovođe** koji panel koriste i za **održavaoce** koji ga instaliraju i ažuriraju. Opisuje šta panel
štiti i kako, šta zavisi od vas i vašeg hostinga, i šta da radite ako nešto pođe naopako. Propuste prijavljujte prema
[SECURITY.md](../SECURITY.md) — nikad javno.

## Šta se čuva u panelu
- **Računi** vaših korisnika (firmi i preduzetnika): PFR broj, link za proveru, datum, iznos, prodavac i PIB-ovi.
- **Korisnici**: naziv, napomena, adresa za aplikaciju i **heš** API ključa (sam ključ se ne čuva).
- **Nalozi** knjigovodstva: korisničko ime, heš lozinke, tajna za dvofaktorsku prijavu, uloga.
- **Dnevnik radnji** (ko je šta uradio, kada i sa koje IP adrese).
- **config.php**: lozinka baze i tajni ključ instalacije.
- **Rezervne kopije** (`storage/backups/`): kopija svih fajlova (uključujući config.php) i cele baze.

Ko dođe do baze ili rezervne kopije, vidi sve račune vaših korisnika. Zato su rezervne kopije i pristup hostingu jednako
važni kao lozinka za panel.

## Kako panel štiti podatke

### Prijava i lozinke
- Lozinke se čuvaju samo kao heš (Argon2id, a gde ga hosting nema — bcrypt). Panel nikad ne zna ni ne prikazuje vašu lozinku.
- Nova lozinka mora imati najmanje **12 znakova**. Najbolje je fraza od nekoliko reči, npr. „plavi-kofer-sedam-oblaka".
- Posle **5 neuspelih pokušaja** za isto korisničko ime sa iste IP adrese (ili 30 sa iste adrese za bilo koje ime) prijava
  se blokira na 15 minuta.
- Poruka „Pogrešno korisničko ime ili lozinka" je ista bez obzira na to da li nalog postoji.

### Dvofaktorska prijava (2FA)
- Uključuje se u **Podešavanja → Dvofaktorska prijava**, aplikacijom za autentifikaciju na telefonu (Google Authenticator,
  Microsoft Authenticator, Aegis, 2FAS…). Standard je TOTP: šestocifreni kod koji se menja na 30 sekundi.
- Isti kod ne može da se upotrebi dva puta za prijavu. Prihvata se kod za tekućih 30 s i susedne intervale (za telefon čiji
  sat malo kasni ili žuri) — zato sat na telefonu treba da je podešen automatski.
- **Uključite 2FA na svakom nalogu, a obavezno na administratorskim.** Lozinka može da procuri (phishing, ista lozinka na
  drugom sajtu); kod sa telefona ne može.

### Sesija (ostanak prijavljen)
- Posle prijave panel dodeljuje nov identifikator sesije, pa ranije podmetnut kolačić ne važi.
- Kolačić sesije nije dostupan JavaScript-u (HttpOnly), ne šalje se sa tuđih sajtova pri slanju formi (SameSite=Lax) i na
  HTTPS-u se šalje samo šifrovano (Secure).
- Sesija ističe posle **2 sata neaktivnosti** ili **12 sati od prijave**, kao i odmah posle odjave ili deaktivacije naloga.
- Fajlovi sesija su u `storage/tmp/sessions/`, a ne u zajedničkom `/tmp` folderu hostinga.
- Promena lozinke **ne odjavljuje** ostale otvorene sesije istog naloga. Ako sumnjate da je neko drugi prijavljen vašim
  nalogom, vidi „Ako sumnjate na provalu" ispod.

### Zaštita formi (CSRF) i klikdžeking
- Svaka forma koja nešto menja nosi tajni token sesije; zahtev bez ispravnog tokena se odbija. Zlonamerni sajt zato ne može
  da vas „natera" da kliknete nešto u panelu.
- Forme koje prave ključeve (nov korisnik, nov ključ) imaju i jednokratni broj, pa osvežavanje stranice ne pravi duplikat.
- Panel ne može da se prikaže unutar tuđe stranice (zabranjeni okviri), pa nema „nevidljivih dugmadi" preko njega.

### Uloge: administrator i zaposleni
| Može | Zaposleni | Administrator |
|---|---|---|
| Kontrolna tabla, korisnici, QR kod, nov ključ, (de)aktivacija korisnika | da | da |
| Računi po mesecu i izvoz CSV | da | da |
| Dnevnik radnji | da | da |
| Sopstvena lozinka i 2FA | da | da |
| Nalozi (dodavanje, deaktivacija, nova lozinka zaposlenom) | ne | da |
| Izmena naziva knjigovodstva, API za knjigovođu | ne | da |
| Ažuriranje, povratak verzije, preuzimanje rezervnih kopija | ne | da |

Svi nalozi vide sve korisnike — panel je zamišljen za jednu kancelariju. Svaki zaposleni treba da ima **svoj** nalog
(dnevnik tada pokazuje ko je šta uradio), a nalog se deaktivira čim zaposleni ode.

### API ključevi i QR kartice
- Svaki korisnik ima sopstvenu nasumičnu adresu (`/u/<32 znaka>`) i API ključ od 256 bita. U bazi je samo SHA-256 heš
  ključa; ceo ključ se prikazuje **samo jednom** (QR kod posle dodavanja korisnika ili „Nov ključ").
- **QR kartica je ključ.** Ko ima QR kod ili ključ, može da šalje račune u ime tog korisnika. Karticu predajte lično, a
  posle skeniranja je uništite ili čuvajte zaključanu. Ako se izgubi — **Nov ključ**: stari odmah prestaje da važi.
- Pogrešan ključ, nepostojeća adresa i deaktiviran korisnik daju isti odgovor (ne otkriva se ko postoji).
- Ograničenja: 60 zahteva u minutu po ključu, najviše 200 računa i 1 MB po zahtevu. Svaki račun se proverava (oblik PFR
  broja, link mora biti na `https://suf.purs.gov.rs/`, datum, iznos, PIB od 9 cifara); ostalo se odbija.
- Panel **ne proverava račune kod Poreske uprave** — prikazuje ono što aplikacija pošalje. Za proveru pojedinačnog računa
  koristite dugme „Otvori na sajtu Poreske uprave".

### Prikaz i izvoz podataka
- Sve što stiže spolja (nazivi prodavaca, napomene, podaci iz aplikacije) prikazuje se kao običan tekst, nikad kao HTML.
  Stroga politika sadržaja (CSP) dodatno zabranjuje izvršavanje bilo kakvih skripti osim panelovih.
- CSV izvoz je zaštićen od „formula u Excel-u": tekst koji počinje znakom `=`, `+`, `-` ili `@` dobija apostrof ispred,
  pa ga Excel prikazuje kao tekst, a ne izvršava kao formulu.
- Link „Otvori na sajtu Poreske uprave" se prikazuje samo za adrese na `https://suf.purs.gov.rs/`.

### Dnevnici
- **Dnevnik radnji** u panelu beleži prijave, odjave, izmene korisnika i naloga, nove ključeve, izvoze, 2FA, ažuriranja i
  preuzimanja rezervnih kopija.
- Ključevi, lozinke i kodovi se **nikad** ne upisuju u dnevnik radnji ni u log grešaka. Log grešaka
  (`storage/logs/error.log`) sadrži tip greške i mesto u kodu, bez vrednosti argumenata; posetilac vidi samo kratku poruku.

### Instalacija
- Install fajl proverava sopstveni SHA-256 i SHA-256 svakog raspakovanog fajla i odbija fajlove sa sumnjivim imenima
  (`..`, apsolutne putanje).
- Čarobnjak prihvata samo pregledač koji je otvorio install fajl (jednokratni ključ u adresi). Kad instalacija završi,
  install fajl se briše, a čarobnjak je **zaključan** dok postoji `config.php`.
- `config.php` dobija prava 0600 i nasumičan tajni ključ instalacije.

### Ažuriranje
- Ništa se ne preuzima ni ne menja bez potvrde administratora. Pre izmene se pravi rezervna kopija, a greška posle zamene
  fajlova vraća prethodnu verziju automatski (detalji u [azuriranje.md](azuriranje.md)).
- Paket se preuzima isključivo sa zvanične stranice izdanja na GitHub-u i proverava na dva načina: **SHA-256** (da nije
  oštećen) i **digitalni potpis Ed25519** (da ga je zaista objavio održavalac). Paket sa neispravnim potpisom se odbija.
- Potpis može da se proveri samo kad hosting ima PHP ekstenziju **sodium**. Bez nje panel proverava samo SHA-256 i upisuje
  upozorenje u dnevnik radnji — zato tražite od hostinga ekstenziju sodium (stranica Ažuriranje pokazuje da li je provera
  potpisa uključena).

## Šta zavisi od vas i hostinga

Panel radi na zajedničkom (shared) hostingu i deo zaštite zavisi od podešavanja servera. Podaci (`storage/`) i `config.php`
nalaze se u folderu panela, a od pristupa preko veba ih štite `.htaccess` pravila. Zato su sledeće stavke važne.

### Obavezno
1. **HTTPS.** Uključite besplatan SSL sertifikat (Let's Encrypt) i javnu adresu panela držite na `https://`. Bez HTTPS-a
   lozinka i podaci putuju nešifrovano, a aplikacija Računator neće slati račune. Da bi i ko otkuca `http://` bio prebačen
   na HTTPS, u `.htaccess` u korenu panela (to je vaš fajl, ažuriranje ga ne menja), odmah ispod reda `RewriteEngine On`,
   dodajte:
   ```
   RewriteCond %{HTTPS} !=on
   RewriteCond %{HTTP:X-Forwarded-Proto} !=https
   RewriteRule ^ https://%{HTTP_HOST}%{REQUEST_URI} [R=301,L]
   ```
2. **Apache sa `mod_rewrite` i `AllowOverride All`** (ili nginx podešen kao u [instalacija.md](instalacija.md), sa
   document root-om na `public/`). Ako hosting ispred Apache-a ima nginx koji sam služi statične fajlove (keš), zatražite
   od podrške da poštuje `.htaccess` za folder panela.
3. **Proverite da poverljivi fajlovi nisu dostupni.** Posle instalacije u pregledaču otvorite (zamenite domen):
   - `https://vas-domen.rs/panel/config.php` — sme da bude samo prazna stranica, „Forbidden" ili „nije pronađena"; **nikad**
     tekst koji počinje sa `<?php`,
   - `https://vas-domen.rs/panel/storage/` i `https://vas-domen.rs/panel/storage/panel.sqlite` — mora biti „Forbidden" (403),
   - `https://vas-domen.rs/panel/src/version.php` — mora biti „Stranica nije pronađena".

   Ako bilo šta od ovoga preuzme fajl ili prikaže sadržaj, odmah isključite panel (preimenujte folder) i javite se podršci
   hostinga.
4. **Podržana verzija PHP-a.** Panel radi od PHP 8.1, ali PHP 8.1 više ne dobija bezbednosne ispravke. Koristite **PHP 8.3
   ili noviji** (cPanel → „Select PHP Version" / „MultiPHP Manager").
5. **PHP radi pod vašim korisnikom** (PHP-FPM ili suPHP — standard na većini hostinga). Tada drugi nalozi na istom serveru
   ne mogu da čitaju vaše fajlove.

### Preporučeno
- Prava fajlova: folderi 755, fajlovi 644, `config.php` 600, `storage/` 700. **Nikad 777.**
- PHP ekstenzija **sodium** (provera potpisa pri ažuriranju), `display_errors = Off` na produkciji.
- Ako hosting dozvoljava, `open_basedir` ograničen na vaš nalog.
- Install fajl i nedovršeno ažuriranje **ne ostavljajte na serveru**:
  - instalaciju završite odmah posle otpremanja install fajla (dok nije završena, ko otvori install fajl može da je preuzme),
  - posle ručnog ažuriranja proverite da u folderu panela nema `racunator-panel-install.php`; ako ste install fajl nove
    verzije otpremili, a ažuriranje ne želite da potvrdite, obrišite folder `releases/` preko FTP-a.
- Dok panel nije potpuno instaliran, ne objavljujte njegovu adresu.

### Rezervne kopije
- Panel pre svakog ažuriranja sam pravi kopiju u `storage/backups/` i čuva poslednje tri. To **nije** zamena za redovnu
  kopiju: ako hosting izgubi disk, nestaju i one.
- Pravite i **sopstvene redovne kopije van hostinga** (npr. jednom nedeljno): kopija foldera panela (sa `storage/`) i
  izvoz baze iz phpMyAdmin-a. Mnogi hostinzi nude automatske dnevne kopije — uključite ih.
- Kopije sadrže sve račune, heševe lozinki, tajne za 2FA i lozinku baze. Čuvajte ih šifrovane (npr. arhiva sa lozinkom ili
  šifrovan disk), ne šaljite ih e-poštom i ne stavljajte u deljene foldere.
- Povremeno proverite da kopija može da se vrati (npr. na probnom folderu).

### Računari i nalozi u kancelariji
- Uključite 2FA na svim nalozima. Ne delite naloge i lozinke.
- Na zajedničkim računarima se uvek odjavite (Odjava u meniju); ne dozvoljavajte pregledaču da pamti lozinku na takvom računaru.
- Deaktivirajte nalog zaposlenog odmah pri odlasku; povremeno pregledajte listu naloga i dnevnik radnji.
- Ne otvarajte panel preko javnog Wi-Fi-ja bez HTTPS-a.

## Izgubljen telefon (2FA)
- **Zaposleni je izgubio telefon:** panel trenutno nema dugme kojim administrator isključuje tuđu dvofaktorsku prijavu
  (nova lozinka ne isključuje 2FA). Administrator to radi u bazi (ispod), a zaposleni posle prijave odmah ponovo uključi 2FA.
- **Jedini administrator je izgubio telefon:** isto, u bazi.
  - MySQL/MariaDB: phpMyAdmin → baza panela → kartica SQL:
    ```sql
    UPDATE users SET totp_secret = NULL WHERE username = 'korisnicko-ime';
    ```
  - SQLite (baza je `storage/panel.sqlite`): preko SSH-a
    `sqlite3 storage/panel.sqlite "UPDATE users SET totp_secret = NULL WHERE username = 'korisnicko-ime';"`,
    ili zatražite od podrške hostinga da to uradi.

  Ova izmena se ne upisuje u dnevnik radnji — zabeležite je sami. Odmah posle prijave ponovo uključite 2FA.

## Ako sumnjate na provalu
1. Promenite lozinku sopstvenog naloga i administratorskih naloga; svim zaposlenima postavite nove lozinke.
2. Deaktivirajte naloge koje ne prepoznajete (Nalozi) i pregledajte **Dnevnik radnji** (nepoznate prijave, IP adrese,
   novi nalozi, izvozi, preuzimanja rezervnih kopija).
3. Pošto promena lozinke ne zatvara druge otvorene sesije, obrišite sadržaj foldera `storage/tmp/sessions/` preko FTP-a —
   tako se odjavljuju svi, uključujući i napadača. (Ako je folder prazan, hosting ne dozvoljava sopstveni folder za sesije;
   tada zatražite od podrške da obriše PHP sesije za vaš nalog.)
4. Za korisnike za koje sumnjate da im je ključ otkriven, kliknite **Nov ključ** i dajte im novi QR kod.
5. Ako je neko mogao da vidi fajlove na hostingu: promenite lozinku baze (cPanel → MySQL Databases) i upišite novu u
   `config.php`, promenite lozinke za hosting i FTP.
6. Javite se održavaocu (vidi [SECURITY.md](../SECURITY.md)) sa opisom i, ako možete, kopijom `storage/logs/`.

## Za održavaoce
- **Ključ za potpis izdanja** je najvažnija tajna projekta: ko ga ima, može da objavi „ažuriranje" koje sve instalacije
  prihvataju. Postupak je u [izdavanje.md](izdavanje.md). Javni ključ u `src/Services/ReleaseChecker.php` mora biti
  postavljen pre prvog javnog izdanja; privatni ključ je samo u GitHub secret-u `RELEASE_SIGNING_KEY` i u menadžeru lozinki.
- Zaštitite GitHub nalog i repo: 2FA za sve sa pravom pisanja, zaštićeni `main` i tagovi `v*`, pregled pre spajanja.
  Preporuka je da se secret za potpis drži u GitHub *environment*-u sa obaveznim odobrenjem i da su akcije u workflow-ima
  pinovane na tačan commit (SHA), a ne na oznaku (`@v2`).
- U kodu: sav izlaz u šablonima ide kroz `e()`, SQL samo kroz prepared statements, nasumične vrednosti samo iz
  `random_bytes()`, ključevi i lozinke nikad u log ni u dnevnik radnji (vidi [razvoj.md](razvoj.md)).
- JavaScript biblioteka za QR kod (`public/assets/qrcode.min.js`, qrcode-generator 1.4.4) je lokalna i pinovana; poreklo i
  licenca su u `public/assets/LICENSES.md`. Pri zameni biblioteke proverite izvor i zabeležite SHA-256 novog fajla.

## Prijava bezbednosnog propusta
Ne objavljujte propust javno (GitHub issue, forum). Pišite na adresu iz [SECURITY.md](../SECURITY.md) sa opisom, koracima za
ponavljanje i verzijom panela (vidi podnožje stranice u panelu). Odgovaramo u roku od 7 dana.

# Bezbednost Računator panela

Ovaj dokument je za **knjigovođe** koji panel koriste i za **održavaoce** koji ga instaliraju i ažuriraju. Opisuje šta panel
štiti i kako, šta zavisi od vas i vašeg hostinga, i šta da radite ako nešto pođe naopako. Propuste prijavljujte prema
[SECURITY.md](../SECURITY.md) — nikad javno.

## Šta se čuva u panelu
- **Računi** vaših korisnika (firmi i preduzetnika): PFR broj, link za proveru, datum, iznos, prodavac i PIB-ovi.
- **Korisnici**: naziv, napomena, adresa za aplikaciju i **heš** API ključa (sam ključ se ne čuva).
- **Nalozi** knjigovodstva: korisničko ime, heš lozinke, tajna za dvofaktorsku prijavu (šifrovana), uloga.
- **Dnevnik radnji** (ko je šta uradio, kada i sa koje IP adrese).
- **config.php**: lozinka baze i tajni ključ instalacije.
- **Rezervne kopije** (`backups/` u folderu za podatke): kopija svih fajlova (uključujući config.php) i cele baze.

„Folder za podatke" je podrazumevano `storage/` u folderu panela; pri instalaciji može da se izabere folder **van web
root-a** (npr. `/home/nalog/racunator-podaci`), i to je bezbednije. U nastavku `storage/` znači taj folder.

Ko dođe do baze ili rezervne kopije, vidi sve račune vaših korisnika. Zato su rezervne kopije i pristup hostingu jednako
važni kao lozinka za panel.

## Kako panel štiti podatke

### Prijava i lozinke
- Lozinke se čuvaju samo kao heš (Argon2id, a gde ga hosting nema — bcrypt). Panel nikad ne zna ni ne prikazuje vašu lozinku.
- Nova lozinka mora imati najmanje **12 znakova**. Najbolje je fraza od nekoliko reči, npr. „plavi-kofer-sedam-oblaka".
- Ograničenje pokušaja prijave (broje se samo neuspeli pokušaji, uključujući pogrešan kod za 2FA):
  - **5 neuspelih** za isto korisničko ime sa iste IP adrese, ili **30** sa iste adrese za bilo koja imena → blokada 15 minuta;
  - za isto korisničko ime **sa bilo koje adrese** (u poslednja 24 sata) čekanje raste: posle **5** neuspelih 1 minut, posle
    **10** 5 minuta, posle **20** 30 minuta (od poslednjeg neuspeha). To štiti i od pogađanja lozinke sa mnogo adresa;
  - IPv6 adrese se broje po mreži `/64` (jedan priključak obično dobija ceo `/64` blok adresa).
- Poruka „Pogrešno korisničko ime ili lozinka" je ista bez obzira na to da li nalog postoji.
- IP adresa posetioca (za ograničenje i dnevnik) je adresa sa koje je zahtev stigao. Zaglavlja `X-Forwarded-For` i
  `X-Forwarded-Proto` se poštuju **samo** ako zahtev stiže sa pouzdanog proksija navedenog u `config.php` (vidi
  „Proksi i HTTPS u config.php" ispod) — inače bi svako mogao da „promeni" svoju adresu i zaobiđe ograničenje.

### Dvofaktorska prijava (2FA)
- Uključuje se u **Podešavanja → Dvofaktorska prijava**, aplikacijom za autentifikaciju na telefonu (Google Authenticator,
  Microsoft Authenticator, Aegis, 2FAS…). Standard je TOTP: šestocifreni kod koji se menja na 30 sekundi.
- Prijava ide u **dva koraka**: posle korisničkog imena i lozinke nalog sa 2FA uvek dobija stranicu samo za kod (rok
  5 minuta) — i kad lozinka nije tačna. Da li je lozinka bila tačna saznaje se tek posle koda („Pogrešna lozinka ili kod"),
  pa napadač bez telefona ne može da proveri da li je pogodio lozinku.
- Isti kod ne može da se upotrebi dva puta (ni za prijavu, ni za uključivanje ili isključivanje 2FA; kod kojim je 2FA
  uključena se odmah beleži kao iskorišćen). Prihvata se kod za tekućih 30 s i susedne intervale (za telefon čiji sat malo
  kasni ili žuri) — zato sat na telefonu treba da je podešen automatski.
- **Isključivanje** 2FA traži lozinku **i** važeći kod iz aplikacije (posle 5 neuspelih pokušaja sesija se odjavljuje i to
  se beleži u dnevnik). Administrator može drugom nalogu da resetuje 2FA
  (Nalozi → **Resetuj 2FA**, beleži se u dnevnik); sopstvenu 2FA administrator isključuje samo uz kod.
- Tajne za 2FA su u bazi **šifrovane** ključem izvedenim iz tajnog ključa instalacije (`app_key` u `config.php`):
  ko dođe samo do baze (npr. izvoz iz phpMyAdmin-a), ne može da pravi kodove. Ako se `app_key` promeni ili izgubi, nalozi
  sa 2FA ne mogu da se prijave dok administrator ne resetuje njihovu 2FA (jedini administrator — vidi „Izgubljen telefon").
- **Uključite 2FA na svakom nalogu, a obavezno na administratorskim.** Lozinka može da procuri (phishing, ista lozinka na
  drugom sajtu); kod sa telefona ne može.

### Sesija (ostanak prijavljen)
- Posle prijave panel dodeljuje nov identifikator sesije, pa ranije podmetnut kolačić ne važi.
- Kolačić sesije nije dostupan JavaScript-u (HttpOnly), ne šalje se sa tuđih sajtova pri slanju formi (SameSite=Lax) i na
  HTTPS-u se šalje samo šifrovano (Secure).
- Sesija ističe posle **2 sata neaktivnosti** ili **12 sati od prijave**, kao i odmah posle odjave ili deaktivacije naloga.
- Fajlovi sesija su u `storage/tmp/sessions/`, a ne u zajedničkom `/tmp` folderu hostinga.
- **Promena lozinke, nova lozinka koju postavi administrator, uključivanje/isključivanje/reset 2FA i deaktivacija**
  odjavljuju sve ostale sesije tog naloga (na svim računarima i telefonima). Ko je promenu napravio ostaje prijavljen, uz
  nov identifikator sesije.
- **Podešavanja → Moj nalog → Odjavi me sa svih uređaja** odjavljuje sve ostale sesije vašeg naloga, bez promene lozinke.

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
| Sopstvena lozinka, 2FA i odjava sa svih uređaja | da | da |
| Nalozi (dodavanje, deaktivacija, nova lozinka, reset 2FA drugom nalogu) | ne | da |
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
- Ograničenja: 60 zahteva u minutu po ključu, najviše 200 računa i 1 MB po zahtevu (veće telo se odbija pre čitanja u
  memoriju), i najviše **2000 prihvaćenih računa dnevno** po korisniku (podešavanje `api_daily_limit` u tabeli `settings`;
  `0` = bez dnevnog limita). Kad korisnik pređe pola dnevnog limita i kad ga dostigne, to se jednom dnevno upisuje u
  **Dnevnik radnji** — neobično mnogo računa može da znači da je ključ procureo. Svaki račun se proverava (oblik PFR
  broja, link mora biti na `https://suf.purs.gov.rs/`, datum, iznos, PIB od 9 cifara); ostalo se odbija.
- Panel **ne proverava račune kod Poreske uprave** — prikazuje ono što aplikacija pošalje. Za proveru pojedinačnog računa
  koristite dugme „Otvori na sajtu Poreske uprave".

### Prikaz i izvoz podataka
- Sve što stiže spolja (nazivi prodavaca, napomene, podaci iz aplikacije) prikazuje se kao običan tekst, nikad kao HTML.
  Stroga politika sadržaja (CSP) dodatno zabranjuje izvršavanje bilo kakvih skripti osim panelovih.
- CSV izvoz je zaštićen od „formula u Excel-u": tekst koji počinje znakom `=`, `+`, `-` ili `@` dobija apostrof ispred,
  pa ga Excel prikazuje kao tekst, a ne izvršava kao formulu.
- Link „Otvori na sajtu Poreske uprave" se prikazuje samo za adrese na `https://suf.purs.gov.rs/`.

### Verzija panela
- Tačnu verziju vide samo prijavljeni (podnožje stranice, Podešavanja). Provera rada `/health` neprijavljenima vraća samo
  `{"ok":true}`; verziju dobija prijavljen administrator ili samoprovera instalacije i ažuriranja (tajno zaglavlje izvedeno
  iz `app_key`). Tako nepoznati ne mogu lako da otkriju da li panel ima neku poznatu, a još neispravljenu slabost.

### Dnevnici
- **Dnevnik radnji** u panelu beleži prijave, odjave, izmene korisnika i naloga, nove ključeve, izvoze, 2FA (uključivanje,
  isključivanje, reset od strane administratora), odjave sa svih uređaja, dnevni limit API-ja, ažuriranja i preuzimanja
  rezervnih kopija.
- Ključevi, lozinke i kodovi se **nikad** ne upisuju u dnevnik radnji ni u log grešaka. Log grešaka
  (`storage/logs/error.log`) sadrži tip greške i mesto u kodu, bez vrednosti argumenata; posetilac vidi samo kratku poruku.

### Instalacija
- Install fajl proverava sopstveni SHA-256 i SHA-256 svakog raspakovanog fajla i odbija fajlove sa sumnjivim imenima
  (`..`, apsolutne putanje) i fajlove kojih nema u manifestu.
- Install fajl izdaje **samo jedan** ključ za čarobnjak — pregledaču koji ga je prvi otvorio. Svako sledeće otvaranje
  install fajla pre kraja instalacije dobija odgovor „Instalacija je već započeta" (409) i **ne** dobija ključ.
- Čarobnjak bez tog ključa (drugi pregledač, istekla sesija, ručno otpremljeni fajlovi bez install fajla) traži **dokaz
  vlasništva**: prikaže ime fajla `install/DOZVOLA-<nasumičan niz>.txt` koji treba napraviti preko FTP-a ili File
  Manager-a; tek tada nastavlja (fajl se sam briše). Sa SSH pristupom isto daje `php install/cli.php dozvola`.
  Bez pristupa fajlovima hostinga niko ne može da „preuzme" nedovršenu instalaciju.
- Čarobnjak odbija svaki rad kad se pokrene iz kopije programa (raspakovan paket u `releases/` ili prethodna verzija u
  `_old/`); ti folderi dobijaju i sopstvenu zabranu pristupa preko veba.
- Provera hostinga u čarobnjaku upiše probne fajlove u `storage/` (kao baza, rezervna kopija i dnevnik) i pokuša da ih
  preuzme preko javne adrese. Ako uspe, stavka je **crvena** i instalacija ne može dalje dok se ne izabere folder za
  podatke van web root-a ili hosting ne počne da poštuje `.htaccess`.
- Kad instalacija završi, install fajl se briše, a čarobnjak je **zaključan** dok postoji `config.php`.
- `config.php` dobija prava 0600 i nasumičan tajni ključ instalacije (`app_key`).

### Ažuriranje
- Ništa se ne preuzima ni ne menja bez potvrde administratora. Pre izmene se pravi rezervna kopija, a greška posle zamene
  fajlova vraća prethodnu verziju automatski (detalji u [azuriranje.md](azuriranje.md)).
- Paket se preuzima isključivo sa zvanične stranice izdanja na GitHub-u i proverava na dva načina: **SHA-256** (da nije
  oštećen) i **digitalni potpis Ed25519** (da ga je zaista objavio održavalac). Paket sa neispravnim potpisom se odbija.
- Potpis može da se proveri samo kad hosting ima PHP ekstenziju **sodium**. **Bez nje se ažuriranje iz panela (preko
  mreže) odbija** — ništa se ne preuzima ni ne menja. Ostaje ručno ažuriranje install fajlom
  ([azuriranje.md](azuriranje.md)): panel pre potvrde prikaže SHA-256 install fajla, administrator ga uporedi sa
  `SHA256SUMS` na zvaničnoj stranici izdanja i izričito potvrdi („Uporedio sam SHA-256"); to se upisuje u dnevnik radnji.
  Bolje je zatražiti od hostinga ekstenziju sodium (deo standardnog PHP-a).
- Install fajl za ručno ažuriranje nosi potpis paketa. Install fajl bez potpisa se uvek odbija, a gde sodium postoji,
  odbija se i install fajl sa neispravnim ili tuđim potpisom. Paket koji čeka potvrdu (`releases/`, `storage/tmp/manual-update*`) nije dostupan preko veba, a dugme
  „Odbaci paket" u `install/` ga briše.
- Paket se proverava i raspakuje iz istih bajtova (iz privatnog privremenog fajla), pa ne može da se podmetne između
  provere i raspakivanja. Fajl kojeg nema u manifestu paketa se odbija.

## Šta zavisi od vas i hostinga

Panel radi na zajedničkom (shared) hostingu i deo zaštite zavisi od podešavanja servera. `config.php` je u folderu panela,
a od pristupa preko veba ga štite `.htaccess` pravila (i to što je PHP fajl). Folder za podatke je takođe u folderu panela,
osim ako pri instalaciji niste izabrali folder van web root-a — preporučujemo to kad hosting dozvoljava. Zato su sledeće
stavke važne.

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
   Isto može i sam panel: u `config.php` dodajte `'force_https' => true,` (vidi ispod). `.htaccess` pravilo je i dalje
   bolje, jer preusmerava i pre nego što se PHP pokrene.
2. **Apache sa `mod_rewrite` i `AllowOverride All`** (ili nginx podešen kao u [instalacija.md](instalacija.md), sa
   document root-om na `public/`). Ako hosting ispred Apache-a ima nginx koji sam služi statične fajlove (keš), zatražite
   od podrške da poštuje `.htaccess` za folder panela.
3. **Proverite da poverljivi fajlovi nisu dostupni.** Posle instalacije u pregledaču otvorite (zamenite domen):
   - `https://vas-domen.rs/panel/config.php` — sme da bude samo prazna stranica, „Forbidden" ili „nije pronađena"; **nikad**
     tekst koji počinje sa `<?php`,
   - `https://vas-domen.rs/panel/storage/` i `https://vas-domen.rs/panel/storage/panel.sqlite` — mora biti „Forbidden" (403)
     (ako je folder za podatke van web root-a, ove adrese daju „nije pronađena"),
   - `https://vas-domen.rs/panel/releases/` i `https://vas-domen.rs/panel/_old/` (ako postoje) — „Forbidden" ili „nije pronađena",
   - `https://vas-domen.rs/panel/src/version.php` — mora biti „Stranica nije pronađena".

   Čarobnjak ovo za `storage/` proverava sam pri instalaciji, ali hosting može kasnije da promeni podešavanja. Ako bilo šta
   od ovoga preuzme fajl ili prikaže sadržaj, odmah isključite panel (preimenujte folder) i javite se podršci hostinga.
4. **Podržana verzija PHP-a.** Panel radi od PHP 8.1, ali PHP 8.1 više ne dobija bezbednosne ispravke. Koristite **PHP 8.3
   ili noviji** (cPanel → „Select PHP Version" / „MultiPHP Manager").
5. **PHP radi pod vašim korisnikom** (PHP-FPM ili suPHP — standard na većini hostinga). Tada drugi nalozi na istom serveru
   ne mogu da čitaju vaše fajlove.

### Proksi i HTTPS u config.php
Dve opcione stavke u `config.php` (fajl je vaš; ažuriranje ga ne menja):
```php
'trusted_proxies' => ['10.0.0.0/8'],   // adrese proksija/balansera ispred panela (CIDR), podrazumevano nijedan
'force_https'     => true,             // panel sam preusmerava http:// na https:// (osim /health), podrazumevano false
```
- `trusted_proxies` popunite **samo** ako ispred panela stoji proksi (Cloudflare, balanser, nginx ispred Apache-a) i ako
  znate njegove adrese (pitajte podršku hostinga). Tada panel pravu adresu posetioca uzima iz `X-Forwarded-For`, a HTTPS
  prepoznaje iz `X-Forwarded-Proto` — ali samo za zahteve koji zaista dolaze sa tih adresa. Bez ove stavke panel ta
  zaglavlja ignoriše (posetilac bi inače mogao da ih podmetne). Simptom da vam treba: u Dnevniku radnji sve prijave imaju
  istu IP adresu (adresu proksija).
- `force_https` uključite ako ne možete da dodate `.htaccess` pravilo iz tačke 1. Ako je ispred panela proksi koji
  završava HTTPS, podesite i `trusted_proxies`, inače bi panel preusmeravao u krug.

### Preporučeno
- Prava fajlova: folderi 755, fajlovi 644, `config.php` 600, `storage/` 700. **Nikad 777.**
- PHP ekstenzija **sodium** (bez nje nema ažuriranja iz panela, samo ručnog), `display_errors = Off` na produkciji.
- Ako hosting dozvoljava, `open_basedir` ograničen na vaš nalog.
- Install fajl i nedovršeno ažuriranje **ne ostavljajte na serveru**:
  - instalaciju završite odmah posle otpremanja install fajla i u istom pregledaču (drugi pregledač traži dokaz
    vlasništva fajlom `install/DOZVOLA-….txt`),
  - posle ručnog ažuriranja proverite da u folderu panela nema `racunator-panel-install.php`; ako ste install fajl nove
    verzije otpremili, a ažuriranje ne želite da potvrdite, u `install/` kliknite „Odbaci paket" (ili preko FTP-a obrišite
    `releases/` i `storage/tmp/manual-update*`).
- Dok panel nije potpuno instaliran, ne objavljujte njegovu adresu.

### Rezervne kopije
- Panel pre svakog ažuriranja sam pravi kopiju u `storage/backups/` i čuva poslednje tri. Ime svake kopije ima nasumičan
  dodatak (npr. `20261029-120000-1-<16 znakova>`), pa adresa kopije ne može da se pogodi po vremenu ažuriranja; folder
  kopije ima prava 0700. To **nije** zamena za redovnu kopiju: ako hosting izgubi disk, nestaju i one.
- Pravite i **sopstvene redovne kopije van hostinga** (npr. jednom nedeljno): kopija foldera panela (sa `storage/`) i
  izvoz baze iz phpMyAdmin-a. Mnogi hostinzi nude automatske dnevne kopije — uključite ih.
- Kopije sadrže sve račune, heševe lozinki, tajne za 2FA (šifrovane, ali je ključ — `config.php` — u istoj kopiji) i
  lozinku baze. Čuvajte ih šifrovane (npr. arhiva sa lozinkom ili
  šifrovan disk), ne šaljite ih e-poštom i ne stavljajte u deljene foldere.
- Povremeno proverite da kopija može da se vrati (npr. na probnom folderu).

### Računari i nalozi u kancelariji
- Uključite 2FA na svim nalozima. Ne delite naloge i lozinke.
- Na zajedničkim računarima se uvek odjavite (Odjava u meniju); ne dozvoljavajte pregledaču da pamti lozinku na takvom računaru.
- Deaktivirajte nalog zaposlenog odmah pri odlasku; povremeno pregledajte listu naloga i dnevnik radnji.
- Ne otvarajte panel preko javnog Wi-Fi-ja bez HTTPS-a.

## Izgubljen telefon (2FA)
- **Zaposleni (ili drugi administrator) je izgubio telefon:** administrator u **Nalozi** klikne **Resetuj 2FA** kod tog
  naloga. Dvofaktorska prijava se isključuje, sve sesije tog naloga se odjavljuju, a radnja se beleži u dnevnik. Zaposleni
  se prijavi lozinkom i odmah ponovo uključi 2FA. (Nova lozinka sama po sebi **ne** isključuje 2FA.)
- **Jedini administrator je izgubio telefon:** **Resetuj 2FA** ne radi za sopstveni nalog, a sopstvena 2FA se isključuje
  samo uz kod. Najjednostavnije je da imate **dva administratorska naloga** (npr. rezervni, sa lozinkom u sefu) — tada drugi
  administrator klikne **Resetuj 2FA**. Ako drugog administratora nema, to se radi u bazi:
  - MySQL/MariaDB: phpMyAdmin → baza panela → kartica SQL:
    ```sql
    UPDATE users SET totp_secret = NULL WHERE username = 'korisnicko-ime';
    ```
  - SQLite (baza je `storage/panel.sqlite`): preko SSH-a
    `sqlite3 storage/panel.sqlite "UPDATE users SET totp_secret = NULL WHERE username = 'korisnicko-ime';"`,
    ili zatražite od podrške hostinga da to uradi.

  (Tajna u bazi je šifrovana, ali `NULL` jednostavno isključuje 2FA; isti postupak važi i ako je `app_key` izgubljen.)
  Ova izmena se ne upisuje u dnevnik radnji i ne odjavljuje druge sesije tog naloga — zabeležite je sami i posle prijave
  kliknite **Odjavi me sa svih uređaja**, pa odmah ponovo uključite 2FA.

## Ako sumnjate na provalu
1. Promenite lozinku sopstvenog naloga i administratorskih naloga; svim zaposlenima postavite nove lozinke.
2. Deaktivirajte naloge koje ne prepoznajete (Nalozi) i pregledajte **Dnevnik radnji** (nepoznate prijave, IP adrese,
   novi nalozi, izvozi, preuzimanja rezervnih kopija).
3. Promena lozinke (i nova lozinka koju postavi administrator) odjavljuje sve ostale sesije tog naloga, pa je napadač
   prijavljen tim nalogom odmah odjavljen. Bez promene lozinke isto radi **Podešavanja → Odjavi me sa svih uređaja**.
   Za potpuno čišćenje (svi nalozi odjednom) možete i da obrišete sadržaj foldera `tmp/sessions/` u folderu za podatke
   preko FTP-a.
4. Za korisnike za koje sumnjate da im je ključ otkriven, kliknite **Nov ključ** i dajte im novi QR kod.
5. Ako je neko mogao da vidi fajlove na hostingu: promenite lozinku baze (cPanel → MySQL Databases) i upišite novu u
   `config.php`, promenite lozinke za hosting i FTP.
6. Javite se održavaocu (vidi [SECURITY.md](../SECURITY.md)) sa opisom i, ako možete, kopijom `storage/logs/`.

## Za održavaoce
- **Ključ za potpis izdanja** je najvažnija tajna projekta: ko ga ima, može da objavi „ažuriranje" koje sve instalacije
  prihvataju. Postupak je u [izdavanje.md](izdavanje.md). Javni ključ je u `src/Services/ReleaseChecker.php`; privatni
  ključ je samo u secret-u `RELEASE_SIGNING_KEY` u GitHub **environment-u `release`** (sa obaveznim odobrenjem
  održavaoca) i u menadžeru lozinki.
- Workflow izdanja (`.github/workflows/release.yml`) je podeljen na izolovane poslove: testovi i build rade bez tajni i
  bez prava pisanja; potpisivanje je zaseban posao koji jedini vidi ključ, čeka odobrenje u environment-u `release` i ne
  pokreće Composer ni zavisnosti; tek poslednji posao ima pravo da napravi GitHub Release. Sve akcije su pinovane na tačan
  commit (SHA). Pri izmeni workflow-a zadržite ta pravila.
- Zaštitite GitHub nalog i repo: 2FA za sve sa pravom pisanja, zaštićeni `main` i tagovi `v*`, pregled pre spajanja, a
  među odobravaocima environment-a `release` samo održavaoci.
- U kodu: sav izlaz u šablonima ide kroz `e()`, SQL samo kroz prepared statements, nasumične vrednosti samo iz
  `random_bytes()`, ključevi i lozinke nikad u log ni u dnevnik radnji (vidi [razvoj.md](razvoj.md)).
- JavaScript biblioteka za QR kod (`public/assets/qrcode.min.js`, qrcode-generator 1.4.4) je lokalna i pinovana; poreklo i
  licenca su u `public/assets/LICENSES.md`. Pri zameni biblioteke proverite izvor i zabeležite SHA-256 novog fajla.

## Prijava bezbednosnog propusta
Ne objavljujte propust javno (GitHub issue, forum). Pišite na adresu iz [SECURITY.md](../SECURITY.md) sa opisom, koracima za
ponavljanje i verzijom panela (vidi podnožje stranice posle prijave ili Podešavanja). Odgovaramo u roku od 7 dana.

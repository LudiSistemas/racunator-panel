# Ažuriranje Računator panela

Panel se **nikad ne ažurira sam**. Proverava da li postoji nova verzija i prikazuje šta je u njoj novo, a ažuriranje
počinje tek kad ga administrator potvrdi. Pre svake izmene pravi se rezervna kopija, a ako bilo šta pođe naopako,
prethodna verzija se vraća automatski.

## Provera nove verzije
Stranica **Ažuriranje** (samo administrator) jednom u 12 sati pita GitHub (`api.github.com`, izdanja repoa
`LudiSistemas/racunator-panel`) koja je poslednja verzija. Šalje se samo običan zahtev sa zaglavljem
`User-Agent: racunator-panel/<verzija>` — nikakvi podaci o vama, klijentima ni računima. Dugme **Proveri sada** pita odmah
(najviše jednom u minutu).

Kad postoji novija verzija, vidite njen broj i **ceo opis izmena** (changelog) — kao običan tekst.

## Potvrda
Dugme **Ažuriraj na X.Y.Z** vodi na stranicu potvrde, koja kaže:
- šta se menja (fajlovi programa) i šta ostaje netaknuto (config.php, baza, korisnici, ključevi, nalozi, podešavanja,
  vaš `.htaccess`, `storage/`),
- da će pre svega biti napravljena rezervna kopija,
- koliko traje (obično manje od minuta).

Tek kad štiklirate „Razumem" i kliknete **Potvrđujem**, panel bilo šta preuzima. Do tada se ništa ne skida i ne menja.

## Šta se dešava posle potvrde
1. **Preduslovi** — prava pisanja u folder panela i `storage/`, slobodan prostor (bar 3× veličina paketa), ekstenzija `zip`,
   odlazni HTTP pozivi. Ako nešto ne valja, ažuriranje se ne pokreće i ništa nije promenjeno.
2. **Režim održavanja** (`storage/maintenance.flag`) — aplikacija Računator dobija odgovor „pokušaj kasnije" (503) i sama
   ponavlja slanje. Stranice za prijavu i Ažuriranje rade i dalje.
3. **Rezervna kopija** u `storage/backups/<datum-vreme>/`: `fajlovi.zip` (svi fajlovi panela, uključujući config.php) i baza —
   `baza.sql` za MySQL/MariaDB (SQL skripta po tabelama panela) ili `baza.sqlite` za SQLite.
4. **Preuzimanje** paketa `racunator-panel-X.Y.Z.zip` u `storage/tmp/`, sa zvanične adrese izdanja na GitHub-u.
5. **Provera**: SHA-256 iz `SHA256SUMS` i **digitalni potpis** (Ed25519, `.sig`). Panel odbija paket čiji potpis nije ispravan.
   Ako hosting nema PHP ekstenziju `sodium`, potpis ne može da se proveri — tada se proverava samo SHA-256, a upozorenje se
   upisuje u dnevnik radnji.
6. **Raspakivanje** u `releases/X.Y.Z/` i provera SHA-256 svakog fajla iz `MANIFEST.sha256`.
7. **Zamena**: direktorijumi `public/`, `src/`, `install/`, `docs/` se preimenovanjem premeštaju u `_old/`, a novi dolaze na
   njihovo mesto. Preimenovanje je trenutno, pa nema trenutka u kom je panel „pola star, pola nov".
8. **Migracije baze** (samo dodavanje, nikad brisanje podataka) i upis nove verzije.
9. **Samoprovera**: panel pozove sopstveni `/health` i proveri da odgovara novom verzijom.
10. **Kraj održavanja**, brisanje privremenih fajlova; čuvaju se poslednje tri rezervne kopije.

Svaki korak se upisuje u `storage/logs/update.log` i prikazuje na stranici Ažuriranje.

### Automatski povratak
Ako padne bilo koji korak posle zamene fajlova (migracija, samoprovera…), panel sam:
- vraća stare direktorijume iz `_old/` na mesto,
- vraća bazu iz upravo napravljene kopije (ako su migracije krenule),
- isključuje režim održavanja i prikazuje šta se desilo.

Ako proces bude prekinut usred posla (npr. hosting ubije PHP zbog vremenskog ograničenja), panel pri gašenju pokušava isti
povratak. Ako i to ne uspe, stranica Ažuriranje pokazuje da je panel ostao u režimu održavanja i nudi da ga isključite
posle provere (vidi „Ručni povratak").

## Vraćanje prethodne verzije
U listi **Rezervne kopije** svaka kopija ima dugme **Vrati ovu verziju**. Stranica potvrde jasno upozorava:
**vraćaju se i fajlovi i baza**, pa se gubi sve što je stiglo posle te kopije (računi, novi korisnici, ključevi, nalozi).
Zato pre povratka izvezite nove račune u CSV (Korisnici → računi za mesec → Izvoz CSV); aplikacija Računator može kasnije
ponovo da pošalje račune koji nedostaju.

Kopije možete i **preuzeti** (fajlovi ili baza). Sadrže sve podatke panela, uključujući heševe lozinki — čuvajte ih na sigurnom.

## Ručno ažuriranje (kad hosting ne dozvoljava preuzimanje iz panela)
1. Preuzmite `racunator-panel-install.php` nove verzije sa stranice izdanja.
2. Otpremite ga preko FTP-a u folder panela (pored `config.php`) i otvorite u pregledaču.
3. Install fajl prepoznaje postojeću instalaciju: **ne dira je**, nego raspakuje novu verziju u `releases/X.Y.Z/`, proveri
   svaki fajl i obriše sebe.
4. Otvara se `install/` — prijavite se kao administrator (ista lozinka i dvofaktorski kod kao u panelu), pročitajte izmene
   i potvrdite. Ažuriranje teče isto kao iz panela (rezervna kopija, zamena, migracije, samoprovera, automatski povratak),
   samo bez preuzimanja sa mreže.

Sa SSH pristupom: `php racunator-panel-install.php`, pa `php install/cli.php azuriraj --paket=releases/X.Y.Z`.
Ostale komande: `php install/cli.php kopija`, `kopije`, `vrati --kopija=<ime>`.

## Ručni povratak (kad ni panel ni install/ ne rade)
1. Preko FTP-a obrišite (ili preimenujte) `public/`, `src/`, `install/`, `docs/` i premestite iste direktorijume iz `_old/`
   u koren panela.
2. Ako treba vratiti i bazu: u `storage/backups/<datum>/` je `baza.sql` (uvezite ga u phpMyAdmin → „Import") ili
   `baza.sqlite` (kopirajte ga preko `storage/panel.sqlite`).
3. Obrišite `storage/maintenance.flag` ako postoji.

## Ograničenja i napomene
- Potrebna su prava pisanja u folder panela (PHP mora moći da preimenuje `public/`, `src/`, `install/`, `docs/`). Ako su
  fajlovi otpremljeni preko FTP-a pod drugim korisnikom nego što PHP radi, panel to kaže **pre** početka i nudi ručni put.
- Na nekim hostinzima PHP keš (OPcache) zadrži stari kod nekoliko sekundi; panel ga briše posle zamene kad hosting to dozvoljava.
- Dugotrajno ažuriranje (vrlo velika baza) može da udari u vremensko ograničenje hostinga; tada pomaže automatski povratak, a
  ažuriranje možete ponoviti noću ili preko SSH-a.
- Verzija se nikad ne vraća „unazad" ažuriranjem — za to služi „Vrati prethodnu verziju".

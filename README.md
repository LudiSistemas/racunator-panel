# Računator panel

Mali panel za knjigovođe koji prima račune iz aplikacije **Računator** (Android i iPhone). Radi na običnom shared hostingu
(PHP 8.1+, Apache, MySQL/MariaDB ili SQLite), instalira se iz jednog fajla i ažurira se samo na zahtev administratora.

- Sajt aplikacije: <https://sistema.sh>
- Stranica za knjigovođe (šta panel radi, zahtevi, instalacija): <https://sistema.sh/knjigovodje>
- Uslovi korišćenja: <https://sistema.sh/uslovi>
- Izdanja (install fajl): <https://github.com/LudiSistemas/racunator-panel/releases/latest>

Izmene po verzijama: [CHANGELOG.md](CHANGELOG.md); verzija instaliranog panela piše u podnožju panela posle prijave.

## Šta radi
- Knjigovođa dodaje korisnika (firmu ili preduzetnika) i dobija QR kod. Korisnik ga skenira u Računatoru i od tada aplikacija šalje
  knjigovođi poslovne račune sa izabranim PIB-ovima: link računa na portalu Poreske uprave (suf.purs.gov.rs) i osnovne podatke
  (PFR broj, datum i vreme, iznos, prodavac, PIB prodavca i kupca, vrsta računa, brojač računa sa nastavkom).
- Svaki korisnik ima svoj nasumični endpoint i svoj API ključ — podaci su razdvojeni po korisniku.
- Računi se pregledaju po korisniku i mesecu i otvaraju na sajtu Poreske uprave; izvoz u CSV (otvara se direktno u Excel-u).
- Računi koje korisnik unese po PFR broju (bez QR koda) imaju oznaku „PFR broj" i blok „Podaci za proveru" za zvaničnu
  stranicu provere; kad aplikacija kasnije dobije pravi link računa, panel sam dopuni zapis.
- Više naloga za zaposlene (administrator i zaposleni), dnevnik radnji, dvofaktorska prijava.
- API za knjigovođu (samo čitanje, `/api/<slug>/v1`): knjigovodstveni program čita korisnike i račune i izvozi mesec u
  CSV ili JSON. Isključen je dok ga administrator ne uključi u Podešavanjima.
- Ažuriranje iz panela: prikaz izmena u novoj verziji, potvrda, provera digitalnog potpisa, rezervna kopija i povratak na
  prethodnu verziju.

## Zahtevi
PHP 8.1 ili noviji (preporučeno 8.3+, jer 8.1 više ne dobija bezbednosne ispravke), ekstenzije pdo + pdo_mysql/pdo_sqlite,
openssl, mbstring, json, session (za ažuriranje iz panela i zip, sodium, curl), **Apache** sa `mod_rewrite` i
`AllowOverride All` i **HTTPS**. Drugi web serveri (nginx) i document root na `public/` rade samo uz ručno podešavanje i
instalaciju iz komandne linije — vidi kraj [docs/instalacija.md](docs/instalacija.md).

## Instalacija (kratko)
1. Preuzmite `racunator-panel-install.php` sa stranice izdanja.
2. Otpremite ga u nov, prazan folder na hostingu (npr. `public_html/panel/`) i otvorite u pregledaču.
3. Pratite čarobnjak: provera hostinga, baza, javna adresa, uslovi korišćenja, prvi nalog.

Detalji: [docs/instalacija.md](docs/instalacija.md).

## Dokumentacija
| Dokument | Za koga |
|---|---|
| [docs/instalacija.md](docs/instalacija.md) | instalacija korak po korak, tipični problemi na hostingu, CLI |
| [docs/panel.md](docs/panel.md) | svakodnevni rad u panelu (korisnici, QR, računi, CSV, nalozi, 2FA, API) |
| [docs/azuriranje.md](docs/azuriranje.md) | ažuriranje iz panela i ručno, rezervne kopije, povratak, migracije |
| [docs/bezbednost.md](docs/bezbednost.md) | šta panel štiti i šta zavisi od vas i hostinga |
| [docs/api.md](docs/api.md), [docs/openapi.yaml](docs/openapi.yaml) | API za aplikaciju (`/u/<slug>`) i API za knjigovođu (`/api/<slug>/v1`) |
| [docs/uslovi-koriscenja.md](docs/uslovi-koriscenja.md) | uslovi korišćenja (isti tekst kao na sajtu i u aplikaciji) |
| [docs/izdavanje.md](docs/izdavanje.md), [docs/razvoj.md](docs/razvoj.md), [docs/arhitektura.md](docs/arhitektura.md) | održavaoci i programeri |

## Uslovi korišćenja
Panel se daje „kakav jeste“, bez garancija; račune klijenata proveravate na zvaničnom portalu Poreske uprave. Pri instalaciji
uslove treba prihvatiti (u čarobnjaku ili `--prihvatam-uslove` u CLI-ju), a kad se uslovi promene, administrator ih ponovo
prihvata posle ažuriranja: [docs/uslovi-koriscenja.md](docs/uslovi-koriscenja.md) (i na <https://sistema.sh/uslovi>).

## Bezbednost
Propuste prijavljujte privatno, prema [SECURITY.md](SECURITY.md) — nikad javno.

## Razvoj
Zahteva PHP 8.1+ i Composer (samo za testove): `composer install && composer test`. Bez lokalnog PHP-a — Docker omotači
`tools/dev/php` i `tools/dev/composer`. Vidi [docs/razvoj.md](docs/razvoj.md).

## Licenca
MIT — vidi [LICENSE](LICENSE).

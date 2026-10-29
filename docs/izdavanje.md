# Izdavanje nove verzije (za održavaoca)

## Jednom: ključ za potpis izdanja
Paketi se potpisuju Ed25519 ključem; panel ima javni ključ u kodu i odbija paket sa pogrešnim potpisom.
1. `php tools/generate-signing-key.php` (potrebna ekstenzija sodium) ispisuje par ključeva.
   Bezbednije: `php tools/generate-signing-key.php --out ~/racunator-signing.key` upisuje privatni ključ samo u fajl (0600) i na ekranu prikazuje samo javni, pa ga postavite sa `gh secret set RELEASE_SIGNING_KEY --env release < ~/racunator-signing.key` (vidi korak 3) i fajl obrišite.
2. Javni ključ upišite u `src/Services/ReleaseChecker.php` → konstanta `PUBLIC_KEY` (umesto `ZAMENITI-JAVNIM-KLJUCEM`).
3. Privatni ključ upišite u GitHub kao **secret environment-a `release`** (ne kao repo secret): repo → *Settings →
   Environments → New environment* → ime **`release`** → *Environment secrets → Add secret*, ime **`RELEASE_SIGNING_KEY`**.
   Sačuvajte ga i u menadžeru lozinki. (Komandom: `gh secret set RELEASE_SIGNING_KEY --env release < ~/racunator-signing.key`.)
   Ako je ranije postavljen kao repo secret, obrišite ga odatle (`gh secret delete RELEASE_SIGNING_KEY`).
4. Nikad ne komitujte privatni ključ. Ako procuri: napravite nov par, objavite verziju sa novim javnim ključem potpisanu
   **starim** ključem (da je postojeće instalacije prihvate), pa sledeće verzije potpisujte novim.

Dok je `PUBLIC_KEY` placeholder, workflow izdanja odbija da objavi (instalacije ne bi mogle da provere potpis).

## Jednom: zaštita u GitHub-u (ručno podešavanje)
Workflow je bezbedan samo ako niko ne može da pokrene izdanje mimo održavaoca:
1. **Environment `release` sa obaveznim odobrenjem**: *Settings → Environments → release* → *Deployment protection rules* →
   uključite **Required reviewers** i dodajte održavaoca (sebe); po želji *Prevent self-review* ako ima više održavalaca.
   U *Deployment branches and tags* izaberite *Selected branches and tags* i dodajte pravilo za tagove **`v*`**. Posao
   „sign" tada čeka da ga odobrite u kartici *Actions* i tek tada dobija `RELEASE_SIGNING_KEY`.
2. **Zaštita tagova `v*`**: *Settings → Rules → Rulesets → New ruleset → New tag ruleset*: ime „Izdanja", *Enforcement:
   Active*, *Target tags → Add target → Include by pattern* `v*`; uključite **Restrict creations**, **Restrict updates** i
   **Restrict deletions**, a u *Bypass list* dodajte samo održavaoca (ili ulogu *Repository admin*). Tako tag izdanja može da
   napravi samo održavalac i postojeći tag ne može da se pomeri na drugi commit.
3. **Zaštita grane `main`**: ruleset za granu `main` sa *Require a pull request before merging* i *Block force pushes*;
   2FA obavezan za sve sa pravom pisanja (*Settings → Collaborators* / organizacija).
4. **Akcije**: *Settings → Actions → General* → *Allow select actions*: dozvolite samo akcije sa GitHub-a (`actions/*`) i
   `shivammathur/setup-php`; *Workflow permissions* → **Read repository contents** (podrazumevani token samo za čitanje —
   `release.yml` izričito traži `contents: write` samo u poslednjem poslu).

Akcije u workflow-ima su pinovane na pun SHA commit-a (komentar pored pokazuje verziju). Pri nadogradnji uzmite novi SHA:
`gh api repos/actions/checkout/git/ref/tags/v4 --jq .object.sha` (ako je `.object.type` „tag", još jednom
`gh api repos/<repo>/git/tags/<sha> --jq .object.sha`). Isto za sliku `php:8.3-cli` u `release.yml`:
`docker buildx imagetools inspect php:8.3-cli` → `Digest:`.

## Svako izdanje
1. Verzija: u `src/version.php` upišite novu verziju (`X.Y.Z`, semver; za probna izdanja `X.Y.Z-rc.1`).
2. `CHANGELOG.md`: dodajte odeljak `## X.Y.Z` (ili `## X.Y.Z — datum`) sa izmenama na srpskom. Taj odeljak postaje telo
   GitHub Release-a i knjigovođe ga vide u panelu pre potvrde ažuriranja — pišite razumljivo i navedite ako nešto traži pažnju.
3. Ako ima izmena baze: nova migracija `src/Db/migrations/NNNN_naziv.php` — samo dodavanje (nove tabele/kolone), idempotentno
   (`IF NOT EXISTS`), nikad brisanje podataka. Povratak na staru verziju vraća bazu iz rezervne kopije.
4. Lokalna provera: `composer test`, pa `php tools/build-release.php` (pravi `build/`) i po želji Docker test:
   `tools/docker/test-install.sh` (Apache + MariaDB; sam gasi kontejnere na kraju).
5. Commit, pa tag: `git tag vX.Y.Z && git push origin vX.Y.Z`.

## Šta radi workflow `.github/workflows/release.yml` (tag `v*`)
Četiri odvojena posla; tajnu vidi samo jedan, pravo pisanja ima samo poslednji:
1. **test** (`contents: read`, bez tajni): tag mora da odgovara `src/version.php` (`v` + verzija), `PUBLIC_KEY` mora biti
   postavljen, `composer install` i PHPUnit. Composer zavisnosti i `setup-php` postoje samo ovde.
2. **build** (`contents: read`, bez tajni, bez Composer-a i tuđih akcija; PHP iz Ubuntu paketa):
   `php tools/build-release.php --expect-version=<tag>` pravi `racunator-panel-X.Y.Z.zip` — paket za ažuriranje (public/,
   src/, install/, docs/, LICENSE, README.md, CHANGELOG.md, htaccess.dist, storage/.htaccess, MANIFEST.sha256 sa SHA-256
   svakog fajla) i `CHANGELOG-X.Y.Z.md`; oba idu u artifakt `paket`.
3. **sign** (environment **`release`** — čeka odobrenje održavaoca; `contents: read`): preuzima artifakt i u zvaničnoj slici
   `php:8.3-cli` (pinovana po digest-u, bez mreže, bez Composer-a) pokreće samo kod iz tog commit-a:
   `php tools/sign-release.php` potpisuje ZIP ključem `RELEASE_SIGNING_KEY` → `racunator-panel-X.Y.Z.zip.sig` (i proverava
   potpis javnim ključem iz koda), pa `php tools/build-release.php --from-zip` pravi `racunator-panel-install.php` sa
   **ugrađenim potpisom** (isti ZIP u base64) i `SHA256SUMS`. Rezultat je artifakt `izdanje`.
4. **release** (jedini sa `contents: write`, bez checkout-a): preuzima artifakt `izdanje`, proverava `sha256sum -c SHA256SUMS`
   i pravi GitHub Release sa ZIP-om, `.sig`, install fajlom i `SHA256SUMS`; verzija sa crticom (`-rc.1`) je „pre-release"
   (panel je ne nudi, jer `/releases/latest` ne vraća pre-release).

Checkout svuda ide sa `persist-credentials: false` (token ne ostaje u `.git/config`).

Imena fajlova su deo ugovora sa panelom (`ReleaseInfo::fromGithub`) — ne menjajte ih.

## Ručni build
```
php tools/build-installer.php          # build/racunator-panel-install.php + ZIP (bez potpisa, za probu i novu instalaciju)
RELEASE_SIGNING_KEY=… php tools/build-installer.php   # isto, ali ZIP potpisan (.sig) i potpis ugrađen u install fajl
php tools/build-release.php            # ZIP, install fajl, SHA256SUMS i changelog odeljak
RELEASE_SIGNING_KEY=… php tools/sign-release.php build/racunator-panel-X.Y.Z.zip
php tools/build-release.php --from-zip # install fajl sa ugrađenim potpisom iz .sig + novi SHA256SUMS (ZIP se ne pravi ponovo)
```
Install fajl **bez potpisa** radi za novu instalaciju, ali ga postojeća instalacija za ručno ažuriranje odbija.
ZIP je ponovljiv (fiksno vreme unosa; `SOURCE_DATE_EPOCH` ga menja). Bez lokalnog PHP-a: `tools/dev/php tools/build-release.php`.

## Kako radi install fajl
`tools/stub/installer-stub.php` je šablon pisan sintaksom koju razume i stari PHP (da na PHP 7 prikaže poruku umesto prazne
stranice). Build mu upisuje verziju, SHA-256 ZIP-a i Ed25519 potpis ZIP-a (ako postoji) i dodaje ZIP u base64 posle
`__halt_compiler();` (base64 preživljava i FTP u tekstualnom režimu). Nova instalacija: koreni `.htaccess` nastaje iz
`htaccess.dist`, a ključ za čarobnjak se izdaje samo pri prvom otvaranju. Postojeća instalacija: paket ide u `releases/X.Y.Z/`
(bez aktivnog `.htaccess`-a, `releases/.htaccess` zabranjuje pristup), a ZIP i potpis u `storage/tmp/manual-update/`. Za raspakivanje **ne treba ext-zip**: ako je nema, koristi se ugrađeni čitač ZIP-a (metode stored
i deflate, provera CRC32; dovoljan je zlib). Ext-zip je ipak potrebna za ažuriranje iz panela i rezervne kopije — čarobnjak to
javlja kao upozorenje.

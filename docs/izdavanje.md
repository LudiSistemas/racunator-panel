# Izdavanje nove verzije (za održavaoca)

## Jednom: ključ za potpis izdanja
Paketi se potpisuju Ed25519 ključem; panel ima javni ključ u kodu i odbija paket sa pogrešnim potpisom.
1. `php tools/generate-signing-key.php` (potrebna ekstenzija sodium) ispisuje par ključeva.
   Bezbednije: `php tools/generate-signing-key.php --out ~/racunator-signing.key` upisuje privatni ključ samo u fajl (0600) i na ekranu prikazuje samo javni, pa ga postavite sa `gh secret set RELEASE_SIGNING_KEY < ~/racunator-signing.key` i fajl obrišite.
2. Javni ključ upišite u `src/Services/ReleaseChecker.php` → konstanta `PUBLIC_KEY` (umesto `ZAMENITI-JAVNIM-KLJUCEM`).
3. Privatni ključ upišite u GitHub: repo → *Settings → Secrets and variables → Actions → New repository secret*,
   ime **`RELEASE_SIGNING_KEY`**. Sačuvajte ga i u menadžeru lozinki.
4. Nikad ne komitujte privatni ključ. Ako procuri: napravite nov par, objavite verziju sa novim javnim ključem potpisanu
   **starim** ključem (da je postojeće instalacije prihvate), pa sledeće verzije potpisujte novim.

Dok je `PUBLIC_KEY` placeholder, workflow izdanja odbija da objavi (instalacije ne bi mogle da provere potpis).

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
1. Proverava da tag odgovara `src/version.php` (`v` + verzija) — inače prekida.
2. Proverava da je `PUBLIC_KEY` postavljen.
3. Pokreće testove.
4. `php tools/build-release.php --expect-version=<tag>` pravi u `build/`:
   - `racunator-panel-X.Y.Z.zip` — paket za ažuriranje (public/, src/, install/, docs/, LICENSE, README.md, CHANGELOG.md,
     .htaccess, storage/.htaccess, MANIFEST.sha256 sa SHA-256 svakog fajla),
   - `racunator-panel-install.php` — samoraspakujući install fajl (PHP stub + isti ZIP u base64),
   - `SHA256SUMS`, `CHANGELOG-X.Y.Z.md`.
5. `php tools/sign-release.php` potpisuje ZIP ključem iz secret-a `RELEASE_SIGNING_KEY` → `racunator-panel-X.Y.Z.zip.sig`
   (i proverava potpis javnim ključem iz koda).
6. Pravi GitHub Release sa ta četiri fajla; verzija sa crticom (`-rc.1`) je „pre-release" (panel je ne nudi, jer
   `/releases/latest` ne vraća pre-release).

Imena fajlova su deo ugovora sa panelom (`ReleaseInfo::fromGithub`) — ne menjajte ih.

## Ručni build
```
php tools/build-installer.php          # build/racunator-panel-install.php + ZIP
php tools/build-release.php            # + SHA256SUMS i changelog odeljak
RELEASE_SIGNING_KEY=… php tools/sign-release.php build/racunator-panel-X.Y.Z.zip
```
ZIP je ponovljiv (fiksno vreme unosa; `SOURCE_DATE_EPOCH` ga menja). Bez lokalnog PHP-a: `tools/dev/php tools/build-release.php`.

## Kako radi install fajl
`tools/stub/installer-stub.php` je šablon pisan sintaksom koju razume i stari PHP (da na PHP 7 prikaže poruku umesto prazne
stranice). Build mu upisuje verziju i SHA-256 ZIP-a i dodaje ZIP u base64 posle `__halt_compiler();` (base64 preživljava i
FTP u tekstualnom režimu). Za raspakivanje **ne treba ext-zip**: ako je nema, koristi se ugrađeni čitač ZIP-a (metode stored
i deflate, provera CRC32; dovoljan je zlib). Ext-zip je ipak potrebna za ažuriranje iz panela i rezervne kopije — čarobnjak to
javlja kao upozorenje.

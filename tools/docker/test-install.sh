#!/usr/bin/env bash
# Docker test instalacije na simuliranom shared hostingu (php:8.1-apache + mod_rewrite + MariaDB 11).
#   1) gradi install fajl od kopije izvora sa TEST ključem za potpis (javni ključ u kopiji ReleaseChecker-a, privatnim
#      se potpisuje ZIP) — pravi ključ izdanja se nikad ne koristi; pravi se i nepotpisan install fajl,
#   2) otprema ga u /var/www/html/panel/: ključ čarobnjaka se izdaje samo jednom, druga sesija mora da dokaže vlasništvo
#      fajlom install/DOZVOLA-<niz>.txt, 3) prolazi čarobnjak kroz HTTP (curl),
#   4) proverava /health, zaštitu fajlova, prijavu i stranicu Ažuriranje, 5) rezervna kopija i povratak MySQL baze
#      (uz NO_BACKSLASH_ESCAPES i napomenu sa prelomom reda i „;"),
#   6) ručno ažuriranje: nepotpisan install fajl se odbija; dok paket čeka potvrdu, releases/<v>/ (i čarobnjak u njemu)
#      nije dostupan preko veba; potpisan install fajl prolazi (potpis, samoprovera /health); _old/ nije dostupan.
# Na kraju (i pri grešci) OBAVEZNO gasi sve: docker compose down -v.
#   tools/docker/test-install.sh
set -euo pipefail

HERE="$(cd "$(dirname "$0")" && pwd)"
ROOT="$(cd "$HERE/../.." && pwd)"
PORT="${RP_TEST_PORT:-8089}"
BASE="http://127.0.0.1:${PORT}/panel"
PROJECT="racunator-panel-test"
WORK="$(mktemp -d)"
JAR="$WORK/kolacici.txt"
JAR2="$WORK/kolacici2.txt"
ADMIN_PASS='Docker-Test-Lozinka-2026'
TESTSRC_REL="build/docker-test-izvor"
TESTSRC="$ROOT/$TESTSRC_REL"
export RP_TEST_PORT="$PORT"

dc() { docker compose -p "$PROJECT" -f "$HERE/compose.yml" "$@"; }
cleanup() {
  local rc=$?
  echo "== Gašenje: docker compose down -v"
  dc down -v --remove-orphans >/dev/null 2>&1 || true
  rm -rf "$WORK" "$TESTSRC"
  if [ $rc -eq 0 ]; then echo "== DOCKER TEST: USPEH"; else echo "== DOCKER TEST: NEUSPEH (kod $rc)"; fi
}
trap cleanup EXIT

fail() { echo "GREŠKA: $*" >&2; exit 1; }
# curl sa Host: localhost — tako je javna adresa http://localhost/panel ista spolja i iznutra (samoprovera iz kontejnera).
c() { curl -sS -H 'Host: localhost' -b "$JAR" -c "$JAR" "$@"; }
c2() { curl -sS -H 'Host: localhost' -b "$JAR2" -c "$JAR2" "$@"; }
token() { grep -o 'name="_token" value="[^"]*"' | head -1 | sed 's/.*value="//; s/"$//'; }
expect() { grep -q -- "$1" "$2" || { echo "--- $2:"; sed -e 's/<[^>]*>/ /g' "$2" | tr -s ' \n' | head -c 3000; echo; fail "nije pronađeno: $1"; }; }
# PHP sa putanjom relativnom u odnosu na koren repoa (lokalni php ili Docker omotač tools/dev/php).
php_repo() { if command -v php >/dev/null 2>&1; then (cd "$ROOT" && php "$@"); else "$ROOT/tools/dev/php" "$@"; fi; }
# Zahtev mora biti odbijen (403/404) — za fajlove koji ne smeju biti dostupni preko veba.
denied() {
  local code
  code="$(c -o "$WORK/x" -w '%{http_code}' "$BASE/$1")"
  case "$code" in
    403|404) echo "   $1 → $code (zaštićeno)";;
    *) sed -e 's/<[^>]*>/ /g' "$WORK/x" | head -c 600; echo; fail "$1 je dostupan (HTTP $code)";;
  esac
}

echo "== Build install fajla (kopija izvora sa test ključem za potpis)"
rm -rf "$TESTSRC"
mkdir -p "$TESTSRC/tools" "$TESTSRC/storage"
for d in public src install docs; do cp -R "$ROOT/$d" "$TESTSRC/$d"; done
cp -R "$ROOT/tools/lib" "$ROOT/tools/stub" "$TESTSRC/tools/"
cp "$ROOT/tools/build-installer.php" "$TESTSRC/tools/"
cp "$ROOT/storage/.htaccess" "$TESTSRC/storage/.htaccess"
cp "$ROOT/LICENSE" "$ROOT/README.md" "$ROOT/CHANGELOG.md" "$ROOT/.htaccess" "$TESTSRC/"
cat > "$TESTSRC/napravi.php" <<'PHP'
<?php
// Test par ključeva: javni ide u kopiju ReleaseChecker-a, privatnim se potpisuje ZIP (samo za ovaj Docker test).
$kp = sodium_crypto_sign_keypair();
$rc = __DIR__ . '/src/Services/ReleaseChecker.php';
$src = preg_replace("/public const PUBLIC_KEY = '[^']*';/", "public const PUBLIC_KEY = '" . base64_encode(sodium_crypto_sign_publickey($kp)) . "';",
    (string) file_get_contents($rc), 1, $n);
if ($n !== 1) {
    fwrite(STDERR, "PUBLIC_KEY nije pronađen\n");
    exit(1);
}
file_put_contents($rc, $src);
$build = static function (string $out): int {
    passthru(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/tools/build-installer.php') . ' --out=' . escapeshellarg($out), $rc);
    return $rc;
};
$rc1 = $build(__DIR__ . '/bez-potpisa');
putenv('RELEASE_SIGNING_KEY=' . base64_encode(sodium_crypto_sign_secretkey($kp)));
$rc2 = $build(__DIR__ . '/potpisan');
exit($rc1 !== 0 || $rc2 !== 0 ? 1 : 0);
PHP
php_repo "$TESTSRC_REL/napravi.php"
INSTALLER="$TESTSRC/potpisan/racunator-panel-install.php"
UNSIGNED="$TESTSRC/bez-potpisa/racunator-panel-install.php"
[ -f "$INSTALLER" ] && [ -f "$UNSIGNED" ] || fail "install fajlovi nisu napravljeni"
grep -aq "define('RPI_SIGNATURE', '[A-Za-z0-9+/]" "$INSTALLER" || fail "potpisan install fajl nema potpis"
grep -aq "define('RPI_SIGNATURE', '')" "$UNSIGNED" || fail "nepotpisan install fajl ima potpis"
VERSION="$(sed -n "s/^define('RPI_VERSION', '\(.*\)');.*/\1/p" "$INSTALLER" | head -1)"
[ -n "$VERSION" ] || fail "nema verzije u install fajlu"

echo "== Podizanje kontejnera"
dc up -d --build --wait
for i in $(seq 1 30); do curl -s -o /dev/null "http://127.0.0.1:${PORT}/" && break; sleep 1; done

echo "== Otpremanje install fajla u /var/www/html/panel/"
dc cp "$INSTALLER" web:/var/www/html/panel/racunator-panel-install.php
dc exec -T web chown www-data:www-data /var/www/html/panel/racunator-panel-install.php

echo "== 1. Install fajl (raspakivanje)"
c -o "$WORK/stub.html" -D "$WORK/stub.h" "$BASE/racunator-panel-install.php"
grep -qi '^location: install/?t=' "$WORK/stub.h" || { cat "$WORK/stub.h" "$WORK/stub.html"; fail "install fajl nije preusmerio na čarobnjak"; }
head -1 "$WORK/stub.h" | grep -q ' 303' || fail "install fajl ne vraća 303"
LOC="$(grep -i '^location:' "$WORK/stub.h" | tr -d '\r' | sed 's/^[Ll]ocation: //')"

echo "== 1a. Drugo otvaranje install fajla ne izdaje nov ključ (S1)"
code="$(curl -sS -H 'Host: localhost' -o "$WORK/stub2.html" -D "$WORK/stub2.h" -w '%{http_code}' "$BASE/racunator-panel-install.php")"
[ "$code" = "409" ] || fail "drugo otvaranje install fajla: HTTP $code (očekivano 409)"
if grep -qi '^location:' "$WORK/stub2.h"; then fail "drugo otvaranje install fajla je izdalo ključ"; fi
expect 'već započeta' "$WORK/stub2.html"

echo "== 1b. Sesija bez ključa: čarobnjak traži dokaz vlasništva (V1a)"
code="$(c2 -o "$WORK/tudji.html" -w '%{http_code}' "$BASE/install/?korak=provere")"
[ "$code" = "403" ] || fail "čarobnjak bez ključa: HTTP $code (očekivano 403)"
expect 'Potvrda vlasništva' "$WORK/tudji.html"
if grep -q 'Provera hostinga' "$WORK/tudji.html"; then fail "čarobnjak bez ključa prikazuje provere"; fi
PROOF="$(grep -o 'DOZVOLA-[0-9a-f]\{32\}\.txt' "$WORK/tudji.html" | head -1)"
[ -n "$PROOF" ] || fail "nema imena fajla za dokaz vlasništva"
CSRF2="$(token < "$WORK/tudji.html")"
c2 -o "$WORK/tudji2.html" --data-urlencode "_token=$CSRF2" -d akcija=dozvola "$BASE/install/"
expect 'još ne postoji' "$WORK/tudji2.html"
dc exec -T -u www-data web touch "/var/www/html/panel/install/$PROOF"
denied "install/$PROOF"
c2 -o /dev/null -D "$WORK/dozvola.h" --data-urlencode "_token=$CSRF2" -d akcija=dozvola "$BASE/install/"
grep -qi '^location: ./' "$WORK/dozvola.h" || { cat "$WORK/dozvola.h"; fail "dokaz vlasništva nije prihvaćen"; }
dc exec -T web sh -c "test ! -e /var/www/html/panel/install/$PROOF" || fail "fajl za dokaz vlasništva nije obrisan"
# Ko je dokazao vlasništvo dobija nov ključ; stari ključ (iz prvog otvaranja install fajla) više ne važi.
c -o /dev/null "$BASE/$LOC"
c -o "$WORK/stari.html" "$BASE/install/?korak=provere"
expect 'Potvrda vlasništva' "$WORK/stari.html"
cp "$JAR2" "$JAR"

echo "== 2. Čarobnjak: provere"
c -o "$WORK/provere.html" "$BASE/install/?korak=provere"
expect 'Provera hostinga' "$WORK/provere.html"
expect 'Dalje: baza' "$WORK/provere.html"
if grep -q 'check-greska' "$WORK/provere.html"; then sed -e 's/<[^>]*>/ /g' "$WORK/provere.html" | tr -s ' ' | head -c 3000; fail "provere imaju crvene stavke"; fi
grep -q 'mod_rewrite (.htaccess)</strong> <span class="status">(u redu)' "$WORK/provere.html" || echo "   (napomena: test mod_rewrite-a nije zelen)"
expect 'Probni fajlovi u storage/ nisu dostupni preko veba' "$WORK/provere.html"
expect 'Folder za podatke' "$WORK/provere.html"

echo "== 3. Čarobnjak: baza (Testiraj vezu, pa Dalje)"
c -o "$WORK/baza.html" "$BASE/install/?korak=baza"
CSRF="$(token < "$WORK/baza.html")"
c -o "$WORK/baza-test.html" --data-urlencode "_token=$CSRF" -d driver=mysql -d host=db -d port=3306 -d name=panel -d user=panel \
  --data-urlencode 'pass=panel-lozinka-test' -d akcija=test "$BASE/install/?korak=baza"
expect 'Veza sa bazom radi' "$WORK/baza-test.html"
if grep -q 'panel-lozinka-test' "$WORK/baza-test.html"; then fail "lozinka baze je prikazana u HTML-u"; fi
if dc exec -T web sh -c 'grep -rl "panel-lozinka-test" /var/www/html/panel/storage/tmp/' >/dev/null 2>&1; then
  fail "lozinka baze je u čistom tekstu u fajlu sesije čarobnjaka"
fi
c -o /dev/null -D "$WORK/baza.h" --data-urlencode "_token=$CSRF" -d driver=mysql -d host=db -d port=3306 -d name=panel -d user=panel \
  -d pass= -d akcija=dalje "$BASE/install/?korak=baza"
grep -qi 'location: ?korak=adresa' "$WORK/baza.h" || fail "korak baza nije prošao (prazna lozinka = zadrži unetu)"

echo "== 4. Čarobnjak: javna adresa"
c -o "$WORK/adresa.html" "$BASE/install/?korak=adresa"
expect 'value="http://localhost/panel"' "$WORK/adresa.html"
c -o /dev/null -D "$WORK/adresa.h" --data-urlencode "_token=$CSRF" --data-urlencode 'public_url=http://localhost/panel' "$BASE/install/?korak=adresa"
grep -qi 'location: ?korak=nalog' "$WORK/adresa.h" || fail "korak adresa nije prošao"

echo "== 5. Čarobnjak: prvi nalog i instalacija"
c -o "$WORK/slab.html" --data-urlencode "_token=$CSRF" -d admin_username=marko -d admin_password=kratka -d admin_password2=kratka \
  --data-urlencode 'accountant_name=Knjigovodstvo Docker' "$BASE/install/?korak=nalog"
expect 'najmanje 12' "$WORK/slab.html"
c -o "$WORK/gotovo.html" --data-urlencode "_token=$CSRF" -d admin_username=marko --data-urlencode "admin_password=$ADMIN_PASS" \
  --data-urlencode "admin_password2=$ADMIN_PASS" --data-urlencode 'accountant_name=Knjigovodstvo Docker' "$BASE/install/?korak=nalog"
expect 'Panel je instaliran' "$WORK/gotovo.html"
expect 'Panel odgovara na' "$WORK/gotovo.html"
expect 'je obrisan' "$WORK/gotovo.html"

echo "== 6. Provere posle instalacije"
c -o "$WORK/health.json" "$BASE/health"
expect '"ok":true' "$WORK/health.json"
for p in config.php src/version.php storage/.htaccess storage/ storage/tmp/ MANIFEST.sha256 install/Installer.php install/cli.php \
         install/.htaccess racunator-panel-install.php htaccess.dist; do
  denied "$p"
done
dc exec -T web sh -c 'stat -c "%a %U" /var/www/html/panel/config.php' | grep -q '^600 www-data' || fail "config.php nema prava 0600"
dc exec -T web sh -c 'test -f /var/www/html/panel/.htaccess && test ! -e /var/www/html/panel/htaccess.dist' || fail "koreni .htaccess nije postavljen iz htaccess.dist"
if dc exec -T web sh -c 'grep -rl "panel-lozinka-test" /var/www/html/panel/storage/' >/dev/null 2>&1; then
  fail "lozinka baze je ostala u storage/ (sesija čarobnjaka)"
fi
c -o "$WORK/install-ponovo.html" "$BASE/install/"
expect 'Panel je već instaliran' "$WORK/install-ponovo.html"

echo "== 7. Prijava i stranica Ažuriranje"
rm -f "$JAR"
c -o "$WORK/prijava.html" "$BASE/prijava"
CSRF="$(token < "$WORK/prijava.html")"
c -o /dev/null -D "$WORK/prijava.h" --data-urlencode "_token=$CSRF" -d korisnicko_ime=marko --data-urlencode "lozinka=$ADMIN_PASS" "$BASE/prijava"
grep -qi 'location: /panel/' "$WORK/prijava.h" || { cat "$WORK/prijava.h"; fail "prijava nije uspela"; }
c -o "$WORK/tabla.html" "$BASE/"
expect 'Kontrolna tabla' "$WORK/tabla.html"
expect 'Knjigovodstvo Docker' "$WORK/tabla.html"
c -o "$WORK/azuriranje.html" "$BASE/azuriranje"
expect 'Instalirana verzija' "$WORK/azuriranje.html"

echo "== 8. Rezervna kopija i povratak MySQL baze (CLI, NO_BACKSLASH_ESCAPES, napomena sa prelomom reda i ;)"
dc exec -T db mariadb -uroot -proot-lozinka-test -e "SET GLOBAL sql_mode = CONCAT(@@GLOBAL.sql_mode, ',NO_BACKSLASH_ESCAPES')"
NAPOMENA="CONCAT('prvi red;', CHAR(10), 'drugi red sa ', CHAR(92), ' i ', CHAR(39), ';', CHAR(13), CHAR(10), 'kraj;')"
dc exec -T db mariadb -upanel -ppanel-lozinka-test panel -e "INSERT INTO settings (name, value) VALUES ('napomena_test', $NAPOMENA)"
dc exec -T -u www-data web php /var/www/html/panel/install/cli.php kopija | tee "$WORK/kopija.txt"
NAME="$(sed -n 's/^Napravljena kopija \([0-9a-f-]*\).*/\1/p' "$WORK/kopija.txt")"
[ -n "$NAME" ] || fail "kopija nije napravljena"
echo "$NAME" | grep -Eq '^[0-9]{8}-[0-9]{6}-[0-9]+-[0-9a-f]{16}$' || fail "ime kopije nema nasumičan sufiks: $NAME"
dc exec -T web sh -c "stat -c '%a' /var/www/html/panel/storage/backups/$NAME/baza.sql" | grep -q '^600' || fail "baza.sql nema prava 0600"
denied "storage/backups/$NAME/baza.sql"
denied "storage/backups/$NAME/fajlovi.zip"
dc exec -T db mariadb -upanel -ppanel-lozinka-test panel \
  -e "INSERT INTO settings (name, value) VALUES ('posle_kopije', '1'); UPDATE settings SET value = 'pokvareno' WHERE name = 'napomena_test'"
dc exec -T -u www-data web php /var/www/html/panel/install/cli.php vrati --kopija="$NAME" --samo-baza
LEFT="$(dc exec -T db mariadb -N -upanel -ppanel-lozinka-test panel -e "SELECT COUNT(*) FROM settings WHERE name='posle_kopije'")"
[ "$(echo "$LEFT" | tr -d '[:space:]')" = "0" ] || fail "povratak baze nije uklonio red upisan posle kopije"
SAME="$(dc exec -T db mariadb -N -upanel -ppanel-lozinka-test panel -e "SELECT value = $NAPOMENA FROM settings WHERE name='napomena_test'")"
[ "$(echo "$SAME" | tr -d '[:space:]')" = "1" ] || fail "napomena sa prelomom reda i ; nije vraćena tačno (NO_BACKSLASH_ESCAPES)"
USERS="$(dc exec -T db mariadb -N -upanel -ppanel-lozinka-test panel -e "SELECT COUNT(*) FROM users")"
[ "$(echo "$USERS" | tr -d '[:space:]')" = "1" ] || fail "posle povratka nema naloga"

echo "== 8b. PHPUnit: kopija i povratak MySQL baze na pravoj MariaDB"
if docker image inspect racunator-panel-dev >/dev/null 2>&1 && [ -f "$ROOT/vendor/bin/phpunit" ]; then
  docker run --rm --network "${PROJECT}_default" -v "$ROOT":/app -w /app -u "$(id -u):$(id -g)" \
    -e RACUNATOR_TEST_MYSQL_DSN='mysql:host=db;port=3306;dbname=panel;charset=utf8mb4' -e RACUNATOR_TEST_MYSQL_USER=panel \
    -e RACUNATOR_TEST_MYSQL_PASS=panel-lozinka-test racunator-panel-dev \
    php vendor/bin/phpunit --filter testMysqlKopijaSaPrelomomRedaINoBackslashEscapes tests/Install/BackupTest.php > "$WORK/phpunit-mysql.txt" 2>&1 \
    || { cat "$WORK/phpunit-mysql.txt"; fail "PHPUnit MySQL test nije prošao"; }
  grep -q 'OK (1 test' "$WORK/phpunit-mysql.txt" || { cat "$WORK/phpunit-mysql.txt"; fail "PHPUnit MySQL test je preskočen"; }
  echo "   OK"
else
  echo "   (preskočeno: nema slike racunator-panel-dev ili vendor/)"
fi
dc exec -T db mariadb -uroot -proot-lozinka-test -e "SET GLOBAL sql_mode = REPLACE(@@GLOBAL.sql_mode, ',NO_BACKSLASH_ESCAPES', '')"

echo "== 9a. Ručno ažuriranje NEPOTPISANIM install fajlom se odbija"
dc cp "$UNSIGNED" web:/var/www/html/panel/racunator-panel-install.php
dc exec -T web chown www-data:www-data /var/www/html/panel/racunator-panel-install.php
# -L: ako OPcache drži stari install fajl istog imena, install fajl se jednom sam ponovo učita (?osvezeno=1).
c -L --max-redirs 3 -o /dev/null -D "$WORK/stub3.h" "$BASE/racunator-panel-install.php"
grep -qi "^location: install/" "$WORK/stub3.h" || { cat "$WORK/stub3.h"; c "$BASE/racunator-panel-install.php" | head -c 1500; fail "install fajl nad postojećom instalacijom nije preusmerio"; }
dc exec -T web sh -c "test -d /var/www/html/panel/releases/$VERSION && test ! -e /var/www/html/panel/releases/$VERSION/.htaccess && test -f /var/www/html/panel/releases/$VERSION/htaccess.dist" \
  || fail "releases/$VERSION/ ne sme imati aktivan .htaccess"
echo "   Dok paket čeka potvrdu, ništa iz releases/ nije dostupno preko veba (V1):"
for p in "releases/" "releases/$VERSION/" "releases/$VERSION/install/" "releases/$VERSION/install/index.php" "releases/$VERSION/install/?korak=provere" \
         "releases/$VERSION/public/" "releases/$VERSION/public/index.php" "releases/$VERSION/htaccess.dist" "releases/$VERSION/src/version.php" \
         "storage/tmp/manual-update/racunator-panel-$VERSION.zip" "storage/tmp/manual-update.json"; do
  denied "$p"
done
c -o "$WORK/rucno-bez.html" "$BASE/install/"
expect 'Ručno ažuriranje' "$WORK/rucno-bez.html"
expect 'nema digitalni potpis' "$WORK/rucno-bez.html"
if grep -q 'Potvrđujem' "$WORK/rucno-bez.html"; then fail "nepotpisan paket nudi potvrdu ažuriranja"; fi
CSRF="$(token < "$WORK/rucno-bez.html")"
c -o "$WORK/rucno-bez2.html" --data-urlencode "_token=$CSRF" -d akcija=azuriraj -d potvrda=da "$BASE/install/"
expect 'nema digitalni potpis' "$WORK/rucno-bez2.html"
if grep -q 'Ažurirano na' "$WORK/rucno-bez2.html"; then fail "nepotpisan paket je instaliran"; fi
c -o /dev/null --data-urlencode "_token=$CSRF" -d akcija=odbaci "$BASE/install/"
dc exec -T web sh -c 'test ! -e /var/www/html/panel/releases && test ! -e /var/www/html/panel/storage/tmp/manual-update.json' \
  || fail "odbačen paket nije obrisan"

echo "== 9b. Ručno ažuriranje POTPISANIM install fajlom"
dc cp "$INSTALLER" web:/var/www/html/panel/racunator-panel-install.php
dc exec -T web chown www-data:www-data /var/www/html/panel/racunator-panel-install.php
c -L --max-redirs 3 -o /dev/null -D "$WORK/stub4.h" "$BASE/racunator-panel-install.php"
grep -qi '^location: install/' "$WORK/stub4.h" || fail "install fajl nad postojećom instalacijom nije preusmerio"
denied "releases/$VERSION/install/"
denied "releases/$VERSION/install/index.php"
c -o "$WORK/rucno.html" "$BASE/install/"
expect 'Ručno ažuriranje' "$WORK/rucno.html"
expect 'Potvrđujem' "$WORK/rucno.html"
CSRF="$(token < "$WORK/rucno.html")"
c -o "$WORK/rucno-rez.html" --data-urlencode "_token=$CSRF" -d akcija=azuriraj -d potvrda=da "$BASE/install/"
expect 'Ažurirano na' "$WORK/rucno-rez.html"
expect 'Digitalni potpis (Ed25519) je ispravan' "$WORK/rucno-rez.html"
expect '/health odgovara verzijom' "$WORK/rucno-rez.html"
c -o "$WORK/health2.json" "$BASE/health"
expect '"ok":true' "$WORK/health2.json"
dc exec -T web sh -c 'test -d /var/www/html/panel/_old/src && test ! -e /var/www/html/panel/releases && test ! -e /var/www/html/panel/storage/maintenance.flag && test ! -e /var/www/html/panel/storage/tmp/manual-update' \
  || fail "posle ažuriranja: očekivan _old/, bez releases/, manual-update/ i maintenance.flag"
echo "   Prethodna verzija u _old/ nije dostupna preko veba:"
for p in "_old/" "_old/install/" "_old/install/index.php" "_old/public/" "_old/public/index.php" "_old/src/version.php"; do
  denied "$p"
done
c -o "$WORK/azuriranje2.html" "$BASE/azuriranje"
expect 'Ručno ažuriranje' "$WORK/azuriranje2.html"
expect 'Vrati ovu verziju' "$WORK/azuriranje2.html"

echo "== Sve provere su prošle"

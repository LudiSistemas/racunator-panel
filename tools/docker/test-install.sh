#!/usr/bin/env bash
# Docker test instalacije na simuliranom shared hostingu (php:8.1-apache + mod_rewrite + MariaDB 11).
#   1) gradi install fajl, 2) otprema ga u /var/www/html/panel/, 3) prolazi čarobnjak kroz HTTP (curl),
#   4) proverava /health, zaštitu fajlova, prijavu i stranicu Ažuriranje, 5) rezervna kopija i povratak MySQL baze,
#   6) ručno ažuriranje install fajlom nad postojećom instalacijom (sa pravom samoproverom /health).
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
ADMIN_PASS='Docker-Test-Lozinka-2026'
export RP_TEST_PORT="$PORT"

dc() { docker compose -p "$PROJECT" -f "$HERE/compose.yml" "$@"; }
cleanup() {
  local rc=$?
  echo "== Gašenje: docker compose down -v"
  dc down -v --remove-orphans >/dev/null 2>&1 || true
  rm -rf "$WORK"
  if [ $rc -eq 0 ]; then echo "== DOCKER TEST: USPEH"; else echo "== DOCKER TEST: NEUSPEH (kod $rc)"; fi
}
trap cleanup EXIT

fail() { echo "GREŠKA: $*" >&2; exit 1; }
# curl sa Host: localhost — tako je javna adresa http://localhost/panel ista spolja i iznutra (samoprovera iz kontejnera).
c() { curl -sS -H 'Host: localhost' -b "$JAR" -c "$JAR" "$@"; }
token() { grep -o 'name="_token" value="[^"]*"' | head -1 | sed 's/.*value="//; s/"$//'; }
expect() { grep -q -- "$1" "$2" || { echo "--- $2:"; sed -e 's/<[^>]*>/ /g' "$2" | tr -s ' \n' | head -c 3000; echo; fail "nije pronađeno: $1"; }; }

echo "== Build install fajla"
if command -v php >/dev/null 2>&1; then php "$ROOT/tools/build-installer.php"; else "$ROOT/tools/dev/php" tools/build-installer.php; fi
INSTALLER="$ROOT/build/racunator-panel-install.php"
[ -f "$INSTALLER" ] || fail "nema $INSTALLER"

echo "== Podizanje kontejnera"
dc up -d --build --wait
for i in $(seq 1 30); do curl -s -o /dev/null "http://127.0.0.1:${PORT}/" && break; sleep 1; done

echo "== Otpremanje install fajla u /var/www/html/panel/"
dc cp "$INSTALLER" web:/var/www/html/panel/racunator-panel-install.php
dc exec -T web chown www-data:www-data /var/www/html/panel/racunator-panel-install.php

echo "== 1. Install fajl (raspakivanje)"
c -o "$WORK/stub.html" -D "$WORK/stub.h" "$BASE/racunator-panel-install.php"
grep -qi '^location: install/?t=' "$WORK/stub.h" || { cat "$WORK/stub.h" "$WORK/stub.html"; fail "install fajl nije preusmerio na čarobnjak"; }
LOC="$(grep -i '^location:' "$WORK/stub.h" | tr -d '\r' | sed 's/^[Ll]ocation: //')"

echo "== 2. Čarobnjak: provere"
c -L -o "$WORK/provere.html" "$BASE/$LOC"
expect 'Provera hostinga' "$WORK/provere.html"
expect 'Dalje: baza' "$WORK/provere.html"
grep -q 'check-greska' "$WORK/provere.html" && { sed -e 's/<[^>]*>/ /g' "$WORK/provere.html" | tr -s ' ' | head -c 3000; fail "provere imaju crvene stavke"; }
grep -q 'mod_rewrite (.htaccess)</strong> <span class="status">(u redu)' "$WORK/provere.html" || echo "   (napomena: test mod_rewrite-a nije zelen)"
CSRF="$(token < "$WORK/provere.html" || true)"

echo "== 3. Čarobnjak: baza (Testiraj vezu, pa Dalje)"
c -o "$WORK/baza.html" "$BASE/install/?korak=baza"
CSRF="$(token < "$WORK/baza.html")"
c -o "$WORK/baza-test.html" --data-urlencode "_token=$CSRF" -d driver=mysql -d host=db -d port=3306 -d name=panel -d user=panel \
  --data-urlencode 'pass=panel-lozinka-test' -d akcija=test "$BASE/install/?korak=baza"
expect 'Veza sa bazom radi' "$WORK/baza-test.html"
grep -q 'panel-lozinka-test' "$WORK/baza-test.html" && fail "lozinka baze je prikazana u HTML-u"
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
for p in config.php src/version.php storage/.htaccess MANIFEST.sha256 install/Installer.php install/cli.php racunator-panel-install.php; do
  code="$(c -o "$WORK/x" -w '%{http_code}' "$BASE/$p")"
  case "$code" in 403|404) echo "   $p → $code (zaštićeno)";; *) fail "$p je dostupan (HTTP $code)";; esac
done
dc exec -T web sh -c 'stat -c "%a %U" /var/www/html/panel/config.php' | grep -q '^600 www-data' || fail "config.php nema prava 0600"
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

echo "== 8. Rezervna kopija i povratak MySQL baze (CLI)"
dc exec -T -u www-data web php /var/www/html/panel/install/cli.php kopija | tee "$WORK/kopija.txt"
NAME="$(sed -n 's/^Napravljena kopija \([0-9-]*\).*/\1/p' "$WORK/kopija.txt")"
[ -n "$NAME" ] || fail "kopija nije napravljena"
dc exec -T db mariadb -upanel -ppanel-lozinka-test panel -e "INSERT INTO settings (name, value) VALUES ('posle_kopije', '1')"
dc exec -T -u www-data web php /var/www/html/panel/install/cli.php vrati --kopija="$NAME" --samo-baza
LEFT="$(dc exec -T db mariadb -N -upanel -ppanel-lozinka-test panel -e "SELECT COUNT(*) FROM settings WHERE name='posle_kopije'")"
[ "$(echo "$LEFT" | tr -d '[:space:]')" = "0" ] || fail "povratak baze nije uklonio red upisan posle kopije"
USERS="$(dc exec -T db mariadb -N -upanel -ppanel-lozinka-test panel -e "SELECT COUNT(*) FROM users")"
[ "$(echo "$USERS" | tr -d '[:space:]')" = "1" ] || fail "posle povratka nema naloga"

echo "== 9. Ručno ažuriranje install fajlom nad postojećom instalacijom"
dc cp "$INSTALLER" web:/var/www/html/panel/racunator-panel-install.php
dc exec -T web chown www-data:www-data /var/www/html/panel/racunator-panel-install.php
c -o /dev/null -D "$WORK/stub2.h" "$BASE/racunator-panel-install.php"
grep -qi '^location: install/' "$WORK/stub2.h" || fail "install fajl nad postojećom instalacijom nije preusmerio"
c -o "$WORK/rucno.html" "$BASE/install/"
expect 'Ručno ažuriranje' "$WORK/rucno.html"
expect 'Potvrđujem' "$WORK/rucno.html"
CSRF="$(token < "$WORK/rucno.html")"
c -o "$WORK/rucno-rez.html" --data-urlencode "_token=$CSRF" -d akcija=azuriraj -d potvrda=da "$BASE/install/"
expect 'Ažurirano na' "$WORK/rucno-rez.html"
expect '/health odgovara verzijom' "$WORK/rucno-rez.html"
c -o "$WORK/health2.json" "$BASE/health"
expect '"ok":true' "$WORK/health2.json"
dc exec -T web sh -c 'test -d /var/www/html/panel/_old/src && test ! -e /var/www/html/panel/releases && test ! -e /var/www/html/panel/storage/maintenance.flag' \
  || fail "posle ažuriranja: očekivan _old/, bez releases/ i bez maintenance.flag"
c -o "$WORK/azuriranje2.html" "$BASE/azuriranje"
expect 'Ručno ažuriranje' "$WORK/azuriranje2.html"
expect 'Vrati ovu verziju' "$WORK/azuriranje2.html"

echo "== Sve provere su prošle"

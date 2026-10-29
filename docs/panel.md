# Uputstvo za knjigovođu

Ovo uputstvo opisuje svakodnevni rad u Računator panelu: prijavu, dodavanje korisnika i QR kod, pregled računa po mesecu,
izvoz u CSV, naloge za zaposlene i dvofaktorsku prijavu. Instalacija je opisana u [instalacija.md](instalacija.md).

## Pojmovi
- **Korisnik** — firma ili preduzetnik čije račune primate. Korisnik ima aplikaciju **Računator** na telefonu.
- **Nalog** — vaš pristup panelu (vi i vaši zaposleni). Uloge: **administrator** (sve, uključujući naloge, API i ažuriranje)
  i **zaposleni** (korisnici i računi).
- **QR kod za uparivanje** — sadrži adresu panela za tog korisnika i njegov tajni ključ. Kad ga korisnik skenira, aplikacija
  zna kome i kako da šalje račune.

## Prijava
1. Otvorite adresu panela (npr. `https://vas-domen.rs/panel`).
2. Upišite korisničko ime i lozinku i kliknite **Prijavi se**.
3. Ako ste uključili dvofaktorsku prijavu, otvorite **Imam uključenu dvofaktorsku prijavu** i upišite šestocifreni kod iz
   aplikacije za autentifikaciju. Ako to zaboravite, panel će vas pitati za kod — tada ponovo upišite lozinku i kod.
4. Posle više pogrešnih pokušaja prijava se privremeno zaključava (zaštita od pogađanja lozinke). Sačekajte nekoliko minuta.

Sesija ističe posle dva sata neaktivnosti. Na tuđem računaru uvek kliknite **Odjava** (gore desno).

## Prvi korak: naziv knjigovodstva
Administrator u **Podešavanja** upisuje **naziv knjigovodstva**. Taj naziv korisnik vidi u aplikaciji posle skeniranja i
na odštampanoj kartici. Na istoj stranici vidite javnu adresu panela i verziju.

## Kontrolna tabla
Prikazuje sve korisnike, broj računa primljenih u tekućem mesecu i vreme poslednjeg slanja. Kolona „Poslednje slanje"
pokazuje da li je aplikacija korisnika povezana i aktivna („još nije slao" znači da QR još nije skeniran ili da nije bilo slanja).

## Dodavanje korisnika i QR kod
1. **Korisnici → Dodaj korisnika**.
2. Upišite ime (npr. „Pera DOO") i, po želji, napomenu (vidi je samo knjigovodstvo). Kliknite **Sačuvaj i prikaži QR kod**.
3. Pojavljuje se kartica sa QR kodom. **QR kod i ključ se prikazuju samo jednom** — panel ne čuva ključ u čitljivom obliku.
   - Kliknite **Štampaj karticu** (ili sačuvajte kao PDF iz dijaloga za štampu) i predajte je korisniku, ili
   - neka korisnik odmah skenira QR sa vašeg ekrana.
4. Korisnik u aplikaciji: **Računator → Podešavanja → Knjigovođa → Skeniraj**, usmeri kameru na QR i potvrdi.
5. Ako skeniranje ne radi, u delu **Podaci za ručni unos** su adresa i ključ sa dugmetom **Kopiraj**.

Kartica sadrži tajni ključ — ne šaljite je javnim kanalima i ne objavljujte je. Posle uparivanja karticu uništite ili čuvajte na sigurnom.

### Nov ključ
Ako je kartica izgubljena, korisnik promenio telefon ili sumnjate da je ključ otkriven: otvorite korisnika i kliknite
**Nov ključ** (uz potvrdu). Stari ključ odmah prestaje da važi, a dobijate novi QR kod (opet samo jednom). Korisnik mora
ponovo da skenira.

### Deaktivacija
**Deaktiviraj** zaustavlja prijem računa od tog korisnika; već primljeni računi ostaju sačuvani i vidljivi.
**Aktiviraj** vraća prijem sa postojećim ključem. Ime i napomenu menjate u delu **Izmena** na stranici korisnika.

## Računi po mesecu
1. Otvorite korisnika i kliknite **Računi** (ili broj računa u listi).
2. U polju **Mesec** izaberite mesec; uz svaki mesec piše broj primljenih računa.
3. Tabela prikazuje datum i vreme računa, prodavca, PIB prodavca, PIB kupca, iznos, PFR broj i vrstu računa (npr.
   „Promet – prodaja", „Promet – refundacija"). Refundacije su blago obojene.
4. Dugme **Otvori na sajtu Poreske uprave** otvara originalni račun (sa stavkama) na `suf.purs.gov.rs` u novoj kartici.
   Panel prikazuje dugme samo za linkove koji zaista vode na sajt Poreske uprave.
5. Ispod tabele je zbir iznosa za mesec (sabira sve primljene račune, uključujući refundacije).

## Izvoz u CSV
Na stranici računa kliknite **Preuzmi CSV**. Fajl sadrži račune izabranog meseca i otvara se direktno u Excel-u
(srpska podešavanja): kodiranje UTF-8, razdvajač tačka-zarez (`;`), iznos sa decimalnim zarezom (npr. `1234,56`).
Kolone: Datum, Prodavac, PIB prodavca, PIB kupca, Iznos, PFR broj, Vrsta računa, Vrsta transakcije, Link za proveru.
Svaki izvoz se beleži u dnevnik.

## Nalozi (samo administrator)
- **Nalozi → Novi nalog**: korisničko ime (slova bez kvačica, cifre, `.`, `-`, `_`, `@`), uloga i lozinka od najmanje
  12 znakova. Lozinku saopštite zaposlenom lično; on je posle može promeniti u **Podešavanja → Promena lozinke**.
- **Nova lozinka**: postavlja novu lozinku nalogu koji je zaboravio svoju.
- **Deaktiviraj / Aktiviraj**: deaktiviran nalog ne može da se prijavi (i odmah se odjavljuje). Sopstveni nalog ne možete
  deaktivirati, a uvek mora ostati bar jedan aktivan administrator.

## Dvofaktorska prijava (2FA)
Preporučujemo je za sve naloge, a obavezno za administratore.
1. **Podešavanja → Dvofaktorska prijava**.
2. U aplikaciji za autentifikaciju na telefonu (Google Authenticator, Microsoft Authenticator, Aegis, 2FAS…) dodajte nalog
   skeniranjem QR koda ili ručnim unosom tajnog ključa.
3. Upišite šestocifreni kod iz aplikacije i kliknite **Potvrdi i uključi**.

Isključivanje traži vašu lozinku. Ako izgubite telefon, administrator vam postavlja novu lozinku, a 2FA ponovo uključite
posle prijave. (Ako je administrator jedini i izgubio je telefon, pogledajte [bezbednost.md](bezbednost.md).)

## API za knjigovođu (samo administrator)
**Podešavanja → API za knjigovođu** sadrži prekidač **Uključi API za knjigovođu**. Pri uključivanju dobijate nasumičnu
osnovnu adresu i ključ (ključ se prikazuje samo jednom). **Nov ključ** poništava stari, **Isključi API** ga gasi.
Sam API (čitanje korisnika i računa iz knjigovodstvenog programa) dolazi u narednoj verziji; opis je u [api.md](api.md).

## Dnevnik radnji
**Dnevnik** prikazuje poslednjih 200 radnji: prijave i odjave, dodavanje i izmene korisnika, nove ključeve, deaktivacije,
izvoze, promene naloga, lozinki, 2FA, API-ja i ažuriranja — sa vremenom, nalogom i IP adresom.

## Ažuriranje
Administrator u meniju **Ažuriranje** vidi da li postoji nova verzija i šta donosi. Ništa se ne menja bez vaše potvrde,
a pre ažuriranja pravi se rezervna kopija. Detalji su na samoj stranici Ažuriranje.

## Saveti za bezbednost
- Koristite dugačke lozinke (fraze od više reči) i uključite 2FA.
- Svaki zaposleni ima svoj nalog — ne delite naloge.
- Deaktivirajte nalog zaposlenog čim prestane da radi kod vas.
- QR kartice tretirajte kao ključeve: ko ima QR, može da šalje račune u ime tog korisnika.

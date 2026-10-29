<?php
use RacunatorPanel\Controllers\Panel\Format;
use function RacunatorPanel\Kernel\e;
?>
<p class="breadcrumbs"><a href="<?= e($u('/podesavanja')) ?>">Podešavanja</a> › API za knjigovođu</p>
<section class="card">
  <h1>API za knjigovođu</h1>
  <p>API omogućava da vaš knjigovodstveni program sam preuzima korisnike i račune iz panela (samo čitanje).
     Isključen je dok ga ovde ne uključite. Adresa je nasumična, a pristup traži poseban ključ.</p>

  <?php if ($newKey !== null): ?>
    <div class="alert alert-warning" role="alert"><strong>Ključ se prikazuje samo ovaj put.</strong> Sačuvajte ga na sigurnom mestu (npr. u menadžeru lozinki).</div>
    <dl class="kv">
      <dt>API ključ</dt><dd><code id="api-key" class="secret"><?= e($newKey) ?></code> <button type="button" class="btn btn-small" data-copy="api-key">Kopiraj</button></dd>
    </dl>
  <?php endif; ?>

  <?php if ($enabled): ?>
    <p><span class="badge badge-ok">uključen</span><?= $enabledAt !== null ? ' od ' . e(Format::utc($enabledAt)) : '' ?></p>
    <dl class="kv">
      <dt>Osnovna adresa</dt><dd><code id="api-base"><?= e($baseUrl ?? '') ?></code> <button type="button" class="btn btn-small" data-copy="api-base">Kopiraj</button></dd>
      <dt>Ključ</dt><dd><code><?= e($keyPrefix) ?>…</code> <span class="muted">(ceo ključ se ne čuva)</span></dd>
    </dl>
    <div class="form-actions">
      <form method="post" action="<?= e($u('/podesavanja/api')) ?>" class="inline" data-confirm="Napraviti nov ključ? Dosadašnji ključ odmah prestaje da važi.">
        <input type="hidden" name="_token" value="<?= e($csrf) ?>">
        <input type="hidden" name="radnja" value="novi-kljuc">
        <input type="hidden" name="_nonce" value="<?= e($rotateNonce) ?>">
        <button type="submit" class="btn btn-warning">Nov ključ</button>
      </form>
      <form method="post" action="<?= e($u('/podesavanja/api')) ?>" class="inline" data-confirm="Isključiti API za knjigovođu? Ključ prestaje da važi; pri ponovnom uključivanju dobijate novu adresu i ključ.">
        <input type="hidden" name="_token" value="<?= e($csrf) ?>">
        <input type="hidden" name="radnja" value="iskljuci">
        <button type="submit" class="btn btn-danger">Isključi API</button>
      </form>
    </div>
  <?php else: ?>
    <p><span class="badge">isključen</span></p>
    <form method="post" action="<?= e($u('/podesavanja/api')) ?>" data-confirm="Uključiti API za knjigovođu? Dobićete novu adresu i ključ koji se prikazuje samo jednom.">
      <input type="hidden" name="_token" value="<?= e($csrf) ?>">
      <input type="hidden" name="radnja" value="ukljuci">
      <button type="submit" class="btn btn-primary">Uključi API za knjigovođu</button>
    </form>
  <?php endif; ?>
</section>

<section class="card" id="dokumentacija">
  <h2>Dokumentacija</h2>
  <p>Svi pozivi su <code>GET</code> na osnovnu adresu iznad, sa zaglavljem <code>Authorization: Bearer &lt;ključ&gt;</code>.
     Odgovori su JSON (<code>application/json; charset=utf-8</code>), osim CSV izvoza. API samo čita — ništa ne menja u panelu.</p>
  <div class="table-wrap">
  <table class="table">
    <thead><tr><th>Poziv</th><th>Šta vraća</th></tr></thead>
    <tbody>
      <tr><td><code>/ping</code></td><td>Provera ključa: <code>{"ok":true,"accountant":"…","api":1}</code></td></tr>
      <tr><td><code>/clients</code></td><td>Korisnici: <code>id</code>, <code>name</code>, <code>active</code>, <code>createdAt</code>, <code>lastSeenAt</code>, <code>receiptsCount</code></td></tr>
      <tr><td><code>/clients/{id}/receipts?month=YYYY-MM&amp;page=1&amp;perPage=100</code></td><td>Računi korisnika za mesec, po stranicama (<code>items</code>, <code>page</code>, <code>perPage</code>, <code>total</code>); <code>month</code> je obavezan, <code>perPage</code> najviše <?= e((string) $perPageMax) ?></td></tr>
      <tr><td><code>/receipts/{id}</code></td><td>Jedan račun; uz <code>?raw=1</code> i izvorni JSON koji je aplikacija poslala (<code>rawJson</code>)</td></tr>
      <tr><td><code>/export?month=YYYY-MM&amp;format=csv</code></td><td>Izvoz meseca: <code>csv</code> (iste kolone kao „Preuzmi CSV" u panelu; bez <code>clientId</code> dodaje se prva kolona „Korisnik") ili <code>json</code> (niz računa); <code>clientId</code> opciono</td></tr>
    </tbody>
  </table>
  </div>
  <ul>
    <li>Iznosi su u parama (<code>totalAmountMinor</code>: 1.234,56 RSD = <code>123456</code>). <code>sdcDateTime</code> je vreme sa računa
        (lokalno vreme PFR-a, <code>2026-10-24T21:42:31</code>); <code>receivedAt</code> je vreme prijema u UTC-u (<code>…Z</code>).</li>
    <li>Pogrešan ključ, pogrešna adresa i isključen API daju isti odgovor <code>401</code>. Posle <?= e((string) $maxFails) ?> promašaja sa iste
        adrese u 15 minuta adresa je privremeno blokirana (<code>429</code>), a promašaji se upisuju u dnevnik radnji.</li>
    <li>Najviše <?= e((string) $rateLimit) ?> zahteva u minutu; preko toga <code>429</code> sa zaglavljem <code>Retry-After</code> (sekunde).</li>
    <li>Greške: <code>{"error":"…","message":"…"}</code> — <code>invalid_parameter</code> (400), <code>unauthorized</code> (401), <code>not_found</code> (404),
        <code>rate_limited</code> / <code>auth_blocked</code> (429), <code>maintenance</code> (503 tokom ažuriranja panela).</li>
  </ul>

  <h3>Primeri (curl)</h3>
  <pre class="code-block"><code><?= e(implode("\n", [
      'API="' . ($baseUrl ?? $exampleBase) . '"',
      'KLJUC="rk_…"   # ceo ključ prikazan pri uključivanju ili pri „Nov ključ"',
      '',
      'curl -s "$API/ping" -H "Authorization: Bearer $KLJUC"',
      'curl -s "$API/clients" -H "Authorization: Bearer $KLJUC"',
      'curl -s "$API/clients/12/receipts?month=2026-10&page=1&perPage=100" -H "Authorization: Bearer $KLJUC"',
      'curl -s "$API/receipts/345?raw=1" -H "Authorization: Bearer $KLJUC"',
      'curl -s -o racuni-2026-10.csv "$API/export?month=2026-10&format=csv" -H "Authorization: Bearer $KLJUC"',
      'curl -s "$API/export?month=2026-10&format=json&clientId=12" -H "Authorization: Bearer $KLJUC"',
  ])) ?></code></pre>
  <p class="hint">Ključ čuvajte kao lozinku: ne stavljajte ga u adresu, u e-poruke ni u deljene tabele. API pozivajte isključivo preko HTTPS-a.</p>
  <p><a class="btn" href="<?= e($u('/podesavanja/api/openapi.yaml')) ?>">Preuzmi OpenAPI opis (openapi.yaml)</a></p>
</section>

<?php
use function RacunatorPanel\Kernel\e;
?>
<div class="no-print">
  <p class="breadcrumbs"><a href="<?= e($u('/korisnici')) ?>">Korisnici</a> › <a href="<?= e($u('/korisnici/' . $client->id)) ?>"><?= e($client->name) ?></a> › QR kod</p>
  <h1>QR kod za korisnika „<?= e($client->name) ?>"</h1>
  <div class="alert alert-warning" role="alert">
    <strong>QR kod i ključ se prikazuju samo ovaj put.</strong> Posle napuštanja ove stranice ne mogu se ponovo videti.
    Ako ih izgubite, na stranici korisnika kliknite „Nov ključ".
    <?php if ($reason === 'novi-kljuc'): ?> Stari ključ je upravo prestao da važi — korisnik mora ponovo da skenira novi QR kod.<?php endif; ?>
  </div>
  <?php if (!$client->active): ?>
    <div class="alert alert-error">Korisnik je deaktiviran — aplikacija neće moći da šalje račune dok ga ne aktivirate.</div>
  <?php endif; ?>
  <?php if (empty($accountantName)): ?>
    <div class="alert alert-warning">Naziv knjigovodstva nije upisan u Podešavanjima, pa se u aplikaciji neće prikazati.</div>
  <?php endif; ?>
</div>

<section class="print-card" aria-label="Kartica za korisnika">
  <p class="print-card-accountant"><?= e($accountantName !== '' ? $accountantName : 'Knjigovodstvo') ?></p>
  <h2 class="print-card-client"><?= e($client->name) ?></h2>
  <div class="qr" data-qr="<?= e($payload) ?>" role="img" aria-label="QR kod za uparivanje aplikacije Računator"></div>
  <noscript><p class="field-error">Za prikaz QR koda uključite JavaScript u pregledaču.</p></noscript>
  <ol class="print-card-steps">
    <li>Otvorite <strong>Računator</strong> na telefonu.</li>
    <li>Idite na <strong>Podešavanja → Knjigovođa → Skeniraj</strong>.</li>
    <li>Usmerite kameru na ovaj QR kod i potvrdite uparivanje.</li>
  </ol>
  <p class="print-card-note">Ovaj QR kod sadrži tajni ključ — ne delite ga i ne objavljujte. Posle skeniranja karticu uništite ili čuvajte na sigurnom.</p>
</section>

<div class="no-print">
  <div class="form-actions">
    <button type="button" class="btn btn-primary" data-print>Štampaj karticu</button>
    <a class="btn" href="<?= e($u('/korisnici/' . $client->id)) ?>">Gotovo — nazad na korisnika</a>
  </div>

  <details class="card">
    <summary>Podaci za ručni unos (ako skeniranje ne radi)</summary>
    <dl class="kv">
      <dt>Adresa (endpoint)</dt><dd><code id="endpoint-text"><?= e($endpoint) ?></code> <button type="button" class="btn btn-small" data-copy="endpoint-text">Kopiraj</button></dd>
      <dt>API ključ</dt><dd><code id="key-text" class="secret"><?= e($key) ?></code> <button type="button" class="btn btn-small" data-copy="key-text">Kopiraj</button></dd>
    </dl>
  </details>
</div>

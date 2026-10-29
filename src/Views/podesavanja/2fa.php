<?php
use RacunatorPanel\Controllers\Panel\Format;
use function RacunatorPanel\Kernel\e;
?>
<p class="breadcrumbs"><a href="<?= e($u('/podesavanja')) ?>">Podešavanja</a> › Dvofaktorska prijava</p>
<section class="card narrow">
  <h1>Dvofaktorska prijava</h1>
  <?php if (!empty($error)): ?><div class="alert alert-error" role="alert"><?= e($error) ?></div><?php endif; ?>
  <?php if ($enabled): ?>
    <p><span class="badge badge-ok">uključena</span> Pri prijavi se pored lozinke traži i šestocifreni kod iz aplikacije za autentifikaciju.</p>
    <h2>Isključivanje</h2>
    <p class="muted">Isključite samo ako menjate telefon ili aplikaciju — posle toga odmah ponovo uključite.</p>
    <form method="post" action="<?= e($u('/podesavanja/2fa')) ?>" class="form" data-confirm="Isključiti dvofaktorsku prijavu? Nalog će biti zaštićen samo lozinkom.">
      <input type="hidden" name="_token" value="<?= e($csrf) ?>">
      <input type="hidden" name="radnja" value="iskljuci">
      <label for="lozinka">Vaša lozinka</label>
      <input id="lozinka" name="lozinka" type="password" autocomplete="current-password" required>
      <button type="submit" class="btn btn-danger">Isključi dvofaktorsku prijavu</button>
    </form>
  <?php else: ?>
    <p>Dvofaktorska prijava štiti nalog i kad neko sazna lozinku. Potrebna vam je aplikacija za autentifikaciju na telefonu
       (Google Authenticator, Microsoft Authenticator, Aegis, 2FAS…).</p>
    <ol>
      <li>U aplikaciji izaberite dodavanje naloga i skenirajte QR kod ispod.</li>
      <li>Ako ne možete da skenirate, ručno unesite tajni ključ.</li>
      <li>Upišite šestocifreni kod koji aplikacija prikaže i potvrdite.</li>
    </ol>
    <div class="qr qr-small" data-qr="<?= e($otpUri) ?>" role="img" aria-label="QR kod za aplikaciju za autentifikaciju"></div>
    <noscript><p class="field-error">Za prikaz QR koda uključite JavaScript ili unesite ključ ručno.</p></noscript>
    <p>Tajni ključ: <code id="totp-secret" class="secret"><?= e(Format::groupSecret((string) $secret)) ?></code>
      <button type="button" class="btn btn-small" data-copy="totp-secret">Kopiraj</button></p>
    <form method="post" action="<?= e($u('/podesavanja/2fa')) ?>" class="form">
      <input type="hidden" name="_token" value="<?= e($csrf) ?>">
      <input type="hidden" name="radnja" value="ukljuci">
      <label for="kod">Kod iz aplikacije</label>
      <input id="kod" name="kod" type="text" inputmode="numeric" pattern="[0-9 ]{6,7}" maxlength="7" autocomplete="one-time-code" required placeholder="123456">
      <button type="submit" class="btn btn-primary">Potvrdi i uključi</button>
    </form>
  <?php endif; ?>
</section>

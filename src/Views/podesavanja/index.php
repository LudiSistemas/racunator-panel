<?php
use function RacunatorPanel\Kernel\e;
$isAdmin = $currentUser !== null && $currentUser->isAdmin();
?>
<h1>Podešavanja</h1>
<div class="grid-2">
  <section class="card">
    <h2>Knjigovodstvo</h2>
    <?php if ($isAdmin): ?>
    <form method="post" action="<?= e($u('/podesavanja')) ?>" class="form">
      <input type="hidden" name="_token" value="<?= e($csrf) ?>">
      <label for="naziv">Naziv knjigovodstva</label>
      <input id="naziv" name="naziv" type="text" maxlength="150" value="<?= e($name) ?>" required placeholder="npr. Knjigovodstvo Petrović"<?= $error !== null ? ' aria-invalid="true"' : '' ?>>
      <?php if ($error !== null): ?><p class="field-error"><?= e($error) ?></p><?php endif; ?>
      <p class="hint">Prikazuje se korisnicima u aplikaciji Računator posle skeniranja QR koda i na odštampanoj kartici.</p>
      <button type="submit" class="btn btn-primary">Sačuvaj</button>
    </form>
    <?php else: ?>
    <dl class="kv"><dt>Naziv knjigovodstva</dt><dd><?= e($name !== '' ? $name : '—') ?></dd></dl>
    <p class="muted">Naziv menja administrator.</p>
    <?php endif; ?>
    <dl class="kv">
      <dt>Javna adresa panela</dt><dd><code><?= e($publicUrl !== '' ? $publicUrl : '(nije podešena)') ?></code></dd>
      <dt>Verzija</dt><dd><?= e($appVersion) ?><?php if ($isAdmin): ?> · <a href="<?= e($u('/azuriranje')) ?>">Ažuriranje</a><?php endif; ?></dd>
    </dl>
    <p class="hint">Javna adresa se podešava pri instalaciji (datoteka <code>config.php</code>); od nje zavise adrese u QR kodovima.</p>
  </section>

  <section class="card">
    <h2>Moj nalog</h2>
    <ul class="links">
      <li><a href="<?= e($u('/podesavanja/lozinka')) ?>">Promena lozinke</a></li>
      <li><a href="<?= e($u('/podesavanja/2fa')) ?>">Dvofaktorska prijava</a> —
        <?= $twoFactorOn ? '<span class="badge badge-ok">uključena</span>' : '<span class="badge badge-off">isključena</span>' ?></li>
      <?php if ($isAdmin): ?>
      <li><a href="<?= e($u('/podesavanja/api')) ?>">API za knjigovođu</a> —
        <?= $apiEnabled ? '<span class="badge badge-ok">uključen</span>' : '<span class="badge">isključen</span>' ?></li>
      <?php endif; ?>
    </ul>
  </section>
</div>

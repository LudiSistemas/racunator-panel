<?php
use function RacunatorPanel\Kernel\e;
?>
<p class="breadcrumbs"><a href="<?= e($u('/podesavanja')) ?>">Podešavanja</a> › Promena lozinke</p>
<section class="card narrow">
  <h1>Promena lozinke</h1>
  <form method="post" action="<?= e($u('/podesavanja/lozinka')) ?>" class="form">
    <input type="hidden" name="_token" value="<?= e($csrf) ?>">
    <label for="trenutna">Trenutna lozinka</label>
    <input id="trenutna" name="trenutna" type="password" autocomplete="current-password" required autofocus>
    <?php if (isset($errors['trenutna'])): ?><p class="field-error"><?= e($errors['trenutna']) ?></p><?php endif; ?>
    <label for="lozinka">Nova lozinka (najmanje <?= e($passwordMin) ?> znakova)</label>
    <input id="lozinka" name="lozinka" type="password" minlength="<?= e($passwordMin) ?>" autocomplete="new-password" required>
    <label for="lozinka_ponovo">Ponovite novu lozinku</label>
    <input id="lozinka_ponovo" name="lozinka_ponovo" type="password" minlength="<?= e($passwordMin) ?>" autocomplete="new-password" required>
    <?php if (isset($errors['lozinka'])): ?><p class="field-error"><?= e($errors['lozinka']) ?></p><?php endif; ?>
    <div class="form-actions">
      <button type="submit" class="btn btn-primary">Promeni lozinku</button>
      <a class="btn" href="<?= e($u('/podesavanja')) ?>">Odustani</a>
    </div>
  </form>
</section>

<?php
use function RacunatorPanel\Kernel\e;
?>
<p class="breadcrumbs"><a href="<?= e($u('/nalozi')) ?>">Nalozi</a> › Novi nalog</p>
<section class="card narrow">
  <h1>Novi nalog</h1>
  <form method="post" action="<?= e($u('/nalozi/novi')) ?>" class="form">
    <input type="hidden" name="_token" value="<?= e($csrf) ?>">
    <label for="korisnicko_ime">Korisničko ime</label>
    <input id="korisnicko_ime" name="korisnicko_ime" type="text" maxlength="64" value="<?= e($username) ?>" autocomplete="off" autocapitalize="none" spellcheck="false" required autofocus>
    <?php if (isset($errors['korisnicko_ime'])): ?><p class="field-error"><?= e($errors['korisnicko_ime']) ?></p><?php endif; ?>
    <fieldset>
      <legend>Uloga</legend>
      <label class="choice"><input type="radio" name="uloga" value="zaposleni"<?= $role !== 'admin' ? ' checked' : '' ?>> Zaposleni — korisnici i računi</label>
      <label class="choice"><input type="radio" name="uloga" value="admin"<?= $role === 'admin' ? ' checked' : '' ?>> Administrator — sve, uključujući naloge, API i ažuriranje</label>
      <?php if (isset($errors['uloga'])): ?><p class="field-error"><?= e($errors['uloga']) ?></p><?php endif; ?>
    </fieldset>
    <label for="lozinka">Lozinka (najmanje <?= e($passwordMin) ?> znakova)</label>
    <input id="lozinka" name="lozinka" type="password" minlength="<?= e($passwordMin) ?>" autocomplete="new-password" required>
    <label for="lozinka_ponovo">Ponovite lozinku</label>
    <input id="lozinka_ponovo" name="lozinka_ponovo" type="password" minlength="<?= e($passwordMin) ?>" autocomplete="new-password" required>
    <?php if (isset($errors['lozinka'])): ?><p class="field-error"><?= e($errors['lozinka']) ?></p><?php endif; ?>
    <p class="hint">Dobra lozinka je fraza od nekoliko reči, npr. „plavi-kofer-sedam-oblaka". Zaposleni posle prve prijave može da je promeni u Podešavanjima.</p>
    <div class="form-actions">
      <button type="submit" class="btn btn-primary">Napravi nalog</button>
      <a class="btn" href="<?= e($u('/nalozi')) ?>">Odustani</a>
    </div>
  </form>
</section>

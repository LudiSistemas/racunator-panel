<?php
use function RacunatorPanel\Kernel\e;
?>
<p class="breadcrumbs"><a href="<?= e($u('/korisnici')) ?>">Korisnici</a> › Dodaj korisnika</p>
<section class="card narrow">
  <h1>Dodaj korisnika</h1>
  <p>Korisnik je firma ili preduzetnik čije račune primate. Posle čuvanja dobićete QR kod koji korisnik skenira u aplikaciji Računator.
     QR kod i ključ se prikazuju <strong>samo jednom</strong> — odmah ih odštampajte ili pokažite korisniku.</p>
  <form method="post" action="<?= e($u('/korisnici/novi')) ?>" class="form">
    <input type="hidden" name="_token" value="<?= e($csrf) ?>">
    <input type="hidden" name="_nonce" value="<?= e($nonce) ?>">
    <label for="ime">Ime korisnika</label>
    <input id="ime" name="ime" type="text" maxlength="150" value="<?= e($name) ?>" required autofocus placeholder="npr. Pera DOO"<?= isset($errors['ime']) ? ' aria-invalid="true" aria-describedby="ime-greska"' : '' ?>>
    <?php if (isset($errors['ime'])): ?><p class="field-error" id="ime-greska"><?= e($errors['ime']) ?></p><?php endif; ?>
    <label for="napomena">Napomena <span class="muted">(opciono, vidi je samo knjigovodstvo)</span></label>
    <textarea id="napomena" name="napomena" rows="3" maxlength="1000"<?= isset($errors['napomena']) ? ' aria-invalid="true"' : '' ?>><?= e($note) ?></textarea>
    <?php if (isset($errors['napomena'])): ?><p class="field-error"><?= e($errors['napomena']) ?></p><?php endif; ?>
    <div class="form-actions">
      <button type="submit" class="btn btn-primary">Sačuvaj i prikaži QR kod</button>
      <a class="btn" href="<?= e($u('/korisnici')) ?>">Odustani</a>
    </div>
  </form>
</section>

<?php
use function RacunatorPanel\Kernel\e;
?>
<section class="card narrow login">
  <h1>Prijava</h1>
  <?php if (!empty($accountantName)): ?><p class="muted"><?= e($accountantName) ?></p><?php endif; ?>
  <?php if (!empty($error)): ?><div class="alert alert-error" role="alert"><?= e($error) ?></div><?php endif; ?>
  <form method="post" action="<?= e($u('/prijava')) ?>" class="form">
    <input type="hidden" name="_token" value="<?= e($csrf) ?>">
    <label for="korisnicko_ime">Korisničko ime</label>
    <input id="korisnicko_ime" name="korisnicko_ime" type="text" value="<?= e($username ?? '') ?>" autocomplete="username" autocapitalize="none" spellcheck="false" required<?= empty($username) ? ' autofocus' : '' ?>>
    <label for="lozinka">Lozinka</label>
    <input id="lozinka" name="lozinka" type="password" autocomplete="current-password" required<?= !empty($username) ? ' autofocus' : '' ?>>
    <details class="totp-field"<?= !empty($needsCode) ? ' open' : '' ?>>
      <summary>Imam uključenu dvofaktorsku prijavu</summary>
      <label for="kod">Kod iz aplikacije za autentifikaciju</label>
      <input id="kod" name="kod" type="text" inputmode="numeric" pattern="[0-9 ]{6,7}" maxlength="7" autocomplete="one-time-code" placeholder="123456"<?= !empty($needsCode) ? ' required' : '' ?>>
      <p class="hint">Šestocifreni kod iz aplikacije (npr. Google Authenticator, Microsoft Authenticator, Aegis).<?= !empty($needsCode) ? ' Unesite ponovo i lozinku.' : '' ?></p>
    </details>
    <button type="submit" class="btn btn-primary">Prijavi se</button>
  </form>
</section>

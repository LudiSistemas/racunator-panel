<?php
/**
 * Prijava u dva koraka: $step = 'lozinka' (korisničko ime i lozinka) ili 'kod' (samo kod za dvofaktorsku prijavu).
 */
use function RacunatorPanel\Kernel\e;

$step = ($step ?? 'lozinka') === 'kod' ? 'kod' : 'lozinka';
?>
<section class="card narrow login">
  <h1>Prijava</h1>
  <?php if (!empty($accountantName)): ?><p class="muted"><?= e($accountantName) ?></p><?php endif; ?>
  <?php if (!empty($error)): ?><div class="alert alert-error" role="alert"><?= e($error) ?></div><?php endif; ?>
  <?php if ($step === 'kod'): ?>
  <form method="post" action="<?= e($u('/prijava')) ?>" class="form">
    <input type="hidden" name="_token" value="<?= e($csrf) ?>">
    <input type="hidden" name="korak" value="kod">
    <p>Prijavljujete se kao <strong><?= e($username ?? '') ?></strong>. Unesite šestocifreni kod iz aplikacije za
       autentifikaciju (npr. Google Authenticator, Microsoft Authenticator, Aegis).</p>
    <label for="kod">Kod iz aplikacije</label>
    <input id="kod" name="kod" type="text" inputmode="numeric" pattern="[0-9 ]{6,7}" maxlength="7" autocomplete="one-time-code" placeholder="123456" required autofocus>
    <p class="hint">Kod važi 30 sekundi; za unos imate 5 minuta, posle toga se prijava ponavlja.</p>
    <button type="submit" class="btn btn-primary">Potvrdi</button>
  </form>
  <form method="post" action="<?= e($u('/prijava')) ?>" class="inline">
    <input type="hidden" name="_token" value="<?= e($csrf) ?>">
    <input type="hidden" name="radnja" value="nazad">
    <button type="submit" class="linklike">Nazad (drugi nalog)</button>
  </form>
  <?php else: ?>
  <form method="post" action="<?= e($u('/prijava')) ?>" class="form">
    <input type="hidden" name="_token" value="<?= e($csrf) ?>">
    <label for="korisnicko_ime">Korisničko ime</label>
    <input id="korisnicko_ime" name="korisnicko_ime" type="text" value="<?= e($username ?? '') ?>" autocomplete="username" autocapitalize="none" spellcheck="false" required<?= empty($username) ? ' autofocus' : '' ?>>
    <label for="lozinka">Lozinka</label>
    <input id="lozinka" name="lozinka" type="password" autocomplete="current-password" required<?= !empty($username) ? ' autofocus' : '' ?>>
    <p class="hint">Ako ste uključili dvofaktorsku prijavu, kod iz aplikacije unosite u sledećem koraku.</p>
    <button type="submit" class="btn btn-primary">Prijavi se</button>
  </form>
  <?php endif; ?>
</section>

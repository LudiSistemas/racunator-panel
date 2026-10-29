<?php
/**
 * Uslovi korišćenja. Promenljive: $termsVersion, $date, $termsUrl, $summary (list<string>), $mustAccept (admin mora da prihvati),
 * $pendingForAdmin (zaposleni: čeka se administrator), $acceptedVersion, $acceptedAt (UTC ili null), $error, $u, $csrf.
 */
use RacunatorPanel\Controllers\Panel\Format;
use function RacunatorPanel\Kernel\e;
?>
<section class="card narrow">
  <h1>Uslovi korišćenja</h1>
  <p class="muted">Verzija <?= e($termsVersion) ?> · važi od <?= e($date) ?></p>
  <?php if ($mustAccept): ?>
    <div class="alert alert-info"><?= $acceptedVersion > 0
        ? 'Uslovi korišćenja su izmenjeni. Pre nastavka rada u panelu pročitajte i prihvatite novu verziju.'
        : 'Pre nastavka rada u panelu pročitajte i prihvatite uslove korišćenja.' ?></div>
  <?php endif; ?>
  <?php if (!empty($error)): ?><div class="alert alert-error" role="alert"><?= e($error) ?></div><?php endif; ?>
  <p>Ukratko:</p>
  <ul>
    <?php foreach ($summary as $point): ?><li><?= e($point) ?></li><?php endforeach; ?>
  </ul>
  <p>Ceo tekst: <a href="<?= e($termsUrl) ?>" target="_blank" rel="noopener noreferrer"><?= e($termsUrl) ?></a>
    (i u fajlu <code>docs/uslovi-koriscenja.md</code> u folderu panela). Za kod panela važi i licenca MIT (fajl <code>LICENSE</code>).</p>
  <?php if ($mustAccept): ?>
    <form method="post" action="<?= e($u('/uslovi')) ?>" class="form">
      <input type="hidden" name="_token" value="<?= e($csrf) ?>">
      <p><label><input type="checkbox" name="prihvatam_uslove" value="da" required> <strong>Prihvatam uslove korišćenja</strong></label></p>
      <div class="form-actions">
        <button type="submit" class="btn btn-primary">Nastavi</button>
      </div>
    </form>
  <?php elseif ($pendingForAdmin): ?>
    <p class="muted">Administrator panela još nije prihvatio ovu verziju uslova.</p>
  <?php else: ?>
    <p class="muted">Prihvaćeno: verzija <?= e($acceptedVersion) ?>, <?= e(Format::utc($acceptedAt)) ?>.</p>
  <?php endif; ?>
</section>

<?php
/** Potvrda povratka iz rezervne kopije. Promenljive: $current, $backup (meta kopije), $u, $csrf. */
use function RacunatorPanel\Kernel\e;
?>
<div class="page-head">
  <h1>Vraćanje na verziju <?= e($backup['version']) ?></h1>
</div>

<div class="alert alert-warning danger-zone">
  <p><strong>Pažnja: vraćaju se i fajlovi i baza</strong> u stanje od <?= e(str_replace(['T', 'Z'], [' ', ' UTC'], (string) $backup['created_at'])) ?>.
    Sve što je stiglo ili promenjeno posle tog trenutka biće <strong>izgubljeno</strong>: računi koje je aplikacija poslala,
    novi korisnici i ključevi, nalozi i podešavanja.</p>
  <p>Pre povratka izvezite nove račune: na stranici <a href="<?= e($u('/korisnici')) ?>">Korisnici</a> otvorite korisnika → računi za mesec → „Izvoz CSV".
    Aplikacija Računator posle povratka može ponovo da pošalje račune koji nedostaju.</p>
</div>

<section class="card">
  <dl class="kv">
    <dt>Trenutna verzija</dt><dd><?= e($current) ?></dd>
    <dt>Vraća se na</dt><dd><?= e($backup['version']) ?> (kopija <?= e($backup['name']) ?>, <?= e($backup['reason']) ?>)</dd>
  </dl>
  <form method="post" action="<?= e($u('/azuriranje/vrati')) ?>" class="form">
    <input type="hidden" name="_token" value="<?= e($csrf) ?>">
    <input type="hidden" name="kopija" value="<?= e($backup['name']) ?>">
    <p><label><input type="checkbox" name="potvrda" value="da" required> Razumem da se gube podaci primljeni posle ove kopije.</label></p>
    <div class="form-actions">
      <button type="submit" class="btn btn-danger">Vrati verziju <?= e($backup['version']) ?></button>
      <a class="btn" href="<?= e($u('/azuriranje')) ?>">Odustani</a>
    </div>
  </form>
</section>

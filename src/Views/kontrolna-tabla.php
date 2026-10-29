<?php
use RacunatorPanel\Controllers\Panel\Format;
use function RacunatorPanel\Kernel\e;
?>
<div class="page-head">
  <h1>Kontrolna tabla</h1>
  <a class="btn btn-primary" href="<?= e($u('/korisnici/novi')) ?>">Dodaj korisnika</a>
</div>
<p class="muted">Računi primljeni u mesecu <strong><?= e(Format::month($month)) ?></strong>: ukupno <?= e($totalCount) ?>.</p>
<?php if ($clients === []): ?>
  <div class="empty">
    <p>Još nema korisnika. Dodajte prvog korisnika — dobićete QR kod koji on skenira u aplikaciji Računator.</p>
    <?php if (empty($accountantName)): ?>
      <p>Pre toga u <a href="<?= e($u('/podesavanja')) ?>">Podešavanjima</a> upišite naziv knjigovodstva (vidi se u aplikaciji i na kartici).</p>
    <?php endif; ?>
  </div>
<?php else: ?>
<div class="table-wrap">
<table class="table">
  <thead><tr><th>Korisnik</th><th class="num">Računa u mesecu</th><th>Poslednje slanje</th><th>Status</th><th></th></tr></thead>
  <tbody>
  <?php foreach ($clients as $c): ?>
    <tr<?= $c->active ? '' : ' class="inactive"' ?>>
      <td data-label="Korisnik"><a href="<?= e($u('/korisnici/' . $c->id)) ?>"><?= e($c->name) ?></a></td>
      <td data-label="Računa u mesecu" class="num"><?= e($counts[$c->id] ?? 0) ?></td>
      <td data-label="Poslednje slanje"><?= e(Format::utc($c->lastSeenAt, 'još nije slao')) ?></td>
      <td data-label="Status"><?= $c->active ? '<span class="badge badge-ok">aktivan</span>' : '<span class="badge badge-off">deaktiviran</span>' ?></td>
      <td class="actions"><a class="btn btn-small" href="<?= e($u('/korisnici/' . $c->id . '/racuni?mesec=' . rawurlencode($month))) ?>">Računi</a></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>
<?php endif; ?>

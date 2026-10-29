<?php
use RacunatorPanel\Controllers\Panel\Format;
use function RacunatorPanel\Kernel\e;
?>
<div class="page-head">
  <h1>Korisnici</h1>
  <a class="btn btn-primary" href="<?= e($u('/korisnici/novi')) ?>">Dodaj korisnika</a>
</div>
<?php if ($clients === []): ?>
  <div class="empty"><p>Još nema korisnika.</p></div>
<?php else: ?>
<div class="table-wrap">
<table class="table">
  <thead><tr><th>Korisnik</th><th>Napomena</th><th class="num">Računa (<?= e(Format::month($month)) ?>)</th><th>Poslednje slanje</th><th>Dodat</th><th>Status</th></tr></thead>
  <tbody>
  <?php foreach ($clients as $c): ?>
    <tr<?= $c->active ? '' : ' class="inactive"' ?>>
      <td data-label="Korisnik"><a href="<?= e($u('/korisnici/' . $c->id)) ?>"><?= e($c->name) ?></a></td>
      <td data-label="Napomena" class="muted"><?= e(mb_strimwidth((string) $c->note, 0, 80, '…')) ?></td>
      <td data-label="Računa" class="num"><a href="<?= e($u('/korisnici/' . $c->id . '/racuni')) ?>"><?= e($counts[$c->id] ?? 0) ?></a></td>
      <td data-label="Poslednje slanje"><?= e(Format::utc($c->lastSeenAt, 'još nije slao')) ?></td>
      <td data-label="Dodat"><?= e(Format::utc($c->createdAt)) ?></td>
      <td data-label="Status"><?= $c->active ? '<span class="badge badge-ok">aktivan</span>' : '<span class="badge badge-off">deaktiviran</span>' ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>
<?php endif; ?>

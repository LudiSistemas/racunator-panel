<?php
use RacunatorPanel\Controllers\Panel\Format;
use function RacunatorPanel\Kernel\e;
?>
<h1>Dnevnik radnji</h1>
<p class="muted">Poslednjih <?= e($limit) ?> radnji u panelu, najnovije prve.</p>
<?php if ($entries === []): ?>
  <div class="empty"><p>Dnevnik je prazan.</p></div>
<?php else: ?>
<div class="table-wrap">
<table class="table">
  <thead><tr><th>Vreme</th><th>Nalog</th><th>Radnja</th><th>Predmet</th><th>Detalji</th><th>IP adresa</th></tr></thead>
  <tbody>
  <?php foreach ($entries as $row): ?>
    <tr>
      <td data-label="Vreme"><?= e(Format::utc($row['at'] ?? null)) ?></td>
      <td data-label="Nalog"><?= e($row['username'] ?? (($row['user_id'] ?? null) !== null ? '#' . $row['user_id'] : 'sistem')) ?></td>
      <td data-label="Radnja"><?= e(Format::auditAction((string) $row['action'])) ?></td>
      <td data-label="Predmet">
        <?php if (($row['subject_type'] ?? null) === 'client' && ($row['subject_id'] ?? null) !== null): ?>
          <a href="<?= e($u('/korisnici/' . $row['subject_id'])) ?>">korisnik #<?= e($row['subject_id']) ?></a>
        <?php elseif (($row['subject_type'] ?? null) === 'user' && ($row['subject_id'] ?? null) !== null): ?>
          nalog #<?= e($row['subject_id']) ?>
        <?php elseif (($row['subject_type'] ?? null) !== null): ?>
          <?= e($row['subject_type']) ?><?= ($row['subject_id'] ?? null) !== null ? ' #' . e($row['subject_id']) : '' ?>
        <?php endif; ?>
      </td>
      <td data-label="Detalji"><?= e($row['details'] ?? '') ?></td>
      <td data-label="IP adresa"><code><?= e($row['ip'] ?? '') ?></code></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>
<?php endif; ?>

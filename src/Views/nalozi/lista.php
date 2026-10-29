<?php
use RacunatorPanel\Controllers\Panel\Format;
use function RacunatorPanel\Kernel\e;
?>
<div class="page-head">
  <h1>Nalozi</h1>
  <a class="btn btn-primary" href="<?= e($u('/nalozi/novi')) ?>">Novi nalog</a>
</div>
<p class="muted">Administrator upravlja nalozima, API-jem i ažuriranjem; zaposleni radi sa korisnicima i računima.</p>
<div class="table-wrap">
<table class="table">
  <thead><tr><th>Korisničko ime</th><th>Uloga</th><th>2FA</th><th>Poslednja prijava</th><th>Status</th><th>Radnje</th></tr></thead>
  <tbody>
  <?php foreach ($users as $usr): ?>
    <tr<?= $usr->active ? '' : ' class="inactive"' ?>>
      <td data-label="Korisničko ime"><?= e($usr->username) ?><?= $usr->id === $me->id ? ' <span class="muted">(vi)</span>' : '' ?></td>
      <td data-label="Uloga"><?= e(Format::role($usr->role)) ?></td>
      <td data-label="2FA"><?= $usr->totpSecret !== null ? '<span class="badge badge-ok">uključena</span>' : '<span class="badge">nije</span>' ?></td>
      <td data-label="Poslednja prijava"><?= e(Format::utc($usr->lastLoginAt, 'nikad')) ?></td>
      <td data-label="Status"><?= $usr->active ? '<span class="badge badge-ok">aktivan</span>' : '<span class="badge badge-off">deaktiviran</span>' ?></td>
      <td class="actions">
        <?php if ($usr->id !== $me->id): ?>
          <?php if ($usr->active): ?>
            <form method="post" action="<?= e($u('/nalozi/' . $usr->id . '/deaktiviraj')) ?>" class="inline" data-confirm="Deaktivirati nalog „<?= e($usr->username) ?>"? Nalog više neće moći da se prijavi.">
              <input type="hidden" name="_token" value="<?= e($csrf) ?>">
              <button type="submit" class="btn btn-small btn-danger">Deaktiviraj</button>
            </form>
          <?php else: ?>
            <form method="post" action="<?= e($u('/nalozi/' . $usr->id . '/aktiviraj')) ?>" class="inline">
              <input type="hidden" name="_token" value="<?= e($csrf) ?>">
              <button type="submit" class="btn btn-small">Aktiviraj</button>
            </form>
          <?php endif; ?>
        <?php endif; ?>
        <details class="inline-details"<?= $openId === $usr->id ? ' open' : '' ?>>
          <summary class="btn btn-small">Nova lozinka</summary>
          <form method="post" action="<?= e($u('/nalozi/' . $usr->id . '/lozinka')) ?>" class="form">
            <input type="hidden" name="_token" value="<?= e($csrf) ?>">
            <label for="lozinka-<?= e($usr->id) ?>">Nova lozinka (najmanje <?= e($passwordMin) ?> znakova)</label>
            <input id="lozinka-<?= e($usr->id) ?>" name="lozinka" type="password" minlength="<?= e($passwordMin) ?>" autocomplete="new-password" required>
            <label for="lozinka2-<?= e($usr->id) ?>">Ponovite lozinku</label>
            <input id="lozinka2-<?= e($usr->id) ?>" name="lozinka_ponovo" type="password" minlength="<?= e($passwordMin) ?>" autocomplete="new-password" required>
            <?php if (isset($passwordErrors[$usr->id])): ?><p class="field-error"><?= e($passwordErrors[$usr->id]) ?></p><?php endif; ?>
            <button type="submit" class="btn btn-small btn-primary">Postavi lozinku</button>
          </form>
        </details>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>

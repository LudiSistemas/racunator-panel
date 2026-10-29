<?php
/**
 * Stranica Ažuriranje. Promenljive: $current, $latest (?ReleaseInfo), $available (?ReleaseInfo), $checkedAt (?int),
 * $checkError (?string), $backups (list), $last (?array), $problems (list<string>), $stuckMaintenance (bool),
 * $manualPackage (?array), $signatureCheck (bool), $u, $csrf.
 */
use function RacunatorPanel\Kernel\e;

$size = static fn (int $b): string => $b >= 1048576 ? number_format($b / 1048576, 1, ',', '.') . ' MB' : number_format($b / 1024, 0, ',', '.') . ' KB';
$statusLabel = ['ok' => 'u redu', 'warn' => 'upozorenje', 'fail' => 'greška', 'info' => 'info'];
$kindLabel = ['azuriranje' => 'Ažuriranje', 'rucno' => 'Ručno ažuriranje', 'povratak' => 'Povratak'];
?>
<div class="page-head">
  <h1>Ažuriranje</h1>
</div>

<?php if ($stuckMaintenance): ?>
  <div class="alert alert-warning">
    <p><strong>Panel je ostao u režimu održavanja</strong> (verovatno je prethodno ažuriranje prekinuto). Aplikacija Računator
      za to vreme ne može da šalje račune. Pogledajte dnevnik ispod, pa isključite režim održavanja.</p>
    <form method="post" action="<?= e($u('/azuriranje/odrzavanje')) ?>" class="form-inline">
      <input type="hidden" name="_token" value="<?= e($csrf) ?>">
      <button type="submit" class="btn btn-warning">Isključi režim održavanja</button>
    </form>
  </div>
<?php endif; ?>

<?php if ($manualPackage !== null): ?>
  <div class="alert alert-info">
    <p>Pronađen je ručno otpremljen paket verzije <strong><?= e($manualPackage['version']) ?></strong>. Da biste ga instalirali,
      otvorite <a href="<?= e($u('/install/')) ?>">čarobnjak za ručno ažuriranje</a> i prijavite se kao administrator.</p>
  </div>
<?php endif; ?>

<section class="card">
  <h2>Instalirana verzija</h2>
  <dl class="kv">
    <dt>Verzija</dt><dd><strong><?= e($current) ?></strong></dd>
    <dt>Poslednja provera</dt><dd><?= $checkedAt !== null ? e(gmdate('d.m.Y. H:i', $checkedAt) . ' UTC') : 'još nije provereno' ?></dd>
    <dt>Provera potpisa paketa</dt><dd><?= $signatureCheck ? 'uključena (Ed25519)' : 'nije moguća na ovom hostingu — proverava se samo SHA-256' ?></dd>
  </dl>
  <?php if ($checkError !== null): ?>
    <p class="alert alert-error">Provera nove verzije nije uspela: <?= e($checkError) ?></p>
  <?php endif; ?>
  <form method="post" action="<?= e($u('/azuriranje')) ?>" class="form-inline">
    <input type="hidden" name="_token" value="<?= e($csrf) ?>">
    <button type="submit" class="btn">Proveri sada</button>
    <span class="hint">Panel pita GitHub samo za broj nove verzije i njen opis; ne šalje nikakve podatke o vama.</span>
  </form>
</section>

<?php if ($available !== null): ?>
  <section class="card">
    <h2>Dostupna je verzija <?= e($available->version) ?></h2>
    <p class="muted"><?= e($available->name) ?><?= $available->publishedAt !== null ? ' · objavljeno ' . e(substr($available->publishedAt, 0, 10)) : '' ?></p>
    <h3>Šta je novo</h3>
    <div class="changelog"><?= $available->body !== '' ? nl2br(e($available->body)) : '<p class="muted">Izdanje nema opis izmena.</p>' ?></div>
    <?php if ($problems !== []): ?>
      <div class="alert alert-error">
        <p><strong>Ažuriranje iz panela trenutno nije moguće:</strong></p>
        <ul><?php foreach ($problems as $p): ?><li><?= e($p) ?></li><?php endforeach; ?></ul>
        <p>Možete ažurirati ručno: preuzmite install fajl sa stranice izdanja, otpremite ga preko FTP-a u folder panela i otvorite ga u pregledaču (vidi docs/azuriranje.md).</p>
      </div>
    <?php else: ?>
      <p><a class="btn btn-primary" href="<?= e($u('/azuriranje/pokreni')) ?>">Ažuriraj na <?= e($available->version) ?></a></p>
      <p class="hint">Na sledećem koraku vidite šta će se desiti i potvrđujete. Do potvrde se ništa ne preuzima.</p>
    <?php endif; ?>
  </section>
<?php elseif ($latest !== null): ?>
  <section class="card"><p>Imate najnoviju verziju (<?= e($latest->version) ?>).</p></section>
<?php endif; ?>

<?php if ($last !== null): ?>
  <section class="card" id="poslednje">
    <h2>Poslednje: <?= e($kindLabel[$last['kind'] ?? ''] ?? 'Ažuriranje') ?> <?= e(($last['from'] ?? '?') . ' → ' . ($last['to'] ?? '?')) ?></h2>
    <?php if (!empty($last['ok'])): ?>
      <p class="alert alert-success">Završeno uspešno<?= isset($last['finished_at']) ? ' (' . e($last['finished_at']) . ')' : '' ?>.</p>
    <?php elseif (!empty($last['rolled_back'])): ?>
      <p class="alert alert-warning">Nije uspelo, prethodna verzija je automatski vraćena. Razlog: <?= e($last['error'] ?? '') ?></p>
    <?php else: ?>
      <p class="alert alert-error">Nije uspelo: <?= e($last['error'] ?? '') ?></p>
    <?php endif; ?>
    <div class="table-wrap">
      <table class="table">
        <thead><tr><th>Vreme (UTC)</th><th>Korak</th><th>Stanje</th><th>Opis</th></tr></thead>
        <tbody>
        <?php foreach ((array) ($last['steps'] ?? []) as $s): ?>
          <tr<?= ($s['status'] ?? '') === 'fail' ? ' class="danger-row"' : '' ?>>
            <td data-label="Vreme"><?= e(substr((string) ($s['at'] ?? ''), 11, 8)) ?></td>
            <td data-label="Korak"><?= e($s['step'] ?? '') ?></td>
            <td data-label="Stanje"><span class="badge<?= ($s['status'] ?? '') === 'ok' ? ' badge-ok' : (($s['status'] ?? '') === 'fail' ? ' badge-off' : '') ?>"><?= e($statusLabel[$s['status'] ?? ''] ?? '') ?></span></td>
            <td data-label="Opis"><?= e($s['message'] ?? '') ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <p class="hint">Ceo dnevnik je u storage/logs/update.log.</p>
  </section>
<?php endif; ?>

<section class="card">
  <h2>Rezervne kopije</h2>
  <p class="muted">Pre svakog ažuriranja pravi se kopija fajlova i baze; čuvaju se poslednje tri. Kopije sadrže sve podatke panela
    (i heševe lozinki) — preuzete fajlove čuvajte na sigurnom.</p>
  <?php if ($backups === []): ?>
    <div class="empty"><p>Još nema rezervnih kopija.</p></div>
  <?php else: ?>
    <div class="table-wrap">
      <table class="table">
        <thead><tr><th>Napravljena</th><th>Verzija</th><th>Povod</th><th>Veličina</th><th>Preuzmi</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($backups as $b): ?>
          <tr>
            <td data-label="Napravljena"><?= e(str_replace(['T', 'Z'], [' ', ' UTC'], (string) $b['created_at'])) ?></td>
            <td data-label="Verzija"><?= e($b['version']) ?></td>
            <td data-label="Povod"><?= e($b['reason']) ?></td>
            <td data-label="Veličina"><?= e($size((int) $b['size'])) ?></td>
            <td data-label="Preuzmi">
              <a href="<?= e($u('/azuriranje/kopije/' . $b['name'] . '/fajlovi.zip')) ?>">fajlovi</a> ·
              <a href="<?= e($u('/azuriranje/kopije/' . $b['name'] . '/' . (($b['driver'] ?? '') === 'sqlite' ? 'baza.sqlite' : 'baza.sql'))) ?>">baza</a>
            </td>
            <td><a class="btn btn-small btn-warning" href="<?= e($u('/azuriranje/vrati?kopija=' . rawurlencode((string) $b['name']))) ?>">Vrati ovu verziju (<?= e($b['version']) ?>)</a></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</section>

<section class="card">
  <h2>Ručno ažuriranje</h2>
  <p>Ako hosting ne dozvoljava preuzimanje iz panela: preuzmite <code>racunator-panel-install.php</code> sa stranice izdanja na GitHub-u,
    otpremite ga preko FTP-a ili File Manager-a u folder panela (pored config.php) i otvorite ga u pregledaču. Install fajl prepoznaje
    postojeću instalaciju i radi isto ažuriranje (sa rezervnom kopijom), posle prijave administratora.</p>
</section>

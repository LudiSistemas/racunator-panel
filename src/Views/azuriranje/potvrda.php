<?php
/** Potvrda ažuriranja. Promenljive: $current, $release (ReleaseInfo), $problems, $signatureCheck, $u, $csrf. */
use function RacunatorPanel\Kernel\e;
?>
<div class="page-head">
  <h1>Ažuriranje <?= e($current) ?> → <?= e($release->version) ?></h1>
</div>

<section class="card">
  <h2>Šta će se desiti</h2>
  <ol>
    <li>Panel prelazi u režim održavanja: aplikacija Računator dobija „pokušaj kasnije" i sama ponavlja slanje posle ažuriranja.</li>
    <li>Pravi se <strong>rezervna kopija</strong> svih fajlova panela i cele baze (u storage/backups/).</li>
    <li>Preuzima se paket <?= e($release->zipName()) ?><?= $release->zipSize !== null ? ' (' . e(number_format($release->zipSize / 1048576, 1, ',', '.')) . ' MB)' : '' ?>
      sa GitHub-a i proverava se SHA-256 i digitalni potpis (Ed25519); paket sa neispravnim ili bez potpisa se odbija.</li>
    <li>Menjaju se fajlovi programa (public/, src/, install/, docs/). <strong>Ostaju netaknuti:</strong> config.php, baza, korisnici, ključevi,
      nalozi, podešavanja, vaš .htaccess i storage/.</li>
    <li>Baza se prilagođava novoj verziji (migracije), pa panel sam proverava da radi.</li>
    <li>Ako bilo koji korak ne uspe, automatski se vraća prethodna verzija.</li>
  </ol>
  <p><strong>Trajanje:</strong> obično manje od minuta; sa mnogo računa u bazi do nekoliko minuta. Ne zatvarajte stranicu dok ne završi.</p>
</section>

<section class="card">
  <h2>Izmene u verziji <?= e($release->version) ?></h2>
  <div class="changelog"><?= $release->body !== '' ? nl2br(e($release->body)) : '<p class="muted">Izdanje nema opis izmena.</p>' ?></div>
</section>

<?php if ($problems !== []): ?>
  <div class="alert alert-error">
    <p><strong>Ažuriranje sada nije moguće:</strong></p>
    <ul><?php foreach ($problems as $p): ?><li><?= e($p) ?></li><?php endforeach; ?></ul>
  </div>
  <p><a class="btn" href="<?= e($u('/azuriranje')) ?>">Nazad</a></p>
<?php else: ?>
  <form method="post" action="<?= e($u('/azuriranje/pokreni')) ?>" class="form">
    <input type="hidden" name="_token" value="<?= e($csrf) ?>">
    <input type="hidden" name="verzija" value="<?= e($release->version) ?>">
    <p><label><input type="checkbox" name="potvrda" value="da" required> Razumem: biće napravljena rezervna kopija, a panel će kratko biti nedostupan.</label></p>
    <div class="form-actions">
      <button type="submit" class="btn btn-primary">Potvrđujem — ažuriraj na <?= e($release->version) ?></button>
      <a class="btn" href="<?= e($u('/azuriranje')) ?>">Odustani</a>
    </div>
  </form>
<?php endif; ?>

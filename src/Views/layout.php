<?php
/**
 * Zajednički raspored stranica panela. Jedina obavezna promenljiva je $content (gotov HTML stranice); ostale su opcione:
 * $title, $nav (aktivna stavka: tabla|korisnici|nalozi|dnevnik|podesavanja|azuriranje), $currentUser (?User), $csrf,
 * $u (callable: putanja → adresa sa osnovnom putanjom instalacije), $flash (list<array{type,message}>), $version (prikazuje
 * se samo prijavljenima — tačna verzija ne treba nepoznatima),
 * $accountantName, $withQr (učitaj biblioteku za QR).
 */
use function RacunatorPanel\Kernel\e;

$u = isset($u) && is_callable($u) ? $u : static fn (string $path): string => $path;
$title = isset($title) ? (string) $title : 'Računator panel';
$nav = isset($nav) ? (string) $nav : '';
$currentUser = $currentUser ?? null;
$flash = isset($flash) && is_array($flash) ? $flash : [];
$items = [
    ['tabla', '/', 'Kontrolna tabla', false],
    ['korisnici', '/korisnici', 'Korisnici', false],
    ['nalozi', '/nalozi', 'Nalozi', true],
    ['dnevnik', '/dnevnik', 'Dnevnik', false],
    ['podesavanja', '/podesavanja', 'Podešavanja', false],
    ['azuriranje', '/azuriranje', 'Ažuriranje', true],
];
?><!doctype html>
<html lang="sr-Latn">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<meta name="referrer" content="no-referrer">
<title><?= e($title) ?> · Računator panel</title>
<link rel="stylesheet" href="<?= e($u('/assets/panel.css')) ?>">
<?php if (!empty($withQr)): ?>
<script src="<?= e($u('/assets/qrcode.min.js')) ?>" defer></script>
<?php endif; ?>
<script src="<?= e($u('/assets/panel.js')) ?>" defer></script>
</head>
<body>
<header class="topbar no-print">
  <div class="topbar-inner">
    <a class="brand" href="<?= e($u('/')) ?>">Računator <span>panel</span></a>
    <?php if ($currentUser !== null): ?>
    <nav class="mainnav" aria-label="Glavna navigacija">
      <ul>
        <?php foreach ($items as [$key, $path, $label, $adminOnly]): ?>
          <?php if ($adminOnly && !$currentUser->isAdmin()) { continue; } ?>
          <li><a href="<?= e($u($path)) ?>"<?= $nav === $key ? ' aria-current="page"' : '' ?>><?= e($label) ?></a></li>
        <?php endforeach; ?>
        <li>
          <form method="post" action="<?= e($u('/odjava')) ?>" class="inline">
            <input type="hidden" name="_token" value="<?= e($csrf ?? '') ?>">
            <button type="submit" class="linklike" title="Prijavljeni ste kao <?= e($currentUser->username) ?>">Odjava (<?= e($currentUser->username) ?>)</button>
          </form>
        </li>
      </ul>
    </nav>
    <?php endif; ?>
  </div>
</header>
<main class="container">
  <?php foreach ($flash as $message): ?>
    <div class="alert <?= ($message['type'] ?? '') === 'greska' ? 'alert-error' : 'alert-success' ?>" role="status"><?= e($message['message'] ?? '') ?></div>
  <?php endforeach; ?>
  <?= $content ?? '' ?>
</main>
<footer class="footer no-print">
  Računator panel<?= $currentUser !== null && isset($version) && $version !== '' ? ' · verzija ' . e($version) : '' ?><?= !empty($accountantName) ? ' · ' . e($accountantName) : '' ?>
</footer>
</body>
</html>

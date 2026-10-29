<?php
use RacunatorPanel\Controllers\Panel\Format;
use function RacunatorPanel\Kernel\e;
?>
<p class="breadcrumbs"><a href="<?= e($u('/korisnici')) ?>">Korisnici</a> › <?= e($client->name) ?></p>
<div class="page-head">
  <h1><?= e($client->name) ?> <?= $client->active ? '<span class="badge badge-ok">aktivan</span>' : '<span class="badge badge-off">deaktiviran</span>' ?></h1>
  <a class="btn btn-primary" href="<?= e($u('/korisnici/' . $client->id . '/racuni')) ?>">Računi</a>
</div>

<div class="grid-2">
  <section class="card">
    <h2>Podaci</h2>
    <dl class="kv">
      <dt>Dodat</dt><dd><?= e(Format::utc($client->createdAt)) ?></dd>
      <dt>Poslednje slanje</dt><dd><?= e(Format::utc($client->lastSeenAt, 'još nije slao')) ?></dd>
      <dt>Adresa za aplikaciju</dt><dd><code><?= e($endpoint) ?></code></dd>
      <dt>Ključ</dt><dd><code><?= e($client->apiKeyPrefix) ?>…</code> <span class="muted">(ceo ključ se ne čuva)</span></dd>
    </dl>
    <?php if ($summary !== []): ?>
    <h3>Računi po mesecima</h3>
    <ul class="months">
      <?php foreach ($summary as $ym => $s): ?>
        <li><a href="<?= e($u('/korisnici/' . $client->id . '/racuni?mesec=' . rawurlencode((string) $ym))) ?>"><?= e(Format::month((string) $ym)) ?></a>
          — <?= e($s['count']) ?> računa, <?= e(Format::amount((int) $s['total'])) ?> RSD</li>
      <?php endforeach; ?>
    </ul>
    <?php endif; ?>
  </section>

  <section class="card">
    <h2>Izmena</h2>
    <form method="post" action="<?= e($u('/korisnici/' . $client->id)) ?>" class="form">
      <input type="hidden" name="_token" value="<?= e($csrf) ?>">
      <label for="ime">Ime korisnika</label>
      <input id="ime" name="ime" type="text" maxlength="150" value="<?= e($name) ?>" required<?= isset($errors['ime']) ? ' aria-invalid="true"' : '' ?>>
      <?php if (isset($errors['ime'])): ?><p class="field-error"><?= e($errors['ime']) ?></p><?php endif; ?>
      <label for="napomena">Napomena</label>
      <textarea id="napomena" name="napomena" rows="3" maxlength="1000"><?= e($note) ?></textarea>
      <?php if (isset($errors['napomena'])): ?><p class="field-error"><?= e($errors['napomena']) ?></p><?php endif; ?>
      <button type="submit" class="btn">Sačuvaj izmene</button>
    </form>
  </section>
</div>

<section class="card danger-zone">
  <h2>Ključ i pristup</h2>
  <div class="danger-row">
    <div>
      <strong>Nov ključ</strong>
      <p class="muted">Pravi nov QR kod. Stari ključ odmah prestaje da važi i korisnik mora ponovo da skenira QR u aplikaciji.
        Koristite ako je QR izgubljen, korisnik promenio telefon ili sumnjate da je ključ otkriven.</p>
    </div>
    <form method="post" action="<?= e($u('/korisnici/' . $client->id . '/novi-kljuc')) ?>"
          data-confirm="Napraviti nov ključ za „<?= e($client->name) ?>"? Stari ključ odmah prestaje da važi.">
      <input type="hidden" name="_token" value="<?= e($csrf) ?>">
      <input type="hidden" name="_nonce" value="<?= e($rotateNonce) ?>">
      <button type="submit" class="btn btn-warning">Nov ključ</button>
    </form>
  </div>
  <div class="danger-row">
    <?php if ($client->active): ?>
    <div>
      <strong>Deaktiviraj</strong>
      <p class="muted">Aplikacija više ne može da šalje račune. Već primljeni računi ostaju sačuvani i vidljivi.</p>
    </div>
    <form method="post" action="<?= e($u('/korisnici/' . $client->id . '/deaktiviraj')) ?>"
          data-confirm="Deaktivirati korisnika „<?= e($client->name) ?>"? Aplikacija više neće moći da šalje račune.">
      <input type="hidden" name="_token" value="<?= e($csrf) ?>">
      <button type="submit" class="btn btn-danger">Deaktiviraj</button>
    </form>
    <?php else: ?>
    <div>
      <strong>Aktiviraj</strong>
      <p class="muted">Aplikacija ponovo može da šalje račune sa postojećim ključem.</p>
    </div>
    <form method="post" action="<?= e($u('/korisnici/' . $client->id . '/aktiviraj')) ?>">
      <input type="hidden" name="_token" value="<?= e($csrf) ?>">
      <button type="submit" class="btn">Aktiviraj</button>
    </form>
    <?php endif; ?>
  </div>
</section>

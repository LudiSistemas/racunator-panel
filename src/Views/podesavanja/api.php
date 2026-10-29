<?php
use RacunatorPanel\Controllers\Panel\Format;
use function RacunatorPanel\Kernel\e;
?>
<p class="breadcrumbs"><a href="<?= e($u('/podesavanja')) ?>">Podešavanja</a> › API za knjigovođu</p>
<section class="card">
  <h1>API za knjigovođu</h1>
  <p>API omogućava da vaš knjigovodstveni program sam preuzima korisnike i račune iz panela (samo čitanje).
     Isključen je dok ga ovde ne uključite. Adresa je nasumična, a pristup traži poseban ključ.</p>
  <div class="alert alert-info">Prekidač i ključ su spremni, a sam API stiže u sledećoj verziji panela; tada će ovde biti i dokumentacija.</div>

  <?php if ($newKey !== null): ?>
    <div class="alert alert-warning" role="alert"><strong>Ključ se prikazuje samo ovaj put.</strong> Sačuvajte ga na sigurnom mestu (npr. u menadžeru lozinki).</div>
    <dl class="kv">
      <dt>API ključ</dt><dd><code id="api-key" class="secret"><?= e($newKey) ?></code> <button type="button" class="btn btn-small" data-copy="api-key">Kopiraj</button></dd>
    </dl>
  <?php endif; ?>

  <?php if ($enabled): ?>
    <p><span class="badge badge-ok">uključen</span><?= $enabledAt !== null ? ' od ' . e(Format::utc($enabledAt)) : '' ?></p>
    <dl class="kv">
      <dt>Osnovna adresa</dt><dd><code id="api-base"><?= e($baseUrl ?? '') ?></code> <button type="button" class="btn btn-small" data-copy="api-base">Kopiraj</button></dd>
      <dt>Ključ</dt><dd><code><?= e($keyPrefix) ?>…</code> <span class="muted">(ceo ključ se ne čuva)</span></dd>
    </dl>
    <div class="form-actions">
      <form method="post" action="<?= e($u('/podesavanja/api')) ?>" class="inline" data-confirm="Napraviti nov ključ? Dosadašnji ključ odmah prestaje da važi.">
        <input type="hidden" name="_token" value="<?= e($csrf) ?>">
        <input type="hidden" name="radnja" value="novi-kljuc">
        <input type="hidden" name="_nonce" value="<?= e($rotateNonce) ?>">
        <button type="submit" class="btn btn-warning">Nov ključ</button>
      </form>
      <form method="post" action="<?= e($u('/podesavanja/api')) ?>" class="inline" data-confirm="Isključiti API za knjigovođu? Ključ prestaje da važi; pri ponovnom uključivanju dobijate novu adresu i ključ.">
        <input type="hidden" name="_token" value="<?= e($csrf) ?>">
        <input type="hidden" name="radnja" value="iskljuci">
        <button type="submit" class="btn btn-danger">Isključi API</button>
      </form>
    </div>
  <?php else: ?>
    <p><span class="badge">isključen</span></p>
    <form method="post" action="<?= e($u('/podesavanja/api')) ?>" data-confirm="Uključiti API za knjigovođu? Dobićete novu adresu i ključ koji se prikazuje samo jednom.">
      <input type="hidden" name="_token" value="<?= e($csrf) ?>">
      <input type="hidden" name="radnja" value="ukljuci">
      <button type="submit" class="btn btn-primary">Uključi API za knjigovođu</button>
    </form>
  <?php endif; ?>
</section>

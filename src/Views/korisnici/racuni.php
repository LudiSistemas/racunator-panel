<?php
use RacunatorPanel\Controllers\Panel\Format;
use function RacunatorPanel\Kernel\e;
?>
<p class="breadcrumbs"><a href="<?= e($u('/korisnici')) ?>">Korisnici</a> › <a href="<?= e($u('/korisnici/' . $client->id)) ?>"><?= e($client->name) ?></a> › Računi</p>
<div class="page-head">
  <h1>Računi — <?= e($client->name) ?></h1>
  <a class="btn" href="<?= e($u('/korisnici/' . $client->id . '/racuni.csv?mesec=' . rawurlencode($month))) ?>">Preuzmi CSV</a>
</div>

<form method="get" action="<?= e($u('/korisnici/' . $client->id . '/racuni')) ?>" class="form-inline">
  <label for="mesec">Mesec</label>
  <select id="mesec" name="mesec" data-autosubmit>
    <?php foreach ($months as $m): ?>
      <option value="<?= e($m) ?>"<?= $m === $month ? ' selected' : '' ?>><?= e(Format::month($m)) ?> (<?= e($summary[$m]['count'] ?? 0) ?>)</option>
    <?php endforeach; ?>
  </select>
  <button type="submit" class="btn btn-small">Prikaži</button>
</form>

<?php if ($receipts === []): ?>
  <div class="empty"><p>Nema računa za <?= e(Format::month($month)) ?>.</p></div>
<?php else: ?>
<div class="table-wrap">
<table class="table receipts">
  <thead>
    <tr><th>Datum</th><th>Prodavac</th><th>PIB prodavca</th><th>PIB kupca</th><th class="num">Iznos (RSD)</th><th class="pfr">PFR broj</th><th>Vrsta</th><th></th></tr>
  </thead>
  <tbody>
  <?php foreach ($receipts as $r): ?>
    <tr<?= $r->transactionType === 'Refund' ? ' class="refund"' : '' ?>>
      <td data-label="Datum"><?= e(Format::sdcDateTime($r->sdcDateTime)) ?></td>
      <td data-label="Prodavac"><?= e($r->sellerName ?? '—') ?></td>
      <td data-label="PIB prodavca"><?= e($r->sellerPib ?? '—') ?></td>
      <td data-label="PIB kupca"><?= e($r->buyerPib ?? '—') ?></td>
      <td data-label="Iznos" class="num"><?= e(Format::amount($r->totalAmountMinor)) ?></td>
      <td data-label="PFR broj" class="pfr"><code><?= e($r->invoiceNumber) ?></code></td>
      <td data-label="Vrsta"><?= e(Format::receiptKind($r->invoiceType, $r->transactionType)) ?></td>
      <td class="actions">
        <?php if (Format::isSufUrl($r->verificationUrl)): ?>
          <a class="btn btn-small" href="<?= e($r->verificationUrl) ?>" target="_blank" rel="noopener noreferrer">Otvori na sajtu Poreske uprave</a>
        <?php else: ?>
          <span class="muted">neispravan link</span>
        <?php endif; ?>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody>
  <tfoot>
    <tr><th colspan="4">Zbir (<?= e(count($receipts)) ?> računa)</th><th class="num"><?= e(Format::amount($total)) ?></th><th colspan="3"></th></tr>
  </tfoot>
</table>
</div>
<p class="hint">Zbir sabira iznose svih računa u mesecu onako kako su primljeni (uključujući eventualne refundacije, označene bojom).</p>
<?php endif; ?>

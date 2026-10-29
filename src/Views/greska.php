<?php
use function RacunatorPanel\Kernel\e;
?>
<section class="card narrow">
  <h1><?= e($title ?? 'Greška') ?></h1>
  <p><?= e($message ?? '') ?></p>
  <p><a class="btn" href="<?= e($u('/')) ?>">Na kontrolnu tablu</a></p>
</section>

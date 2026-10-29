<?php
declare(strict_types=1);

// Učitava klase instalera/ažuriranja (install/ nije pod PSR-4 autoloaderom iz src/).
require_once __DIR__ . '/../src/autoload.php';
require_once __DIR__ . '/SystemProbe.php';
require_once __DIR__ . '/Checks.php';
require_once __DIR__ . '/Installer.php';
require_once __DIR__ . '/Updater.php';

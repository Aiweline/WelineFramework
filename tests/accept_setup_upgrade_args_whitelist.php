<?php

declare(strict_types=1);

define('BP', dirname(__DIR__) . DIRECTORY_SEPARATOR);
require BP . 'app/bootstrap.php';

$upgrade = \Weline\Framework\Manager\ObjectManager::getInstance(
    \Weline\Framework\Setup\Console\Setup\Upgrade::class
);
$validate = new \ReflectionMethod($upgrade, 'validateSupportedArgs');
$validate->setAccessible(true);

$accepted = [
    'force-i18n-collect',
    'skip-extends-rebuild',
    'yes',
    'y',
    'keep-maintenance',
];
$failures = [];
foreach ($accepted as $key) {
    try {
        $validate->invoke($upgrade, ['command' => 'setup:upgrade', $key => true]);
        $validate->invoke($upgrade, ['command' => 'setup:upgrade', '--' . $key => true]);
    } catch (\Throwable $e) {
        $failures[] = '--' . $key . ': ' . $e->getMessage();
    }
}

try {
    $validate->invoke($upgrade, ['command' => 'setup:upgrade', '--definitely-unknown' => true]);
    $failures[] = 'unknown option was accepted';
} catch (\Weline\Framework\App\Exception) {
}

$help = $upgrade->help();
if (!is_string($help)) {
    $help = \Weline\Framework\Console\CommandHelper::parseHelpArray($help);
}
foreach (['--force-i18n-collect', '--skip-extends-rebuild', '--yes, -y'] as $needle) {
    if (!str_contains($help, $needle)) {
        $failures[] = 'help missing ' . $needle;
    }
}

if ($failures !== []) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

echo 'ACCEPT PASS', PHP_EOL;

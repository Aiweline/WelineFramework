<?php

declare(strict_types=1);

define('BP', dirname(__DIR__) . DIRECTORY_SEPARATOR);
require BP . 'app/bootstrap.php';

$failures = [];
$check = static function (bool $ok, string $message) use (&$failures): void {
    if (!$ok) {
        $failures[] = $message;
    }
};

$src = (string) file_get_contents(BP . 'app/code/Weline/Theme/Observer/SetupUpgradeAfterDeployStatic.php');
$check(str_contains($src, 'StaticPublishFingerprint'), 'observer must use StaticPublishFingerprint');
$check(str_contains($src, "setup.static_publish"), 'observer must read setup.static_publish');
$check(str_contains($src, 'theme_static_publish.log'), 'observer must write its own failure log');
$check(str_contains($src, 'never'), 'observer must support never mode');
$check((bool) preg_match('/catch \(\\\\Throwable[^)]*\)[\s\S]{0,400}(warning|w_log_warning|theme_static_publish\.log)/', $src), 'observer must downgrade failures to warning');

$observer = \Weline\Framework\Manager\ObjectManager::getInstance(\Weline\Theme\Observer\SetupUpgradeAfterDeployStatic::class);
$m = new \ReflectionMethod($observer, 'staticPublishInputs');
$m->setAccessible(true);
$inputs = $m->invoke($observer);
$check(is_array($inputs), 'staticPublishInputs must return array');
$check(($inputs['version'] ?? 0) === 1, 'staticPublishInputs must carry version');
$check(isset($inputs['sources']) && is_array($inputs['sources']), 'staticPublishInputs must include source stamps');

if ($failures !== []) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

echo 'ACCEPT PASS', PHP_EOL;

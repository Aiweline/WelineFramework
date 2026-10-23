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

$src = (string) file_get_contents(BP . 'app/code/Weline/Theme/Observer/SetupUpgradeAfterPublishNotFoundStatic.php');
$check(str_contains($src, 'StaticPublishFingerprint'), '404 observer must use StaticPublishFingerprint');
$check(str_contains($src, "setup.static_publish"), '404 observer must read setup.static_publish');
$check(str_contains($src, 'theme_static_publish.log'), '404 observer must write failure log');
$check(str_contains($src, 'not_found'), '404 observer must use not_found fingerprint kind');
$check(str_contains($src, 'never'), '404 observer must support never mode');

$observer = \Weline\Framework\Manager\ObjectManager::getInstance(\Weline\Theme\Observer\SetupUpgradeAfterPublishNotFoundStatic::class);
$m = new \ReflectionMethod($observer, 'staticPublishInputs');
$m->setAccessible(true);
$inputs = $m->invoke($observer);
$check(is_array($inputs) && ($inputs['version'] ?? 0) === 1, '404 inputs must carry version');
$check(isset($inputs['sources']) && is_array($inputs['sources']), '404 inputs must include source stamps');

if ($failures !== []) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

echo 'ACCEPT PASS', PHP_EOL;

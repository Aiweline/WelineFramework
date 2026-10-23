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

$file = BP . 'app/code/Weline/Theme/Service/LayoutEntity/ThemeLayoutEntityUpgradeSolidifyService.php';
$src = (string) file_get_contents($file);
$check(str_contains($src, 'setup.layout_solidify'), 'solidify service must read setup.layout_solidify');
$check(str_contains($src, 'StaticPublishFingerprint'), 'solidify service must use StaticPublishFingerprint');
$check(str_contains($src, "'layout_solidify'"), 'solidify service must use layout_solidify fingerprint kind');
$check(str_contains($src, 'never'), 'solidify service must support never mode');

$svc = \Weline\Framework\Manager\ObjectManager::getInstance(
    \Weline\Theme\Service\LayoutEntity\ThemeLayoutEntityUpgradeSolidifyService::class
);
if (!method_exists($svc, 'layoutSolidifyInputs')) {
    fwrite(STDERR, 'missing layoutSolidifyInputs()' . PHP_EOL);
    exit(1);
}
$m = new \ReflectionMethod($svc, 'layoutSolidifyInputs');
$m->setAccessible(true);
$inputs = $m->invoke($svc);
$check(is_array($inputs) && ($inputs['version'] ?? 0) === 1, 'inputs must carry version');
$check(array_key_exists('layout_versions', $inputs), 'inputs must include layout version count');
$check(array_key_exists('theme_module', $inputs), 'inputs must include theme module version');
$check(isset($inputs['sources']) && is_array($inputs['sources']), 'inputs must include design source stamps');

// seed 当前指纹后，runOnce 必须跳过（不执行 12s 的固化）
$fp = new \Weline\Theme\Service\StaticPublishFingerprint(BP . 'var' . DIRECTORY_SEPARATOR . 'state' . DIRECTORY_SEPARATOR . 'static_publish');
$fp->remember('layout_solidify', $fp->compute($inputs));
\Weline\Theme\Service\LayoutEntity\ThemeLayoutEntityUpgradeSolidifyService::resetHasRunFlag();
$start = microtime(true);
$result = $svc->runOnce('accept_test');
$elapsed = microtime(true) - $start;
$check(($result['solidified'] ?? -1) === 0, 'seeded fingerprint must skip solidify');
$check($elapsed < 2.0, sprintf('skip must be fast, took %.1fs', $elapsed));

if ($failures !== []) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

echo 'ACCEPT PASS', PHP_EOL;

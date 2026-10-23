<?php

declare(strict_types=1);

define('BP', dirname(__DIR__) . DIRECTORY_SEPARATOR);
$failures = [];
$check = static function (bool $ok, string $message) use (&$failures): void {
    if (!$ok) {
        $failures[] = $message;
    }
};
$src = (string) file_get_contents(BP . 'app/code/Weline/Theme/Service/LayoutEntity/ThemeLayoutEntityUpgradeSolidifyService.php');
$check(!str_contains($src, "throw new \\RuntimeException('layout_entities_cache_clear_partial_failure:"), 'FPC partial failure must not abort the upgrade');
$check(str_contains($src, 'cache_failures'), 'FPC failures must be reported through cache_failures');
$check((bool) preg_match('/cache_failures[\s\S]{0,400}warning/', $src), 'FPC failures must be downgraded to warning');
if ($failures !== []) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}
echo 'ACCEPT PASS', PHP_EOL;

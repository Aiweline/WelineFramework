<?php

declare(strict_types=1);

define('BP', dirname(__DIR__) . DIRECTORY_SEPARATOR);

$failures = [];
$check = static function (bool $ok, string $message) use (&$failures): void {
    if (!$ok) {
        $failures[] = $message;
    }
};

$output = [];
$code = 0;
exec(PHP_BINARY . ' ' . escapeshellarg(BP . 'bin/w') . ' setup:upgrade --help 2>&1', $output, $code);
$text = implode("\n", $output);
$check($code === 0, 'help must exit 0, got ' . $code);
$check(str_contains($text, '仍必执行'), 'help must state that schema stages still run');
$check(!str_contains($text, '仅运行 schema_diff 阶段'), 'help must not claim --stage=schema_diff runs only that stage');
$check(!str_contains($text, '仅运行 framework_db_bootstrap 阶段'), 'help must not claim --stage=framework_db_bootstrap runs only that stage');

$src = (string) file_get_contents(BP . 'app/code/Weline/Framework/Setup/Console/Setup/Upgrade.php');
$check(str_contains($src, '优先运行指定阶段'), 'option description must use 优先 wording');
$check(str_contains($src, '以 schema_diff 为优先阶段'), 'schema_diff example must use 优先 wording');

if ($failures !== []) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

echo 'ACCEPT PASS', PHP_EOL;

<?php

declare(strict_types=1);

define('BP', dirname(__DIR__) . DIRECTORY_SEPARATOR);
require BP . 'app/code/Weline/Ai/Mcp/src/bootstrap.php';

$failures = [];
$check = static function (bool $ok, string $message) use (&$failures): void {
    if (!$ok) {
        $failures[] = $message;
    }
};
$src = (string) file_get_contents(BP . 'app/code/Weline/Ai/Mcp/src/ToolService.php');
$check(str_contains($src, "'cognition'"), 'ToolService must register a cognition tool');
$check(str_contains($src, 'CognitionService'), 'ToolService must delegate to CognitionService');
$check(str_contains($src, 'RepositoryScope::projectDataDir'), 'cognition must use the per-project data dir');
$check((bool) preg_match('/function cognition\(/', $src), 'missing cognition() handler');

$check((bool) preg_match("/self::tool\(\s*'cognition'/", $src), 'definitions must declare cognition tool');

if ($failures !== []) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}
echo 'ACCEPT PASS', PHP_EOL;

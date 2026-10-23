<?php

declare(strict_types=1);

define('BP', dirname(__DIR__) . DIRECTORY_SEPARATOR);
define('VAR_DIR', '/tmp/weline-core-guard/var/');
@mkdir(VAR_DIR . 'process', 0775, true);
require BP . 'app/code/Weline/Framework/App/SystemUpdateActivity.php';

use Weline\Framework\App\SystemUpdateActivity;

$failures = [];
$check = static function (bool $ok, string $message) use (&$failures): void {
    if (!$ok) {
        $failures[] = $message;
    }
};

// A. acquire 必须返回 bool，第二次同进程失败
$first = SystemUpdateActivity::acquire('core:update');
$check($first === true, 'first acquire should return true');
$second = SystemUpdateActivity::acquire('core:update');
$check($second === false, 'second acquire in same process should return false');
SystemUpdateActivity::release('core:update');
$third = SystemUpdateActivity::acquire('core:update');
$check($third === true, 'acquire after release should return true');
SystemUpdateActivity::release('core:update');
@rmdir(VAR_DIR . 'process');
@rmdir(VAR_DIR);

// B. Core 不得用 exit(1) 绕过 finally
$coreSrc = (string) file_get_contents(BP . 'app/code/Weline/Deploy/Console/Update/Core.php');
$check(!str_contains($coreSrc, 'exit(1)'), 'Core.php must not call exit(1) (bypasses finally release)');

// C. copy 失败必须计数并最终非零
$check(substr_count($coreSrc, 'failedFiles') >= 2, 'Core.php must track failedFiles and fail the command');
$check((bool) preg_match('/if\s*\(\s*!\s*@?copy\(/', $coreSrc), 'copy() return value must be checked');

// D. diff 失败不得假成功
$start = strpos($coreSrc, 'private function getGitChangedFiles');
$check($start !== false, 'getGitChangedFiles not found');
$body = $start === false ? '' : substr($coreSrc, $start, 3000);
$check(str_contains($body, 'throw'), 'getGitChangedFiles failure must throw instead of returning empty silently');

if ($failures !== []) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

echo 'ACCEPT PASS', PHP_EOL;

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

$dir = '/tmp/weline-cognition-' . bin2hex(random_bytes(4));
$svc = new \LearningMcp\CognitionService($dir);

$entries = [
    [
        'path' => 'app/code/Weline/Framework/Setup/Console/Setup/Upgrade.php',
        'f' => '系统升级主流程',
        'r' => ['code:app/code/Weline/Framework/Setup/Stage/StageUpdateManager.php'],
        'a' => ['prepare', 'commit'],
        's' => ['三条注册表扫描不可跳过'],
        'hash' => 'sha256:abc',
    ],
    [
        'path' => 'app/code/Weline/Theme/Service/StaticPublishFingerprint.php',
        'f' => '静态发布指纹',
        'r' => [],
        'a' => ['compute', 'matches'],
        's' => ['存储必须在 var/state'],
        'hash' => 'sha256:def',
    ],
];
$result = $svc->maintain($entries);
$check(($result['written'] ?? 0) === 2, 'maintain must write 2 entries');

$q = $svc->query(['module' => 'Weline_Framework']);
$check(($q['count'] ?? 0) === 1, 'query by module must return 1 entry');
$check(($q['entries'][0]['f'] ?? '') === '系统升级主流程', 'query must return f field');

$q2 = $svc->query(['path' => 'app/code/Weline/Theme/Service/StaticPublishFingerprint.php']);
$check(($q2['count'] ?? 0) === 1, 'query by path must return 1 entry');
$check(($q2['entries'][0]['s'][0] ?? '') === '存储必须在 var/state', 'query must keep s constraints');

$o = $svc->overview();
$check(($o['total'] ?? 0) === 2, 'overview total must be 2');
$check(isset($o['modules']['Weline_Framework']), 'overview must list modules');

$st = $svc->status();
$check(($st['total'] ?? 0) === 2, 'status total must be 2');
$check(($st['manifest_version'] ?? 0) === 1, 'status must expose manifest version');

$bad = $svc->maintain([['path' => '', 'f' => 'x']]);
$check(($bad['skipped'] ?? 0) === 1, 'empty path must be skipped');

exec('rm -rf ' . escapeshellarg($dir));

if ($failures !== []) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}
echo 'ACCEPT PASS', PHP_EOL;

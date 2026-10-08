<?php

declare(strict_types=1);

$stabilityFile = dirname(__DIR__) . '/scripts/project-guidance-cursor-mcp-stability.php';
if (!is_file($stabilityFile)) {
    fwrite(STDERR, "[FAIL] project-guidance-cursor-mcp-stability.php missing\n");
    exit(1);
}

require $stabilityFile;

$failed = false;
$tmp = sys_get_temp_dir() . '/weline-cursor-mcp-stability-' . getmypid();
@mkdir($tmp, 0700, true);

$mcpPath = $tmp . '/mcp.json';
file_put_contents($mcpPath, json_encode([
    'mcpServers' => [
        'aoci' => [
            'command' => '/usr/local/bin/aoci',
            'args' => ['--repo', '/repo', 'mcp'],
        ],
        'weline_project_intelligence' => [
            'command' => 'php',
            'args' => ['/mcp/bin/learning-mcp'],
        ],
    ],
], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
$mtimeBefore = filemtime($mcpPath);

$equal = welineMcpRegistrationSemanticallyEqual(
    ['command' => '/usr/local/bin/aoci', 'args' => ['--repo', '/repo', 'mcp']],
    ['command' => '/usr/local/bin/aoci', 'args' => ['--repo', '/repo', 'mcp'], 'env' => ['EXTRA' => '1']],
);
$unequal = welineMcpRegistrationSemanticallyEqual(
    ['command' => '/usr/local/bin/aoci', 'args' => ['--repo', '/repo', 'mcp']],
    ['command' => '/usr/local/bin/aoci', 'args' => ['--repo', '/other', 'mcp']],
);
fwrite(($equal && !$unequal) ? STDOUT : STDERR, sprintf(
    "[%s] registration semantic equality\n",
    ($equal && !$unequal) ? 'PASS' : 'FAIL'
));
$failed = $failed || !($equal && !$unequal);

$step = welineMcpInstallBuildWriteJsonStep(
    $mcpPath,
    [
        'mcpServers' => [
            'aoci' => [
                'command' => '/usr/local/bin/aoci',
                'args' => ['--repo', '/repo', 'mcp'],
            ],
        ],
    ],
    $mcpPath,
);
$noopOk = ($step['noop'] ?? false) === true && ($step['skip_reason'] ?? '') === 'semantic_equivalent';
fwrite($noopOk ? STDOUT : STDERR, sprintf("[%s] write_json noop when aoci already equivalent\n", $noopOk ? 'PASS' : 'FAIL'));
$failed = $failed || !$noopOk;

$stamp = $tmp . '/bounce.stamp';
$kills = 0;
$killOk = static function (int $pid) use (&$kills): bool {
    $kills++;

    return true;
};
$bounce = welineGuidanceBounceCursorMcpProcess(
    ['pid' => 4242, 'reason' => 'orphan_no_learning_mcp_child'],
    $mcpPath,
    $stamp,
    60,
    $killOk,
);
clearstatcache(true, $mcpPath);
$mtimeAfterKill = filemtime($mcpPath);
$killNoTouch = ($bounce['kill_ok'] ?? false) === true
    && ($bounce['touched_user_mcp'] ?? true) === false
    && ($bounce['bounced'] ?? false) === true
    && $mtimeAfterKill === $mtimeBefore;
fwrite($killNoTouch ? STDOUT : STDERR, sprintf(
    "[%s] kill_ok bounce does not touch mcp.json\n",
    $killNoTouch ? 'PASS' : 'FAIL'
));
$failed = $failed || !$killNoTouch;

$bounce2 = welineGuidanceBounceCursorMcpProcess(
    ['pid' => 4242, 'reason' => 'orphan_no_learning_mcp_child'],
    $mcpPath,
    $stamp,
    60,
    $killOk,
);
$debounced = ($bounce2['deferred'] ?? false) === true
    && ($bounce2['bounced'] ?? true) === false
    && $kills === 1;
fwrite($debounced ? STDOUT : STDERR, sprintf(
    "[%s] same-reason bounce debounced within window\n",
    $debounced ? 'PASS' : 'FAIL'
));
$failed = $failed || !$debounced;

$stampFail = $tmp . '/bounce-fail.stamp';
$mcpTouch = $tmp . '/mcp-touch.json';
file_put_contents($mcpTouch, "{}");
$mtimeTouchBefore = filemtime($mcpTouch);
usleep(1100000);
$bounceFail = welineGuidanceBounceCursorMcpProcess(
    ['pid' => 99, 'reason' => 'cursor_mcp_process_stale'],
    $mcpTouch,
    $stampFail,
    60,
    static fn (int $pid): bool => false,
);
clearstatcache(true, $mcpTouch);
$touchFallback = ($bounceFail['kill_ok'] ?? true) === false
    && ($bounceFail['touched_user_mcp'] ?? false) === true
    && filemtime($mcpTouch) > $mtimeTouchBefore;
fwrite($touchFallback ? STDOUT : STDERR, sprintf(
    "[%s] kill failure falls back to touch once\n",
    $touchFallback ? 'PASS' : 'FAIL'
));
$failed = $failed || !$touchFallback;

$ensure = (string) file_get_contents(dirname(__DIR__) . '/scripts/ensure-project-guidance.php');
$ops = (string) file_get_contents(dirname(__DIR__) . '/docs/OPERATIONS.md');
$aociDoc = (string) file_get_contents(dirname(__DIR__, 2) . '/doc/AOCI自动安装.md');
$noReloadEnsure = !str_contains($ensure, 'or Developer: Reload Window');
$opsOk = str_contains($ops, 'SIGTERM-bounces')
    && str_contains($ops, 'Never')
    && str_contains($ops, 'Reload Window')
    && str_contains($ops, 'semantic-idempotent');
$aociOk = str_contains($aociDoc, '语义幂等')
    && str_contains($aociDoc, '禁止')
    && str_contains($aociDoc, 'Reload Window')
    && !str_contains($aociDoc, '新开 Agent 回合或 Reload Window');
fwrite($noReloadEnsure ? STDOUT : STDERR, sprintf(
    "[%s] ensure nextAction no longer offers Reload Window fallback\n",
    $noReloadEnsure ? 'PASS' : 'FAIL'
));
fwrite($opsOk ? STDOUT : STDERR, sprintf("[%s] OPERATIONS current guidance stable\n", $opsOk ? 'PASS' : 'FAIL'));
fwrite($aociOk ? STDOUT : STDERR, sprintf("[%s] AOCI自动安装 current guidance stable\n", $aociOk ? 'PASS' : 'FAIL'));
$failed = $failed || !$noReloadEnsure || !$opsOk || !$aociOk;

foreach (glob($tmp . '/*') ?: [] as $file) {
    @unlink($file);
}
@rmdir($tmp);

exit($failed ? 1 : 0);

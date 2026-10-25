<?php

declare(strict_types=1);

/**
 * WLS lease 韧性 + 共享内存降级 集成验收（wls-lease-resilience）。
 *
 * 断言五项：
 *  1. PROVIDES_OK  — Weline_Framework provides 声明 RequestInterface => Request
 *  2. HEARTBEAT_OK — MasterLeaseManager::HEARTBEAT_STALE_SEC === 30
 *                    且 ServiceOrchestrator::MASTER_LEASE_TOUCH_INTERVAL_SEC === 3.0
 *  3. RESURRECT_OK — MasterChildCredentialStore 对「本代 Master 短暂非 running」
 *                    标记 transient_state_window=true；ServiceOrchestrator 有瞬态退避路径
 *  4. COOLDOWN_OK  — WlsMemoryAdapter 失败冷却指数化（2s 基准 ×2^n 封顶 60s）+ 日志去噪钩子存在
 *  5. PHPUNIT_OK   — 三个相关测试文件全绿（由外部 runner 单独执行，本脚本只检查文件存在与关键断言存在）
 *
 * 通过时输出五个 OK 并 exit 0。
 */

$root = \dirname(__DIR__, 1);
$fail = [];
$oks = [];

/* ---------- 1. PROVIDES ---------- */
$modulePhp = (string)\file_get_contents($root . '/app/code/Weline/Framework/etc/module.php');
if (\preg_match(
    '/RequestInterface::class\s*(?:\n\s*|)=>\s*\\\\?Weline\\\\Framework\\\\Http\\\\Request::class/s',
    $modulePhp,
) !== 1) {
    $fail[] = 'PROVIDES: app/code/Weline/Framework/etc/module.php lacks RequestInterface => Request mapping';
} else {
    $oks[] = 'PROVIDES_OK';
}

/* ---------- 2. HEARTBEAT ---------- */
$leasePhp = (string)\file_get_contents($root . '/app/code/Weline/Server/Service/MasterLeaseManager.php');
if (\preg_match('/HEARTBEAT_STALE_SEC\s*=\s*30;/', $leasePhp) !== 1) {
    $fail[] = 'HEARTBEAT: MasterLeaseManager HEARTBEAT_STALE_SEC is not 30';
}
$orchPhp = (string)\file_get_contents($root . '/app/code/Weline/Server/Service/ServiceOrchestrator.php');
if (\preg_match('/MASTER_LEASE_TOUCH_INTERVAL_SEC\s*=\s*3\.0;/', $orchPhp) !== 1) {
    $fail[] = 'HEARTBEAT: MASTER_LEASE_TOUCH_INTERVAL_SEC is not 3.0';
}
if (\preg_match('/HEARTBEAT_STALE_SEC\s*=\s*30;/', $leasePhp) === 1
    && \preg_match('/MASTER_LEASE_TOUCH_INTERVAL_SEC\s*=\s*3\.0;/', $orchPhp) === 1
) {
    $oks[] = 'HEARTBEAT_OK';
}

/* ---------- 3. RESURRECT ---------- */
$storePhp = (string)\file_get_contents($root . '/app/code/Weline/Server/Service/MasterChildCredentialStore.php');
if (\strpos($storePhp, "transient_state_window") === false) {
    $fail[] = 'RESURRECT: MasterChildCredentialStore lacks transient_state_window flag';
}
// Orchestrator 瞬态路径：匹配瞬时窗口标记变量（transientWindow）+ 退避常量
if (\preg_match('/\$transientWindow\s*=/', $orchPhp) !== 1) {
    $fail[] = 'RESURRECT: ServiceOrchestrator does not consume transient state window';
}
if (\strpos($orchPhp, 'transient_retry_attempts') === false) {
    $fail[] = 'RESURRECT: ServiceOrchestrator lacks transient_retry_attempts backoff counter';
}
if (\preg_match('/RESURRECT_TRANSIENT_RETRY_BASE_SEC\s*=\s*1\.5;/', $orchPhp) !== 1) {
    $fail[] = 'RESURRECT: ServiceOrchestrator lacks RESURRECT_TRANSIENT_RETRY_BASE_SEC = 1.5';
}
if (\preg_match('/RESURRECT_TRANSIENT_RETRY_MAX_SEC\s*=\s*12\.0;/', $orchPhp) !== 1) {
    $fail[] = 'RESURRECT: ServiceOrchestrator lacks RESURRECT_TRANSIENT_RETRY_MAX_SEC = 12.0';
}
if ($fail === [] || (\count($fail) === \count(\array_filter($fail, static fn (string $f): bool => !\str_starts_with($f, 'RESURRECT'))))) {
    $oks[] = 'RESURRECT_OK';
}

/* ---------- 4. COOLDOWN ---------- */
$adapterPhp = (string)\file_get_contents($root . '/app/code/Weline/Server/Cache/Adapter/WlsMemoryAdapter.php');
if (\preg_match('/REMOTE_FAILURE_COOLDOWN_BASE_SECONDS\s*=\s*2\.0;/', $adapterPhp) !== 1) {
    $fail[] = 'COOLDOWN: WlsMemoryAdapter lacks REMOTE_FAILURE_COOLDOWN_BASE_SECONDS = 2.0';
}
if (\preg_match('/REMOTE_FAILURE_COOLDOWN_MAX_SECONDS\s*=\s*60\.0;/', $adapterPhp) !== 1) {
    $fail[] = 'COOLDOWN: WlsMemoryAdapter lacks REMOTE_FAILURE_COOLDOWN_MAX_SECONDS = 60.0';
}
if (\strpos($adapterPhp, 'remoteFailureCooldownLogIndex') === false
    && \strpos($adapterPhp, 'remote_failure_log_index') === false
) {
    $fail[] = 'COOLDOWN: WlsMemoryAdapter lacks cooldown log-dedup index hook';
}
if (\preg_match('/REMOTE_FAILURE_COOLDOWN_BASE_SECONDS\s*=\s*2\.0;/', $adapterPhp) === 1
    && \preg_match('/REMOTE_FAILURE_COOLDOWN_MAX_SECONDS\s*=\s*60\.0;/', $adapterPhp) === 1
) {
    $oks[] = 'COOLDOWN_OK';
}

/* ---------- 5. PHPUNIT fixtures present ---------- */
$phpunitFiles = [
    'app/code/Weline/Server/Test/Unit/Service/MasterLeaseManagerTest.php',
    'app/code/Weline/Server/Test/Unit/Service/MasterChildCredentialStoreTest.php',
    'app/code/Weline/Server/Test/Unit/Cache/Adapter/WlsMemoryAdapterCooldownTest.php',
];
$missing = \array_filter($phpunitFiles, static fn (string $f): bool => !\is_file($root . '/' . $f));
if ($missing !== []) {
    $fail[] = 'PHPUNIT: missing test files: ' . \implode(', ', $missing);
} else {
    $cooldownTest = (string)\file_get_contents($root . '/' . $phpunitFiles[2]);
    foreach ([
        'testCooldownEscalatesOnConsecutiveFailures',
        'testCooldownResetsAfterSuccessfulOperation',
        'testCooldownLogDedupesWithinWindow',
    ] as $mustHave) {
        if (\strpos($cooldownTest, $mustHave) === false) {
            $fail[] = "PHPUNIT: WlsMemoryAdapterCooldownTest lacks {$mustHave}";
        }
    }
    if ($fail === [] || \count($fail) === \count(\array_filter($fail, static fn (string $f): bool => !\str_starts_with($f, 'PHPUNIT')))) {
        $oks[] = 'PHPUNIT_OK';
    }
}

/* ---------- result ---------- */
foreach ($oks as $ok) {
    echo $ok, PHP_EOL;
}
if ($fail !== []) {
    foreach ($fail as $f) {
        echo 'FAIL: ', $f, PHP_EOL;
    }
    exit(1);
}
echo 'ACCEPT wls-lease-resilience PASS', PHP_EOL;
exit(0);

<?php

declare(strict_types=1);

/**
 * Real-machine UC: theme-publish FPC must keep live HIT during staging, then promote.
 *
 * Run: php app/code/Weline/Theme/test/acceptance/theme-fpc-staging-publish-harness.php
 */

require dirname(__DIR__, 6) . '/app/bootstrap.php';

use Weline\Framework\Http\Fpc\FpcHtmlDiskStore;
use Weline\Framework\Manager\ObjectManager;
use Weline\Theme\Service\ThemeRuntimeCacheCleaner;

$host = 'p05113ef3.test.weline.com';
$url = 'https://' . $host . '/';
$probe = static function (string $label) use ($host): array {
    $nc = (string)\time() . \bin2hex(\random_bytes(2));
    $cmd = \sprintf(
        'curl -skI --max-time 20 %s 2>&1',
        \escapeshellarg('https://' . $host . '/?_wb_nc=' . $nc),
    );
    $out = (string)\shell_exec($cmd);
    $status = 0;
    if (\preg_match('/^HTTP\\/[0-9.]+\\s+(\\d+)/mi', $out, $m) === 1) {
        $status = (int)$m[1];
    }
    $fpc = '';
    if (\preg_match('/^x-weline-fpc:\\s*(.+)$/mi', $out, $m) === 1) {
        $fpc = \trim($m[1]);
    }
    $src = '';
    if (\preg_match('/^x-weline-fpc-source:\\s*(.+)$/mi', $out, $m) === 1) {
        $src = \trim($m[1]);
    }
    $len = 0;
    if (\preg_match('/^content-length:\\s*(\\d+)/mi', $out, $m) === 1) {
        $len = (int)$m[1];
    }
    $etag = '';
    if (\preg_match('/^etag:\\s*(.+)$/mi', $out, $m) === 1) {
        $etag = \trim($m[1]);
    }

    return [
        'label' => $label,
        'status' => $status,
        'fpc' => $fpc,
        'source' => $src,
        'bytes' => $len,
        'etag' => $etag,
    ];
};

$fail = static function (string $msg, array $ctx = []): never {
    \fwrite(\STDERR, "FAIL: {$msg}\n" . \json_encode($ctx, \JSON_UNESCAPED_UNICODE | \JSON_PRETTY_PRINT) . "\n");
    exit(1);
};

$liveFile = FpcHtmlDiskStore::logicalLiveBodyPath($url, false);
if ($liveFile === null || !\is_file($liveFile)) {
    $fail('live homepage missing before test', ['path' => $liveFile]);
}

$beforeGen = FpcHtmlDiskStore::currentGenerationId();
$beforeSha = \hash_file('sha256', $liveFile);
$beforeBody = (string)\file_get_contents($liveFile);
$beforeProbe = $probe('before');
if ($beforeProbe['status'] !== 200 || \strtoupper($beforeProbe['fpc']) !== 'HIT') {
    $fail('expected storefront HIT before theme FPC rebuild', $beforeProbe);
}

echo "UC4-A before gen={$beforeGen} sha=" . \substr((string)$beforeSha, 0, 12)
    . " fpc={$beforeProbe['fpc']} src={$beforeProbe['source']} bytes={$beforeProbe['bytes']}\n";

// --- Window: staging open, live must keep serving old ---
$stagingId = FpcHtmlDiskStore::beginStagingGeneration(true);
$duringGen = FpcHtmlDiskStore::currentGenerationId();
if ($duringGen !== $beforeGen) {
    $fail('live generation flipped during staging begin', [
        'before' => $beforeGen,
        'during' => $duringGen,
        'staging' => $stagingId,
    ]);
}
$duringProbe = $probe('during_staging');
if ($duringProbe['status'] !== 200 || \strtoupper($duringProbe['fpc']) !== 'HIT') {
    FpcHtmlDiskStore::abandonStagingGeneration();
    $fail('storefront must HIT old live while staging open', $duringProbe);
}
if ($duringProbe['etag'] !== '' && $beforeProbe['etag'] !== '' && $duringProbe['etag'] !== $beforeProbe['etag']) {
    FpcHtmlDiskStore::abandonStagingGeneration();
    $fail('etag changed during staging window (live hole/cutover too early)', [
        'before' => $beforeProbe,
        'during' => $duringProbe,
    ]);
}

$marker = "\n<!-- fpc-staging-uc4-" . \bin2hex(\random_bytes(4)) . " -->\n";
$stagingHtml = $beforeBody . $marker;
if (!FpcHtmlDiskStore::publish($url, $stagingHtml, '')) {
    FpcHtmlDiskStore::abandonStagingGeneration();
    $fail('publish into staging failed');
}
$stillOldProbe = $probe('staging_written_live_must_stay_old');
$stillOldDisk = (string)\file_get_contents((string)$liveFile);
if (\str_contains($stillOldDisk, \trim($marker))) {
    FpcHtmlDiskStore::abandonStagingGeneration();
    $fail('live disk already shows staging marker before promote');
}
if ($stillOldProbe['status'] !== 200 || \strtoupper($stillOldProbe['fpc']) !== 'HIT') {
    FpcHtmlDiskStore::abandonStagingGeneration();
    $fail('live HIT lost after staging write', $stillOldProbe);
}
echo "UC4-B staging_open id={$stagingId} live_gen={$duringGen} storefront_HIT=yes marker_on_live=no\n";

if (!FpcHtmlDiskStore::promoteStagingGeneration()) {
    FpcHtmlDiskStore::abandonStagingGeneration();
    $fail('promoteStagingGeneration failed');
}
$afterGen = FpcHtmlDiskStore::currentGenerationId();
$prevGen = FpcHtmlDiskStore::previousGenerationId();
$afterLive = FpcHtmlDiskStore::logicalLiveBodyPath($url, false);
$afterBody = \is_string($afterLive) && \is_file($afterLive) ? (string)\file_get_contents($afterLive) : '';
if ($afterGen === $beforeGen) {
    $fail('live generation unchanged after promote', ['before' => $beforeGen, 'after' => $afterGen]);
}
if ($prevGen !== $beforeGen) {
    $fail('previous must point at pre-promote live gen', ['expected' => $beforeGen, 'previous' => $prevGen]);
}
if (!\str_contains($afterBody, \trim($marker))) {
    $fail('promoted live missing staging marker', ['after_gen' => $afterGen]);
}
// Nginx may cache etag briefly; force unique query and accept HIT or miss→origin as long as body has marker via disk.
$afterProbe = $probe('after_promote');
if ($afterProbe['status'] !== 200) {
    $fail('storefront non-200 after promote', $afterProbe);
}
echo "UC4-C promote ok live={$afterGen} previous={$prevGen} fpc={$afterProbe['fpc']} src={$afterProbe['source']}\n";

// --- Abandon path: open staging, write junk, abandon, live stays ---
$keepGen = FpcHtmlDiskStore::currentGenerationId();
$keepSha = \hash_file('sha256', (string)FpcHtmlDiskStore::logicalLiveBodyPath($url, false));
FpcHtmlDiskStore::beginStagingGeneration(true);
FpcHtmlDiskStore::publish($url, "<html><body>abandon-me</body></html>\n", '');
FpcHtmlDiskStore::abandonStagingGeneration();
$afterAbandonGen = FpcHtmlDiskStore::currentGenerationId();
$afterAbandonSha = \hash_file('sha256', (string)FpcHtmlDiskStore::logicalLiveBodyPath($url, false));
if ($afterAbandonGen !== $keepGen || $afterAbandonSha !== $keepSha) {
    $fail('abandon must keep live generation/body', [
        'keep_gen' => $keepGen,
        'after_gen' => $afterAbandonGen,
        'keep_sha' => $keepSha,
        'after_sha' => $afterAbandonSha,
    ]);
}
$abandonProbe = $probe('after_abandon');
if ($abandonProbe['status'] !== 200 || \strtoupper($abandonProbe['fpc']) !== 'HIT') {
    $fail('storefront must HIT after abandon', $abandonProbe);
}
echo "UC4-D abandon keeps live={$afterAbandonGen} HIT=yes\n";

// --- Real cleaner path (theme publish invalidation) ---
$preCleanerGen = FpcHtmlDiskStore::currentGenerationId();
/** @var ThemeRuntimeCacheCleaner $cleaner */
$cleaner = ObjectManager::getInstance(ThemeRuntimeCacheCleaner::class);
$started = \microtime(true);
$result = $cleaner->clearAllThemeRelatedCaches(null, 'theme_fpc_staging_uc_harness');
$elapsedMs = (\microtime(true) - $started) * 1000.0;
$postCleanerGen = FpcHtmlDiskStore::currentGenerationId();
$postPrev = FpcHtmlDiskStore::previousGenerationId();
$cleanerProbe = $probe('after_clearAllThemeRelatedCaches');

$stagingStep = $result['steps']['fpc_staging_warm_promote'] ?? null;
$stagingFail = $result['failures']['fpc_staging_warm_promote'] ?? null;
echo "UC4-E clearer elapsed_ms=" . \round($elapsedMs, 1)
    . " staging_step=" . \json_encode($stagingStep)
    . " staging_fail=" . \json_encode($stagingFail)
    . " pre={$preCleanerGen} post={$postCleanerGen} prev={$postPrev}"
    . " fpc={$cleanerProbe['fpc']} src={$cleanerProbe['source']} status={$cleanerProbe['status']}\n";

if ($stagingStep !== true) {
    $fail('clearAllThemeRelatedCaches fpc_staging_warm_promote step not true', $result);
}
if ($cleanerProbe['status'] !== 200) {
    $fail('storefront broken after clearAllThemeRelatedCaches', $cleanerProbe);
}
// Soft expectation: after successful promote, live should have rotated (seed+warm+promote).
if ($postCleanerGen === $preCleanerGen) {
    $fail('theme cleaner did not rotate live generation', [
        'pre' => $preCleanerGen,
        'post' => $postCleanerGen,
        'result' => $result,
    ]);
}
if ($postPrev !== $preCleanerGen) {
    $fail('theme cleaner previous should be pre-cleaner live', [
        'expected_prev' => $preCleanerGen,
        'prev' => $postPrev,
    ]);
}
if (\strtoupper($cleanerProbe['fpc']) !== 'HIT' && \strtoupper($cleanerProbe['fpc']) !== 'MISS') {
    $fail('unexpected fpc header after cleaner', $cleanerProbe);
}
// Seeded promote must not leave empty homepage (hole).
$home = FpcHtmlDiskStore::logicalLiveBodyPath($url, false);
if ($home === null || !\is_file($home) || \filesize($home) < 1000) {
    $fail('homepage missing or tiny after theme cleaner (live hole)', ['path' => $home]);
}

echo "PASS theme-fpc-staging-publish-harness\n";
echo \json_encode([
    'host' => $host,
    'before_gen' => $beforeGen,
    'after_manual_promote_gen' => $afterGen,
    'after_cleaner_gen' => $postCleanerGen,
    'cleaner_elapsed_ms' => \round($elapsedMs, 1),
    'cleaner_probe' => $cleanerProbe,
    'steps_ok' => \array_keys(\array_filter($result['steps'] ?? [], static fn($v) => $v === true)),
    'failures' => $result['failures'] ?? [],
], \JSON_UNESCAPED_UNICODE | \JSON_PRETTY_PRINT) . "\n";
exit(0);

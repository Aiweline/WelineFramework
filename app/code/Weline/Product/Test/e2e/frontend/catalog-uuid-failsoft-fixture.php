<?php

declare(strict_types=1);

/**
 * E2E fixture matrix for catalog UUID fail-soft (source + unit contract).
 */
$root = dirname(__DIR__, 7);
$serviceSrc = (string)file_get_contents($root . '/app/code/Weline/Product/Service/ProductAdminReadService.php');
$testSrc = (string)file_get_contents(
    $root . '/app/code/Weline/Product/Test/Unit/Service/ProductAdminReadServiceBatchSearchTest.php'
);

$wired = str_contains($serviceSrc, 'resolveProductsByUuids($uuids, strict: !$includeDetails)');
$hasTest = str_contains($testSrc, 'testSearchFailSoftOnCorruptGlobalProductUuid');

$unitOk = false;
$unitOut = '';
$cmd = [
    PHP_BINARY,
    $root . '/vendor/bin/phpunit',
    '--bootstrap',
    $root . '/app/code/Weline/Product/Test/Unit/bootstrap.php',
    '--filter',
    'ProductAdminReadServiceBatchSearchTest::testSearchFailSoftOnCorruptGlobalProductUuid',
    $root . '/app/code/Weline/Product/Test/Unit/Service/ProductAdminReadServiceBatchSearchTest.php',
];
$proc = proc_open(
    $cmd,
    [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
    $pipes,
    $root,
);
if (is_resource($proc)) {
    $unitOut = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    foreach ($pipes as $pipe) {
        fclose($pipe);
    }
    $code = proc_close($proc);
    $unitOk = $code === 0 && str_contains($unitOut, 'OK');
}

echo json_encode([
    'ok' => $wired && $hasTest && $unitOk,
    'wired_bulk_permissive' => $wired,
    'has_unit' => $hasTest,
    'unit_ok' => $unitOk,
], JSON_UNESCAPED_UNICODE) . "\n";

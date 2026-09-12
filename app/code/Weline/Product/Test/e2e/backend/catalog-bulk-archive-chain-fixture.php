<?php

declare(strict_types=1);

/**
 * E2E fixture: catalog bulk archive chain + idempotent + JS failed counts.
 */
$root = dirname(__DIR__, 7);
$commandSrc = (string)file_get_contents($root . '/app/code/Weline/Product/Service/ProductAdminCommandService.php');
$jsSrc = (string)file_get_contents($root . '/app/code/Weline/Product/view/statics/js/backend/product-admin.js');
$readSrc = (string)file_get_contents($root . '/app/code/Weline/Product/Service/ProductAdminReadService.php');

$wiredChain = str_contains($commandSrc, 'lifecycleStepsToward')
    && (bool)preg_match('/published.*?disabled.*?archived/s', $commandSrc)
    && str_contains($commandSrc, '商品已归档');
$wiredJs = (bool)preg_match('/function bulkArchive\([\s\S]*?data\.failed[\s\S]*?succeeded/m', $jsSrc);
$wiredHideArchived = str_contains($readSrc, 'STATUS_ARCHIVED')
    && str_contains($readSrc, '$statusFilter === \'\' && $includeDetails');

$bulkSrc = (string)file_get_contents($root . '/app/code/Weline/Product/Service/ProductAdminBulkService.php');
$wiredUuidPurge = str_contains($bulkSrc, 'isValidProductUuid')
    && str_contains($bulkSrc, 'ProductPhysicalDeleteService')
    && str_contains($bulkSrc, 'deleteByIds')
    && (bool)preg_match('/function bulkArchive\([\s\S]*?product_id:\s*item\.product_id/m', $jsSrc);

$unitOk = false;
$unitOut = '';
$cmd = [
    PHP_BINARY,
    $root . '/vendor/bin/phpunit',
    '--no-configuration',
    $root . '/app/code/Weline/Product/Test/Unit/Service/ProductAdminArchiveTransitionContractTest.php',
    $root . '/app/code/Weline/Product/Test/Unit/Service/ProductAdminBulkInvalidUuidPurgeContractTest.php',
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
    'ok' => $wiredChain && $wiredJs && $wiredHideArchived && $wiredUuidPurge && $unitOk,
    'wired_chain' => $wiredChain,
    'wired_js' => $wiredJs,
    'wired_hide_archived' => $wiredHideArchived,
    'wired_uuid_purge' => $wiredUuidPurge,
    'unit_ok' => $unitOk,
], JSON_UNESCAPED_UNICODE) . "\n";

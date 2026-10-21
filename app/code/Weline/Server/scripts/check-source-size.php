<?php

declare(strict_types=1);

/**
 * WLS source-size budget guard.
 *
 * Large files are not automatically wrong, but unchecked growth is. This guard
 * fails when a non-allowlisted PHP file exceeds the budget so new god-files
 * cannot slip in silently. Existing giants carry an explicit allowance and a
 * roadmap entry; lower an allowance only after the corresponding split lands.
 *
 * Usage: php app/code/Weline/Server/scripts/check-source-size.php [--json]
 */

$root = \dirname(__DIR__) . \DIRECTORY_SEPARATOR;
$budget = (int)(\getenv('WLS_MAX_PHP_LINES') ?: 5000);
// Exact current ceilings: any growth fails until the split lands.
$allow = [
    'bin/wls_gateway_controller.php' => 50499,
    'Service/ServiceOrchestrator.php' => 34506,
    'Service/Edge/Gateway/HostGatewayPackageManager.php' => 20946,
    'Console/Server/Start.php' => 15265,
    'bin/worker_ssl.php' => 13081,
    'Service/SslCertificateService.php' => 12609,
    'Runtime/WlsRuntime.php' => 11284,
    'Service/Edge/Gateway/GatewayPlatformServiceInstaller.php' => 9876,
    'Service/Edge/Gateway/GatewayHostManager.php' => 8256,
    'bin/worker.php' => 7564,
    'Service/Edge/Nginx/ManagedNginxService.php' => 6117,
    'Service/Edge/Gateway/ProjectCertificateGenerationStore.php' => 6034,
];

$iterator = new \RecursiveIteratorIterator(
    new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
);
$rows = [];
foreach ($iterator as $file) {
    if (!$file instanceof \SplFileInfo || $file->getExtension() !== 'php') {
        continue;
    }
    $relative = \str_replace($root, '', $file->getPathname());
    $relative = \str_replace('\\', '/', $relative);
    if (\str_starts_with($relative, 'Test/')) {
        continue;
    }
    $lines = \count(\file($file->getPathname()) ?: []);
    $limit = $allow[$relative] ?? $budget;
    if ($lines > $limit) {
        $rows[] = ['file' => $relative, 'lines' => $lines, 'limit' => $limit];
    }
}

if (\in_array('--json', $argv ?? [], true)) {
    echo \json_encode(['budget' => $budget, 'violations' => $rows], \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES), "\n";
} else {
    echo "WLS source-size budget: {$budget} lines (allowlist=" . \count($allow) . ")\n";
    foreach ($rows as $row) {
        echo "  OVER  {$row['file']}: {$row['lines']} > {$row['limit']}\n";
    }
    echo $rows === [] ? "OK: no non-allowlisted file exceeds the budget.\n" : "FAIL: " . \count($rows) . " file(s) over budget.\n";
}

exit($rows === [] ? 0 : 1);

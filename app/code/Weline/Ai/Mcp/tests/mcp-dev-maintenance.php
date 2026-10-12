<?php

declare(strict_types=1);

use LearningMcp\Analyzer;
use LearningMcp\Config;
use LearningMcp\DevMaintenanceGate;
use LearningMcp\Store;
use LearningMcp\ToolException;
use LearningMcp\ToolService;

require dirname(__DIR__) . '/src/bootstrap.php';

$temporary = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'weline-mcp-dev-' . bin2hex(random_bytes(6));
$dataDirectory = $temporary . DIRECTORY_SEPARATOR . 'data';
$failures = [];

function mcpDevCheck(bool $condition, string $label): void
{
    global $failures;
    if ($condition) {
        fwrite(STDOUT, "[PASS] {$label}\n");
        return;
    }
    $failures[] = $label;
    fwrite(STDERR, "[FAIL] {$label}\n");
}

try {
    mkdir($dataDirectory, 0700, true);
    file_put_contents($temporary . '/config.json', json_encode([
        'data_dir' => $dataDirectory,
        'index' => [
            'enabled' => false,
            'sidecar_enabled' => false,
            'bound_repository' => $temporary,
        ],
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

    $config = Config::load($temporary . '/config.json');
    $gate = new DevMaintenanceGate($config);
    $enabled = $gate->enable('async prepare work', 'test-owner', '1h');
    mcpDevCheck(($enabled['active'] ?? false) === true, 'mcp-dev enable marks gate active');
    mcpDevCheck(is_file($gate->path()), 'mcp-dev writes maintenance flag under data dir');

    $store = new Store($config);
    $tools = new ToolService($store, $config, new Analyzer($store, $config));
    $blocked = false;
    $instruction = '';
    try {
        $tools->call('prepare_project', [
            'repository' => $temporary,
            'client_session_id' => 'dev-gate',
        ]);
    } catch (ToolException $exception) {
        $blocked = $exception->errorCode === 'MCP_DEV_MAINTENANCE';
        $instruction = (string) ($exception->details['caller_instruction'] ?? '');
        mcpDevCheck(
            str_contains($exception->getMessage(), '宿主原生')
                || str_contains($exception->getMessage(), 'host-native')
                || str_contains($exception->getMessage(), '改用宿主原生'),
            'MCP_DEV_MAINTENANCE message tells caller to use host-native tools',
        );
    }
    mcpDevCheck($blocked, 'prepare_project is blocked while maintenance is active');
    mcpDevCheck($instruction === 'use_host_native_tools', 'blocked response sets caller_instruction=use_host_native_tools');

    $health = $tools->call('health', []);
    mcpDevCheck(($health['dev_maintenance']['active'] ?? false) === true, 'health remains callable and reports active maintenance');
    mcpDevCheck(($health['dev_maintenance']['mcp_available'] ?? true) === false, 'health reports mcp_available=false');
    mcpDevCheck(
        ($health['dev_maintenance']['caller_instruction'] ?? '') === 'use_host_native_tools',
        'health instructs callers to use host-native tools',
    );

    $disabled = $gate->disable('test-owner');
    mcpDevCheck(($disabled['active'] ?? true) === false, 'mcp-dev disable clears the gate');
    mcpDevCheck(!is_file($gate->path()), 'maintenance flag file is removed on disable');
} finally {
    $store?->close();
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($temporary, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );
    foreach ($iterator as $file) {
        $file->isDir() ? @rmdir($file->getPathname()) : @unlink($file->getPathname());
    }
    @rmdir($temporary);
}

if ($failures !== []) {
    fwrite(STDERR, 'mcp-dev-maintenance failures: ' . count($failures) . "\n");
    exit(1);
}

fwrite(STDOUT, "mcp-dev-maintenance: all checks passed\n");
exit(0);

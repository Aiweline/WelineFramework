<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/scripts/project-guidance-mcp-install.php';
require_once dirname(__DIR__) . '/scripts/project-guidance-reload-policy.php';
require_once dirname(__DIR__) . '/src/bootstrap.php';

$failed = false;
$check = static function (bool $passed, string $label) use (&$failed): void {
    fwrite($passed ? STDOUT : STDERR, sprintf("[%s] %s\n", $passed ? 'PASS' : 'FAIL', $label));
    $failed = $failed || !$passed;
};
$installed = ['binary_found' => true, 'ready' => true];
$check(
    welineMcpInstallDetectPrimaryHost($installed, $installed, 'codex_app_server') === 'codex',
    'Codex runtime wins over installed Cursor and Claude binaries',
);
$check(
    welineMcpInstallDetectPrimaryHost($installed, $installed, 'cursor') === 'cursor',
    'explicit cursor host kind maps to cursor',
);
$check(
    welineMcpInstallDetectPrimaryHost($installed, $installed, 'other') === 'unknown',
    'installed editor binaries do not prove the active host',
);

$previousCursorAgent = getenv('CURSOR_AGENT');
$previousCursorRole = getenv('CURSOR_EXTENSION_HOST_ROLE');
$previousCodexThread = getenv('CODEX_THREAD_ID');
$previousClaude = getenv('CLAUDECODE');
$previousTerm = getenv('TERM_PROGRAM');
putenv('CODEX_THREAD_ID');
putenv('CLAUDECODE');
putenv('TERM_PROGRAM');
putenv('CURSOR_EXTENSION_HOST_ROLE');
putenv('CURSOR_AGENT=1');
$check(
    welineMcpInstallDetectPrimaryHost([], [], null) === 'cursor',
    'CURSOR_AGENT env selects cursor without TERM_PROGRAM',
);
putenv('CURSOR_AGENT');
if ($previousCursorAgent !== false) {
    putenv('CURSOR_AGENT=' . $previousCursorAgent);
}
if ($previousCursorRole !== false) {
    putenv('CURSOR_EXTENSION_HOST_ROLE=' . $previousCursorRole);
}
if ($previousCodexThread !== false) {
    putenv('CODEX_THREAD_ID=' . $previousCodexThread);
}
if ($previousClaude !== false) {
    putenv('CLAUDECODE=' . $previousClaude);
}
if ($previousTerm !== false) {
    putenv('TERM_PROGRAM=' . $previousTerm);
}

$decision = welineGuidanceReloadDecision(['kind' => 'codex_app_server', 'current' => false], false, true);
$check(
    $decision['reload_required'] === false && $decision['plugin_refresh_deferred'] === true,
    'source changes do not require restarting the Codex app-server',
);

$temporary = sys_get_temp_dir() . '/weline-host-detection-' . bin2hex(random_bytes(5));
$runner = new \LearningMcp\ProcessRunner();
try {
    $result = $runner->run([
        PHP_BINARY,
        dirname(__DIR__) . '/scripts/install.php',
        'install',
        '--dry-run',
        '--config=' . $temporary . '/config.yaml',
        '--marketplace-dir=' . $temporary . '/marketplace',
    ], dirname(__DIR__), '', 30);
    $check($result['exit_code'] === 0, 'isolated installer generates the real plugin configuration');
    $configuration = json_decode((string) file_get_contents(
        $temporary . '/marketplace/plugins/weline-project-intelligence/.mcp.json',
    ), true, 512, JSON_THROW_ON_ERROR);
    $enabled = $configuration['mcpServers']['weline-project-intelligence']['enabled_tools'] ?? [];
    $expected = [
        'get_indexed_document',
        'get_skill',
        'health',
        'prepare_project',
        'project_index_status',
        'repair_project_docs',
        'resolve_skill',
        'resolve_task_context',
        'search_project_knowledge',
    ];
    $sortedEnabled = is_array($enabled) ? $enabled : [];
    sort($sortedEnabled);
    $check($sortedEnabled === $expected, 'plugin enabled_tools equals the nine index/knowledge tools');
} finally {
    if (is_dir($temporary)) {
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($temporary, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($files as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($temporary);
    }
}

exit($failed ? 1 : 0);

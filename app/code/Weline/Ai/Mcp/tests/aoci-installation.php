<?php

declare(strict_types=1);

use LearningMcp\AociInstaller;
use LearningMcp\Config;
use LearningMcp\IntelligenceService;
use LearningMcp\ProcessRunner;
use LearningMcp\Store;

require dirname(__DIR__) . '/src/bootstrap.php';

if (!class_exists(AociInstaller::class)) {
    fwrite(STDERR, "[FAIL] prepare-time AOCI installer is not implemented\n");
    exit(1);
}

$temporary = sys_get_temp_dir() . '/weline-aoci-' . bin2hex(random_bytes(6));
$failures = [];
function aociCheck(bool $condition, string $label): void
{
    global $failures;
    if (!$condition) {
        $failures[] = $label;
    }
    fwrite($condition ? STDOUT : STDERR, ($condition ? '[PASS] ' : '[FAIL] ') . $label . "\n");
}
function aociRemove(string $path): void
{
    if (is_dir($path) && !is_link($path)) {
        foreach (scandir($path) ?: [] as $name) {
            if ($name !== '.' && $name !== '..') {
                aociRemove($path . '/' . $name);
            }
        }
        rmdir($path);
    } else {
        @unlink($path);
    }
}

try {
    mkdir($temporary . '/existing', 0700, true);
    $counter = $temporary . '/version-calls';
    $binary = $temporary . '/existing/aoci';
    file_put_contents($binary, '#!' . PHP_BINARY . "\n<?php\n"
        . 'file_put_contents(' . var_export($counter, true) . ', "v", FILE_APPEND);' . "\n"
        . 'echo "aoci version 0.1.0-rc17 (commit fixture)\\n";' . "\n");
    chmod($binary, 0755);
    $runner = new ProcessRunner();
    $noDownload = static function (string $url, string $path): void {
        throw new RuntimeException('unexpected download');
    };
    $installer = new AociInstaller($temporary . '/state-existing', $runner, [$temporary . '/existing'], $noDownload);
    $first = $installer->ensure($temporary);
    aociCheck(($first['status'] ?? '') === 'ready' && ($first['source'] ?? '') === 'existing', 'existing AOCI is reused and verified');
    aociCheck(($first['binary'] ?? '') === realpath($binary) && is_file($first['marker_path'] ?? ''), 'verified binary path is persisted locally');
    $marker = file_get_contents($first['marker_path']);
    unlink($binary);
    $again = (new AociInstaller($temporary . '/state-existing', $runner, [], $noDownload))->ensure($temporary);
    aociCheck(($again['status'] ?? '') === 'ready' && ($again['cached'] ?? false), 'new instance trusts the success marker without binary detection');
    aociCheck(file_get_contents($counter) === 'v' && file_get_contents($first['marker_path']) === $marker, 'cached preparation neither executes the binary nor rewrites the marker');

    mkdir($temporary . '/package');
    $packageBinary = $temporary . '/package/aoci';
    file_put_contents($packageBinary, '#!' . PHP_BINARY . "\n<?php echo \"aoci version 0.1.0-rc17\\n\";\n");
    chmod($packageBinary, 0755);
    $archive = $temporary . '/release.tar.gz';
    $tar = $runner->run(['tar', '-czf', $archive, '-C', $temporary . '/package', 'aoci'], $temporary);
    if ($tar['exit_code'] !== 0) {
        throw new RuntimeException('cannot create release fixture: ' . $tar['stderr']);
    }
    $releaseDownload = static function (string $url, string $path) use ($archive): void {
        if (basename($url) === 'SHA256SUMS') {
            $asset = 'aoci_0.1.0-rc17_' . (PHP_OS_FAMILY === 'Darwin' ? 'darwin' : 'linux')
                . '_' . (in_array(strtolower(php_uname('m')), ['arm64', 'aarch64'], true) ? 'arm64' : 'amd64') . '.tar.gz';
            file_put_contents($path, hash_file('sha256', $archive) . '  ' . $asset . "\n");
        } else {
            copy($archive, $path);
        }
    };
    $installed = (new AociInstaller($temporary . '/state-install', $runner, [], $releaseDownload))->ensure($temporary);
    aociCheck(($installed['status'] ?? '') === 'ready' && ($installed['source'] ?? '') === 'download', 'missing AOCI is installed from a checksum-verified archive');
    aociCheck(is_executable($installed['binary'] ?? '') && is_file($installed['marker_path'] ?? ''), 'successful install produces a runnable binary and success marker');
    $identity = $runner->run([$installed['binary'], '--version'], $temporary);
    aociCheck($identity['exit_code'] === 0 && str_contains($identity['stdout'], 'aoci version 0.1.0-rc17'), 'installed program really runs');

    $brokenDownload = static function (string $url, string $path) use ($archive, $releaseDownload): void {
        $releaseDownload($url, $path);
        if (basename($url) === 'SHA256SUMS') {
            file_put_contents($path, str_replace(hash_file('sha256', $archive), str_repeat('0', 64), file_get_contents($path)));
        }
    };
    $failed = (new AociInstaller($temporary . '/state-fail', $runner, [], $brokenDownload))->ensure($temporary);
    aociCheck(($failed['status'] ?? '') === 'failed' && !is_file($failed['marker_path'] ?? ''), 'failed verification never records success');
    $retried = (new AociInstaller($temporary . '/state-fail', $runner, [], $releaseDownload))->ensure($temporary);
    aociCheck(($retried['status'] ?? '') === 'ready', 'a later preparation can succeed after a failed install');

    $offline = (new AociInstaller($temporary . '/state-offline', $runner, [], static function (): void {
        throw new RuntimeException('network unavailable');
    }))->ensure($temporary);
    aociCheck(($offline['status'] ?? '') === 'failed' && str_contains($offline['error'] ?? '', 'network unavailable')
        && !is_file($offline['marker_path'] ?? ''), 'network failure reports the cause without recording success');

    mkdir($temporary . '/project/app/code/Acme/Demo/etc', 0700, true);
    file_put_contents($temporary . '/project/app/code/Acme/Demo/etc/module.php', "<?php return ['name' => 'Acme_Demo', 'version' => '1.0.0'];\n");
    file_put_contents($temporary . '/config.json', json_encode([
        'data_dir' => $temporary . '/data',
        'index' => ['sidecar_enabled' => false],
        'analysis' => ['provider' => 'none'],
    ], JSON_THROW_ON_ERROR));
    $config = Config::load($temporary . '/config.json', $temporary . '/data');
    $store = new Store($config);
    $service = new IntelligenceService($store, $config, new AociInstaller($temporary . '/state-install', $runner, [], $noDownload));
    $prepared = $service->call('prepare_project', ['repository' => $temporary . '/project', 'client_session_id' => 'aoci-test']);
    $guidance = $prepared['agent_guidance']['aoci_installation'] ?? [];
    aociCheck(($prepared['ready'] ?? false) && ($guidance['status'] ?? '') === 'ready' && ($guidance['cached'] ?? false), 'public preparation delivers cached installation guidance');
    aociCheck(($guidance['mcp_registration']['args'] ?? []) === ['--repo', realpath($temporary . '/project'), 'mcp'], 'shared installation binds MCP instructions to the current project');
    unset($service);
    $failureService = new IntelligenceService($store, $config, new AociInstaller($temporary . '/state-offline', $runner, [], static function (): void {
        throw new RuntimeException('network unavailable');
    }));
    $failurePrepared = $failureService->call('prepare_project', ['repository' => $temporary . '/project', 'client_session_id' => 'aoci-failure']);
    aociCheck(($failurePrepared['ready'] ?? false) && ($failurePrepared['agent_guidance']['aoci_installation']['status'] ?? '') === 'failed', 'AOCI installation failure is visible without changing Weline readiness');
    aociCheck(!file_exists($temporary . '/project/.aoci') && !file_exists($temporary . '/project/aoci.txt'), 'preparation does not initialize or scan AOCI in the project');
    unset($failureService, $store);
} finally {
    aociRemove($temporary);
}
exit($failures === [] ? 0 : 1);

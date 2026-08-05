<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Service;

use Weline\Framework\Async\TaskContextInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Test\Model\TestRun;

/**
 * Executes Playwright / PHPUnit subprocesses and streams progress into TestRun + task context.
 */
final class TestRunExecutor
{
    /**
     * @param array{
     *   run_id:int,
     *   module:string,
     *   type:string,
     *   ui_enabled?:bool,
     *   files?:list<string>
     * } $payload
     */
    public function execute(array $payload, ?TaskContextInterface $task = null): int
    {
        $runId = (int)($payload['run_id'] ?? 0);
        $module = trim((string)($payload['module'] ?? ''));
        $type = strtolower(trim((string)($payload['type'] ?? TestRun::TYPE_E2E)));
        $uiEnabled = (bool)($payload['ui_enabled'] ?? false);
        $files = [];
        if (isset($payload['files']) && is_array($payload['files'])) {
            foreach ($payload['files'] as $file) {
                if (is_string($file) && trim($file) !== '') {
                    $files[] = str_replace('\\', '/', trim($file));
                }
            }
        }

        if ($runId <= 0 || $module === '') {
            throw new \InvalidArgumentException((string)__('测试执行参数无效。'));
        }

        /** @var TestRunService $runService */
        $runService = ObjectManager::getInstance(TestRunService::class);
        $runService->appendProgress($runId, [
            'passed' => 0,
            'failed' => 0,
            'skipped' => 0,
            'total' => count($files),
            'current' => '',
            'percent' => 0,
        ], (string)__('开始执行 %{1} 测试：%{2}', [$type, $module]), TestRun::STATUS_RUNNING);
        $task?->setProcess((string)__('运行中：%{1}/%{2}', [$module, $type]))->persist();

        try {
            $exitCode = $type === TestRun::TYPE_E2E
                ? $this->runE2e($runId, $module, $uiEnabled, $files, $runService, $task)
                : $this->runPhpUnit($runId, $module, $files, $runService, $task);
        } catch (\Throwable $e) {
            $hint = $uiEnabled && !$this->canRunHeadedUi()
                ? (string)__('若无图形界面，请关闭「UI 测试」后重试。')
                : '';
            $message = $e->getMessage() . ($hint !== '' ? ' ' . $hint : '');
            $runService->appendProgress($runId, [], $message, TestRun::STATUS_ERROR);
            $runService->finalize($runId, 1, '', $message);
            $task?->setProcess($message)->setResult($message)->persist();
            throw $e;
        }

        $status = $exitCode === 0 ? TestRun::STATUS_SUCCESS : TestRun::STATUS_FAILED;
        $summary = $exitCode === 0
            ? (string)__('测试执行成功。')
            : (string)__('测试执行失败，退出码：%{1}', [(string)$exitCode]);
        if ($exitCode !== 0 && $uiEnabled && !$this->canRunHeadedUi()) {
            $summary .= ' ' . (string)__('若无图形界面，请关闭「UI 测试」后重试。');
        }
        $runService->appendProgress($runId, [], $summary, $status);
        $runService->finalize($runId, $exitCode, '', $exitCode === 0 ? '' : $summary);
        $task?->setProcess($summary)->setResult($summary)->persist();

        return $exitCode;
    }

    /**
     * @param list<string> $files
     */
    private function runE2e(
        int $runId,
        string $module,
        bool $uiEnabled,
        array $files,
        TestRunService $runService,
        ?TaskContextInterface $task
    ): int {
        $e2eDir = BP . 'tests' . DIRECTORY_SEPARATOR . 'e2e';
        if (!is_dir($e2eDir)) {
            throw new \RuntimeException((string)__('未找到 E2E 目录：%{1}', [$e2eDir]));
        }

        $node = $this->resolveNodeBinary();
        $cli = $e2eDir . DIRECTORY_SEPARATOR . 'node_modules' . DIRECTORY_SEPARATOR . 'playwright' . DIRECTORY_SEPARATOR . 'cli.js';
        if (!is_file($cli)) {
            throw new \RuntimeException((string)__('未找到 Playwright CLI。请先在 tests/e2e 安装依赖。'));
        }

        $files = $this->sanitizeE2eFiles($files);
        // 列表页「UI 测试」开关必须原样生效：开启 → headed，关闭 → headless。
        // 禁止因探测不到 DISPLAY 而静默降级，否则总览行内「运行 E2E」会与开关不一致。
        if ($uiEnabled && !$this->canRunHeadedUi()) {
            throw new \RuntimeException(
                (string)__('已开启「UI 测试」，但当前环境无法弹出浏览器。请关闭「UI 测试」后重试，或在有图形界面的环境运行。')
            );
        }
        $headed = $uiEnabled;

        $args = [$node, $cli, 'test', '--workers=1', '--reporter=list', '--project=chromium'];
        if ($headed) {
            $args[] = '--headed';
        }

        $env = $this->baseEnv();
        $env['MODULE_FILTER'] = $module;
        $env['PLAYWRIGHT_TEST_FILES'] = json_encode(array_values($files), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '[]';
        $env['PLAYWRIGHT_HTML_REPORT'] = '0';
        if (!$headed) {
            $env['PLAYWRIGHT_HEADLESS'] = '1';
        } else {
            unset($env['PLAYWRIGHT_HEADLESS']);
        }
        $instanceName = $this->resolvePlaywrightInstanceName();
        if ($instanceName !== '') {
            $env['PLAYWRIGHT_INSTANCE_NAME'] = $instanceName;
            $runService->appendProgress(
                $runId,
                [],
                (string)__('E2E 目标实例：%{1}（UI=%{2}）', [
                    $instanceName,
                    $headed ? 'ON' : 'OFF',
                ]),
                TestRun::STATUS_RUNNING
            );
        } else {
            $runService->appendProgress(
                $runId,
                [],
                (string)__('E2E UI 模式：%{1}', [$headed ? 'ON' : 'OFF']),
                TestRun::STATUS_RUNNING
            );
        }

        return $this->runProcess($args, $e2eDir, $env, $runId, count($files), $runService, $task, true);
    }

    /**
     * @param list<string> $files
     */
    private function runPhpUnit(
        int $runId,
        string $module,
        array $files,
        TestRunService $runService,
        ?TaskContextInterface $task
    ): int {
        $phpunit = BP . 'vendor' . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'phpunit';
        if (!is_file($phpunit)) {
            throw new \RuntimeException((string)__('未找到 phpunit：%{1}', [$phpunit]));
        }

        $bootstrap = BP . 'app' . DIRECTORY_SEPARATOR . 'bootstrap_phpunit.php';
        $args = [PHP_BINARY, $phpunit, '--bootstrap', $bootstrap];
        if ($files !== []) {
            foreach ($files as $file) {
                $absolute = str_starts_with($file, DIRECTORY_SEPARATOR) || preg_match('#^[A-Za-z]:[\\\\/]#', $file) === 1
                    ? $file
                    : BP . ltrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $file), DIRECTORY_SEPARATOR);
                if (is_file($absolute)) {
                    $args[] = $absolute;
                }
            }
        } else {
            // Fallback: ask collection service paths via catalog
            /** @var TestCatalogService $catalog */
            $catalog = ObjectManager::getInstance(TestCatalogService::class);
            $cases = $catalog->listCases($module, null);
            foreach (array_merge($cases['tests']['unit'], $cases['tests']['integration'], $cases['tests']['phpunit']) as $file) {
                $absolute = BP . ltrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $file), DIRECTORY_SEPARATOR);
                if (is_file($absolute)) {
                    $args[] = $absolute;
                }
            }
        }

        if (count($args) <= 4) {
            throw new \RuntimeException((string)__('模块 %{1} 没有可执行的 PHPUnit 文件。', [$module]));
        }

        return $this->runProcess($args, BP, $this->baseEnv(), $runId, max(1, count($args) - 4), $runService, $task, false);
    }

    /**
     * @param list<string> $args
     * @param array<string,string> $env
     */
    private function runProcess(
        array $args,
        string $cwd,
        array $env,
        int $runId,
        int $total,
        TestRunService $runService,
        ?TaskContextInterface $task,
        bool $isE2e
    ): int {
        if (!function_exists('proc_open')) {
            throw new \RuntimeException((string)__('当前 PHP 环境不支持 proc_open。'));
        }

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $process = @proc_open($args, $descriptors, $pipes, $cwd, $env);
        if (!is_resource($process)) {
            throw new \RuntimeException((string)__('无法启动测试进程。'));
        }

        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $passed = 0;
        $failed = 0;
        $skipped = 0;
        $current = '';
        $buffer = '';

        while (true) {
            $stdout = stream_get_contents($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
            $chunk = '';
            if (is_string($stdout) && $stdout !== '') {
                $chunk .= $stdout;
            }
            if (is_string($stderr) && $stderr !== '') {
                $chunk .= $stderr;
            }

            if ($chunk !== '') {
                $buffer .= $chunk;
                $lines = preg_split("/\r\n|\n|\r/", $buffer) ?: [];
                $buffer = array_pop($lines) ?? '';
                foreach ($lines as $line) {
                    $line = rtrim($line);
                    if ($line === '') {
                        continue;
                    }
                    if ($isE2e) {
                        if (preg_match('/✓|✔|passed/i', $line)) {
                            $passed++;
                        } elseif (preg_match('/✘|✗|failed|error/i', $line)) {
                            $failed++;
                        } elseif (preg_match('/skipped|°/i', $line)) {
                            $skipped++;
                        }
                        if (preg_match('/\.(spec\.js)/', $line) || preg_match('/›|-/u', $line)) {
                            $current = mb_substr($line, 0, 200);
                        }
                    } else {
                        if (preg_match('/^OK\b/i', $line) || preg_match('/tests?,?\s+\d+\s+assertions?/i', $line)) {
                            // keep
                        }
                        if (preg_match('/FAIL|ERROR/i', $line)) {
                            $failed++;
                        } elseif (preg_match('/OK\s*\(/i', $line)) {
                            $passed = max($passed, 1);
                        }
                        $current = mb_substr($line, 0, 200);
                    }

                    $done = $passed + $failed + $skipped;
                    $percent = $total > 0 ? (int)min(100, round(($done / $total) * 100)) : 0;
                    $progress = [
                        'passed' => $passed,
                        'failed' => $failed,
                        'skipped' => $skipped,
                        'total' => $total,
                        'current' => $current,
                        'percent' => $percent,
                    ];
                    $runService->appendProgress($runId, $progress, $line, TestRun::STATUS_RUNNING);
                    $task?->setProcess((string)__('进度 %{1}%：%{2}', [(string)$percent, $current]))->persist();
                }
            }

            $status = proc_get_status($process);
            if (!$status['running']) {
                break;
            }
            usleep(150000);
        }

        $restOut = stream_get_contents($pipes[1]);
        $restErr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $tail = trim((string)$restOut . (string)$restErr . $buffer);
        if ($tail !== '') {
            $runService->appendProgress($runId, [], $tail, TestRun::STATUS_RUNNING);
        }

        $exitCode = proc_close($process);
        return (int)$exitCode;
    }

    /**
     * @return array<string,string>
     */
    private function baseEnv(): array
    {
        $env = [];
        foreach ($_ENV as $key => $value) {
            if (is_string($key) && (is_string($value) || is_numeric($value))) {
                $env[$key] = (string)$value;
            }
        }
        foreach ($_SERVER as $key => $value) {
            if (is_string($key) && (is_string($value) || is_numeric($value)) && !isset($env[$key])) {
                $env[$key] = (string)$value;
            }
        }
        $path = getenv('PATH');
        if (is_string($path) && $path !== '') {
            $env['PATH'] = $path;
        }
        return $env;
    }

    /**
     * @param list<string> $files
     * @return list<string>
     */
    private function sanitizeE2eFiles(array $files): array
    {
        $out = [];
        foreach ($files as $file) {
            if (!is_string($file)) {
                continue;
            }
            $normalized = str_replace('\\', '/', trim($file));
            if ($normalized === '') {
                continue;
            }
            // 排除 WLS 隔离克隆目录，避免同一用例被跑多遍且命中过期代码。
            if (preg_match('#(^|/)var/#', $normalized) === 1 || str_contains($normalized, '/var/')) {
                continue;
            }
            if (str_starts_with($normalized, 'var/')) {
                continue;
            }
            $out[] = $normalized;
        }
        return array_values(array_unique($out));
    }

    private function canRunHeadedUi(): bool
    {
        // macOS / Windows 原生 GUI 不依赖 DISPLAY；Linux/X11 才需要。
        if (PHP_OS_FAMILY === 'Windows' || PHP_OS_FAMILY === 'Darwin') {
            return true;
        }
        $display = trim((string)(getenv('DISPLAY') ?: ($_ENV['DISPLAY'] ?? $_SERVER['DISPLAY'] ?? '')));
        return $display !== '';
    }

    private function resolvePlaywrightInstanceName(): string
    {
        foreach (['PLAYWRIGHT_INSTANCE_NAME', 'WLS_INSTANCE', 'WELINE_WLS_INSTANCE'] as $key) {
            $value = trim((string)(getenv($key) ?: ($_ENV[$key] ?? $_SERVER[$key] ?? '')));
            if ($value !== '' && $value !== 'default') {
                return $value;
            }
        }

        $instancesDir = BP . 'var' . DIRECTORY_SEPARATOR . 'server' . DIRECTORY_SEPARATOR . 'instances';
        if (!is_dir($instancesDir)) {
            return '';
        }

        $bestName = '';
        $bestMtime = 0;
        foreach ((array)glob($instancesDir . DIRECTORY_SEPARATOR . '*.json') as $path) {
            if (!is_string($path) || !is_file($path)) {
                continue;
            }
            $raw = @file_get_contents($path);
            $data = is_string($raw) ? json_decode($raw, true) : null;
            if (!is_array($data)) {
                continue;
            }
            $masterPid = (int)($data['master_pid'] ?? $data['pid'] ?? 0);
            if ($masterPid <= 0) {
                continue;
            }
            if (function_exists('posix_kill') && !@posix_kill($masterPid, 0)) {
                continue;
            }
            $mtime = (int)@filemtime($path);
            if ($mtime >= $bestMtime) {
                $bestMtime = $mtime;
                $bestName = basename($path, '.json');
            }
        }

        return $bestName;
    }

    private function resolveNodeBinary(): string
    {
        foreach (['NODE_BINARY', 'WELINE_NODE_BINARY'] as $key) {
            $configured = trim((string)getenv($key));
            if ($configured !== '' && is_file($configured)) {
                return $configured;
            }
        }

        $which = PHP_OS_FAMILY === 'Windows' ? 'where node' : 'command -v node';
        $resolved = trim((string)shell_exec($which));
        if ($resolved !== '') {
            $first = preg_split("/\r\n|\n|\r/", $resolved)[0] ?? '';
            if (is_string($first) && $first !== '' && (is_file($first) || PHP_OS_FAMILY === 'Windows')) {
                return $first;
            }
        }

        return 'node';
    }
}

<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Service;

use Weline\Framework\App\Env;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Setup\Lock\SetupDatabaseAccessLock;

/**
 * 发布门禁 / E2E 前置体检。
 *
 * 门禁失败最常见的根因不是用例断言，而是环境噪声：并发门禁、残留浏览器、
 * 后台缓存预热/AI 队列、主题布局冷编译把 WLS Worker 占满，最终表现为
 * `worker_timeout` 之类的假失败。本类在 Playwright 启动前把这些问题变成
 * 可读的体检结论，并在 blocker 存在时 fail-closed，避免跑 40 分钟才报错。
 */
final class E2eGatePreflight
{
    private const DEFAULT_MAX_LOAD_PER_CPU = 2.0;

    /** @var list<string> */
    private array $ok = [];

    /** @var list<string> */
    private array $warnings = [];

    /** @var list<string> */
    private array $blockers = [];

    /** @var array<string, mixed> */
    private array $facts = [];

    private ?E2eGateLock $lock = null;

    private ?SetupDatabaseAccessLock $databaseLease = null;

    /**
     * @param array{
     *   suite?: string,
     *   allow_busy?: bool,
     *   force_unlock?: bool,
     *   skip_browser_cleanup?: bool
     * } $options
     */
    public function run(array $options = []): bool
    {
        $suite = (string)($options['suite'] ?? '');
        $this->ok = [];
        $this->warnings = [];
        $this->blockers = [];
        $this->facts = ['suite' => $suite];

        $this->acquireLease($suite, (bool)($options['force_unlock'] ?? false));
        if ($this->blockers !== []) {
            return false;
        }

        $this->checkUpgradeGate();
        if ($this->blockers !== []) {
            return false;
        }

        $this->cleanupOrphanBrowsers((bool)($options['skip_browser_cleanup'] ?? false));
        $this->checkConcurrentRunners();
        $this->checkLoad((bool)($options['allow_busy'] ?? false));
        $this->checkRuntimeHealth();

        return $this->blockers === [];
    }

    /**
     * 释放门禁租约与数据库共享租约（失败路径必须调用，避免把下一次运行挡在门外）。
     */
    public function release(): void
    {
        $this->databaseLease?->release();
        $this->databaseLease = null;
        $this->lock?->release();
        $this->lock = null;
    }

    public function lock(): ?E2eGateLock
    {
        return $this->lock;
    }

    /**
     * @return list<string>
     */
    public function blockers(): array
    {
        return $this->blockers;
    }

    /**
     * @return list<string>
     */
    public function warnings(): array
    {
        return $this->warnings;
    }

    /**
     * @return array<string, mixed>
     */
    public function facts(): array
    {
        return $this->facts;
    }

    public function render(): string
    {
        $lines = ['[e2e gate] 前置体检'];
        foreach ($this->ok as $line) {
            $lines[] = '  ✓ ' . $line;
        }
        foreach ($this->warnings as $line) {
            $lines[] = '  ⚠ ' . $line;
        }
        foreach ($this->blockers as $line) {
            $lines[] = '  ✗ ' . $line;
        }
        $lines[] = $this->blockers === []
            ? '  → 体检通过，启动 Playwright。'
            : '  → 体检未通过，门禁未启动（先处理上面 ✗ 项）。';

        return \implode(\PHP_EOL, $lines);
    }

    private function acquireLease(string $suite, bool $forceUnlock): void
    {
        if ($forceUnlock) {
            $this->forceUnlock();
        }

        try {
            $this->lock = E2eGateLock::acquire(['suite' => $suite !== '' ? $suite : 'e2e:run']);
            $pid = (int)($this->lock->meta()['pid'] ?? 0);
            $this->ok[] = '单实例租约已取得（pid=' . $pid . '）';
            $this->facts['lock_pid'] = $pid;
        } catch (\Throwable $throwable) {
            $this->blockers[] = $throwable->getMessage();
        }
    }

    private function forceUnlock(): void
    {
        $holder = E2eGateLock::holder();
        if ($holder === null) {
            E2eGateLock::clearStaleMeta();
            $this->warnings[] = '--force-unlock：未发现门禁持有者元数据。';
            return;
        }

        $pid = (int)($holder['pid'] ?? 0);
        $command = (string)($holder['command'] ?? '');
        if ($pid > 0 && E2eGateLock::isProcessAlive($pid)) {
            if (!\str_contains($command, 'e2e:run')) {
                $this->blockers[] = '--force-unlock 拒绝：锁持有者 pid=' . $pid . ' 不是 e2e:run（command=' . $command . '）。';
                return;
            }
            $this->warnings[] = '--force-unlock：终止残留门禁进程 pid=' . $pid . '。';
            @\posix_kill($pid, \SIGTERM);
            for ($i = 0; $i < 50 && E2eGateLock::isProcessAlive($pid); $i++) {
                \usleep(100_000);
            }
            if (E2eGateLock::isProcessAlive($pid) && \function_exists('posix_kill')) {
                @\posix_kill($pid, \SIGKILL);
            }
        }
        E2eGateLock::clearStaleMeta();
    }

    private function cleanupOrphanBrowsers(bool $skip): void
    {
        if ($skip) {
            $this->warnings[] = '浏览器残留清理已跳过（--skip-browser-cleanup）。';
            return;
        }
        try {
            /** @var E2eBrowserCleanupService $cleanup */
            $cleanup = ObjectManager::getInstance(E2eBrowserCleanupService::class);
            $report = $cleanup->cleanup(['dry_run' => false]);
            $killed = \count((array)($report['killedPids'] ?? []));
            $removed = \count((array)($report['removedPaths'] ?? []));
            $this->ok[] = '浏览器残留清理：killed=' . $killed . ' removed=' . $removed;
            $this->facts['browser_killed'] = $killed;
            $this->facts['browser_removed'] = $removed;
        } catch (\Throwable $throwable) {
            $this->warnings[] = '浏览器残留清理失败：' . $throwable->getMessage();
        }
    }

    private function checkUpgradeGate(): void
    {
        // setup:upgrade 会开维护模式并持有数据库独占租约；门禁若在窗内开跑，
        // 店面只会返回维护页，所有通路都会假失败。
        $lease = new SetupDatabaseAccessLock();
        if (!$lease->acquireShared()) {
            $this->blockers[] = '系统升级（setup:upgrade）正在执行（数据库独占租约被占用）。'
                . ' 门禁会在维护页上跑出全红；等升级收尾后再跑。';
            return;
        }
        $this->databaseLease = $lease;
        $this->ok[] = '已取得数据库共享租约（门禁期间 setup:upgrade 会等待）';

        $maintenance = (bool)Env::system('maintenance');
        $this->facts['maintenance'] = $maintenance;
        if ($maintenance) {
            $this->blockers[] = '站点处于维护模式（app/etc/env.php system.maintenance=true）。'
                . ' 多半是升级/主题发布没走到 maintenance:disable；先收尾再跑门禁。';
            return;
        }
    }

    private function checkConcurrentRunners(): void
    {
        $self = (int)(\getmypid() ?: 0);
        $ancestors = $this->ancestorPids($self);
        $output = [];
        @\exec('ps -axo pid=,command= 2>/dev/null', $output);
        $others = [];
        foreach ($output as $line) {
            $line = \trim($line);
            if ($line === '' || \preg_match('/^(\d+)\s+(.*)$/', $line, $m) !== 1) {
                continue;
            }
            $pid = (int)$m[1];
            $command = (string)$m[2];
            if ($pid === $self || isset($ancestors[$pid])) {
                continue;
            }
            if (\str_contains($command, 'ps -axo')) {
                continue;
            }
            if (!\preg_match('~(bin/w\s+e2e:run|playwright/cli\.js\s+test)~', $command)) {
                continue;
            }
            $others[$pid] = $command;
        }

        if ($others === []) {
            $this->ok[] = '未发现其它 E2E 运行进程';
            return;
        }

        $this->warnings[] = '发现 ' . \count($others) . ' 个其它 E2E 进程（可能是残留或另一会话）：'
            . \implode('; ', \array_map(
                static fn(int $pid, string $command): string => $pid . '=' . \substr($command, 0, 80),
                \array_keys($others),
                \array_values($others),
            ));
        $this->facts['other_runners'] = \array_keys($others);
    }

    /**
     * @return array<int, true>
     */
    private function ancestorPids(int $pid): array
    {
        $ancestors = [];
        $current = $pid;
        for ($depth = 0; $depth < 20 && $current > 1; $depth++) {
            $output = [];
            @\exec('ps -o ppid= -p ' . $current . ' 2>/dev/null', $output);
            $parent = (int)\trim((string)($output[0] ?? '0'));
            if ($parent <= 1 || isset($ancestors[$parent])) {
                break;
            }
            $ancestors[$parent] = true;
            $current = $parent;
        }

        return $ancestors;
    }

    private function checkLoad(bool $allowBusy): void
    {
        $load = $this->loadAverage();
        $cpus = $this->cpuCount();
        if ($load === null || $cpus < 1) {
            $this->warnings[] = '无法读取本机负载，跳过负载检查。';
            return;
        }

        $perCpu = $load / $cpus;
        $threshold = (float)(\getenv('WELINE_E2E_MAX_LOAD_PER_CPU') ?: self::DEFAULT_MAX_LOAD_PER_CPU);
        if ($threshold <= 0) {
            $threshold = self::DEFAULT_MAX_LOAD_PER_CPU;
        }
        $message = \sprintf('本机负载 %.2f / %d 核 = %.2f 每核（阈值 %.2f）', $load, $cpus, $perCpu, $threshold);
        $this->facts['load'] = $load;
        $this->facts['load_per_cpu'] = $perCpu;

        if ($perCpu <= $threshold) {
            $this->ok[] = $message;
            return;
        }

        if ($allowBusy) {
            $this->warnings[] = $message . '；--allow-busy 已显式放行，结果可能受负载噪声影响。';
            return;
        }

        $this->blockers[] = $message . '。高负载下 WLS Worker 排队会让用例出现 worker_timeout 假失败；'
            . '请先停掉缓存预热/AI 队列/并发门禁，或显式加 --allow-busy 承担噪声风险。';
    }

    private function checkRuntimeHealth(): void
    {
        $script = BP . 'tests' . \DIRECTORY_SEPARATOR . 'e2e' . \DIRECTORY_SEPARATOR
            . 'framework' . \DIRECTORY_SEPARATOR . 'runtime-info.php';
        if (!\is_file($script)) {
            $this->warnings[] = '未找到 tests/e2e/framework/runtime-info.php，跳过站点探活。';
            return;
        }

        $output = [];
        $exitCode = 1;
        @\exec(\escapeshellarg(\PHP_BINARY) . ' ' . \escapeshellarg($script) . ' 2>/dev/null', $output, $exitCode);
        $raw = \trim(\implode("\n", $output));
        $start = \strpos($raw, '{');
        $end = \strrpos($raw, '}');
        if ($start === false || $end === false || $end < $start) {
            $this->blockers[] = '站点探活失败：runtime-info.php 未返回 JSON（exit=' . $exitCode . '）。';
            return;
        }

        $info = \json_decode(\substr($raw, $start, $end - $start + 1), true);
        if (!\is_array($info)) {
            $this->blockers[] = '站点探活失败：runtime-info.php 返回的 JSON 无法解析。';
            return;
        }

        $runtime = (array)($info['runtime'] ?? []);
        $origin = (string)($runtime['target_origin'] ?? '');
        $reachable = (bool)($runtime['reachable'] ?? false);
        $this->facts['target_origin'] = $origin;
        if ($origin === '' || !$reachable) {
            $this->blockers[] = '目标站点不可达：' . ($origin !== '' ? $origin : '(unknown)')
                . '。先启动 WLS（php bin/w server:start）再跑门禁。';
            return;
        }
        $this->ok[] = '目标站点可达：' . $origin;

        $themes = (array)($info['themes'] ?? []);
        $source = (string)($themes['source'] ?? '');
        $this->facts['themes_source'] = $source;
        if ($source === 'database_error') {
            $this->blockers[] = '主题探针数据库错误（schema 漂移）：' . (string)($themes['error'] ?? 'unknown');
            return;
        }
        $frontend = $themes['active']['frontend'] ?? null;
        if (\is_array($frontend) && (int)($frontend['id'] ?? 0) > 0) {
            $this->ok[] = '主题探针：' . $source . '，前台主题 id=' . (int)$frontend['id'];
        } else {
            $this->warnings[] = '主题探针未解析出前台主题（source=' . $source . '），依赖主题预览的用例可能失败。';
        }
    }

    private function loadAverage(): ?float
    {
        if (\function_exists('sys_getloadavg')) {
            $loads = \sys_getloadavg();
            if (\is_array($loads) && isset($loads[0]) && \is_numeric($loads[0])) {
                return (float)$loads[0];
            }
        }

        $output = [];
        @\exec('uptime 2>/dev/null', $output);
        $line = (string)($output[0] ?? '');
        if (\preg_match('/load averages?:\s*([0-9.]+)/i', $line, $m) === 1) {
            return (float)$m[1];
        }

        return null;
    }

    private function cpuCount(): int
    {
        $env = Env::getInstance();
        $configured = (int)($env->getConfig('wls.worker_count') ?? 0);
        $detected = 0;
        if (\is_file('/proc/cpuinfo')) {
            $detected = \substr_count((string)@\file_get_contents('/proc/cpuinfo'), 'processor');
        }
        if ($detected < 1) {
            $output = [];
            @\exec('sysctl -n hw.ncpu 2>/dev/null', $output);
            $detected = (int)(\trim((string)($output[0] ?? '0')));
        }
        if ($detected < 1) {
            $detected = $configured > 0 ? $configured : 1;
        }

        return \max(1, $detected);
    }
}

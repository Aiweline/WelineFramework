<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Service;

/**
 * 跨进程的 E2E / 发布门禁独占租约。
 *
 * 目的（架构级）：
 * - 同一仓库同时只允许一个 E2E 运行。并发跑门禁会互相抢 WLS Worker、FPC 构建锁、
 *   主题布局编译锁，最终把功能用例变成 worker_timeout 假失败。
 * - Cron / Queue 在门禁期间主动让路（见 {@see \Weline\Cron\Console\Cron\Task\Run}
 *   与 {@see \Weline\Queue\Console\Queue\Run}），避免缓存预热、AI 翻译重试等后台
 *   任务在门禁期间制造负载噪声。
 *
 * 租约真相只看 flock，不看文件是否存在：进程崩溃后内核自动释放。
 */
final class E2eGateLock
{
    public const LOCK_FILENAME = 'e2e_release_gate.lock';
    public const META_FILENAME = 'e2e_release_gate.json';

    private const META_MAX_BYTES = 8192;

    /** @var resource|null */
    private $handle = null;

    private bool $held = false;

    private static ?self $current = null;

    /**
     * @param array<string, mixed> $meta
     */
    private function __construct(private array $meta = [])
    {
    }

    public static function directory(): string
    {
        $directory = \VAR_DIR . 'process' . DIRECTORY_SEPARATOR;
        if (!\is_dir($directory) && !@\mkdir($directory, 0775, true) && !\is_dir($directory)) {
            throw new \RuntimeException('e2e_gate_lock_directory_failed: ' . $directory);
        }

        return $directory;
    }

    public static function lockPath(): string
    {
        return self::directory() . self::LOCK_FILENAME;
    }

    public static function metaPath(): string
    {
        return self::directory() . self::META_FILENAME;
    }

    /**
     * 取得门禁独占租约；已被占用时抛出带持有者信息的异常。
     *
     * @param array<string, mixed> $meta
     */
    public static function acquire(array $meta = []): self
    {
        if (self::$current instanceof self && self::$current->held) {
            return self::$current;
        }

        $lockPath = self::lockPath();
        $handle = @\fopen($lockPath, 'c+b');
        if (!\is_resource($handle)) {
            throw new \RuntimeException('e2e_gate_lock_open_failed: ' . $lockPath);
        }

        if (!\flock($handle, \LOCK_EX | \LOCK_NB)) {
            \fclose($handle);
            throw new \RuntimeException(self::buildBusyMessage(self::holder()));
        }

        $meta = \array_merge([
            'pid' => (int)(\getmypid() ?: 0),
            'started_at' => \date('c'),
            'command' => \implode(' ', (array)($_SERVER['argv'] ?? [])),
            'cwd' => (string)\getcwd(),
        ], $meta);

        $lease = new self($meta);
        $lease->handle = $handle;
        $lease->held = true;
        self::$current = $lease;
        $lease->writeMeta();

        \register_shutdown_function(static function () use ($lease): void {
            $lease->release();
        });

        return $lease;
    }

    /**
     * 探测门禁是否正在运行（非破坏性）。
     */
    public static function isHeld(): bool
    {
        if (self::$current instanceof self && self::$current->held) {
            return true;
        }

        $lockPath = self::lockPath();
        if (!\is_file($lockPath)) {
            return false;
        }

        $handle = @\fopen($lockPath, 'c+b');
        if (!\is_resource($handle)) {
            // 打不开锁文件时按「可能占用」处理，后台任务宁可让路。
            return true;
        }

        $acquired = \flock($handle, \LOCK_SH | \LOCK_NB);
        if ($acquired) {
            \flock($handle, \LOCK_UN);
        }
        \fclose($handle);

        return !$acquired;
    }

    /**
     * 读取当前持有者元数据；无持有者或元数据损坏时返回 null。
     *
     * @return array<string, mixed>|null
     */
    public static function holder(): ?array
    {
        $path = self::metaPath();
        if (!\is_file($path)) {
            return null;
        }

        $raw = @\file_get_contents($path, false, null, 0, self::META_MAX_BYTES);
        if (!\is_string($raw) || \trim($raw) === '') {
            return null;
        }

        $decoded = \json_decode($raw, true);
        return \is_array($decoded) ? $decoded : null;
    }

    public function release(): void
    {
        if (!$this->held) {
            return;
        }
        $this->held = false;
        if (\is_resource($this->handle)) {
            @\flock($this->handle, \LOCK_UN);
            @\fclose($this->handle);
        }
        $this->handle = null;
        if (self::$current === $this) {
            self::$current = null;
        }
        $metaPath = self::metaPath();
        if (\is_file($metaPath)) {
            @\unlink($metaPath);
        }
    }

    /**
     * 门禁运行期间刷新心跳（长套件可观测）。
     *
     * @param array<string, mixed> $extra
     */
    public function heartbeat(array $extra = []): void
    {
        if (!$this->held) {
            return;
        }
        $this->meta = \array_merge($this->meta, $extra, ['heartbeat_at' => \date('c')]);
        $this->writeMeta();
    }

    /**
     * @return array<string, mixed>
     */
    public function meta(): array
    {
        return $this->meta;
    }

    private function writeMeta(): void
    {
        $payload = \json_encode($this->meta, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES);
        if (!\is_string($payload)) {
            return;
        }
        $path = self::metaPath();
        $tmp = $path . '.' . \getmypid() . '.tmp';
        if (@\file_put_contents($tmp, $payload) !== false) {
            @\rename($tmp, $path);
        }
    }

    /**
     * @param array<string, mixed>|null $holder
     */
    private static function buildBusyMessage(?array $holder): string
    {
        if ($holder === null) {
            return 'e2e_gate_busy: 另一个 E2E/发布门禁正在运行（无法读取持有者信息）。'
                . ' 请等待其结束；确认是残留进程时用 --force-unlock 或 kill 对应 PID。';
        }

        $pid = (int)($holder['pid'] ?? 0);
        $alive = $pid > 0 && self::isProcessAlive($pid);
        $parts = [
            'e2e_gate_busy: 另一个 E2E/发布门禁正在运行。',
            'pid=' . $pid . ($alive ? '（存活）' : '（已不存在，锁可能残留）'),
            'started_at=' . (string)($holder['started_at'] ?? '?'),
            'suite=' . (string)($holder['suite'] ?? '?'),
        ];
        $command = \trim((string)($holder['command'] ?? ''));
        if ($command !== '') {
            $parts[] = 'command=' . $command;
        }
        $parts[] = '并发跑门禁会互相抢 Worker/构建锁，请等它结束，或用 --force-unlock 清理残留。';

        return \implode(' ', $parts);
    }

    public static function isProcessAlive(int $pid): bool
    {
        if ($pid <= 0) {
            return false;
        }
        if (\function_exists('posix_kill')) {
            return @\posix_kill($pid, 0);
        }
        if (\is_dir('/proc')) {
            return \is_dir('/proc/' . $pid);
        }
        $output = [];
        @\exec('ps -p ' . $pid . ' -o pid=', $output);
        return \trim((string)($output[0] ?? '')) !== '';
    }

    /**
     * 清理已死进程留下的元数据（锁本身由 flock 判定，不需要删）。
     */
    public static function clearStaleMeta(): void
    {
        $holder = self::holder();
        if ($holder === null) {
            return;
        }
        $pid = (int)($holder['pid'] ?? 0);
        if ($pid > 0 && self::isProcessAlive($pid)) {
            return;
        }
        $metaPath = self::metaPath();
        if (\is_file($metaPath)) {
            @\unlink($metaPath);
        }
    }
}

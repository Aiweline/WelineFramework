<?php

declare(strict_types=1);

namespace Weline\Maintenance\Helper;

/**
 * 维护模式的「谁开的、开了多久」现场标记。
 *
 * `system.maintenance` 只有布尔值，进程被 kill / 会话中断时无法判断维护是
 * 正在升级还是已经残留。这里在开启时写 var/maintenance/session.json，
 * 关闭时删除；`maintenance:status` 用它判断持有进程是否还活着。
 */
final class MaintenanceSessionMarker
{
    public const RELATIVE_PATH = 'var/maintenance/session.json';

    public function __construct(private readonly ?string $baseDir = null)
    {
    }

    public function path(): string
    {
        $base = $this->baseDir ?? (\defined('BP') ? (string)BP : (string)\getcwd() . DIRECTORY_SEPARATOR);

        return \rtrim($base, "/\\") . \DIRECTORY_SEPARATOR
            . \str_replace('/', \DIRECTORY_SEPARATOR, self::RELATIVE_PATH);
    }

    public function start(string $action): void
    {
        $path = $this->path();
        $dir = \dirname($path);
        if (!\is_dir($dir) && !@\mkdir($dir, 0775, true) && !\is_dir($dir)) {
            return;
        }

        $payload = [
            'pid' => (int)(\getmypid() ?: 0),
            'action' => $action,
            'started_at' => \time(),
            'started_at_iso' => \date('c'),
            'command' => \implode(' ', (array)($_SERVER['argv'] ?? [])),
            'cwd' => (string)\getcwd(),
        ];
        @\file_put_contents(
            $path,
            \json_encode($payload, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES),
            \LOCK_EX,
        );
    }

    public function clear(): void
    {
        $path = $this->path();
        if (\is_file($path)) {
            @\unlink($path);
        }
    }

    /**
     * @return array{pid:int, action:string, started_at:int, started_at_iso:string, command:string, cwd:string}|null
     */
    public function read(): ?array
    {
        $path = $this->path();
        if (!\is_file($path)) {
            return null;
        }

        $raw = @\file_get_contents($path);
        if (!\is_string($raw) || \trim($raw) === '') {
            return null;
        }

        $decoded = \json_decode($raw, true);
        if (!\is_array($decoded)) {
            return null;
        }

        return [
            'pid' => (int)($decoded['pid'] ?? 0),
            'action' => (string)($decoded['action'] ?? ''),
            'started_at' => (int)($decoded['started_at'] ?? 0),
            'started_at_iso' => (string)($decoded['started_at_iso'] ?? ''),
            'command' => (string)($decoded['command'] ?? ''),
            'cwd' => (string)($decoded['cwd'] ?? ''),
        ];
    }

    public function ageSeconds(): ?int
    {
        $marker = $this->read();
        if ($marker === null || $marker['started_at'] < 1) {
            return null;
        }

        return \max(0, \time() - $marker['started_at']);
    }

    public function isOwnerAlive(): bool
    {
        $marker = $this->read();
        if ($marker === null) {
            return false;
        }

        return self::pidAlive($marker['pid']);
    }

    public static function pidAlive(int $pid): bool
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
}

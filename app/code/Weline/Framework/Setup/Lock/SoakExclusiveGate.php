<?php

declare(strict_types=1);

namespace Weline\Framework\Setup\Lock;

use Weline\Framework\App\Env;
use Weline\Framework\App\Exception;

/**
 * Exclusive lease for WLS soak / long stability windows.
 *
 * While the lock file is fresh (mtime within stale_after_sec), setup:upgrade and
 * maintenance:enable must refuse to flip system.maintenance — concurrent upgrades
 * otherwise force storefront 503「网站维护」and fail TOTAL_ERRORS=0 soaks.
 *
 * Heartbeat by touching the lock file; remove on soak end.
 */
final class SoakExclusiveGate
{
    public const LOCK_FILENAME = 'wls-soak-exclusive.lock';
    public const DEFAULT_STALE_AFTER_SEC = 30;

    public static function lockPath(): string
    {
        return Env::VAR_DIR . 'run' . DIRECTORY_SEPARATOR . self::LOCK_FILENAME;
    }

    /**
     * @return array{active:bool,path:string,age_sec:?float,payload:?array<string,mixed>,reason:string}
     */
    public static function inspect(int $staleAfterSec = self::DEFAULT_STALE_AFTER_SEC): array
    {
        $path = self::lockPath();
        if (!is_file($path)) {
            return [
                'active' => false,
                'path' => $path,
                'age_sec' => null,
                'payload' => null,
                'reason' => 'missing',
            ];
        }

        $mtime = @filemtime($path);
        if ($mtime === false) {
            return [
                'active' => false,
                'path' => $path,
                'age_sec' => null,
                'payload' => null,
                'reason' => 'unreadable_mtime',
            ];
        }

        $age = microtime(true) - (float)$mtime;
        $raw = @file_get_contents($path);
        $payload = null;
        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $payload = $decoded;
            }
        }

        if ($age > (float)max(1, $staleAfterSec)) {
            return [
                'active' => false,
                'path' => $path,
                'age_sec' => $age,
                'payload' => $payload,
                'reason' => 'stale',
            ];
        }

        return [
            'active' => true,
            'path' => $path,
            'age_sec' => $age,
            'payload' => $payload,
            'reason' => 'fresh',
        ];
    }

    public static function isActive(int $staleAfterSec = self::DEFAULT_STALE_AFTER_SEC): bool
    {
        return self::inspect($staleAfterSec)['active'] === true;
    }

    /**
     * @throws Exception
     */
    public static function assertClearForMaintenanceFlip(string $action): void
    {
        $state = self::inspect();
        if ($state['active'] !== true) {
            return;
        }

        $payload = $state['payload'] ?? [];
        $out = is_array($payload) ? (string)($payload['out'] ?? '') : '';
        $owner = is_array($payload) ? (string)($payload['owner'] ?? '') : '';
        $detail = trim($owner . ($out !== '' ? ' out=' . $out : ''));
        throw new Exception(__(
            '拒绝%{1}：WLS soak 排他锁生效（%{2}，age=%{3}s）。请等待 soak 结束或删除 %{4} 后再开维护/升级。',
            [
                $action,
                $detail !== '' ? $detail : 'active',
                number_format((float)($state['age_sec'] ?? 0), 1, '.', ''),
                $state['path'],
            ]
        ));
    }

    /**
     * @param array<string,mixed> $meta
     */
    public static function acquire(array $meta = []): string
    {
        $path = self::lockPath();
        $dir = dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException('Cannot create soak lock directory: ' . $dir);
        }

        $payload = array_merge([
            'schema' => 'wls-soak-exclusive.v1',
            'pid' => (int)(getmypid() ?: 0),
            'started_utc' => gmdate('c'),
            'stale_after_sec' => self::DEFAULT_STALE_AFTER_SEC,
        ], $meta);

        $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            throw new \RuntimeException('Cannot encode soak lock payload');
        }
        if (@file_put_contents($path, $json . "\n", LOCK_EX) === false) {
            throw new \RuntimeException('Cannot write soak lock: ' . $path);
        }
        @touch($path);

        return $path;
    }

    public static function heartbeat(): bool
    {
        $path = self::lockPath();
        if (!is_file($path)) {
            return false;
        }

        return @touch($path);
    }

    public static function release(): bool
    {
        $path = self::lockPath();
        if (!is_file($path)) {
            return true;
        }

        return @unlink($path);
    }
}

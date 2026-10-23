<?php

declare(strict_types=1);

namespace Weline\Framework\App;

/**
 * 系统更新进行中标记。
 *
 * setup:upgrade 写 setup_upgrade.lock，core:update 写 core_update.lock。
 * 后台索引只在这些锁和维护旗都不在时才允许开工。
 */
final class SystemUpdateActivity
{
    /** @var array<string, resource> */
    private static array $handles = [];

    public static function inProgress(): bool
    {
        if (self::maintenanceOn()) {
            return true;
        }
        foreach (['setup_upgrade.lock', 'core_update.lock'] as $name) {
            if (self::lockHeld($name)) {
                return true;
            }
        }

        return false;
    }

    public static function acquire(string $command): bool
    {
        $path = self::lockPath(self::fileName($command));
        if ($path === '') {
            return false;
        }
        $dir = dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            return false;
        }
        $handle = @fopen($path, 'c+');
        if ($handle === false) {
            return false;
        }
        if (!flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);
            return false;
        }
        ftruncate($handle, 0);
        fwrite($handle, (string)json_encode([
            'pid' => getmypid(),
            'time' => date('Y-m-d H:i:s'),
            'command' => $command,
        ], JSON_UNESCAPED_UNICODE));
        fflush($handle);
        self::$handles[$command] = $handle;

        return true;
    }

    public static function release(string $command): void
    {
        $handle = self::$handles[$command] ?? null;
        if (is_resource($handle)) {
            flock($handle, LOCK_UN);
            fclose($handle);
            unset(self::$handles[$command]);
        }
        $path = self::lockPath(self::fileName($command));
        if ($path === '' || !is_file($path)) {
            return;
        }
        $info = self::read($path);
        $pid = (int)($info['pid'] ?? 0);
        if ($pid === 0 || $pid === (int)getmypid()) {
            @unlink($path);
        }
    }

    private static function fileName(string $command): string
    {
        return $command === 'setup:upgrade' ? 'setup_upgrade.lock' : 'core_update.lock';
    }

    private static function lockHeld(string $fileName): bool
    {
        $path = self::lockPath($fileName);
        if ($path === '' || !is_file($path)) {
            return false;
        }
        $info = self::read($path);
        $pid = (int)($info['pid'] ?? 0);
        if ($pid > 0) {
            return self::processAlive($pid);
        }

        $mtime = @filemtime($path);

        return $mtime !== false && (time() - $mtime) < 6 * 3600;
    }

    private static function maintenanceOn(): bool
    {
        $root = '';
        if (defined('BP')) {
            $root = rtrim((string)BP, '/\\');
        }
        if ($root === '') {
            return false;
        }

        return is_file($root . DIRECTORY_SEPARATOR . 'var' . DIRECTORY_SEPARATOR . 'maintenance' . DIRECTORY_SEPARATOR . 'edge.on');
    }

    private static function lockPath(string $fileName): string
    {
        $dir = self::processDir();
        if ($dir === '') {
            return '';
        }

        return $dir . DIRECTORY_SEPARATOR . $fileName;
    }

    private static function processDir(): string
    {
        if (defined('VAR_DIR')) {
            return rtrim((string)VAR_DIR, '/\\') . DIRECTORY_SEPARATOR . 'process';
        }
        if (defined('BP')) {
            return rtrim((string)BP, '/\\') . DIRECTORY_SEPARATOR . 'var' . DIRECTORY_SEPARATOR . 'process';
        }

        return '';
    }

    /** @return array<string, mixed> */
    private static function read(string $path): array
    {
        $content = @file_get_contents($path);
        if (!is_string($content) || trim($content) === '') {
            return [];
        }
        $info = json_decode($content, true);

        return is_array($info) ? $info : [];
    }

    private static function processAlive(int $pid): bool
    {
        if ($pid < 1) {
            return false;
        }
        if (!function_exists('posix_kill')) {
            return true;
        }
        if (@posix_kill($pid, 0)) {
            return true;
        }
        $errno = function_exists('posix_get_last_error') ? posix_get_last_error() : 0;

        return $errno === 1;
    }
}

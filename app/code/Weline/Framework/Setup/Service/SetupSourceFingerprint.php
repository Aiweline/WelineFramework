<?php

declare(strict_types=1);

namespace Weline\Framework\Setup\Service;

/**
 * setup:upgrade 源码树稳定指纹仓（相对 path + size + mtime → sha256）。
 *
 * 持久化：generated/setup/source_fingerprints.php
 */
final class SetupSourceFingerprint
{
    public const STORE_RELATIVE = 'generated' . DIRECTORY_SEPARATOR . 'setup' . DIRECTORY_SEPARATOR . 'source_fingerprints.php';

    /** @var array<string, string>|null */
    private static ?array $memoryStore = null;

    public function storePath(): string
    {
        return \rtrim((string)BP, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . self::STORE_RELATIVE;
    }

    /**
     * 目录树稳定指纹：相对路径 + size + 内容 sha256（不用裸 mtime，避免触碰时间戳误失效）。
     * 目录不存在或不可读时返回固定空树指纹。
     */
    public function fingerprintTree(string $absDir): string
    {
        $absDir = \rtrim(\str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $absDir), DIRECTORY_SEPARATOR);
        $entries = [];
        if ($absDir !== '' && \is_dir($absDir)) {
            try {
                $iterator = new \RecursiveIteratorIterator(
                    new \RecursiveDirectoryIterator(
                        $absDir,
                        \FilesystemIterator::SKIP_DOTS | \FilesystemIterator::CURRENT_AS_FILEINFO
                    ),
                    \RecursiveIteratorIterator::SELF_FIRST
                );
                foreach ($iterator as $info) {
                    if (!$info instanceof \SplFileInfo || !$info->isFile()) {
                        continue;
                    }
                    $full = $info->getPathname();
                    $rel = \ltrim(\str_replace('\\', '/', \substr($full, \strlen($absDir))), '/');
                    if ($rel === '') {
                        continue;
                    }
                    $size = (int)$info->getSize();
                    $content = @\file_get_contents($full);
                    $digest = \is_string($content) ? \hash('sha256', $content) : 'unreadable';
                    $entries[] = $rel . '|' . $size . '|' . $digest;
                }
            } catch (\Throwable) {
                $entries = [];
            }
        }
        \sort($entries, \SORT_STRING);

        return \hash('sha256', \implode("\n", $entries));
    }

    /**
     * 单文件稳定指纹（basename + size + 内容 sha256；不用裸 mtime）。
     */
    public function fingerprintFile(string $absFile): string
    {
        $absFile = \str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $absFile);
        if ($absFile === '' || !\is_file($absFile)) {
            return \hash('sha256', 'missing');
        }
        $size = (int)@\filesize($absFile);
        $base = \basename($absFile);
        $content = @\file_get_contents($absFile);
        $digest = \is_string($content) ? \hash('sha256', $content) : 'unreadable';

        return \hash('sha256', $base . '|' . $size . '|' . $digest);
    }

    /**
     * @return array<string, string>
     */
    public function loadStore(): array
    {
        $path = $this->storePath();
        // 磁盘仓被外部删除时，禁止继续命中进程内旧指纹（否则「删了指纹仍跳过」）。
        if (!\is_file($path)) {
            self::$memoryStore = [];

            return [];
        }
        if (self::$memoryStore !== null) {
            return self::$memoryStore;
        }
        try {
            if (\function_exists('opcache_invalidate')) {
                @\opcache_invalidate($path, true);
            }
            $data = require $path;
            self::$memoryStore = \is_array($data)
                ? \array_map(static fn(mixed $v): string => (string)$v, $data)
                : [];
        } catch (\Throwable) {
            self::$memoryStore = [];
        }

        return self::$memoryStore;
    }

    /**
     * 删除指定前缀的指纹键（如 menu:），并写回磁盘。
     *
     * @return int 删除的键数量
     */
    public function forgetByPrefix(string $prefix): int
    {
        $prefix = \trim($prefix);
        if ($prefix === '') {
            return 0;
        }
        $this->loadStore();
        if (self::$memoryStore === null || self::$memoryStore === []) {
            return 0;
        }
        $removed = 0;
        foreach (\array_keys(self::$memoryStore) as $key) {
            if (\str_starts_with((string)$key, $prefix)) {
                unset(self::$memoryStore[$key]);
                $removed++;
            }
        }
        if ($removed > 0) {
            $this->writeMemoryToDisk();
        }

        return $removed;
    }

    /**
     * 全量替换指纹仓（测试/显式重建）。热路径请用 mergeUpdates，避免 COW 快照互踩。
     *
     * @param array<string, string> $store
     */
    public function saveStore(array $store): void
    {
        $normalized = [];
        foreach ($store as $key => $value) {
            $k = (string)$key;
            $v = (string)$value;
            if ($k === '' || $v === '') {
                continue;
            }
            $normalized[$k] = $v;
        }
        \ksort($normalized, \SORT_STRING);
        self::$memoryStore = $normalized;
        $this->writeMemoryToDisk();
    }

    /**
     * 合并写入：只更新给定键，原地改 memoryStore，避免 Fiber/并行 save 丢失其它键。
     *
     * @param array<string, string|null> $updates
     */
    public function mergeUpdates(array $updates): void
    {
        if ($updates === []) {
            return;
        }
        $this->loadStore();
        if (self::$memoryStore === null) {
            self::$memoryStore = [];
        }
        foreach ($updates as $key => $value) {
            $k = \trim((string)$key);
            if ($k === '') {
                continue;
            }
            if ($value === null || $value === '') {
                unset(self::$memoryStore[$k]);
            } else {
                self::$memoryStore[$k] = (string)$value;
            }
        }
        $this->writeMemoryToDisk();
    }

    private function writeMemoryToDisk(): void
    {
        if (self::$memoryStore === null) {
            return;
        }
        $normalized = self::$memoryStore;
        \ksort($normalized, \SORT_STRING);
        self::$memoryStore = $normalized;

        $path = $this->storePath();
        $dir = \dirname($path);
        if (!\is_dir($dir) && !@\mkdir($dir, 0755, true) && !\is_dir($dir)) {
            return;
        }

        $export = \var_export($normalized, true);
        $contents = "<?php\n\ndeclare(strict_types=1);\n\n// Generated by SetupSourceFingerprint — do not edit.\nreturn {$export};\n";
        $tmp = $path . '.tmp.' . \getmypid() . '.' . \bin2hex(\random_bytes(4));
        if (@\file_put_contents($tmp, $contents) === false) {
            return;
        }
        if (!@\rename($tmp, $path)) {
            @\copy($tmp, $path);
            @\unlink($tmp);
        }
        if (\function_exists('opcache_invalidate')) {
            @\opcache_invalidate($path, true);
        }
    }

    public function get(string $key): ?string
    {
        $key = \trim($key);
        if ($key === '') {
            return null;
        }
        $store = $this->loadStore();
        $value = $store[$key] ?? null;

        return $value !== null && $value !== '' ? (string)$value : null;
    }

    public function set(string $key, ?string $value): void
    {
        $key = \trim($key);
        if ($key === '') {
            return;
        }
        $this->mergeUpdates([$key => $value]);
    }

    /**
     * 命中：store 中已有相同指纹。
     */
    public function matches(string $key, string $fingerprint): bool
    {
        $stored = $this->get($key);
        if ($stored === null || $fingerprint === '') {
            return false;
        }

        return \hash_equals($stored, $fingerprint);
    }

    /**
     * 测试/隔离：清空进程内缓存。
     */
    public static function resetMemoryStore(): void
    {
        self::$memoryStore = null;
    }

    /**
     * 收尾优化 stamp：composer.lock + generated 顶层关键产物清单（非全树扫描）。
     */
    public function computeOptimizeStamp(): string
    {
        $parts = [];
        $lock = \rtrim((string)BP, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'composer.lock';
        $parts[] = 'lock:' . $this->fingerprintFile($lock);

        $generated = \rtrim((string)BP, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'generated';
        $names = [
            'extends.php',
            'modules.php',
            'classmap.php',
            'psr4.php',
            'reflection_metadata.php',
            'compiled_factories.php',
        ];
        $entries = [];
        foreach ($names as $name) {
            $path = $generated . DIRECTORY_SEPARATOR . $name;
            $entries[] = $name . '|' . $this->fingerprintFile($path);
        }
        // 轻量：generated 顶层 .php 文件名列表（不含递归）
        if (\is_dir($generated)) {
            $top = [];
            foreach (\scandir($generated) ?: [] as $item) {
                if ($item === '.' || $item === '..') {
                    continue;
                }
                $full = $generated . DIRECTORY_SEPARATOR . $item;
                if (\is_file($full) && \str_ends_with($item, '.php')) {
                    $top[] = $item . '|' . (int)@\filesize($full) . '|' . (int)@\filemtime($full);
                }
            }
            \sort($top, \SORT_STRING);
            $entries[] = 'top:' . \hash('sha256', \implode("\n", $top));
        }
        $parts[] = 'generated:' . \hash('sha256', \implode("\n", $entries));

        return \hash('sha256', \implode('|', $parts));
    }

    public const OPTIMIZE_STAMP_KEY = 'optimize:complete_stamp';
}

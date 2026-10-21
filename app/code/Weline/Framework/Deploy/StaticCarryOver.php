<?php

declare(strict_types=1);

namespace Weline\Framework\Deploy;

use Weline\Framework\App\Env;

/**
 * 跨 `pub/static` rename-swap 保留仍可能被旧 HTML 引用的可服务静态字节。
 *
 * # 为什么必须有这一类
 *
 * `DeployStagingSession::commitSwap()` 把整棵 `pub/static` rename 成
 * `pub/static.prev.{stamp}`，随后 `purgePrevAndResidue()` 删除旧树。主题包
 * （`theme-head/{sha256}.css`、`widget-assets/{sha256}.css|js`）是内容哈希
 * 命名的不可变产物，但它们的寿命此前被绑定在「一次发布树」上：只要边缘还
 * 在发上一代 HTML，旧哈希就会在源站 404。
 *
 * 本类在 swap 之前把旧树中**新树没有、且公共面允许服务**的文件硬链接
 * （失败时复制）进 staging。新树优先，旧树只补缺失路径，因此：
 *
 * - 新发布仍是原子的：staging 完整后才 rename 切换；
 * - 旧 HTML 引用的旧哈希继续 200，直到保留窗口结束；
 * - 被有意重新发布的同名文件仍由新树内容胜出；
 * - 保留窗口由 {@see self::ENV_RETENTION_DAYS} 控制，默认覆盖历史
 *   一年 HTML 边缘 TTL（400 天）。窗口过后由 {@see self::pruneExpired()}
 *   只删除本类记录过的 tombstone，不碰新树原生文件。
 *
 * # 安全边界
 *
 * 只携带 {@see StaticPublicSurface::isServableUrlPath()} 允许的路径；
 * `.php`、构建残留、发布排除项永不进入保留集合。`pub/static` 本身已是公开
 * 面，保留窗口不是新增暴露面；安全删除应通过
 * `wls.performance.static_carry_retention_days=0` 关闭携带，或显式清理 tombstone。
 */
final class StaticCarryOver
{
    public const MANIFEST_VERSION = 1;

    /** 默认 400 天：覆盖本次修复前已按一年 TTL 写入边缘的 HTML。 */
    public const DEFAULT_RETENTION_DAYS = 400;

    public const ENV_RETENTION_DAYS = 'wls.performance.static_carry_retention_days';

    private string $manifestPath;
    private int $retentionSeconds;

    public function __construct(?string $manifestPath = null, ?int $retentionSeconds = null)
    {
        $this->manifestPath = $manifestPath ?? self::defaultManifestPath();
        $this->retentionSeconds = $retentionSeconds ?? self::resolveRetentionSeconds();
    }

    /**
     * 把 live 中缺失于 staging 的可服务文件补进 staging（硬链接优先）。
     *
     * 必须在 `commitSwap()` 的 rename 之前调用。调用方应保证 staging 已发布
     * 完成；本方法只补缺失路径，不覆盖 staging 已有文件。
     *
     * @return array{carried:int,native:int,skipped:int,manifest:string}
     */
    public function carryForward(string $liveRoot, string $stagingRoot): array
    {
        $liveRoot = self::normalizeRoot($liveRoot);
        $stagingRoot = self::normalizeRoot($stagingRoot);
        $result = [
            'carried' => 0,
            'native' => 0,
            'skipped' => 0,
            'manifest' => $this->manifestPath,
        ];
        if ($liveRoot === '' || $stagingRoot === '' || !is_dir($liveRoot) || !is_dir($stagingRoot)) {
            return $result;
        }
        if ($this->retentionSeconds <= 0) {
            // Explicit opt-out: do not carry or retain tombstones.
            return $result;
        }

        $now = time();
        $previousEntries = $this->readManifest();
        $entries = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($liveRoot, \FilesystemIterator::SKIP_DOTS),
        );
        foreach ($iterator as $item) {
            /** @var \SplFileInfo $item */
            if (!$item->isFile() || $item->isLink()) {
                continue;
            }
            $source = $item->getPathname();
            $relative = self::relativePath($liveRoot, $source);
            if ($relative === null) {
                continue;
            }
            if (!StaticPublicSurface::isServableUrlPath('/static/' . $relative)) {
                $result['skipped']++;
                continue;
            }

            $target = $stagingRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
            if (file_exists($target) || is_link($target)) {
                // New publish owns this path; a stale tombstone entry must not
                // later delete the newly published bytes.
                $result['native']++;
                continue;
            }

            $this->ensureParentDirectory($target);
            $stat = @stat($source);
            if (!\is_array($stat)) {
                throw new \RuntimeException('static_carry_stat_failed:' . $relative);
            }
            if (!@\link($source, $target)) {
                if (!@\copy($source, $target)) {
                    throw new \RuntimeException('static_carry_copy_failed:' . $relative);
                }
                @\chmod($target, (int)$stat['mode'] & 0777);
                @\touch($target, (int)$stat['mtime']);
                \clearstatcache(true, $target);
                $targetStat = @stat($target);
                if (\is_array($targetStat)) {
                    $stat = $targetStat;
                }
            }

            $previous = $previousEntries[$relative] ?? null;
            $entries[$relative] = [
                // Retention starts when the path first disappears from the
                // published tree; re-carries must not extend it indefinitely.
                'carried_at' => \is_array($previous)
                    ? (int)($previous['carried_at'] ?? $now)
                    : $now,
                'mtime' => (int)$stat['mtime'],
                'size' => (int)$stat['size'],
                'dev' => (int)($stat['dev'] ?? 0),
                'ino' => (int)($stat['ino'] ?? 0),
            ];
            $result['carried']++;
        }

        $this->writeManifest($entries, $now);

        return $result;
    }

    /**
     * 删除超过保留窗口、且仍与 manifest 中记录为同一 inode 的 tombstone。
     *
     * 只处理本类记录过的路径；如果同名路径已被新树原生文件替换（inode /
     * 大小 / mtime 任一变化），丢弃 manifest 条目但**不删除**文件。
     *
     * @return array{pruned:int,kept:int,replaced:int,manifest:string}
     */
    public function pruneExpired(string $liveRoot, ?int $now = null): array
    {
        $liveRoot = self::normalizeRoot($liveRoot);
        $result = [
            'pruned' => 0,
            'kept' => 0,
            'replaced' => 0,
            'manifest' => $this->manifestPath,
        ];
        if ($liveRoot === '' || !is_dir($liveRoot) || $this->retentionSeconds < 0) {
            return $result;
        }

        $now ??= time();
        $entries = $this->readManifest();
        $remaining = [];
        foreach ($entries as $relative => $entry) {
            $path = $liveRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
            if (!is_file($path) || is_link($path)
                || !StaticPublicSurface::isServableUrlPath('/static/' . $relative)
            ) {
                continue;
            }
            $stat = @stat($path);
            if (!\is_array($stat)) {
                continue;
            }
            if (!self::sameFile($stat, $entry)) {
                // A native publish replaced the tombstone path. Never delete it.
                $result['replaced']++;
                continue;
            }
            $carriedAt = (int)($entry['carried_at'] ?? $now);
            if ($this->retentionSeconds > 0 && ($now - $carriedAt) < $this->retentionSeconds) {
                $remaining[$relative] = $entry;
                $result['kept']++;
                continue;
            }
            if (!@\unlink($path)) {
                $remaining[$relative] = $entry;
                $result['kept']++;
                continue;
            }
            $result['pruned']++;
        }

        $this->writeManifest($remaining, $now);

        return $result;
    }

    public function manifestPath(): string
    {
        return $this->manifestPath;
    }

    public function retentionSeconds(): int
    {
        return $this->retentionSeconds;
    }

    /**
     * @return array<string, array{carried_at:int,mtime:int,size:int,dev:int,ino:int}>
     */
    private function readManifest(): array
    {
        if (!is_file($this->manifestPath)) {
            return [];
        }
        $raw = @file_get_contents($this->manifestPath);
        if (!\is_string($raw) || trim($raw) === '') {
            return [];
        }
        $decoded = \json_decode($raw, true);
        if (!\is_array($decoded) || (int)($decoded['version'] ?? 0) !== self::MANIFEST_VERSION) {
            return [];
        }
        $entries = $decoded['entries'] ?? [];
        if (!\is_array($entries)) {
            return [];
        }
        $valid = [];
        foreach ($entries as $relative => $entry) {
            if (!\is_string($relative) || !\is_array($entry)) {
                continue;
            }
            $relative = \str_replace('\\', '/', \trim($relative));
            if ($relative === '' || \str_contains($relative, '..')
                || !StaticPublicSurface::isServableUrlPath('/static/' . $relative)
            ) {
                continue;
            }
            $valid[$relative] = [
                'carried_at' => (int)($entry['carried_at'] ?? 0),
                'mtime' => (int)($entry['mtime'] ?? 0),
                'size' => (int)($entry['size'] ?? 0),
                'dev' => (int)($entry['dev'] ?? 0),
                'ino' => (int)($entry['ino'] ?? 0),
            ];
        }

        return $valid;
    }

    /**
     * @param array<string, array{carried_at:int,mtime:int,size:int,dev:int,ino:int}> $entries
     */
    private function writeManifest(array $entries, int $now): void
    {
        $directory = \dirname($this->manifestPath);
        if (!is_dir($directory) && !@\mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new \RuntimeException('static_carry_manifest_dir_failed:' . $directory);
        }
        $payload = \json_encode([
            'version' => self::MANIFEST_VERSION,
            'updated_at' => $now,
            'entries' => $entries,
        ], \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);
        if (!\is_string($payload)) {
            throw new \RuntimeException('static_carry_manifest_encode_failed');
        }
        $temporary = $this->manifestPath . '.tmp.' . \getmypid();
        if (@\file_put_contents($temporary, $payload) === false || !@\rename($temporary, $this->manifestPath)) {
            @\unlink($temporary);
            throw new \RuntimeException('static_carry_manifest_write_failed:' . $this->manifestPath);
        }
    }

    /**
     * @param array<string, mixed> $stat
     * @param array<string, mixed> $entry
     */
    private static function sameFile(array $stat, array $entry): bool
    {
        foreach (['dev', 'ino', 'size', 'mtime'] as $key) {
            $recorded = (int)($entry[$key] ?? 0);
            if ($recorded !== 0 && $recorded !== (int)($stat[$key] ?? 0)) {
                return false;
            }
        }

        return true;
    }

    private static function relativePath(string $root, string $path): ?string
    {
        $normalizedRoot = \rtrim(\str_replace('\\', '/', $root), '/') . '/';
        $normalizedPath = \str_replace('\\', '/', $path);
        if (!\str_starts_with($normalizedPath, $normalizedRoot)) {
            return null;
        }
        $relative = \substr($normalizedPath, \strlen($normalizedRoot));
        if ($relative === '' || \str_contains($relative, '..')) {
            return null;
        }

        return $relative;
    }

    private static function normalizeRoot(string $root): string
    {
        $root = \rtrim(\str_replace('\\', '/', \trim($root)), '/');

        return $root === '' ? '' : $root;
    }

    private function ensureParentDirectory(string $path): void
    {
        $directory = \dirname($path);
        if (!is_dir($directory) && !@\mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new \RuntimeException('static_carry_mkdir_failed:' . $directory);
        }
    }

    private static function defaultManifestPath(): string
    {
        try {
            $var = Env::varDir();
        } catch (\Throwable) {
            $var = \defined('BP')
                ? \rtrim((string)\constant('BP'), '/\\') . \DIRECTORY_SEPARATOR . 'var' . \DIRECTORY_SEPARATOR
                : \sys_get_temp_dir() . \DIRECTORY_SEPARATOR . 'weline-var' . \DIRECTORY_SEPARATOR;
        }

        return \rtrim($var, '/\\') . \DIRECTORY_SEPARATOR . 'deploy' . \DIRECTORY_SEPARATOR . 'static-carry.json';
    }

    private static function resolveRetentionSeconds(): int
    {
        $days = self::DEFAULT_RETENTION_DAYS;
        if (\defined('BP')) {
            try {
                $configured = Env::get(self::ENV_RETENTION_DAYS, self::DEFAULT_RETENTION_DAYS);
                if (\is_numeric($configured)) {
                    $days = (int)$configured;
                }
            } catch (\Throwable) {
                $days = self::DEFAULT_RETENTION_DAYS;
            }
        }
        $days = \max(0, \min($days, 3650));

        return $days * 86400;
    }
}

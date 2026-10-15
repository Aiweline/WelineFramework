<?php

declare(strict_types=1);

namespace Weline\Framework\Http\Fpc;

use Weline\Framework\App\Env;

/**
 * Path-keyed static FPC cold disk for Nginx try_files.
 *
 * Layout: generated/static/live/{host}/{uri/path/file.ext}
 *
 * Used when DEV/WLS resolves statics from app/code (or vendor) that are not
 * already under pub/media or pub/static. Nginx serves this tree before proxying
 * to WLS; pure WLS mode still reads source paths directly.
 */
final class FpcStaticDiskStore
{
    public const LIVE_NAME = 'live';

    /** Skip mirroring oversized files (Range/stream stay on WLS). */
    public const MAX_MIRROR_BYTES = 2_097_152;

    public static function root(): string
    {
        return \rtrim((string)Env::GENERATED_DIR, '\\/') . \DIRECTORY_SEPARATOR . 'static';
    }

    public static function liveRoot(): string
    {
        $live = self::root() . \DIRECTORY_SEPARATOR . self::LIVE_NAME;
        if (!\is_dir($live)) {
            @\mkdir($live, 0775, true);
        }

        return $live;
    }

    /**
     * Absolute leaf path for a Host + URL path, or null when unsafe.
     */
    public static function absolutePath(string $host, string $uriPath): ?string
    {
        $rel = self::relativeLeaf($host, $uriPath);
        if ($rel === null) {
            return null;
        }

        return self::liveRoot() . \DIRECTORY_SEPARATOR . $rel;
    }

    /**
     * Mirror a resolved on-disk file into the static FPC tree.
     * No-op when the source is already pub/ or this store.
     *
     * Plaintext early-out (size+mtime) must still refresh `$target.br`:
     * Nginx brotli_static prefers stale `.br` for Accept-Encoding:br clients.
     */
    public static function publishFromFile(string $host, string $uriPath, string $sourceAbsolutePath): bool
    {
        if (!self::shouldMirrorResolvedFile($sourceAbsolutePath)) {
            return false;
        }
        $size = @\filesize($sourceAbsolutePath);
        if (!\is_int($size) || $size < 1 || $size > self::MAX_MIRROR_BYTES) {
            return false;
        }
        $target = self::absolutePath($host, $uriPath);
        if ($target === null) {
            return false;
        }
        if (\is_file($target)) {
            $existingSize = @\filesize($target);
            $existingMtime = @\filemtime($target);
            $sourceMtime = @\filemtime($sourceAbsolutePath);
            if ($existingSize === $size
                && \is_int($existingMtime)
                && \is_int($sourceMtime)
                && $existingMtime >= $sourceMtime
            ) {
                // Plaintext already mirrors source — still heal/refresh `.br` sidecar.
                StaticAssetBrotliSidecar::publish($target);

                return true;
            }
        }
        $dir = \dirname($target);
        if (!self::ensureDirectory($dir)) {
            return false;
        }
        if (!self::copyAtomic($sourceAbsolutePath, $target)) {
            return false;
        }
        StaticAssetBrotliSidecar::publish($target);

        return true;
    }

    public static function publishBytes(string $host, string $uriPath, string $bytes): bool
    {
        if ($bytes === '' || \strlen($bytes) > self::MAX_MIRROR_BYTES) {
            return false;
        }
        $target = self::absolutePath($host, $uriPath);
        if ($target === null) {
            return false;
        }
        $dir = \dirname($target);
        if (!self::ensureDirectory($dir)) {
            return false;
        }
        if (!self::writeAtomic($target, $bytes)) {
            return false;
        }
        StaticAssetBrotliSidecar::publish($target);

        return true;
    }

    public static function shouldMirrorResolvedFile(string $absolutePath): bool
    {
        $path = \str_replace('\\', '/', $absolutePath);
        if ($path === '' || !\is_file($absolutePath) || !\is_readable($absolutePath)) {
            return false;
        }
        $base = \defined('BP') ? \rtrim(\str_replace('\\', '/', (string)\constant('BP')), '/') : '';
        if ($base === '') {
            return false;
        }
        $pub = $base . '/pub/';
        if (\str_starts_with($path, $pub)) {
            return false;
        }
        $store = \str_replace('\\', '/', self::root()) . '/';
        if (\str_starts_with($path, $store)) {
            return false;
        }

        return \str_starts_with($path, $base . '/');
    }

    /**
     * @param list<string> $uriPaths Absolute URL paths (/a/b.js) or host-qualified later via host arg
     */
    public static function purgePaths(string $host, array $uriPaths): void
    {
        foreach ($uriPaths as $uriPath) {
            $target = self::absolutePath($host, (string)$uriPath);
            if ($target === null) {
                continue;
            }
            if (\is_file($target)) {
                @\unlink($target);
            }
            StaticAssetBrotliSidecar::unlinkSidecar($target);
            self::removeEmptyParents(\dirname($target), self::liveRoot());
        }
    }

    /**
     * Host directories already under generated/static/live/ (Nginx try_files front).
     *
     * @return list<string>
     */
    public static function listLiveHosts(): array
    {
        $live = self::liveRoot();
        if (!\is_dir($live)) {
            return [];
        }
        $hosts = [];
        $entries = @\scandir($live);
        if (!\is_array($entries)) {
            return [];
        }
        foreach ($entries as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            $host = \strtolower(\trim((string)$name));
            if ($host === '' || \str_contains($host, '..') || \str_contains($host, '/') || \str_contains($host, '\\')) {
                continue;
            }
            if (\preg_match('/^[a-z0-9._:-]+$/D', $host) !== 1) {
                continue;
            }
            if (!\is_dir($live . \DIRECTORY_SEPARATOR . $name)) {
                continue;
            }
            $hosts[] = $host;
        }
        \sort($hosts);

        return $hosts;
    }

    /**
     * Map `BP/app/code/{Vendor}/{Module}/view/statics/...` → `/Vendor/Module/view/statics/...`.
     * Empty when outside app/code or unsafe.
     */
    public static function uriFromAppCodeAbsolute(string $absolutePath): string
    {
        $path = \str_replace('\\', '/', $absolutePath);
        if ($path === '' || !\is_file($absolutePath)) {
            return '';
        }
        $base = \defined('BP') ? \rtrim(\str_replace('\\', '/', (string)\constant('BP')), '/') : '';
        if ($base === '') {
            return '';
        }
        $prefix = $base . '/app/code/';
        if (!\str_starts_with($path, $prefix)) {
            return '';
        }
        $rel = \substr($path, \strlen($prefix));
        if ($rel === '' || \str_contains($rel, '..')) {
            return '';
        }
        $segments = \explode('/', $rel);
        $safe = [];
        foreach ($segments as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                return '';
            }
            if (\preg_match('/^[A-Za-z0-9._~%-]+$/D', $segment) !== 1) {
                return '';
            }
            $safe[] = $segment;
        }
        if ($safe === [] || \count($safe) < 4) {
            // Vendor/Module/view/statics/... minimum
            return '';
        }

        return '/' . \implode('/', $safe);
    }

    /**
     * Refresh (or create) the live FPC mirror for one URI on every known host.
     * Used when module static sources change in DEV while Nginx still serves stale live leaves.
     * Content-hash compared: identical → still refresh `.br` sidecar then count ok;
     * diverge → unlink then publishFromFile (which also writes `.br`).
     */
    public static function republishFromFileOnAllHosts(string $uriPath, string $sourceAbsolutePath): int
    {
        if (!self::shouldMirrorResolvedFile($sourceAbsolutePath)) {
            return 0;
        }
        $hosts = self::listLiveHosts();
        if ($hosts === []) {
            return 0;
        }
        $srcHash = \hash_file('sha256', $sourceAbsolutePath) ?: '';
        $ok = 0;
        foreach ($hosts as $host) {
            $target = self::absolutePath($host, $uriPath);
            if ($target !== null && \is_file($target) && $srcHash !== '') {
                $dstHash = \hash_file('sha256', $target) ?: '';
                if ($dstHash === $srcHash) {
                    // Hash match must not leave a stale precompressed sidecar for br clients.
                    StaticAssetBrotliSidecar::publish($target);
                    ++$ok;
                    continue;
                }
                @\unlink($target);
                StaticAssetBrotliSidecar::unlinkSidecar($target);
            }
            if (self::publishFromFile($host, $uriPath, $sourceAbsolutePath)) {
                ++$ok;
            }
        }

        return $ok;
    }

    /**
     * Drop stale live leaves for URIs across all hosts (next hit cold-mirrors from WLS).
     *
     * @param list<string> $uriPaths
     */
    public static function purgeUriOnAllHosts(array $uriPaths): int
    {
        $hosts = self::listLiveHosts();
        if ($hosts === [] || $uriPaths === []) {
            return 0;
        }
        $before = 0;
        foreach ($hosts as $host) {
            foreach ($uriPaths as $uriPath) {
                $target = self::absolutePath($host, (string)$uriPath);
                if ($target !== null && \is_file($target)) {
                    ++$before;
                }
            }
            self::purgePaths($host, $uriPaths);
        }

        return $before;
    }

    /**
     * Wipe entire static FPC live tree (all hosts). Used when deploy mode flips to prod
     * so DEV-mirrored `/Weline/...` leaves cannot outlive the module-prefix withdrawal.
     */
    public static function purgeAllLive(): int
    {
        $live = self::root() . \DIRECTORY_SEPARATOR . self::LIVE_NAME;
        if (!\is_dir($live)) {
            return 0;
        }
        $removed = 0;
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($live, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $item) {
            $path = $item->getPathname();
            if (!self::isUnderRoot($path)) {
                continue;
            }
            if ($item->isLink() || $item->isFile()) {
                if (@\unlink($path)) {
                    ++$removed;
                }
            } elseif ($item->isDir()) {
                @\rmdir($path);
            }
        }

        return $removed;
    }

    private static function relativeLeaf(string $host, string $uriPath): ?string
    {
        $host = \strtolower(\trim($host));
        if ($host === '' || \str_contains($host, '..') || \str_contains($host, '/') || \str_contains($host, '\\')) {
            return null;
        }
        if (\preg_match('/^[a-z0-9._:-]+$/D', $host) !== 1) {
            return null;
        }
        $path = \str_replace('\\', '/', (string)\parse_url($uriPath, \PHP_URL_PATH));
        if ($path === '' || $path === '/') {
            return null;
        }
        if ($path[0] !== '/') {
            $path = '/' . \ltrim($path, '/');
        }
        $segments = \explode('/', \trim($path, '/'));
        $safe = [];
        foreach ($segments as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                return null;
            }
            if (\preg_match('/^[A-Za-z0-9._~%-]+$/D', $segment) !== 1) {
                return null;
            }
            $safe[] = $segment;
        }
        if ($safe === []) {
            return null;
        }

        return $host . \DIRECTORY_SEPARATOR . \implode(\DIRECTORY_SEPARATOR, $safe);
    }

    private static function ensureDirectory(string $dir): bool
    {
        if (\is_dir($dir)) {
            return self::isUnderRoot($dir);
        }
        if (!@\mkdir($dir, 0775, true) && !\is_dir($dir)) {
            return false;
        }

        return self::isUnderRoot($dir);
    }

    private static function copyAtomic(string $source, string $target): bool
    {
        if (!self::isUnderRoot($target)) {
            return false;
        }
        $dir = \dirname($target);
        $tmp = $dir . \DIRECTORY_SEPARATOR . '.tmp-' . \bin2hex(\random_bytes(8));
        if (!@\copy($source, $tmp)) {
            @\unlink($tmp);

            return false;
        }
        if (!@\rename($tmp, $target)) {
            @\unlink($tmp);

            return false;
        }

        return true;
    }

    private static function writeAtomic(string $path, string $bytes): bool
    {
        if (!self::isUnderRoot($path)) {
            return false;
        }
        $dir = \dirname($path);
        $tmp = $dir . \DIRECTORY_SEPARATOR . '.tmp-' . \bin2hex(\random_bytes(8));
        if (@\file_put_contents($tmp, $bytes, \LOCK_EX) === false) {
            @\unlink($tmp);

            return false;
        }
        if (!@\rename($tmp, $path)) {
            @\unlink($tmp);

            return false;
        }

        return true;
    }

    private static function isUnderRoot(string $path): bool
    {
        $root = \realpath(self::root());
        if (!\is_string($root) || $root === '') {
            $root = self::root();
            if (!\is_dir($root) && !@\mkdir($root, 0775, true)) {
                return false;
            }
            $root = \realpath($root) ?: $root;
        }
        $normalized = \str_replace(['/', '\\'], \DIRECTORY_SEPARATOR, $path);
        $rootNorm = \rtrim(\str_replace(['/', '\\'], \DIRECTORY_SEPARATOR, $root), \DIRECTORY_SEPARATOR);
        $check = $normalized;
        if (\is_dir($normalized) || \is_file($normalized)) {
            $real = \realpath($normalized);
            if (\is_string($real)) {
                $check = $real;
            }
        } else {
            $parent = \dirname($normalized);
            $realParent = \realpath($parent);
            if (\is_string($realParent)) {
                $check = $realParent . \DIRECTORY_SEPARATOR . \basename($normalized);
            }
        }
        $check = \str_replace(['/', '\\'], \DIRECTORY_SEPARATOR, $check);

        return $check === $rootNorm || \str_starts_with($check, $rootNorm . \DIRECTORY_SEPARATOR);
    }

    private static function removeEmptyParents(string $dir, string $stopRoot): void
    {
        $stop = \rtrim(\str_replace(['/', '\\'], \DIRECTORY_SEPARATOR, $stopRoot), \DIRECTORY_SEPARATOR);
        $current = \rtrim(\str_replace(['/', '\\'], \DIRECTORY_SEPARATOR, $dir), \DIRECTORY_SEPARATOR);
        while ($current !== '' && $current !== $stop && \str_starts_with($current, $stop . \DIRECTORY_SEPARATOR)) {
            if (!\is_dir($current)) {
                break;
            }
            $items = @\scandir($current);
            if (!\is_array($items)) {
                break;
            }
            $items = \array_values(\array_filter($items, static fn(string $n): bool => $n !== '.' && $n !== '..'));
            if ($items !== []) {
                break;
            }
            if (!@\rmdir($current)) {
                break;
            }
            $current = \dirname($current);
        }
    }
}

<?php

declare(strict_types=1);

namespace Weline\Theme\Service;

/**
 * Header chrome 嵌套部件/导航源内容指纹（活路径权威）。
 *
 * 键消费方：Partials frontend header → storefront_chrome。
 * 算法：每文件内容 sha256；mtime|size 仅作进程内 memo 门控（stat 变才重 hash）。
 * 禁止无条件每请求整文件 hash；禁止 Worker 生命周期一次算死不刷新。
 */
final class NestedChromeSourceFingerprint
{
    /**
     * Module-relative paths under Weline_Theme (stable contract list).
     *
     * @return list<string>
     */
    public static function sourceRelativePaths(): array
    {
        return [
            'view/theme/frontend/widgets/header/account/default.phtml',
            'view/theme/frontend/widgets/header/mini-cart-icon/default.phtml',
            'view/theme/frontend/partials/header/categories-horizontal-nav.phtml',
            'view/theme/frontend/partials/header/categories-sidebar-nav.phtml',
            'view/theme/frontend/partials/header/mega-menu-panel.phtml',
        ];
    }

    /**
     * @return list<string>
     */
    public static function sourceAbsolutePaths(): array
    {
        $root = \dirname(__DIR__);
        $out = [];
        foreach (self::sourceRelativePaths() as $rel) {
            $out[] = $root . '/' . $rel;
        }

        return $out;
    }

    /**
     * @var array<string, array{mtime: int, size: int, hash: string}>
     */
    private static array $memo = [];

    public static function compute(): string
    {
        $parts = [];
        foreach (self::sourceAbsolutePaths() as $path) {
            $parts[] = self::hashFile($path);
        }

        return \implode(';', $parts);
    }

    /**
     * Content sha256 for one path; memo keyed by path + (mtime, size).
     */
    public static function hashFile(string $path): string
    {
        if ($path === '' || !\is_file($path)) {
            return '0';
        }
        $stat = @\stat($path);
        if (!\is_array($stat)) {
            return '0';
        }
        $mtime = (int)($stat['mtime'] ?? 0);
        $size = (int)($stat['size'] ?? 0);
        $cached = self::$memo[$path] ?? null;
        if (
            \is_array($cached)
            && (int)$cached['mtime'] === $mtime
            && (int)$cached['size'] === $size
            && isset($cached['hash'])
            && \is_string($cached['hash'])
            && $cached['hash'] !== ''
        ) {
            return $cached['hash'];
        }
        $hash = @\hash_file('sha256', $path);
        if (!\is_string($hash) || $hash === '') {
            $hash = '0';
        }
        self::$memo[$path] = [
            'mtime' => $mtime,
            'size' => $size,
            'hash' => $hash,
        ];

        return $hash;
    }

    /** Test-only: clear process memo. */
    public static function resetMemoForTests(): void
    {
        self::$memo = [];
    }
}

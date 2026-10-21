<?php

declare(strict_types=1);

namespace Weline\Framework\Deploy;

final class StaticBuildGc
{
    /**
     * @return array{deleted:list<string>,kept:list<string>}
     */
    public static function prune(string $staticRoot, int $keepDays = 7, int $keepLast = 2, bool $dryRun = false): array
    {
        $root = \rtrim($staticRoot, '/\\');
        if (!is_dir($root)) {
            throw new \RuntimeException('static_root_missing');
        }
        $current = StaticBuildId::current($root);
        $deleted = [];
        $kept = [];
        $tmpDirs = [];
        $builds = [];

        foreach (\scandir($root) as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            $path = $root . '/' . $name;
            if (!is_dir($path) || is_link($path)) {
                continue;
            }
            $mtime = @\filemtime($path);
            if ($mtime === false) {
                continue;
            }
            if (\str_starts_with($name, '.tmp-')) {
                if ($mtime < \time() - 86400) {
                    $tmpDirs[] = $name;
                    if (!$dryRun) {
                        self::removeTree($path);
                    }
                    $deleted[] = $name;
                }
                continue;
            }
            if (\preg_match('/^[0-9a-f]{12}$/', $name) !== 1 || !is_file($path . '/' . StaticBuildId::BUILD_MARKER)) {
                continue;
            }
            $builds[] = ['name' => $name, 'mtime' => $mtime];
        }

        \usort($builds, static fn(array $a, array $b): int => $b['mtime'] <=> $a['mtime']);
        $now = \time();
        foreach ($builds as $index => $build) {
            $name = $build['name'];
            $mtime = $build['mtime'];
            $shouldKeep = $name === $current
                || $index < \max(0, $keepLast)
                || $mtime >= $now - $keepDays * 86400;
            if ($shouldKeep) {
                $kept[] = $name;
            } else {
                if (!$dryRun) {
                    self::removeTree($root . '/' . $name);
                }
                $deleted[] = $name;
            }
        }

        return ['deleted' => $deleted, 'kept' => $kept];
    }

    private static function removeTree(string $path): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $item) {
            if ($item->isLink()) {
                @\unlink($item->getPathname());
                continue;
            }
            $item->isDir() ? @\rmdir($item->getPathname()) : @\unlink($item->getPathname());
        }
        @\rmdir($path);
    }
}

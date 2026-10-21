<?php

declare(strict_types=1);

namespace Weline\Framework\Deploy;

final class StaticBuildPublisher
{
    public static function publish(string $staticRoot): string
    {
        $root = \rtrim($staticRoot, '/\\');
        if (!is_dir($root)) {
            throw new \RuntimeException('static_root_missing');
        }
        $id = self::computeBuildId($root);
        $target = $root . '/' . $id;
        $marker = $target . '/' . StaticBuildId::BUILD_MARKER;
        if (is_file($marker)) {
            self::writeManifest($root, $id);
            StaticBuildId::forget($root);
            return $id;
        }

        $staging = $root . '/.tmp-' . $id;
        if (is_dir($staging)) {
            self::removeTree($staging);
        }
        if (!@mkdir($staging, 0775, true)) {
            throw new \RuntimeException('static_build_tmp_mkdir_failed');
        }
        self::linkTree($root, $staging);
        if (@file_put_contents($staging . '/' . StaticBuildId::BUILD_MARKER, $id) === false) {
            throw new \RuntimeException('static_build_marker_write_failed');
        }
        if (!@\rename($staging, $target)) {
            throw new \RuntimeException('static_build_rename_failed');
        }
        self::writeManifest($root, $id);
        StaticBuildId::forget($root);
        return $id;
    }

    public static function computeBuildId(string $staticRoot): string
    {
        $root = \rtrim($staticRoot, '/\\');
        $hashes = [];
        $skipRoots = [];
        foreach (@\scandir($root) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $root . '/' . $entry;
            if (is_dir($path) && (str_starts_with($entry, '.tmp-') || is_file($path . '/' . StaticBuildId::BUILD_MARKER))) {
                $skipRoots[$entry] = true;
            }
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
        );
        foreach ($iterator as $item) {
            /** @var \SplFileInfo $item */
            if (!$item->isFile() || $item->isLink()) {
                continue;
            }
            $relative = \substr($item->getPathname(), \strlen($root) + 1);
            $relative = \str_replace(DIRECTORY_SEPARATOR, '/', $relative);
            if ($relative === StaticBuildId::MANIFEST
                || $relative === StaticBuildId::MANIFEST . '.tmp'
                || $skipRoots[\explode('/', $relative)[0]] ?? false
            ) {
                continue;
            }
            $sha = @\hash_file('sha256', $item->getPathname());
            if (\is_string($sha)) {
                $hashes[$relative] = $sha;
            }
        }
        \ksort($hashes);
        $payload = '';
        foreach ($hashes as $relative => $sha) {
            $payload .= $relative . "\0" . $sha . "\n";
        }
        return \substr(\hash('sha256', $payload), 0, 12);
    }

    private static function linkTree(string $root, string $staging): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
        );
        foreach ($iterator as $item) {
            /** @var \SplFileInfo $item */
            if (!$item->isFile() || $item->isLink()) {
                continue;
            }
            $relative = \substr($item->getPathname(), \strlen($root) + 1);
            $relative = \str_replace(DIRECTORY_SEPARATOR, '/', $relative);
            if ($relative === StaticBuildId::MANIFEST
                || $relative === StaticBuildId::MANIFEST . '.tmp'
                || self::isSkippedTopDir($root, \explode('/', $relative)[0])
            ) {
                continue;
            }
            $target = $staging . '/' . \str_replace('/', DIRECTORY_SEPARATOR, $relative);
            @\mkdir(\dirname($target), 0775, true);
            if (!@\link($item->getPathname(), $target) && !@\copy($item->getPathname(), $target)) {
                throw new \RuntimeException('static_build_copy_failed:' . $relative);
            }
        }
    }

    private static function isSkippedTopDir(string $root, string $entry): bool
    {
        return \str_starts_with($entry, '.tmp-')
            || is_file($root . '/' . $entry . '/' . StaticBuildId::BUILD_MARKER);
    }

    private static function writeManifest(string $root, string $id): void
    {
        $json = \json_encode([
            'build_id' => $id,
            'digest' => $id,
            'built_at' => \time(),
        ]);
        $tmp = $root . '/' . StaticBuildId::MANIFEST . '.tmp';
        if (@\file_put_contents($tmp, $json) === false || !@\rename($tmp, $root . '/' . StaticBuildId::MANIFEST)) {
            throw new \RuntimeException('static_build_manifest_write_failed');
        }
    }

    private static function removeTree(string $path): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $item) {
            $item->isDir() && !$item->isLink() ? @\rmdir($item->getPathname()) : @\unlink($item->getPathname());
        }
        @\rmdir($path);
    }
}

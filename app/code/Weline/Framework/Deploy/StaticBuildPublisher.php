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
        $iterator = self::iterateSourceTree($root);
        foreach ($iterator as $item) {
            /** @var \SplFileInfo $item */
            if (!$item->isFile() || $item->isLink()) {
                continue;
            }
            $relative = \substr($item->getPathname(), \strlen($root) + 1);
            $relative = \str_replace(DIRECTORY_SEPARATOR, '/', $relative);
            if ($relative === StaticBuildId::MANIFEST
                || $relative === StaticBuildId::MANIFEST . '.tmp'
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
        $iterator = self::iterateSourceTree($root);
        foreach ($iterator as $item) {
            /** @var \SplFileInfo $item */
            if (!$item->isFile() || $item->isLink()) {
                continue;
            }
            $relative = \substr($item->getPathname(), \strlen($root) + 1);
            $relative = \str_replace(DIRECTORY_SEPARATOR, '/', $relative);
            if ($relative === StaticBuildId::MANIFEST
                || $relative === StaticBuildId::MANIFEST . '.tmp'
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

    /**
     * 只遍历当前发布树：旧代目录与 .tmp-* 在「进入前」就被排除，
     * 否则每次发布都会把所有旧代整树重扫一遍（代次越多越慢）。
     */
    private static function iterateSourceTree(string $root): \RecursiveIteratorIterator
    {
        $filter = new \RecursiveCallbackFilterIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
            static function (\SplFileInfo $item) use ($root): bool {
                if (!$item->isDir()) {
                    return true;
                }
                $name = $item->getFilename();
                if (\str_starts_with($name, '.tmp-')) {
                    return false;
                }
                // 主题更新代次根（pub/static/tu-*）不参与构建哈希与版本树复制。
                if (\str_starts_with($name, 'tu-')
                    && \rtrim(\str_replace('\\', '/', \dirname($item->getPathname())), '/') === \rtrim(\str_replace('\\', '/', $root), '/')
                ) {
                    return false;
                }
                if (\preg_match('/^[0-9a-f]{12}$/', $name) === 1
                    && \is_file($item->getPathname() . '/' . StaticBuildId::BUILD_MARKER)) {
                    return false;
                }

                return true;
            },
        );

        return new \RecursiveIteratorIterator($filter);
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

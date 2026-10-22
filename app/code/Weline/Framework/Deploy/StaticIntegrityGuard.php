<?php

declare(strict_types=1);

namespace Weline\Framework\Deploy;

/**
 * 发布后自愈：修掉两类真实事故
 *
 * 1) `.build.json` 指向**不完整**的构建目录（例如 staging 只含变更文件的树被提升）：
 *    校验当前代的必需文件，缺失则删除该代并重新完整发布；
 * 2) 主题更新代次目录（`/static/tu-*`）只含变更文件，旧烘焙页面引用缺失文件会 404：
 *    从规范静态树（`pub/static/Weline/**`）硬链接补齐缺失文件。
 */
final class StaticIntegrityGuard
{
    /** @var list<string> 当前代必须存在的哨兵文件（相对静态根） */
    public const REQUIRED_BUILD_FILES = [
        'Weline/Frontend/js/weline.js',
        'Weline/Frontend/js/weline-core.js',
        'Weline/Theme/ui/weline-ui.js',
    ];

    /**
     * @param list<string> $required
     * @return array{build:string,rebuilt:bool,missing:list<string>}
     */
    public static function healBuild(string $staticRoot, array $required = self::REQUIRED_BUILD_FILES): array
    {
        $root = \rtrim($staticRoot, '/\\');
        $current = StaticBuildId::current($root);
        if ($current === '') {
            return ['build' => '', 'rebuilt' => false, 'missing' => []];
        }
        $missing = self::missingFiles($root . '/' . $current, $required);
        if ($missing === []) {
            return ['build' => $current, 'rebuilt' => false, 'missing' => []];
        }

        // 不完整代不可信：删除后重新完整发布（publisher 会硬链接整棵规范树）。
        self::removeTree($root . '/' . $current);
        $rebuilt = StaticBuildPublisher::publish($root);

        return ['build' => $rebuilt, 'rebuilt' => true, 'missing' => $missing];
    }

    /**
     * 把规范静态树补齐进指定代次目录（仅补缺失文件，不覆盖代次内已有内容）。
     *
     * @return array{copied:int,generation:string}
     */
    public static function backfillGeneration(string $staticRoot, string $generation): array
    {
        $root = \rtrim($staticRoot, '/\\');
        $generation = \trim($generation);
        if ($generation === '') {
            return ['copied' => 0, 'generation' => ''];
        }
        if (!\str_starts_with($generation, 'tu-')) {
            $generation = 'tu-' . $generation;
        }
        $canonical = $root . '/Weline';
        if (!\is_dir($canonical)) {
            return ['copied' => 0, 'generation' => $generation];
        }
        $target = $root . '/' . $generation . '/Weline';
        $copied = 0;
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($canonical, \FilesystemIterator::SKIP_DOTS),
        );
        foreach ($iterator as $item) {
            /** @var \SplFileInfo $item */
            if (!$item->isFile() || $item->isLink()) {
                continue;
            }
            $relative = \substr($item->getPathname(), \strlen($canonical) + 1);
            $destination = $target . '/' . $relative;
            if (\is_file($destination)) {
                continue;
            }
            $directory = \dirname($destination);
            if (!\is_dir($directory) && !@\mkdir($directory, 0775, true) && !\is_dir($directory)) {
                continue;
            }
            if (!@\link($item->getPathname(), $destination)) {
                @\copy($item->getPathname(), $destination);
            }
            $copied++;
        }

        return ['copied' => $copied, 'generation' => $generation];
    }

    /** @param list<string> $required */
    private static function missingFiles(string $buildPath, array $required): array
    {
        if (!\is_dir($buildPath)) {
            return $required;
        }
        $missing = [];
        foreach ($required as $relative) {
            if (!\is_file($buildPath . '/' . \ltrim($relative, '/'))) {
                $missing[] = $relative;
            }
        }

        return $missing;
    }

    private static function removeTree(string $path): void
    {
        if (!\is_dir($path)) {
            return;
        }
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

<?php

declare(strict_types=1);

namespace Weline\Theme\Service\ThemeUpdate;

/**
 * 主题静态树内容指纹（12 位 hex），用于「本次发布」的 static_build。
 *
 * 只取「相对路径 + 字节数 + mtime」，不做全文哈希：一次 theme:update 要扫上千个文件，
 * 读内容会拖慢发布；路径/大小/mtime 三项已足够区分「内容或文件名变了」。
 *
 * 纯函数、无副作用，便于单测；不写任何文件。
 */
final class ThemeStaticTreeFingerprint
{
    public static function compute(string $absoluteRoot): string
    {
        $root = \rtrim(\str_replace('\\', '/', $absoluteRoot), '/');
        if ($root === '' || !\is_dir($root)) {
            return '';
        }

        $rows = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS | \FilesystemIterator::CURRENT_AS_FILEINFO),
            \RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($iterator as $item) {
            /** @var \SplFileInfo $item */
            if ($item->isLink()) {
                continue;
            }
            if ($item->isDir()) {
                continue;
            }
            if (!$item->isFile()) {
                continue;
            }
            $path = \str_replace('\\', '/', $item->getPathname());
            $base = \basename($path);
            // 发布过程自身会改这些文件（编译锁每次 touch、点开头的是构建元数据），
            // 算进指纹会导致「没改内容也换代」——实测连续两次 theme:update 指纹会变。
            if (\str_starts_with($base, '.') || \str_ends_with($base, '.lock')) {
                continue;
            }
            $relative = \str_starts_with($path, $root . '/') ? \substr($path, \strlen($root) + 1) : $path;
            // 内容寻址目录（theme-head/、widget-assets/）的文件名本身就是内容哈希：
            // 名字不变 ⇒ 内容不变；但发布每轮都会重写同名文件、刷新 mtime，
            // 若把 mtime 算进指纹，无改动也会换代（实测 stamp 每轮都变）。只算文件名。
            if (\preg_match('#/(theme-head|widget-assets)/#', '/' . $relative) === 1) {
                $rows[] = $relative;
                continue;
            }
            $rows[] = $relative . '|' . (string)$item->getSize() . '|' . (string)$item->getMTime();
        }

        if ($rows === []) {
            return '';
        }
        \sort($rows, SORT_STRING);

        return \substr(\hash('sha256', \implode("\n", $rows)), 0, 12);
    }
}

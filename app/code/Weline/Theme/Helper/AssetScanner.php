<?php
declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Theme\Helper;

use Weline\Theme\Helper\Interface\AssetScannerInterface;

/**
 * 资源扫描器
 * 
 * 职责：扫描资源目录，返回资源文件路径
 * 遵循：单一职责原则 (SRP)
 */
class AssetScanner implements AssetScannerInterface
{
    /**
     * 扫描资源目录，返回所有资源文件路径
     * 
     * @param string $directory 目录路径
     * @return array 文件路径数组
     */
    public function scanDirectory(string $directory): array
    {
        $assets = [];
        
        if (!is_dir($directory)) {
            return $assets;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $assets[] = $file->getPathname();
            }
        }

        // 按文件名排序，确保加载顺序一致
        sort($assets);

        return $assets;
    }
}

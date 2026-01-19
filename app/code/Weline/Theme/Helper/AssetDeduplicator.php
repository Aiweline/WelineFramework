<?php
declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Theme\Helper;

use Weline\Theme\Helper\Interface\AssetDeduplicatorInterface;

/**
 * 资源去重器
 * 
 * 职责：去重资源文件，确保同名文件只保留激活主题的版本
 * 遵循：单一职责原则 (SRP)
 */
class AssetDeduplicator implements AssetDeduplicatorInterface
{
    /**
     * 去重资源文件（保留激活主题的版本）
     * 
     * 如果多个主题中有同名文件，只保留激活主题的版本
     * 
     * @param array $assets 资源文件路径数组（按主题链顺序，激活主题在后）
     * @return array 去重后的资源文件路径数组
     */
    public function deduplicate(array $assets): array
    {
        $uniqueAssets = [];
        $seenFiles = [];

        // 从后往前遍历，保留最后一个出现的文件（激活主题的）
        foreach (array_reverse($assets) as $assetPath) {
            $fileName = basename($assetPath);
            
            // 如果还没见过这个文件名，添加到结果中
            if (!isset($seenFiles[$fileName])) {
                $uniqueAssets[] = $assetPath;
                $seenFiles[$fileName] = true;
            }
        }

        // 反转回来，保持正确的加载顺序
        return array_reverse($uniqueAssets);
    }
}

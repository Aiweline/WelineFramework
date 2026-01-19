<?php
declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Theme\Helper\Interface;

use Weline\Theme\Model\WelineTheme;

/**
 * 资源合并器接口
 * 
 * 职责：合并主题资源，实现同名文件以激活主题为准的机制
 * 遵循：单一职责原则 (SRP)、依赖倒置原则 (DIP)
 */
interface AssetMergerInterface
{
    /**
     * 合并主题资源（支持继承链）
     * 
     * @param string $assetType 资源类型（css/js）
     * @param string $area 区域（frontend/backend）
     * @param WelineTheme|null $theme 主题对象，如果为null则使用激活的主题
     * @return array 资源文件路径数组，按加载顺序排列
     */
    public function mergeAssets(string $assetType, string $area, ?WelineTheme $theme = null): array;
}

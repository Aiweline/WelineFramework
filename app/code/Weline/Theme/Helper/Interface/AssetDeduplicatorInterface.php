<?php
declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Theme\Helper\Interface;

/**
 * 资源去重器接口
 * 
 * 职责：去重资源文件，确保同名文件只保留激活主题的版本
 * 遵循：单一职责原则 (SRP)、接口隔离原则 (ISP)
 */
interface AssetDeduplicatorInterface
{
    /**
     * 去重资源文件（保留激活主题的版本）
     * 
     * 如果多个主题中有同名文件，只保留激活主题的版本
     * 
     * @param array $assets 资源文件路径数组（按主题链顺序，激活主题在后）
     * @return array 去重后的资源文件路径数组
     */
    public function deduplicate(array $assets): array;
}

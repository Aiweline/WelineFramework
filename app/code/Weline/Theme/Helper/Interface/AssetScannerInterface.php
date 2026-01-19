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
 * 资源扫描器接口
 * 
 * 职责：扫描资源目录，返回资源文件路径
 * 遵循：单一职责原则 (SRP)、接口隔离原则 (ISP)
 */
interface AssetScannerInterface
{
    /**
     * 扫描资源目录，返回所有资源文件路径
     * 
     * @param string $directory 目录路径
     * @return array 文件路径数组
     */
    public function scanDirectory(string $directory): array;
}

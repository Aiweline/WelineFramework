<?php
declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Theme\Helper\Interface;

use Weline\Framework\Manager\FactoryObjectInterface;
use Weline\Theme\Helper\AssetScanner;

/**
 * 资源扫描器接口工厂类
 * 
 * 职责：将接口映射到实现类，支持依赖注入
 */
class AssetScannerInterfaceFactory implements FactoryObjectInterface
{
    /**
     * 创建实例
     * 
     * @return AssetScannerInterface
     */
    public function create(): AssetScannerInterface
    {
        return new AssetScanner();
    }
}

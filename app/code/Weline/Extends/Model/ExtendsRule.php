<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Extends\Model;

use Weline\Framework\Database\AbstractModel;
use Weline\Framework\Extends\ExtendsRegistry;

/**
 * 扩展规则模型
 * 存储扩展点信息和使用关系
 */
class ExtendsRule extends AbstractModel
{
    private ExtendsRegistry $extendsRegistry;

    public function __construct(ExtendsRegistry $extendsRegistry)
    {
        parent::__construct();
        $this->extendsRegistry = $extendsRegistry;
    }

    /**
     * 获取所有模块的扩展信息
     *
     * @return array
     */
    public function getAllExtends(): array
    {
        return $this->extendsRegistry->getRegistry();
    }

    /**
     * 获取模块定义的扩展点
     *
     * @param string $moduleName 模块名
     * @return array
     */
    public function getModuleExtends(string $moduleName): array
    {
        return $this->extendsRegistry->getModuleExtends($moduleName);
    }

    /**
     * 获取扩展该模块的其他模块
     *
     * @param string $moduleName 模块名
     * @return array
     */
    public function getExtendedBy(string $moduleName): array
    {
        return $this->extendsRegistry->getExtendedBy($moduleName);
    }

    /**
     * 检查模块是否有扩展定义
     *
     * @param string $moduleName 模块名
     * @return bool
     */
    public function hasExtends(string $moduleName): bool
    {
        return $this->extendsRegistry->hasExtends($moduleName);
    }

    /**
     * 检查模块是否被其他模块扩展
     *
     * @param string $moduleName 模块名
     * @return bool
     */
    public function isExtendedBy(string $moduleName): bool
    {
        return $this->extendsRegistry->isExtendedBy($moduleName);
    }
}


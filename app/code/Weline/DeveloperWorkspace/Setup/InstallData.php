<?php

declare(strict_types=1);

namespace Weline\DeveloperWorkspace\Setup;

use Weline\DeveloperWorkspace\Model\Document\Catalog;
use Weline\Framework\Manager\ObjectManager;

/**
 * 安装默认目录和文档种子数据
 * 通过 Model 插入，跨 MySQL/PostgreSQL 兼容，禁止直接使用 SQL 方言。
 */
class InstallData
{
    public function install(): void
    {
        /** @var Catalog $catalog */
        $catalog = ObjectManager::getInstance(Catalog::class);

        if ($catalog->clear()->select()->count() > 0) {
            return;
        }

        $this->insertCatalog($catalog);
    }

    private function insertCatalog(Catalog $catalog): void
    {
        $roots = [
            ['name' => __('前言'), 'description' => __('说在前面'), 'level' => 1, 'position' => 0, 'is_active' => 1],
            ['name' => __('安装'), 'description' => __('安装文档'), 'level' => 1, 'position' => 1, 'is_active' => 1],
            ['name' => __('快速开始'), 'description' => __('框架模组'), 'level' => 1, 'position' => 2, 'is_active' => 1],
            ['name' => __('框架规范'), 'description' => __('框架类的规范'), 'level' => 1, 'position' => 3, 'is_active' => 1],
        ];

        $quickStartId = null;
        foreach ($roots as $r) {
            $catalog->clear()
                ->forceCheck(false)
                ->setData(Catalog::fields_NAME, $r['name'])
                ->setData(Catalog::fields_DESCRIPTION, $r['description'])
                ->setData(Catalog::fields_level, $r['level'])
                ->setData(Catalog::fields_PID, 0)
                ->setData(Catalog::fields_position, $r['position'])
                ->setData(Catalog::fields_is_active, $r['is_active'])
                ->setData(Catalog::fields_is_system, 0)
                ->save();
            if ($r['name'] === __('快速开始')) {
                $quickStartId = (int) $catalog->getId();
            }
        }

        if ($quickStartId) {
            $children = [
                ['name' => 'Model', 'description' => __('模型文档'), 'position' => 0, 'is_active' => 0],
                ['name' => 'Controller', 'description' => __('使用控制器'), 'position' => 1, 'is_active' => 1],
                ['name' => 'Event', 'description' => __('模组事件'), 'position' => 2, 'is_active' => 1],
                ['name' => 'Plugin', 'description' => __('WelineFramework插件功能'), 'position' => 3, 'is_active' => 1],
            ];
            foreach ($children as $c) {
                $catalog->clear()
                    ->forceCheck(false)
                    ->setData(Catalog::fields_NAME, $c['name'])
                    ->setData(Catalog::fields_DESCRIPTION, $c['description'])
                    ->setData(Catalog::fields_level, 2)
                    ->setData(Catalog::fields_PID, $quickStartId)
                    ->setData(Catalog::fields_position, $c['position'])
                    ->setData(Catalog::fields_is_active, $c['is_active'])
                    ->setData(Catalog::fields_is_system, 0)
                    ->save();
            }
        }
    }
}

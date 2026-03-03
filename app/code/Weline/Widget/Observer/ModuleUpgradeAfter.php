<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Widget\Observer;

use Weline\Framework\App\Env;
use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Widget\Service\ParamSchemaRegistry;
use Weline\Widget\Service\WidgetRegistry;

/**
 * 模块升级后事件监听器
 * 监听模块升级事件，自动收集部件与 ParamSchema 并生成注册表
 */
class ModuleUpgradeAfter implements ObserverInterface
{
    public function execute(Event &$event): void
    {
        try {
            /** @var WidgetRegistry $registry */
            $registry = ObjectManager::getInstance(WidgetRegistry::class);
            $registry->refresh();

            /** @var ParamSchemaRegistry $paramSchemaRegistry */
            $paramSchemaRegistry = ObjectManager::getInstance(ParamSchemaRegistry::class);
            $paramSchemaRegistry->refresh();
        } catch (\Exception $e) {
            w_log_error('模块升级后收集注册表时出错: ' . $e->getMessage(), [], 'WidgetObserver');
        }
    }
}

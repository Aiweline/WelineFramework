<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Framework\Extends\Observer;

use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Framework\Extends\ExtendsRegistry;
use Weline\Framework\Manager\ObjectManager;

/**
 * 系统更新后观察者
 * 监听 Weline_Framework_Setup::upgrade_after 事件，自动扫描并更新扩展信息
 */
class SetupUpgradeAfter implements ObserverInterface
{
    /**
     * @inheritDoc
     */
    public function execute(Event &$event): void
    {
        try {
            // 延迟加载 ExtendsRegistry，避免在事件系统初始化时的循环依赖
            /** @var ExtendsRegistry $extendsRegistry */
            $extendsRegistry = ObjectManager::getInstance(ExtendsRegistry::class);
            // 刷新扩展注册表
            $extendsRegistry->refresh();
        } catch (\Exception $e) {
            // 记录错误但不中断系统更新流程
            error_log("扩展注册表更新失败: " . $e->getMessage());
        }
    }
}


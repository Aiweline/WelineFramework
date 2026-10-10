<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 作者：Admin
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 * 日期：2024/01/01 00:00:00
 */

namespace Weline\I18n\Observer;

use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\I18n\Service\Catalog\DisplayNameCartesianSeeder;

/**
 * 系统更新后：用种子包补齐国家/地区库存缺口（不碰安装/激活态，不走第三方 Intl）。
 * 监听 Weline_Framework_System::system_update_after 和 Weline_Framework_Module::module_install_after。
 */
class SystemUpdateObserver implements ObserverInterface
{
    /**
     * @inheritDoc
     */
    public function execute(Event &$event): void
    {
        try {
            /** @var DisplayNameCartesianSeeder $seeder */
            $seeder = ObjectManager::getInstance(DisplayNameCartesianSeeder::class);
            $seeder->syncInventoryGaps(false);

            $eventName = $event->getName();
            $source = strpos($eventName, 'module_install') !== false ? '模块安装后' : '系统更新后';
            w_log_info("I18n: {$source}已用种子包补齐国家/地区库存缺口", [], 'i18n');
        } catch (\Exception $e) {
            w_log_error('I18n: 种子包库存补洞失败 - ' . $e->getMessage(), [], 'i18n');
        }
    }
}

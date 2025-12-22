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
use Weline\I18n\Service\CountryUpdateService;

/**
 * 系统更新后自动检测和更新国家信息观察者
 * 监听 Weline_Framework_System::system_update_after 和 Weline_Framework_Module::module_install_after 事件
 */
class SystemUpdateObserver implements ObserverInterface
{
    /**
     * @inheritDoc
     */
    public function execute(Event &$event): void
    {
        try {
            /** @var CountryUpdateService $countryUpdateService */
            $countryUpdateService = ObjectManager::getInstance(CountryUpdateService::class);
            
            // 检查并自动更新国家信息
            $result = $countryUpdateService->checkAndUpdateCountries();
            
            $eventName = $event->getName();
            $source = strpos($eventName, 'module_install') !== false ? '模块安装后' : '系统更新后';
            
            if ($result['updated']) {
                error_log("I18n: {$source}自动更新了 " . $result['updated_count'] . ' 个国家信息');
            } else {
                error_log("I18n: {$source}国家信息检查完成 - " . $result['message']);
            }
            
        } catch (\Exception $e) {
            error_log('I18n: 国家信息自动更新失败 - ' . $e->getMessage());
        }
    }
}

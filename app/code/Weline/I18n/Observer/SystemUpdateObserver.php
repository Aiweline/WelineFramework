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

use Weline\Framework\Manager\ObjectManager;
use Weline\I18n\Service\CountryUpdateService;

class SystemUpdateObserver
{
    /**
     * 系统更新后自动检测和更新国家信息
     * 
     * @param \Weline\Framework\Event\Event $event
     * @return void
     */
    public function afterSystemUpdate(\Weline\Framework\Event\Event $event): void
    {
        try {
            /** @var CountryUpdateService $countryUpdateService */
            $countryUpdateService = ObjectManager::getInstance(CountryUpdateService::class);
            
            // 检查并自动更新国家信息
            $result = $countryUpdateService->checkAndUpdateCountries();
            
            if ($result['updated']) {
                error_log('I18n: 系统更新后自动更新了 ' . $result['updated_count'] . ' 个国家信息');
            } else {
                error_log('I18n: 系统更新后国家信息检查完成 - ' . $result['message']);
            }
            
        } catch (\Exception $e) {
            error_log('I18n: 系统更新后国家信息自动更新失败 - ' . $e->getMessage());
        }
    }

    /**
     * 模块安装后自动检测和更新国家信息
     * 
     * @param \Weline\Framework\Event\Event $event
     * @return void
     */
    public function afterModuleInstall(\Weline\Framework\Event\Event $event): void
    {
        try {
            /** @var CountryUpdateService $countryUpdateService */
            $countryUpdateService = ObjectManager::getInstance(CountryUpdateService::class);
            
            // 检查并自动更新国家信息
            $result = $countryUpdateService->checkAndUpdateCountries();
            
            if ($result['updated']) {
                error_log('I18n: 模块安装后自动更新了 ' . $result['updated_count'] . ' 个国家信息');
            }
            
        } catch (\Exception $e) {
            error_log('I18n: 模块安装后国家信息自动更新失败 - ' . $e->getMessage());
        }
    }
}

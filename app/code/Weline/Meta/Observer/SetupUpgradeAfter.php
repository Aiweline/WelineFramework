<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 作者：Admin
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Meta\Observer;

use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Meta\Console\Meta\ScanConvention;

/**
 * 系统更新后自动扫描元数据 Observer
 * 
 * 功能：
 * - 监听系统升级完成事件（Framework_Setup::upgrade_after）
 * - 自动扫描所有模块的 @meta.json 规约文件
 * - 存储元数据到数据库
 * 
 * 注意：此观察者只在系统升级完成后执行一次，不会在每个模块升级时重复执行
 */
class SetupUpgradeAfter implements ObserverInterface
{
    /**
     * 静态变量：记录是否已经扫描过元数据
     * 防止事件被多次触发时重复扫描
     */
    private static bool $hasScanned = false;
    
    /**
     * 执行 Observer 逻辑
     * 
     * @param Event $event
     * @return void
     */
    public function execute(Event &$event): void
    {
        try {
            // 如果已经扫描过，直接返回，避免重复扫描
            if (self::$hasScanned) {
                return;
            }
            
            // 获取元数据扫描命令实例
            /** @var ScanConvention $scanCommand */
            $scanCommand = ObjectManager::getInstance(ScanConvention::class);
            
            // 执行扫描（不指定模块，扫描所有模块）
            $scanCommand->execute([], []);
            
            // 标记已扫描
            self::$hasScanned = true;
            
        } catch (\Throwable $e) {
            // 捕获所有异常，静默处理，避免影响系统升级流程
            error_log("元数据扫描失败: " . $e->getMessage() . "\n" . $e->getTraceAsString());
            // 不输出错误信息，避免干扰升级流程
        }
    }
}


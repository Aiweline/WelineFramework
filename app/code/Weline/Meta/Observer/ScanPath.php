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
use Weline\Meta\Service\Scanner;

/**
 * 元数据路径扫描观察者
 * 
 * 监听 Weline_Meta::scan_path 事件，执行元数据扫描任务
 */
class ScanPath implements ObserverInterface
{
    /**
     * 执行扫描任务
     * 
     * @param Event $event
     * @return void
     */
    public function execute(Event &$event): void
    {
        $data = $event->getData();
        
        // 检查必需参数
        if (empty($data['scan_path'])) {
            return; // 缺少扫描路径，跳过
        }
        
        $scanPath = $data['scan_path'];
        $namespace = $data['namespace'] ?? 'theme';
        $strictMode = $data['strict_mode'] ?? true;
        
        try {
            /** @var Scanner $scanner */
            $scanner = ObjectManager::getInstance(Scanner::class);
            $results = $scanner->scanPath($scanPath, $namespace, $strictMode);
            
            // 将结果添加到事件数据中，供其他观察者使用
            $event->setData('scan_results', $results);
            
            // 记录成功和失败的数量
            $successCount = count($results['success'] ?? []);
            $failedCount = count($results['failed'] ?? []);
            $skippedCount = count($results['skipped'] ?? []);
            
            // 可以通过日志记录扫描结果（可选）
            // 扫描结果已存储在事件数据中，供其他观察者使用
        } catch (\Exception $e) {
            // 记录错误，但不中断事件流程
            error_log("元数据扫描失败: 路径={$scanPath}, 错误=" . $e->getMessage());
        }
    }
}


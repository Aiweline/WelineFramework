<?php

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Framework\Uninstall\Observer;

use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Uninstall\UninstallService;

/**
 * 卸载服务观察者
 * 
 * 监听卸载通知事件，执行卸载操作
 */
class UninstallObserver implements ObserverInterface
{
    /**
     * 执行观察者逻辑
     * 
     * @param Event $event
     * @return void
     */
    public function execute(Event $event)
    {
        $data = $event->getData();
        
        // 获取卸载类型和名称
        $type = $data['type'] ?? '';
        $name = $data['name'] ?? '';
        $autoBackup = $data['auto_backup'] ?? true;
        
        if (empty($type) || empty($name)) {
            $data['uninstall_result'] = [
                'success' => false,
                'message' => __('缺少必要参数：type 和 name'),
            ];
            return;
        }
        
        // 验证卸载类型
        $validTypes = [
            UninstallService::TYPE_MODULE,
            UninstallService::TYPE_THEME,
            UninstallService::TYPE_I18N,
        ];
        
        if (!in_array($type, $validTypes)) {
            $data['uninstall_result'] = [
                'success' => false,
                'message' => __('不支持的卸载类型：%{1}。支持的类型：%{2}', [
                    $type,
                    implode(', ', $validTypes)
                ]),
            ];
            return;
        }
        
        try {
            // 获取卸载服务
            $uninstallService = ObjectManager::getInstance(UninstallService::class);
            
            // 执行卸载
            $result = $uninstallService->uninstall($type, $name, $autoBackup);
            
            // 将结果返回到事件数据中
            $data['uninstall_result'] = $result;
            
        } catch (\Exception $e) {
            $data['uninstall_result'] = [
                'success' => false,
                'type' => $type,
                'name' => $name,
                'message' => __('卸载异常：%{1}', [$e->getMessage()]),
                'steps' => [
                    [
                        'step' => 'error',
                        'message' => $e->getMessage(),
                        'time' => date('Y-m-d H:i:s'),
                        'success' => false,
                    ]
                ],
                'start_time' => date('Y-m-d H:i:s'),
                'end_time' => date('Y-m-d H:i:s'),
            ];
        }
    }
}


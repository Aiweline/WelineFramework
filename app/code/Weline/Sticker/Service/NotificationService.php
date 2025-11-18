<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Sticker\Service;

use Weline\Framework\Event\EventsManager;
use Weline\Framework\Manager\ObjectManager;

/**
 * 通知服务
 * 发送系统通知（使用 Weline_Framework::msg 事件）
 */
class NotificationService
{
    private ?EventsManager $eventsManager = null;
    
    /**
     * 获取事件管理器实例（延迟加载）
     *
     * @return EventsManager
     */
    private function getEventsManager(): EventsManager
    {
        if ($this->eventsManager === null) {
            $this->eventsManager = ObjectManager::getInstance(EventsManager::class);
        }
        return $this->eventsManager;
    }
    
    /**
     * 发送系统消息通知
     *
     * @param string $title 标题
     * @param string $content 内容
     * @param string $icon 图标名称
     * @return void
     */
    private function sendSystemMessage(string $title, string $content, string $icon = 'ri-error-warning-line'): void
    {
        try {
            $this->getEventsManager()->dispatch('Weline_Framework::msg', [
                'data' => [
                    'title' => $title,
                    'content' => $content,
                    'is_read' => false,
                    'is_icon' => 1,
                    'is_img' => 0,
                    'avatar' => $icon
                ]
            ]);
        } catch (\Exception $e) {
            error_log("发送 Sticker 系统消息失败: " . $e->getMessage());
        }
    }
    /**
     * 发送 Sticker 规则失效通知
     *
     * @param string $targetModule 目标模块
     * @param string $targetFile 目标文件
     * @param string $sourceModule 来源模块
     * @param string $stickerFile Sticker 文件
     * @param string $reason 失效原因
     * @return void
     */
    public function notifyRuleInvalid(
        string $targetModule,
        string $targetFile,
        string $sourceModule,
        string $stickerFile,
        string $reason
    ): void {
        $this->sendSystemMessage(
            __('Sticker 规则失效警告'),
            __('Sticker 规则已失效') . "\n\n" . 
            __('目标模块') . ": {$targetModule}\n" . 
            __('目标文件') . ": {$targetFile}\n" . 
            __('来源模块') . ": {$sourceModule}\n" . 
            __('Sticker 文件') . ": {$stickerFile}\n" . 
            __('原因') . ": {$reason}",
            'ri-error-warning-line'
        );
    }

    /**
     * 发送目标代码未找到通知
     *
     * @param string $targetModule 目标模块
     * @param string $targetFile 目标文件
     * @param string $sourceModule 来源模块
     * @param string $stickerFile Sticker 文件
     * @param string $targetCode 目标代码片段
     * @return void
     */
    public function notifyTargetCodeNotFound(
        string $targetModule,
        string $targetFile,
        string $sourceModule,
        string $stickerFile,
        string $targetCode
    ): void {
        $targetCodePreview = substr($targetCode ?? '', 0, 200);
        $this->sendSystemMessage(
            __('Sticker 目标代码未找到'),
            __('Sticker 规则无法找到目标代码') . "\n\n" . 
            __('目标模块') . ": {$targetModule}\n" . 
            __('目标文件') . ": {$targetFile}\n" . 
            __('来源模块') . ": {$sourceModule}\n" . 
            __('Sticker 文件') . ": {$stickerFile}\n" . 
            __('目标代码') . ": " . ($targetCodePreview ?: __('（空）')),
            'ri-search-line'
        );
    }

    /**
     * 发送冲突检测通知
     *
     * @param string $targetModule 目标模块
     * @param string $targetFile 目标文件
     * @param array $conflicts 冲突信息
     * @return void
     */
    public function notifyConflict(
        string $targetModule,
        string $targetFile,
        array $conflicts
    ): void {
        $conflictDetails = [];
        foreach ($conflicts as $conflict) {
            $conflictDetails[] = sprintf(
                __('来源模块') . ": %s, " . __('Sticker 文件') . ": %s, " . __('位置') . ": %s",
                $conflict['source_module'] ?? __('未知'),
                $conflict['sticker_file'] ?? __('未知'),
                $conflict['position'] ?? __('未知')
            );
        }

        $this->sendSystemMessage(
            __('Sticker 冲突检测'),
            __('检测到 Sticker 规则冲突') . "\n\n" . 
            __('目标模块') . ": {$targetModule}\n" . 
            __('目标文件') . ": {$targetFile}\n\n" . 
            __('冲突详情') . ":\n" . implode("\n", $conflictDetails),
            'ri-alert-line'
        );
    }
}


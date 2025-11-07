<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Sticker\Service;

use Weline\Admin\Model\System\SystemNotification;
use Weline\Framework\Manager\ObjectManager;

/**
 * 通知服务
 * 发送系统通知
 */
class NotificationService
{
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
        try {
            /** @var SystemNotification $notification */
            $notification = ObjectManager::getInstance(SystemNotification::class);
            $notification->setTitle('Sticker 规则失效警告')
                ->setContent("Sticker 规则已失效\n\n目标模块: {$targetModule}\n目标文件: {$targetFile}\n来源模块: {$sourceModule}\nSticker 文件: {$stickerFile}\n原因: {$reason}")
                ->setIsRead(false)
                ->setIsIcon(1)
                ->setIsImg(0)
                ->setAvatar('ri-error-warning-line')
                ->save();
        } catch (\Exception $e) {
            error_log("发送 Sticker 通知失败: " . $e->getMessage());
        }
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
        try {
            /** @var SystemNotification $notification */
            $notification = ObjectManager::getInstance(SystemNotification::class);
            $notification->setTitle('Sticker 目标代码未找到')
                ->setContent("Sticker 规则无法找到目标代码\n\n目标模块: {$targetModule}\n目标文件: {$targetFile}\n来源模块: {$sourceModule}\nSticker 文件: {$stickerFile}\n目标代码: " . substr($targetCode, 0, 200))
                ->setIsRead(false)
                ->setIsIcon(1)
                ->setIsImg(0)
                ->setAvatar('ri-search-line')
                ->save();
        } catch (\Exception $e) {
            error_log("发送 Sticker 通知失败: " . $e->getMessage());
        }
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
        try {
            $conflictDetails = [];
            foreach ($conflicts as $conflict) {
                $conflictDetails[] = sprintf(
                    "来源模块: %s, Sticker 文件: %s, 位置: %s",
                    $conflict['source_module'] ?? 'unknown',
                    $conflict['sticker_file'] ?? 'unknown',
                    $conflict['position'] ?? 'unknown'
                );
            }

            /** @var SystemNotification $notification */
            $notification = ObjectManager::getInstance(SystemNotification::class);
            $notification->setTitle('Sticker 冲突检测')
                ->setContent("检测到 Sticker 规则冲突\n\n目标模块: {$targetModule}\n目标文件: {$targetFile}\n\n冲突详情:\n" . implode("\n", $conflictDetails))
                ->setIsRead(false)
                ->setIsIcon(1)
                ->setIsImg(0)
                ->setAvatar('ri-alert-line')
                ->save();
        } catch (\Exception $e) {
            error_log("发送 Sticker 通知失败: " . $e->getMessage());
        }
    }
}


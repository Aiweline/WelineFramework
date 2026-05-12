<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Geo\Console\Command;

use Weline\Framework\Console\CommandInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Geo\Model\Feed;
use Weline\Geo\Model\Platform;
use Weline\Geo\Model\PushLog;
use Weline\Geo\Service\PushService;

/**
 * 推送Feed命令
 * 
 * @package Weline_Geo
 */
class PushFeed implements CommandInterface
{
    /**
     * @inheritDoc
     */
    public function execute(array $args = [], array $data = []): mixed
    {
        $feedId = $args[0] ?? null;
        $platformId = $args[1] ?? null;

        if (!$feedId) {
            echo "用法: php bin/m geo:feed:push <feed_id> [platform_id]\n";
            echo "示例: php bin/m geo:feed:push 1 1\n";
            echo "       php bin/m geo:feed:push 1 (推送到所有平台)\n";
            return false;
        }

        try {
            /** @var Feed $feedModel */
            $feedModel = ObjectManager::getInstance(Feed::class);
            $feed = $feedModel->load($feedId);

            if (!$feed->getId()) {
                echo "错误: Feed ID {$feedId} 不存在\n";
                return false;
            }

            // 检查是否启用自动推送（Cron任务时）
            if (!$feed->isAutoPush()) {
                echo "提示: Feed ID {$feedId} 已关闭自动推送，跳过推送\n";
                return true; // 返回true表示正常，只是跳过
            }

            // 检查是否启用
            if (!$feed->isEnabled()) {
                echo "提示: Feed ID {$feedId} 未启用，跳过推送\n";
                return true;
            }

            /** @var PushService $pushService */
            $pushService = ObjectManager::getInstance(PushService::class);

            if ($platformId) {
                // 推送到指定平台
                /** @var Platform $platformModel */
                $platformModel = ObjectManager::getInstance(Platform::class);
                $platform = $platformModel->load($platformId);

                if (!$platform->getId()) {
                    echo "错误: Platform ID {$platformId} 不存在\n";
                    return false;
                }

                $result = $pushService->pushFeed($feed, $platform, null, PushLog::TYPE_SCHEDULED);

                if ($result->success) {
                    echo "推送成功！\n";
                    echo "平台: {$platform->getData(Platform::schema_fields_PLATFORM_NAME)}\n";
                    echo "推送条目数: {$result->itemsCount}\n";
                } else {
                    echo "推送失败: {$result->message}\n";
                    return false;
                }
            } else {
                // 推送到所有平台
                /** @var Platform $platformModel */
                $platformModel = ObjectManager::getInstance(Platform::class);
                $platforms = $platformModel
                    ->where(Platform::schema_fields_IS_ENABLED, 1)
                    ->select()
                    ->fetchArray();

                $successCount = 0;
                $failCount = 0;

                foreach ($platforms as $platformData) {
                    $platform = $platformModel->load($platformData['id']);
                    $result = $pushService->pushFeed($feed, $platform, null, PushLog::TYPE_SCHEDULED);

                    if ($result->success) {
                        $successCount++;
                        echo "✓ {$platform->getData(Platform::schema_fields_PLATFORM_NAME)}: 成功\n";
                    } else {
                        $failCount++;
                        echo "✗ {$platform->getData(Platform::schema_fields_PLATFORM_NAME)}: {$result->message}\n";
                    }
                }

                echo "\n推送完成：成功 {$successCount} 个，失败 {$failCount} 个\n";
            }

            return true;
        } catch (\Exception $e) {
            echo "错误: {$e->getMessage()}\n";
            return false;
        }
    }

    /**
     * @inheritDoc
     */
    public function tip(): string
    {
        return '推送Feed到平台';
    }

    /**
     * @inheritDoc
     */
    public function help(): array|string
    {
        return '推送Feed到平台命令。用法: php bin/m geo:feed:push <feed_id> [platform_id]';
    }
}


<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\GenerativeEngineOptimization\Queue;

use Weline\Framework\Manager\ObjectManager;
use Weline\GenerativeEngineOptimization\Model\Feed;
use Weline\GenerativeEngineOptimization\Model\Platform;
use Weline\GenerativeEngineOptimization\Service\PushService;
use Weline\Queue\Model\Queue;
use Weline\Queue\QueueInterface;

/**
 * Feed推送队列处理器
 * 
 * @package Weline_GenerativeEngineOptimization
 */
class FeedPushQueue implements QueueInterface
{
    /**
     * 队列名称
     * 
     * @return string
     */
    public function name(): string
    {
        return 'GEO Feed推送队列';
    }

    /**
     * 队列类型所需属性
     * 
     * @return array
     */
    public function attributes(): array
    {
        // Feed推送队列不需要额外属性，数据在content中
        return [];
    }

    /**
     * 提示信息
     * 
     * @return string
     */
    public function tip(): string
    {
        return '推送Feed到AI搜索引擎平台';
    }

    /**
     * 执行队列任务
     * 
     * @param Queue $queue
     * @return string
     */
    public function execute(Queue &$queue): string
    {
        try {
            $content = json_decode($queue->getContent(), true);
            
            if (empty($content['feed_id'])) {
                $queue->setResult('错误：缺少feed_id参数');
                $queue->setStatus(Queue::status_error);
                $queue->save();
                return '错误：缺少feed_id参数';
            }

            $feedId = (int)$content['feed_id'];
            $platformIds = $content['platform_ids'] ?? [];
            $pushType = $content['push_type'] ?? 'scheduled';

            /** @var Feed $feedModel */
            $feedModel = ObjectManager::getInstance(Feed::class);
            $feed = $feedModel->load($feedId);

            if (!$feed->getId()) {
                $queue->setResult("错误：Feed ID {$feedId} 不存在");
                $queue->setStatus(Queue::status_error);
                $queue->save();
                return "错误：Feed ID {$feedId} 不存在";
            }

            // 检查是否启用自动推送
            if ($pushType === 'scheduled' && !$feed->isAutoPush()) {
                $queue->setResult("Feed已关闭自动推送，跳过推送");
                $queue->setStatus(Queue::status_done);
                $queue->save();
                return "Feed已关闭自动推送，跳过推送";
            }

            /** @var PushService $pushService */
            $pushService = ObjectManager::getInstance(PushService::class);

            $results = [];
            $successCount = 0;
            $failCount = 0;

            if (empty($platformIds)) {
                // 推送到所有启用的平台
                /** @var Platform $platformModel */
                $platformModel = ObjectManager::getInstance(Platform::class);
                $platforms = $platformModel
                    ->where(Platform::fields_IS_ENABLED, 1)
                    ->select()
                    ->fetchArray();

                foreach ($platforms as $platformData) {
                    $platform = $platformModel->load($platformData['id']);
                    $result = $pushService->pushFeedSync($feed, $platform, null, $pushType);
                    
                    if ($result->success) {
                        $successCount++;
                        $results[] = "平台 {$platform->getData(Platform::fields_PLATFORM_NAME)}: 成功";
                    } else {
                        $failCount++;
                        $results[] = "平台 {$platform->getData(Platform::fields_PLATFORM_NAME)}: 失败 - {$result->message}";
                    }
                }
            } else {
                // 推送到指定平台
                /** @var Platform $platformModel */
                $platformModel = ObjectManager::getInstance(Platform::class);
                
                foreach ($platformIds as $platformId) {
                    $platform = $platformModel->load((int)$platformId);
                    if (!$platform->getId()) {
                        $failCount++;
                        $results[] = "平台 ID {$platformId}: 不存在";
                        continue;
                    }

                    $result = $pushService->pushFeedSync($feed, $platform, null, $pushType);
                    
                    if ($result->success) {
                        $successCount++;
                        $results[] = "平台 {$platform->getData(Platform::fields_PLATFORM_NAME)}: 成功";
                    } else {
                        $failCount++;
                        $results[] = "平台 {$platform->getData(Platform::fields_PLATFORM_NAME)}: 失败 - {$result->message}";
                    }
                }
            }

            $resultMsg = "推送完成。成功: {$successCount}, 失败: {$failCount}\n" . implode("\n", $results);
            $queue->setResult($resultMsg);
            $queue->setStatus($failCount === 0 ? Queue::status_done : Queue::status_error);
            $queue->save();

            return $resultMsg;
        } catch (\Exception $e) {
            $queue->setResult("错误：" . $e->getMessage());
            $queue->setStatus(Queue::status_error);
            $queue->save();
            return "错误：" . $e->getMessage();
        }
    }

    /**
     * 验证任务数据
     * 
     * @param Queue $queue
     * @return bool
     */
    public function validate(Queue &$queue): bool
    {
        $content = json_decode($queue->getContent(), true);
        
        if (empty($content['feed_id'])) {
            $queue->setResult('验证失败：缺少feed_id参数');
            return false;
        }

        if (!is_numeric($content['feed_id'])) {
            $queue->setResult('验证失败：feed_id必须是数字');
            return false;
        }

        return true;
    }
}


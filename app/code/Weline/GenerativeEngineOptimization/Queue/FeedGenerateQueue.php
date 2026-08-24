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
use Weline\GenerativeEngineOptimization\Service\FeedGeneratorService;
use Weline\Queue\Model\Queue;
use Weline\Queue\QueueInterface;

/**
 * Feed生成队列处理器
 * 
 * @package Weline_GenerativeEngineOptimization
 */
class FeedGenerateQueue implements QueueInterface
{
    /**
     * 队列名称
     * 
     * @return string
     */
    public function name(): string
    {
        return 'GEO Feed生成队列';
    }

    /**
     * 队列类型所需属性
     * 
     * @return array
     */
    public function attributes(): array
    {
        // Feed生成队列不需要额外属性，数据在content中
        return [];
    }

    /**
     * 提示信息
     * 
     * @return string
     */
    public function tip(): string
    {
        return '生成Feed文件并保存到pub目录';
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
            $format = $content['format'] ?? 'json_feed';
            $force = $content['force'] ?? false;

            /** @var Feed $feedModel */
            $feedModel = ObjectManager::getInstance(Feed::class);
            $feed = $feedModel->load($feedId);

            if (!$feed->getId()) {
                $queue->setResult("错误：Feed ID {$feedId} 不存在");
                $queue->setStatus(Queue::status_error);
                $queue->save();
                return "错误：Feed ID {$feedId} 不存在";
            }

            // 检查是否需要生成（如果不强制且已生成，跳过）
            if (!$force && $feed->getData(Feed::schema_fields_LAST_GENERATED_AT)) {
                $lastGenerated = (int)$feed->getData(Feed::schema_fields_LAST_GENERATED_AT);
                $feedUrl = $feed->getData(Feed::schema_fields_FEED_URL);
                if ($lastGenerated > 0 && !empty($feedUrl)) {
                    $queue->setResult("Feed已存在，跳过生成。URL: {$feedUrl}");
                    $queue->setStatus(Queue::status_done);
                    $queue->save();
                    return "Feed已存在，跳过生成";
                }
            }

            /** @var FeedGeneratorService $feedGenerator */
            $feedGenerator = ObjectManager::getInstance(FeedGeneratorService::class);
            $relativeUrl = $feedGenerator->generateAndSaveFeed($feed, $format);

            $queue->setResult("Feed生成成功。URL: {$relativeUrl}");
            $queue->setStatus(Queue::status_done);
            $queue->save();

            return "Feed生成成功：{$relativeUrl}";
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


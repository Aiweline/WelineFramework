<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Seo\Cron;

use Weline\Framework\Cron\CronTaskInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Seo\Model\SeoTask;
use Weline\Seo\Service\TaskProcessor;

/**
 * SEO Feed 生成任务
 * 
 * 定时消费Feed生成任务队列，批量处理
 * 
 * @package Weline_Seo
 */
class FeedGenerator implements CronTaskInterface
{
    private ObjectManager $objectManager;

    public function __construct(ObjectManager $objectManager)
    {
        $this->objectManager = $objectManager;
    }

    /**
     * 任务名称
     */
    public function name(): string
    {
        return 'SEO Feed生成任务';
    }

    /**
     * 执行名称
     */
    public function execute_name(): string
    {
        return 'seo_feed_generator';
    }

    /**
     * 任务描述
     */
    public function tip(): string
    {
        return '定时消费Feed生成任务队列，批量处理SEO Feed生成，避免阻塞主流程';
    }

    /**
     * Cron时间表达式 - 每10分钟执行一次
     */
    public function cron_time(): string
    {
        return '*/10 * * * *';
    }

    /**
     * 执行任务
     */
    public function execute(): string
    {
        try {
            /** @var SeoTask $taskModel */
            $taskModel = $this->objectManager->getInstance(SeoTask::class);
            
            // 获取待处理的Feed生成任务（每次处理100个）
            $tasks = $taskModel->getPendingTasks(SeoTask::TASK_TYPE_FEED_GENERATE, 100);
            
            if (empty($tasks)) {
                return '没有待处理的Feed生成任务';
            }

            /** @var TaskProcessor $taskProcessor */
            $taskProcessor = $this->objectManager->getInstance(TaskProcessor::class);
            
            $successCount = 0;
            $errorCount = 0;

            foreach ($tasks as $taskData) {
                $task = $taskModel->reset()->load($taskData['task_id']);
                
                if (!$task->getId() || !$task->isPending()) {
                    continue;
                }

                // 标记为处理中
                $task->markProcessing();

                // 处理任务
                $success = $taskProcessor->process($task);
                
                if ($success) {
                    $successCount++;
                } else {
                    $errorCount++;
                }
            }

            return sprintf(
                'Feed生成任务处理完成：成功 %d 个，失败 %d 个，共处理 %d 个任务',
                $successCount,
                $errorCount,
                count($tasks)
            );

        } catch (\Exception $e) {
            return 'Feed生成任务执行失败：' . $e->getMessage();
        }
    }

    /**
     * @inheritDoc
     */
    public function unlock_timeout(int $minute = 30): int
    {
        return $minute; // 默认30分钟超时解锁
    }
}


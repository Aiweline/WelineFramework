<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Seo\Cron;

use Weline\Cron\CronTaskInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Seo\Model\SeoTask;
use Weline\Seo\Model\SeoSubject;
use Weline\Seo\Service\TaskProcessor;

/**
 * SEO URL推送任务
 * 
 * 按周期批量推送URL到搜索引擎，避免频繁API调用
 * 
 * @package Weline_Seo
 */
class UrlPusher implements CronTaskInterface
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
        return 'SEO URL推送任务';
    }

    /**
     * 执行名称
     */
    public function execute_name(): string
    {
        return 'seo_url_pusher';
    }

    /**
     * 任务描述
     */
    public function tip(): string
    {
        return '按周期批量推送URL到搜索引擎，支持多平台推送，避免频繁API调用';
    }

    /**
     * Cron时间表达式 - 每天凌晨2点执行
     */
    public function cron_time(): string
    {
        return '0 2 * * *';
    }

    /**
     * 执行任务
     */
    public function execute(): string
    {
        try {
            /** @var SeoSubject $subjectModel */
            $subjectModel = $this->objectManager->getInstance(SeoSubject::class);
            
            // 获取需要推送的SEO主体（启用状态，有URL，最近7天内有更新）
            $subjects = $subjectModel->reset()
                ->where(SeoSubject::fields_STATUS, SeoSubject::STATUS_ENABLED)
                ->where(SeoSubject::fields_URL, '', '!=')
                ->where(SeoSubject::fields_UPDATED_AT, date('Y-m-d H:i:s', strtotime('-7 days')), '>=')
                ->select()
                ->fetchArray();

            if (empty($subjects)) {
                return '没有需要推送的URL';
            }

            /** @var SeoTask $taskModel */
            $taskModel = $this->objectManager->getInstance(SeoTask::class);
            
            // 按平台分组创建推送任务
            $platforms = ['google', 'baidu', 'bing', '360', 'sogou', 'shenma'];
            $taskCount = 0;

            foreach ($platforms as $platform) {
                // 检查是否已有待处理的推送任务
                $existingTask = $taskModel->reset()
                    ->where(SeoTask::fields_TASK_TYPE, SeoTask::TASK_TYPE_PUSH_URLS)
                    ->where(SeoTask::fields_STATUS, [SeoTask::STATUS_PENDING, SeoTask::STATUS_PROCESSING], 'IN')
                    ->where(SeoTask::fields_PAYLOAD, '%"' . $platform . '"%', 'LIKE')
                    ->find()
                    ->fetch();

                if ($existingTask->getId()) {
                    // 已有待处理任务，跳过
                    continue;
                }

                // 收集URL列表
                $urls = [];
                foreach ($subjects as $subject) {
                    if (!empty($subject['url'])) {
                        $urls[] = $subject['url'];
                    }
                }

                if (empty($urls)) {
                    continue;
                }

                // 创建推送任务（批量推送，最多100个URL）
                $urlChunks = array_chunk($urls, 100);
                foreach ($urlChunks as $chunk) {
                    $payload = [
                        'urls' => $chunk,
                        'platforms' => [$platform],
                    ];

                    $taskModel->reset()
                        ->setTaskType(SeoTask::TASK_TYPE_PUSH_URLS)
                        ->setSubjectType('batch')
                        ->setSubjectId(0)
                        ->setPayloadArray($payload)
                        ->setPriority(SeoTask::PRIORITY_NORMAL)
                        ->setStatus(SeoTask::STATUS_PENDING)
                        ->setMaxAttempts(3)
                        ->save();
                    
                    $taskCount++;
                }
            }

            // 处理已创建的推送任务
            /** @var TaskProcessor $taskProcessor */
            $taskProcessor = $this->objectManager->getInstance(TaskProcessor::class);
            
            $tasks = $taskModel->getPendingTasks(SeoTask::TASK_TYPE_PUSH_URLS, 50);
            $successCount = 0;
            $errorCount = 0;

            foreach ($tasks as $taskData) {
                $task = $taskModel->reset()->load($taskData['task_id']);
                
                if (!$task->getId() || !$task->isPending()) {
                    continue;
                }

                $task->markProcessing();
                $success = $taskProcessor->process($task);
                
                if ($success) {
                    $successCount++;
                } else {
                    $errorCount++;
                }
            }

            return sprintf(
                'URL推送任务处理完成：创建 %d 个任务，成功 %d 个，失败 %d 个',
                $taskCount,
                $successCount,
                $errorCount
            );

        } catch (\Exception $e) {
            return 'URL推送任务执行失败：' . $e->getMessage();
        }
    }

    /**
     * 调度任务超时解锁时间（分钟）
     */
    public function timeout(): int
    {
        return 60; // 60分钟超时
    }
}


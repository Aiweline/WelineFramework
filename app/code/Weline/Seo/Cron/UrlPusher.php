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
            /** @var SeoTask $taskModel */
            $taskModel = $this->objectManager->getInstance(SeoTask::class);
            /** @var \Weline\Seo\Model\SeoAccount $accountModel */
            $accountModel = $this->objectManager->getInstance(\Weline\Seo\Model\SeoAccount::class);

            $accounts = $accountModel->reset()
                ->where(\Weline\Seo\Model\SeoAccount::fields_IS_ACTIVE, \Weline\Seo\Model\SeoAccount::STATUS_ACTIVE)
                ->where(\Weline\Seo\Model\SeoAccount::fields_ENABLE_CRON_PUSH_URLS, 1)
                ->select()
                ->fetchArray();

            if (empty($accounts)) {
                return '没有启用定时URL推送的SEO账户';
            }

            $taskCount = 0;

            foreach ($accounts as $account) {
                $provider = (string)($account[\Weline\Seo\Model\SeoAccount::fields_PROVIDER] ?? '');
                $accountId = (int)($account[\Weline\Seo\Model\SeoAccount::fields_ACCOUNT_ID] ?? 0);
                $scope = (string)($account[\Weline\Seo\Model\SeoAccount::fields_SCOPE] ?? '');

                if ($provider === '' || $accountId <= 0) {
                    continue;
                }

                // 检查该账户是否已有待处理任务，避免重复
                $existingTask = $taskModel->reset()
                    ->where(SeoTask::fields_TASK_TYPE, SeoTask::TASK_TYPE_PUSH_URLS)
                    ->where(SeoTask::fields_STATUS, [SeoTask::STATUS_PENDING, SeoTask::STATUS_PROCESSING], 'IN')
                    ->where(SeoTask::fields_SCOPE, $scope)
                    ->where(SeoTask::fields_PAYLOAD, '%"account_id":' . $accountId . '%', 'LIKE')
                    ->find()
                    ->fetch();

                if ($existingTask->getId()) {
                    continue;
                }

                // 获取需要推送的SEO主体（按账户的 scope 过滤）
                $subjectQuery = $subjectModel->reset()
                    ->where(SeoSubject::fields_STATUS, SeoSubject::STATUS_ENABLED)
                    ->where(SeoSubject::fields_URL, '', '!=')
                    ->where(SeoSubject::fields_UPDATED_AT, date('Y-m-d H:i:s', strtotime('-7 days')), '>=');

                if ($scope !== '') {
                    $subjectQuery->where(SeoSubject::fields_SCOPE, $scope);
                }

                $subjects = $subjectQuery->select()->fetchArray();
                if (empty($subjects)) {
                    continue;
                }

                $urls = [];
                foreach ($subjects as $subject) {
                    if (!empty($subject['url'])) {
                        $urls[] = $subject['url'];
                    }
                }

                if (empty($urls)) {
                    continue;
                }

                $urlChunks = array_chunk($urls, 100);
                foreach ($urlChunks as $chunk) {
                    $payload = [
                        'urls' => $chunk,
                        'provider' => $provider,
                        'account_id' => $accountId,
                        'scope' => $scope,
                    ];

                    $taskModel->reset()
                        ->setTaskType(SeoTask::TASK_TYPE_PUSH_URLS)
                        ->setSubjectType('batch')
                        ->setSubjectId(0)
                        ->setPayloadArray($payload)
                        ->setPriority(SeoTask::PRIORITY_NORMAL)
                        ->setStatus(SeoTask::STATUS_PENDING)
                        ->setMaxAttempts(3)
                        ->setData(SeoTask::fields_SCOPE, $scope)
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

    /**
     * 调度任务阻塞超时时间（分钟）
     */
    public function unlock_timeout(int $minute = 30): int
    {
        return $minute;
    }
}


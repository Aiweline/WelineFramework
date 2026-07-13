<?php

declare(strict_types=1);

namespace Weline\Seo\Cron;

use Weline\Framework\Cron\CronTaskInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Seo\Model\SeoTask;
use Weline\Seo\Service\TaskProcessor;

class UrlPusher implements CronTaskInterface
{
    public function __construct(private readonly ObjectManager $objectManager)
    {
    }

    public function name(): string
    {
        return 'SEO URL push task consumer';
    }

    public function execute_name(): string
    {
        return 'seo_url_pusher';
    }

    public function tip(): string
    {
        return 'Consumes pending SEO URL push tasks created by the URL rewrite diff cron.';
    }

    public function cron_time(): string
    {
        return '*/5 * * * *';
    }

    public function execute(): string
    {
        try {
            /** @var SeoTask $taskModel */
            $taskModel = $this->objectManager->getInstance(SeoTask::class);

            $tasks = $taskModel->getPendingTasks(SeoTask::TASK_TYPE_PUSH_URLS, 50);
            if (empty($tasks)) {
                return 'No pending SEO URL push tasks.';
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

                $task->markProcessing();
                $success = $taskProcessor->process($task);

                if ($success) {
                    $successCount++;
                } else {
                    $errorCount++;
                }
            }

            return sprintf(
                'SEO URL push tasks processed: success=%d, failed=%d, total=%d.',
                $successCount,
                $errorCount,
                count($tasks)
            );
        } catch (\Exception $e) {
            return 'SEO URL push task consumer failed: ' . $e->getMessage();
        }
    }

    public function timeout(): int
    {
        return 60;
    }

    public function unlock_timeout(int $minute = 30): int
    {
        return $minute;
    }
}

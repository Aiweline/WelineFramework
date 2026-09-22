<?php

declare(strict_types=1);

namespace Weline\I18n\Queue;

use Weline\Framework\Manager\ObjectManager;
use Weline\I18n\Service\RemoteCollectTaskService;
use Weline\Queue\Model\Queue;
use Weline\Queue\QueueInterface;

final class RemoteDictionaryCollectQueue implements QueueInterface
{
    public function name(): string
    {
        return (string)__('远程词典收集队列');
    }

    public function attributes(): array
    {
        return [];
    }

    public function tip(): string
    {
        return (string)__('远程协助翻译触发的词典收集（不入队站内 AI）');
    }

    public function validate(Queue &$queue): bool
    {
        $content = $this->decodeContent($queue);
        $taskId = strtolower(trim((string)($content['task_id'] ?? '')));
        if ($taskId === '' || !preg_match('/^[a-f0-9]{32}$/', $taskId)) {
            $queue->setResult((string)__('远程收集任务 ID 无效'));

            return false;
        }

        return true;
    }

    public function execute(Queue &$queue): string
    {
        $content = $this->decodeContent($queue);
        $taskId = strtolower(trim((string)($content['task_id'] ?? '')));
        /** @var RemoteCollectTaskService $service */
        $service = ObjectManager::getInstance(RemoteCollectTaskService::class);
        $service->runCollect($taskId);

        return (string)__('远程词典收集已执行');
    }

    /** @return array<string,mixed> */
    private function decodeContent(Queue $queue): array
    {
        $raw = $queue->getContent();
        if ($raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }
}

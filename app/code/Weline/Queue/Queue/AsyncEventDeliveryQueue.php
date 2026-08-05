<?php

declare(strict_types=1);

namespace Weline\Queue\Queue;

use Weline\Framework\Api\Event\AsyncEventDeliveryRunnerInterface;
use Weline\Queue\Api\QueueConsumerInterface;
use Weline\Queue\Api\QueueTaskContextInterface;

final class AsyncEventDeliveryQueue implements QueueConsumerInterface
{
    public function __construct(
        private readonly AsyncEventDeliveryRunnerInterface $runner,
    ) {
    }

    public function name(): string
    {
        return (string)__('异步事件投递');
    }

    public function attributes(): array
    {
        return [];
    }

    public function tip(): string
    {
        return (string)__('执行一轮带 fencing 的异步 Observer 投递');
    }

    public function validate(QueueTaskContextInterface $queue): bool
    {
        try {
            $content = $this->content($queue);
        } catch (\Throwable) {
            return false;
        }

        return (int)$queue->getId() > 0
            && $content['delivery_id'] > 0
            && $content['attempt_no'] > 0
            && \preg_match(
                '/^[a-f0-9]{64}$/',
                (string)$queue->taskData('dispatch_token'),
            ) === 1;
    }

    public function execute(QueueTaskContextInterface $queue): string
    {
        $content = $this->content($queue);
        $queueId = (int)$queue->getId();
        $fenceToken = (string)$queue->taskData('dispatch_token');
        $status = $this->runner->run(
            $content['delivery_id'],
            $content['attempt_no'],
            'queue:' . $queueId,
            $fenceToken,
        );
        if ($status === 'retry_wait' || $status === 'dead') {
            throw new \RuntimeException('async_event_delivery_' . $status);
        }
        if ($status !== 'succeeded' && $status !== 'noop') {
            throw new \UnexpectedValueException('async_event_delivery_runner_status_invalid');
        }

        return 'QUEUE_DONE: async_event_delivery_' . $status;
    }

    /** @return array{delivery_id:int,attempt_no:int} */
    private function content(QueueTaskContextInterface $queue): array
    {
        $content = \json_decode($queue->getContent(), true, 16, JSON_THROW_ON_ERROR);
        if (!\is_array($content)
            || \count($content) !== 2
            || !\array_key_exists('delivery_id', $content)
            || !\array_key_exists('attempt_no', $content)
            || !\is_int($content['delivery_id'])
            || !\is_int($content['attempt_no'])) {
            throw new \UnexpectedValueException('async_event_delivery_queue_content_invalid');
        }

        return [
            'delivery_id' => $content['delivery_id'],
            'attempt_no' => $content['attempt_no'],
        ];
    }
}

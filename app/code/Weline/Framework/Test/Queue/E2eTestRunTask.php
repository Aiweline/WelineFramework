<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Queue;

use Weline\Framework\Async\TaskConsumerInterface;
use Weline\Framework\Async\TaskContextInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Test\Service\TestRunExecutor;

/**
 * Async E2E test runner for framework test management.
 */
final class E2eTestRunTask implements TaskConsumerInterface
{
    public function name(): string
    {
        return (string)__('框架 E2E 测试运行');
    }

    public function attributes(): array
    {
        return [];
    }

    public function tip(): string
    {
        return (string)__('按模块异步执行 Playwright E2E，并回写测试运行进度。');
    }

    public function validate(TaskContextInterface $task): bool
    {
        try {
            $content = $this->decode($task);
        } catch (\Throwable) {
            return false;
        }

        return (int)($content['run_id'] ?? 0) > 0
            && trim((string)($content['module'] ?? '')) !== '';
    }

    public function execute(TaskContextInterface $task): string
    {
        $content = $this->decode($task);
        /** @var TestRunExecutor $executor */
        $executor = ObjectManager::getInstance(TestRunExecutor::class);
        $exitCode = $executor->execute($content, $task);

        return 'QUEUE_DONE: framework_test_e2e_exit_' . $exitCode;
    }

    /**
     * @return array<string,mixed>
     */
    private function decode(TaskContextInterface $task): array
    {
        $raw = $task->getContent();
        $decoded = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) {
            throw new \InvalidArgumentException('framework_test_e2e_content_invalid');
        }

        return $decoded;
    }
}

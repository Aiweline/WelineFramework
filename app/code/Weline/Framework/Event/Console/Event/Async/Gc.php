<?php

declare(strict_types=1);

namespace Weline\Framework\Event\Console\Event\Async;

use Weline\Framework\Console\CommandAbstract;
use Weline\Framework\Console\CommandHelper;
use Weline\Framework\Event\Async\AsyncEventGarbageCollector;
use Weline\Framework\Manager\ObjectManager;

final class Gc extends CommandAbstract
{
    private const DEFAULT_LIMIT = 500;
    private const MAX_LIMIT = 500;

    public function execute(array $args = [], array $data = []): void
    {
        $limit = $this->limit($args);
        $result = ObjectManager::getInstance(AsyncEventGarbageCollector::class)->collect($limit);
        $this->printer->success(__('异步事件 GC 完成：Delivery %{1}，Outbox %{2}', [
            $result['deliveries'],
            $result['outboxes'],
        ]));
    }

    public function tip(): string
    {
        return __('按保留期有界清理已终态的异步事件记录');
    }

    public function help(): array|string
    {
        return CommandHelper::formatHelp(
            'event:async:gc',
            $this->tip(),
            [
                '--limit=<1..500>' => __('Outbox 与 Delivery 合计最多删除数量（默认 500）'),
            ],
            [],
            [],
            'php bin/w event:async:gc [--limit=500]',
        );
    }

    private function limit(array $args): int
    {
        $value = $args['limit'] ?? null;
        if ($value === null) {
            foreach ($args as $arg) {
                if (is_string($arg) && str_starts_with($arg, '--limit=')) {
                    $value = substr($arg, 8);
                    break;
                }
            }
        }
        if ($value === null) {
            return self::DEFAULT_LIMIT;
        }
        if ((!is_int($value) && !is_string($value))
            || preg_match('/^[1-9][0-9]*$/D', (string)$value) !== 1) {
            throw new \InvalidArgumentException((string)__('limit 必须是 1 到 %{1} 的整数', [self::MAX_LIMIT]));
        }
        $limit = (int)$value;
        if ($limit < 1 || $limit > self::MAX_LIMIT) {
            throw new \InvalidArgumentException((string)__('limit 必须是 1 到 %{1} 的整数', [self::MAX_LIMIT]));
        }

        return $limit;
    }
}

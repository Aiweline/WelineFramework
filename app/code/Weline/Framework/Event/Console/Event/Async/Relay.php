<?php

declare(strict_types=1);

namespace Weline\Framework\Event\Console\Event\Async;

use Weline\Framework\Console\CommandAbstract;
use Weline\Framework\Console\CommandHelper;
use Weline\Framework\Event\Async\OutboxRelay;
use Weline\Framework\Manager\ObjectManager;

final class Relay extends CommandAbstract
{
    private const DEFAULT_LIMIT = 100;
    private const MAX_LIMIT = 1000;

    public function execute(array $args = [], array $data = []): void
    {
        $limit = $this->limit($args);
        $result = ObjectManager::getInstance(OutboxRelay::class)->relayAvailable($limit);
        $this->printer->success(__('异步事件 Relay 完成：处理 %{1}，展开 %{2}，死信 %{3}，重试 %{4}', [
            $result['processed'],
            $result['expanded'],
            $result['dead'],
            $result['retried'],
        ]));
    }

    public function tip(): string
    {
        return __('有界扫描并展开可用的异步事件 Outbox');
    }

    public function help(): array|string
    {
        return CommandHelper::formatHelp(
            'event:async:relay',
            $this->tip(),
            [
                '--once' => __('执行一次有界扫描后退出'),
                '--limit=<1..1000>' => __('本次最多处理的 Outbox 数量（默认 100）'),
            ],
            [],
            [],
            'php bin/w event:async:relay [--once] [--limit=100]',
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

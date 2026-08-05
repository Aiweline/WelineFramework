<?php

declare(strict_types=1);

namespace Weline\DeveloperWorkspace\Extends\Module\Weline_Framework\Query;

use Weline\DeveloperWorkspace\Service\AsyncEventProbeService;
use Weline\Framework\Service\Query\Provider\QueryProviderInterface;

final class AsyncEventProbeQueryProvider implements QueryProviderInterface
{
    public const ACL = 'Weline_Framework::event_delivery_view';

    public function __construct(private readonly AsyncEventProbeService $service)
    {
    }

    public function getProviderName(): string
    {
        return 'async_event_probe';
    }

    public function execute(string $operation, array $params = []): mixed
    {
        return match ($operation) {
            'trigger' => $this->service->trigger(),
            'status' => $this->service->status((string)($params['event_id'] ?? '')),
            'advance' => $this->service->advance((string)($params['event_id'] ?? '')),
            default => throw new \InvalidArgumentException((string)__('异步事件验收台不支持操作：%{1}', [$operation])),
        };
    }

    public function getDescriptor(): array
    {
        $operation = static fn(string $name, string $mode, array $params = []): array => [
            'name' => $name,
            'description' => (string)__('执行异步资源变更 WebUI 验收步骤'),
            'frontend' => true,
            'backend' => true,
            'external' => false,
            'auth' => 'backend',
            'backend_acl' => ['kind' => 'source', 'source_id' => self::ACL],
            'mode' => $mode,
            'graph' => false,
            'cost' => $mode === 'write' ? 3 : 1,
            'params' => $params,
            'returns' => ['type' => 'map'],
        ];
        $eventId = [['name' => 'event_id', 'type' => 'string', 'required' => true, 'max_length' => 32]];
        return [
            'provider' => $this->getProviderName(),
            'name' => (string)__('异步资源变更 WebUI 验收台'),
            'description' => (string)__('通过 w_changed() 实测 Outbox、Delivery、Queue 与异步 Observer。'),
            'module' => 'Weline_DeveloperWorkspace',
            'operations' => [
                $operation('trigger', 'write'),
                $operation('status', 'read', $eventId),
                $operation('advance', 'write', $eventId),
            ],
        ];
    }
}

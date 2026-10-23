<?php

declare(strict_types=1);

namespace Weline\Framework\Database\Release\Api;

/** append-only 账本：只追加事件，状态由事件流投影。 */
interface SchemaJournalInterface
{
    /**
     * 追加一条事件。
     *
     * @param array<string, mixed> $event
     * @return int 事件自增 ID
     */
    public function append(array $event): int;

    /**
     * 按发布、操作、作用域筛选事件。
     *
     * @return list<array<string, mixed>>
     */
    public function events(string $releaseId, ?string $operationId = null, ?string $scopeKey = null): array;

    /** 返回节点最新状态；无记录返回 null。 */
    public function latestState(string $scopeKey, string $nodeId): ?string;
}

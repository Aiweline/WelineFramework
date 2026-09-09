<?php

declare(strict_types=1);

namespace Weline\Framework\Cache\Contract;

/** 可选的批量传输能力，保留单键适配器兼容。 */
interface BatchCacheAdapterInterface extends CacheAdapterInterface
{
    /** @param list<string> $keys @return array<string,mixed> 未命中的键返回 null。 */
    public function getMultiple(array $keys): array;

    /** @param array<string,mixed> $values 整批条目使用相同 TTL。 */
    public function setMultiple(array $values, int $ttl = 0): bool;
}

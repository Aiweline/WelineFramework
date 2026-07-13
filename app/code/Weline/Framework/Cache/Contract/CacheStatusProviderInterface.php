<?php

declare(strict_types=1);

namespace Weline\Framework\Cache\Contract;

interface CacheStatusProviderInterface
{
    /** @return array<string, int> */
    public function all(): array;

    public function get(string $identity): ?int;
}

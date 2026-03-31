<?php
declare(strict_types=1);

namespace Weline\Server\Service\Protocol\LongLived;

interface MatcherInterface
{
    /**
     * @return array{is_long_lived: bool, layer: string, protocol: string}|null
     */
    public function match(string $rawRequest): ?array;
}


<?php

declare(strict_types=1);

namespace Weline\Ai\Interface;

/** @deprecated Implement \Weline\Ai\Api\StyleProviderInterface. */
interface StyleProviderInterface extends \Weline\Ai\Api\StyleProviderInterface
{
    /**
     * @return array<int|string, array<string, mixed>>
     */
    public function listStyles(): array;
}

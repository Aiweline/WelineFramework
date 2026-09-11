<?php

declare(strict_types=1);

namespace Weline\Dropship\Api;

use Weline\Dropship\Interface\DropshipProviderInterface;

interface DropshipChannelManagerInterface
{
    /**
     * @return list<array<string, mixed>>
     */
    public function registerAllProviders(bool $forceReload = false): array;

    /**
     * @return list<DropshipProviderInterface>
     */
    public function getProviders(bool $forceReload = false): array;

    public function getProvider(string $code): ?DropshipProviderInterface;
}

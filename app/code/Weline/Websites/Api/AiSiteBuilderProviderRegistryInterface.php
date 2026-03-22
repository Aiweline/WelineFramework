<?php

declare(strict_types=1);

namespace Weline\Websites\Api;

interface AiSiteBuilderProviderRegistryInterface
{
    /**
     * @return array<string, AiSiteBuilderProviderInterface>
     */
    public function getProviders(bool $onlyEnabled = true, bool $forceReload = false): array;

    public function getProvider(string $providerCode, bool $forceReload = false): ?AiSiteBuilderProviderInterface;

    public function clearCache(): void;
}

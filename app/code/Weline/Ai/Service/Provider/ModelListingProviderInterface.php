<?php
declare(strict_types=1);

namespace Weline\Ai\Service\Provider;

/**
 * Providers expose their remote model listing here instead of duplicating
 * provider-specific auth and response parsing in controllers.
 */
interface ModelListingProviderInterface
{
    public function supportsModelsApi(): bool;

    /**
     * @param array<string,mixed> $config
     * @param array<string,mixed> $options
     * @return array<int,array<string,mixed>>
     */
    public function listRemoteModels(array $config, array $options = []): array;
}

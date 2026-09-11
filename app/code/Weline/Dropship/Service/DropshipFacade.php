<?php

declare(strict_types=1);

namespace Weline\Dropship\Service;

use Weline\Dropship\Api\Data\DropshipCatalogSnapshot;
use Weline\Dropship\Api\DropshipFacadeInterface;

class DropshipFacade implements DropshipFacadeInterface
{
    public function __construct(
        private readonly DropshipChannelManager $channels,
        private readonly DropshipSettings $settings,
        private readonly DropshipPublishService $publishService,
        private readonly DropshipOutboxService $outboxService,
    ) {
    }

    public function listProvidersForScope(array $scope): array
    {
        $storageScope = (string)($scope['storage_scope'] ?? 'default.default.default');
        $enabled = $this->settings->enabledPlatforms($storageScope);
        $this->channels->registerAllProviders();
        $out = [];
        foreach ($this->channels->getProviders() as $provider) {
            $code = $provider->getCode();
            if ($enabled !== [] && !in_array($code, $enabled, true)) {
                continue;
            }
            $meta = $provider->getDisplayMetadata();
            $out[] = [
                'code' => $code,
                'title' => (string)($meta['title'] ?? $code),
                'capabilities' => $provider->getCapabilities(),
            ];
        }

        return $out;
    }

    public function publishSnapshot(string $providerCode, DropshipCatalogSnapshot $snapshot, array $scope): array
    {
        if ($snapshot->providerCode !== $providerCode) {
            throw new \InvalidArgumentException('dropship_snapshot_provider_mismatch');
        }

        return $this->publishService->publish($snapshot, $scope);
    }

    public function enqueuePaidOrder(string $orderUuid): void
    {
        // Observers extract lines; facade entry for tests/manual.
        $this->outboxService->admitCreateForOrder($orderUuid, [], [], 0, 0);
    }
}

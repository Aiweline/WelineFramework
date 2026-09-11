<?php

declare(strict_types=1);

namespace Weline\Dropship\Api;

use Weline\Dropship\Api\Data\DropshipCatalogSnapshot;

interface DropshipFacadeInterface
{
    /**
     * @param array<string, mixed> $scope website_id,store_id,channel
     * @return list<array<string, mixed>>
     */
    public function listProvidersForScope(array $scope): array;

    public function publishSnapshot(string $providerCode, DropshipCatalogSnapshot $snapshot, array $scope): array;

    public function enqueuePaidOrder(string $orderUuid): void;
}

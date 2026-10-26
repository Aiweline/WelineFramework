<?php

declare(strict_types=1);

namespace Weline\Points\Service;

use Weline\Points\Api\PointsVipFacadeInterface;

final class PointsVipService implements PointsVipFacadeInterface
{
    public function __construct(
        private readonly VipLevelService $vip,
    ) {
    }

    public function getCustomerState(int $customerId, int $websiteId = 0, string $namespace = 'live'): array
    {
        return $this->vip->stateFor($customerId, $websiteId, $namespace);
    }

    public function getBenefits(int $customerId, int $websiteId = 0, string $namespace = 'live'): array
    {
        return $this->vip->benefitsFor($customerId, $websiteId, $namespace);
    }

    public function listLevels(): array
    {
        return $this->vip->listLevels();
    }
}

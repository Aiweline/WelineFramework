<?php

declare(strict_types=1);

namespace Weline\Points\Service;

use Weline\Eav\Api\Attribute\AttributeRecord;
use Weline\Eav\Api\Attribute\EntityAttributeStoreInterface;
use Weline\Framework\Manager\ObjectManager;

/**
 * Reads product-level `points_earn_ratio` / `points_earn_fixed` EAV values.
 */
final class PointsProductEarnPolicy
{
    private ?AttributeRecord $ratioAttribute = null;
    private ?AttributeRecord $fixedAttribute = null;
    private bool $attributesLoaded = false;

    public function __construct(
        private readonly ?EntityAttributeStoreInterface $store = null,
    ) {
    }

    /**
     * @return array{ratio:?float,fixed:?int}
     */
    public function policyForProduct(int $productId): array
    {
        if ($productId <= 0) {
            return ['ratio' => null, 'fixed' => null];
        }

        $this->loadAttributes();
        $entity = new ProductPointsEntity();
        $store = $this->store();
        $ratio = null;
        $fixed = null;

        if ($this->ratioAttribute instanceof AttributeRecord) {
            $value = $store->readValue($entity, $productId, $this->ratioAttribute);
            if (\is_numeric($value)) {
                $ratio = max(0.0, (float) $value);
            }
        }
        if ($this->fixedAttribute instanceof AttributeRecord) {
            $value = $store->readValue($entity, $productId, $this->fixedAttribute);
            if (\is_numeric($value)) {
                $fixed = max(0, (int) $value);
            }
        }

        return ['ratio' => $ratio, 'fixed' => $fixed];
    }

    private function loadAttributes(): void
    {
        if ($this->attributesLoaded) {
            return;
        }
        $this->attributesLoaded = true;
        try {
            $store = $this->store();
            $entity = new ProductPointsEntity();
            $this->ratioAttribute = $store->getAttribute($entity, PointsProductAttributeInstaller::ATTR_RATIO);
            $this->fixedAttribute = $store->getAttribute($entity, PointsProductAttributeInstaller::ATTR_FIXED);
        } catch (\Throwable) {
            $this->ratioAttribute = null;
            $this->fixedAttribute = null;
        }
    }

    private function store(): EntityAttributeStoreInterface
    {
        if ($this->store instanceof EntityAttributeStoreInterface) {
            return $this->store;
        }
        $resolved = ObjectManager::getInstance(EntityAttributeStoreInterface::class);
        if (!$resolved instanceof EntityAttributeStoreInterface) {
            throw new \RuntimeException('points_eav_store_unavailable');
        }

        return $resolved;
    }
}

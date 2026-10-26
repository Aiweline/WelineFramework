<?php

declare(strict_types=1);

namespace Weline\Points\Service;

use Weline\Eav\Api\Attribute\AttributeDefinition;
use Weline\Eav\Api\Attribute\EntityAttributeStoreInterface;
use Weline\Framework\Manager\ObjectManager;

/**
 * Declares product-level earning attributes through the public EAV boundary.
 */
final class PointsProductAttributeInstaller
{
    public const ATTR_RATIO = 'points_earn_ratio';
    public const ATTR_FIXED = 'points_earn_fixed';

    public function __construct(
        private readonly ?EntityAttributeStoreInterface $store = null,
    ) {
    }

    /**
     * @return array<string, bool> attribute code => created now
     */
    public function ensureAttributes(): array
    {
        $entity = new ProductPointsEntity();
        $store = $this->store();
        $created = [];

        foreach ([
            new AttributeDefinition(
                code: self::ATTR_RATIO,
                name: '积分获取比例',
                typeCode: 'input_decimal_2',
                multiple: false,
                hasOption: false,
                setCode: 'storefront',
                groupCode: 'specs',
                system: false,
                enabled: true,
            ),
            new AttributeDefinition(
                code: self::ATTR_FIXED,
                name: '每件固定积分',
                typeCode: 'input_int',
                multiple: false,
                hasOption: false,
                setCode: 'storefront',
                groupCode: 'specs',
                system: false,
                enabled: true,
            ),
        ] as $definition) {
            if ($store->getAttribute($entity, $definition->code) !== null) {
                $created[$definition->code] = false;
                continue;
            }
            $store->declareAttribute($entity, $definition);
            $created[$definition->code] = true;
        }

        return $created;
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

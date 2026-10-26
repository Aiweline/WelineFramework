<?php

declare(strict_types=1);

namespace Weline\Points\Service;

use Weline\Eav\Api\Entity\EntityDefinitionInterface;

/**
 * Minimal public EAV entity descriptor for the existing product catalog entity.
 *
 * It carries only the stable entity identity; Points never reads Product models.
 */
final class ProductPointsEntity implements EntityDefinitionInterface
{
    public const CODE = 'product';

    public function getEntityCode(): string
    {
        return self::CODE;
    }

    public function getEntityName(): string
    {
        return '商品';
    }

    public function getEntityFieldIdType(): string
    {
        return 'int';
    }

    public function getEntityFieldIdLength(): int
    {
        return 11;
    }
}

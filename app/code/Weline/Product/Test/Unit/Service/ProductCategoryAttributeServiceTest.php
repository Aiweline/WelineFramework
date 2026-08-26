<?php

declare(strict_types=1);

namespace Weline\Product\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Product\Model\CategoryAttributeEntity;
use Weline\Product\Service\ProductCategoryAttributeService;

final class ProductCategoryAttributeServiceTest extends TestCase
{
    public function testEntityTypeMatchesCategoryEavEntity(): void
    {
        self::assertSame(CategoryAttributeEntity::entity_code, ProductCategoryAttributeService::ENTITY_TYPE);
    }

    public function testServiceRoutesWritesThroughAttributeRepository(): void
    {
        $source = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Service/ProductCategoryAttributeService.php',
        );

        self::assertStringContainsString('$this->attributes->writeExplicit', $source);
        self::assertStringContainsString("'name'", $source);
        self::assertStringContainsString("'code'", $source);
        self::assertStringContainsString('purgeEntity', $source);
        self::assertStringContainsString('copyExplicitAttributes', $source);
    }
}

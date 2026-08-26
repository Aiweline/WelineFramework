<?php

declare(strict_types=1);

namespace Weline\Product\Test\Unit\Service;

use PHPUnit\Framework\TestCase;

final class ProductCategoryAdminServiceEavPathContractTest extends TestCase
{
    public function testSaveDoesNotCallAttributeRepositoryWriteExplicitDirectly(): void
    {
        $source = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Service/ProductCategoryAdminService.php',
        );

        self::assertStringContainsString('ProductCategoryAttributeService', $source);
        self::assertStringContainsString('$this->categoryAttributes->writeName', $source);
        self::assertStringContainsString('$this->categoryAttributes->writeCode', $source);
        self::assertStringContainsString('$this->categoryAttributes->purge', $source);
        self::assertStringNotContainsString('$this->attributes->writeExplicit', $source);
    }
}

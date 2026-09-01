<?php

declare(strict_types=1);

namespace Weline\Product\Test\Unit\Extends\CatalogSpace;

use PHPUnit\Framework\TestCase;

final class ProductCatalogSpaceProviderDisplayTest extends TestCase
{
    public function testDisplaySelectionDelegatesToRepository(): void
    {
        $source = (string)file_get_contents(
            dirname(__DIR__, 4) . '/extends/module/Weline_Catalog/Space/ProductCatalogSpaceProvider.php',
        );
        self::assertStringContainsString('CategoryDisplaySelectionRepository', $source);
        self::assertStringContainsString('displaySelections->listForScope', $source);
        self::assertStringContainsString('displaySelections->replaceScope', $source);
        self::assertStringContainsString('invalidateAfterMutation($scope, \'display_selection_saved\')', $source);
    }
}

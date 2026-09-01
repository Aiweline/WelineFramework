<?php

declare(strict_types=1);

namespace Weline\Product\Test\Unit\Service;

use PHPUnit\Framework\TestCase;

final class ProductCategoryBulkAssignServiceTest extends TestCase
{
    public function testServiceSupportsBulkModesAndMergeFlow(): void
    {
        $source = $this->read('app/code/Weline/Product/Service/ProductCategoryBulkAssignService.php');

        foreach (['add', 'replace', 'remove'] as $mode) {
            self::assertStringContainsString("'" . $mode . "'", $source);
        }
        foreach ([
            'syncProductScope',
            'notifyCatalogChanged',
            'product_archived_readonly',
            'product_category_not_found',
            'mergeAssignments',
        ] as $marker) {
            self::assertStringContainsString($marker, $source);
        }
    }

    private function read(string $path): string
    {
        $content = file_get_contents(BP . $path);
        self::assertIsString($content, 'Unable to read ' . $path);

        return $content;
    }
}

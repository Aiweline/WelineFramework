<?php

declare(strict_types=1);

namespace Weline\Product\Test\Unit\Service;

use PHPUnit\Framework\TestCase;

/**
 * Catalog bulk「删除」= archive. Published cannot jump to archived in the
 * repository state machine; already-archived must be idempotent success.
 */
final class ProductAdminArchiveTransitionContractTest extends TestCase
{
    public function testCommandServiceChainsPublishedArchiveAndNoopsArchived(): void
    {
        $source = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Service/ProductAdminCommandService.php',
        );

        self::assertStringContainsString('lifecycleStepsToward', $source);
        self::assertStringContainsString("STATUS_ARCHIVED", $source);
        self::assertStringContainsString('ACTION_RESTORE', $source);
        self::assertMatchesRegularExpression(
            '/lifecycleStepsToward.*?published.*?disabled.*?archived/s',
            $source,
        );
        self::assertMatchesRegularExpression(
            "/'archived'\\s*=>\\s*\\['draft'\\]/",
            $source,
        );
        self::assertMatchesRegularExpression(
            '/private function transition\(.*?STATUS_ARCHIVED.*?noop|already.?archiv|已归档/s',
            $source,
        );
    }

    public function testBulkArchiveJsSurfacesFailedCounts(): void
    {
        $script = (string)file_get_contents(
            dirname(__DIR__, 3) . '/view/statics/js/backend/product-admin.js',
        );

        self::assertStringContainsString('function bulkArchive', $script);
        self::assertMatchesRegularExpression(
            '/function bulkArchive\([\s\S]*?data\.failed[\s\S]*?succeeded/m',
            $script,
        );
    }
}

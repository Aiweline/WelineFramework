<?php

declare(strict_types=1);

namespace Weline\Product\Test\Unit\Model;

use PHPUnit\Framework\TestCase;

/**
 * Storefront PDP calls assertReady/getStatus many times; registry row must be request-memoized.
 */
final class ProductShardRegistryRequestMemoContractTest extends TestCase
{
    public function testEnsureWebsiteUsesRequestMemoAndInvalidatesOnMutation(): void
    {
        $source = (string)file_get_contents(
            BP . 'app/code/Weline/Product/Model/ProductShardRegistry.php',
        );

        self::assertStringContainsString("REQUEST_MEMO_RESOURCE = 'product.shard_registry.row'", $source);
        self::assertStringContainsString('rememberForRequest(', $source);
        self::assertStringContainsString('forgetRequestMemo(', $source);
        self::assertStringContainsString('forgetWebsiteRequestMemo($websiteId)', $source);
        self::assertStringContainsString('loadOrCreateWebsiteRow(', $source);
        self::assertMatchesRegularExpression(
            '/forgetWebsiteRequestMemo\(\$websiteId\);\s*\$after = \$this->getStatus\(\$websiteId\)/s',
            $source,
        );
        self::assertMatchesRegularExpression(
            '/forgetWebsiteRequestMemo\(\$websiteId\);\s*\$row = \$this->ensureWebsite\(\$websiteId\)/s',
            $source,
        );
    }
}

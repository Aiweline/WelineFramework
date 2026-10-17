<?php

declare(strict_types=1);

namespace Weline\Product\Test\Unit\Model;

use PHPUnit\Framework\TestCase;


/**
 * Aggregated contract suite (merged from 1 *ContractTest files).
 * Observe one overall pass/fail via testAllProductModelContractsPass().
 */
final class ProductModelContractsTest extends TestCase
{
    public function testAllProductModelContractsPass(): void
    {
        $failures = [];
        foreach ($this->contractChecks() as $name => $fn) {
            try {
                $fn();
            } catch (\Throwable $e) {
                $failures[] = $name . ": " . $e->getMessage();
            }
        }
        self::assertSame([], $failures, implode("\n", $failures));
    }

    /** @return array<string, callable(): void> */
    private function contractChecks(): array
    {
        return [
            'ProductShardRegistryRequestMemo::testEnsureWebsiteUsesRequestMemoAndInvalidatesOnMutation' => function () {
            $this->assert_ProductShardRegistryRequestMemo_testEnsureWebsiteUsesRequestMemoAndInvalidatesOnMutation();
        },
        ];
    }

private function assert_ProductShardRegistryRequestMemo_testEnsureWebsiteUsesRequestMemoAndInvalidatesOnMutation(): void
{
    $source = (string) file_get_contents(BP . 'app/code/Weline/Product/Model/ProductShardRegistry.php');
    self::assertStringContainsString("REQUEST_MEMO_RESOURCE = 'product.shard_registry.row'", $source);
    self::assertStringContainsString('rememberForRequest(', $source);
    self::assertStringContainsString('forgetRequestMemo(', $source);
    self::assertStringContainsString('forgetWebsiteRequestMemo($websiteId)', $source);
    self::assertStringContainsString('loadOrCreateWebsiteRow(', $source);
    self::assertMatchesRegularExpression('/forgetWebsiteRequestMemo\(\$websiteId\);\s*\$after = \$this->getStatus\(\$websiteId\)/s', $source);
    self::assertMatchesRegularExpression('/forgetWebsiteRequestMemo\(\$websiteId\);\s*\$row = \$this->ensureWebsite\(\$websiteId\)/s', $source);
}
}

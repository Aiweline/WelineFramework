<?php

declare(strict_types=1);

namespace Weline\Promotion\Test\Unit\Extends\Module\Weline_Seo;

use PHPUnit\Framework\TestCase;
use Weline\Promotion\Extends\Module\Weline_Seo\SeoProfileProvider\PromotionSeoProfileProvider;
use Weline\Seo\Interface\SeoProfileProviderInterface;

/**
 * Aggregated contract suite (merged from 1 *ContractTest files).
 * Observe one overall pass/fail via testAllPromotionWeline_SeoContractsPass().
 */
final class PromotionWelineSeoContractsTest extends TestCase
{
    public function testAllPromotionWeline_SeoContractsPass(): void
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
            'PromotionSeoProfileProvider::testProviderFileAndInterfaceContract' => function () {
            $this->assert_PromotionSeoProfileProvider_testProviderFileAndInterfaceContract();
        },
        ];
    }

private function assert_PromotionSeoProfileProvider_testProviderFileAndInterfaceContract(): void
{
    $root = '/Users/weline/Project/Official/框架/app/code/Weline/Promotion';
    $path = $root . '/extends/module/Weline_Seo/SeoProfileProvider/PromotionSeoProfileProvider.php';
    self::assertFileExists($path);
    self::assertFileExists($root . '/extends/module/Weline_Seo/SitemapUrlProvider/PromotionSitemapUrlProvider.php');
    $src = (string) file_get_contents($path);
    self::assertStringContainsString('implements SeoProfileProviderInterface', $src);
    self::assertStringContainsString("\$slot !== 'head'", $src);
    self::assertStringContainsString('claimsPromotion', $src);
    self::assertStringContainsString('products', $src);
    self::assertStringContainsString('promotion/', $src);
    self::assertTrue(is_a(PromotionSeoProfileProvider::class, SeoProfileProviderInterface::class, true));
}
}

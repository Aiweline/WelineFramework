<?php

declare(strict_types=1);

namespace Weline\Product\Test\Unit\Extends\Module\Weline_Seo;

use PHPUnit\Framework\TestCase;


/**
 * Aggregated contract suite (merged from 1 *ContractTest files).
 * Observe one overall pass/fail via testAllProductWeline_SeoContractsPass().
 */
final class ProductWelineSeoContractsTest extends TestCase
{
    public function testAllProductWeline_SeoContractsPass(): void
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
            'ProductSeoDescriptionPad::testEmptyDescriptionTemplateIsPaddedPastSoftMinimum' => function () {
            $this->assert_ProductSeoDescriptionPad_testEmptyDescriptionTemplateIsPaddedPastSoftMinimum();
        },
        ];
    }

private function assert_ProductSeoDescriptionPad_testEmptyDescriptionTemplateIsPaddedPastSoftMinimum(): void
{
    $provider = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Product' . '/extends/module/Weline_Seo/SeoProfileProvider/ProductSeoProfileProvider.php');
    $detail = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Product' . '/Controller/Frontend/Detail.php');
    self::assertStringContainsString('if (mb_strlen($description) < 80)', $provider);
    self::assertStringNotContainsString('} elseif ($length < 80)', $provider);
    self::assertStringContainsString('if (mb_strlen($description) < 80)', $detail);
    self::assertStringContainsString("\$profileProduct['meta_description'] = \$description", $provider);
}
}

<?php

declare(strict_types=1);

namespace Weline\Promotion\Test\Unit\Controller;

use PHPUnit\Framework\TestCase;


/**
 * Aggregated contract suite (merged from 2 *ContractTest files).
 * Observe one overall pass/fail via testAllPromotionControllerContractsPass().
 */
final class PromotionControllerContractsTest extends TestCase
{
    public function testAllPromotionControllerContractsPass(): void
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
            'PromotionStorefrontSeoAssign::testPageServicePublishesSeoWithoutOverwritingUiPageType' => function () {
            $this->assert_PromotionStorefrontSeoAssign_testPageServicePublishesSeoWithoutOverwritingUiPageType();
        },
            'PromotionStorefrontSeoAssign::testControllerAssignsSeoBag' => function () {
            $this->assert_PromotionStorefrontSeoAssign_testControllerAssignsSeoBag();
        },
            'PromotionStorefrontSeoAssign::testSeoExtendsExist' => function () {
            $this->assert_PromotionStorefrontSeoAssign_testSeoExtendsExist();
        },
            'ThemeDefaultWebsiteScope::testThemeIndexTreatsWebsiteIdZeroAsScoped' => function () {
            $this->assert_ThemeDefaultWebsiteScope_testThemeIndexTreatsWebsiteIdZeroAsScoped();
        },
        ];
    }

private function assert_PromotionStorefrontSeoAssign_testPageServicePublishesSeoWithoutOverwritingUiPageType(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/Promotion' . '/Service/PromotionStorefrontPageService.php';
    $src = (string) file_get_contents($path);
    self::assertStringContainsString('PromotionSeoFactsBuilder', $src);
    self::assertStringContainsString("\$pageData['seo'] = \$this->seoFacts()->buildListingProfile", $src);
    self::assertStringContainsString("'page_type' => \$pageType,", $src);
    self::assertStringNotContainsString("'page_type' => 'product_list'", $src);
}

private function assert_PromotionStorefrontSeoAssign_testControllerAssignsSeoBag(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/Promotion' . '/Controller/Index.php';
    $src = (string) file_get_contents($path);
    self::assertStringContainsString("assign('seo'", $src);
    self::assertStringContainsString("\$data['seo']", $src);
    self::assertStringNotContainsString("setGet('page_type', 'product_list')", $src);
}

private function assert_PromotionStorefrontSeoAssign_testSeoExtendsExist(): void
{
    $root = '/Users/weline/Project/Official/框架/app/code/Weline/Promotion';
    self::assertFileExists($root . '/extends/module/Weline_Seo/SeoProfileProvider/PromotionSeoProfileProvider.php');
    self::assertFileExists($root . '/extends/module/Weline_Seo/SitemapUrlProvider/PromotionSitemapUrlProvider.php');
    self::assertFileExists($root . '/Service/PromotionSeoFactsBuilder.php');
}

private function assert_ThemeDefaultWebsiteScope_testThemeIndexTreatsWebsiteIdZeroAsScoped(): void
{
    $ctrl = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Promotion' . '/Controller/Backend/Theme.php');
    self::assertStringContainsString("hasGet('website_id')", $ctrl);
    self::assertStringNotContainsString("scope_filter_active', \$websiteId > 0 ||", $ctrl);
    $tpl = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Promotion' . '/view/templates/backend/promotion/theme/index.phtml');
    self::assertStringContainsString("'website_id' => \$scopeFilterActive ? \$filterWebsiteId : null", $tpl);
    self::assertStringNotContainsString("'website_id' => \$filterWebsiteId > 0 ? \$filterWebsiteId : null", $tpl);
}
}

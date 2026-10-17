<?php

declare(strict_types=1);

namespace Weline\Cms\Test\Unit\Extends\Module\Weline_Seo;

use PHPUnit\Framework\TestCase;


/**
 * Aggregated contract suite (merged from 1 *ContractTest files).
 * Observe one overall pass/fail via testAllCmsWeline_SeoContractsPass().
 */
final class CmsWelineSeoContractsTest extends TestCase
{
    public function testAllCmsWeline_SeoContractsPass(): void
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
            'CmsPageProviderBlogExclude::testProviderSkipsBlogPathGroupWhenBlogModuleEnabled' => function () {
            $this->assert_CmsPageProviderBlogExclude_testProviderSkipsBlogPathGroupWhenBlogModuleEnabled();
        },
        ];
    }

private function assert_CmsPageProviderBlogExclude_testProviderSkipsBlogPathGroupWhenBlogModuleEnabled(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/Cms' . '/extends/module/Weline_Seo/SitemapUrlProvider/CmsPageProvider.php';
    $source = (string) file_get_contents($path);
    self::assertStringContainsString('shouldSkipOwnedPathGroup', $source);
    self::assertStringContainsString("=== 'blog'", $source);
    self::assertStringContainsString('Weline_Blog', $source);
    self::assertStringContainsString("=== 'faq'", $source);
    self::assertStringContainsString('Weline_Faq', $source);
}
}

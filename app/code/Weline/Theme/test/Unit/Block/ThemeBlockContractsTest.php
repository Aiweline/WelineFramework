<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\Block;

use PHPUnit\Framework\TestCase;
use Weline\Theme\Block\Partials;

/**
 * Aggregated contract suite (merged from 3 *ContractTest files).
 * Observe one overall pass/fail via testAllThemeBlockContractsPass().
 */
final class ThemeBlockContractsTest extends TestCase
{
    public function testAllThemeBlockContractsPass(): void
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
            'PartialsBreadcrumbContext::testRenderPartialsInjectsBreadcrumbTrailFromTemplate' => function () {
            $this->assert_PartialsBreadcrumbContext_testRenderPartialsInjectsBreadcrumbTrailFromTemplate();
        },
            'PartialsHtmlOutputCacheKey::testPartialOutputKeysUseSystemEnvironmentScopedBuilder' => function () {
            $this->assert_PartialsHtmlOutputCacheKey_testPartialOutputKeysUseSystemEnvironmentScopedBuilder();
        },
            'PartialsPostResponseCaptureMode::testPartialOutputRefreshUsesScopeCaptureMode' => function () {
            $this->assert_PartialsPostResponseCaptureMode_testPartialOutputRefreshUsesScopeCaptureMode();
        },
        ];
    }

private function assert_PartialsBreadcrumbContext_testRenderPartialsInjectsBreadcrumbTrailFromTemplate(): void
{
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Theme' . '/Block/Partials.php');
    self::assertStringContainsString("strtolower(\$type) === 'breadcrumb'", $src);
    self::assertStringContainsString("storefront_product_breadcrumbs", $src);
    self::assertStringContainsString("storefront_category_breadcrumbs", $src);
    self::assertStringContainsString("\$seoBag['breadcrumbs']", $src);
    self::assertStringContainsString("\$data['breadcrumbs'] = \$seoBag['breadcrumbs']", $src);
    // Page-trail partial must not fall into shared chrome cache (poisoned PLP crumbs).
    self::assertDoesNotMatchRegularExpression("/'head', 'loading', 'scripts', 'topnav', 'sidebar', 'right-sidebar', 'header', 'footer', 'breadcrumb' => 'role'/", $src);
    self::assertStringContainsString("'head', 'loading', 'scripts', 'topnav', 'sidebar', 'right-sidebar', 'header', 'footer' => 'role'", $src);
}

private function assert_PartialsHtmlOutputCacheKey_testPartialOutputKeysUseSystemEnvironmentScopedBuilder(): void
{
    $src = (string) \file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Theme' . '/Block/Partials.php');
    self::assertStringContainsString('function resolvePartialOutputCacheKey(', $src);
    self::assertStringContainsString('KeyBuilder::buildEnvironmentScoped(', $src);
    self::assertStringContainsString("'theme.head.partial'", $src);
    self::assertStringContainsString("'theme.chrome.partial'", $src);
    // v25 supersedes v24 nested-footer-extras-filled (same Partials bag; footer-tail fp added).
    self::assertStringContainsString("'schema' => 'chrome-partial-v25-footer-tail-chrome-fp'", $src);
    self::assertStringContainsString('fillNestedChromeCarriersForSharedBag', $src);
    self::assertStringContainsString("\$typeLc !== 'header' && \$typeLc !== 'footer'", $src);
    self::assertStringNotContainsString('\sha1($path . \'|\' . $sourceFingerprint . \'|\' . $cacheContext)', $src);
    self::assertStringNotContainsString('\sha1($fileName . \'|\' . $sourceFingerprint . \'|\' . $cacheContext)', $src);
    self::assertStringNotContainsString('return KeyBuilder::environmentHash($hashPayload, $hashDims);', $src);
}

private function assert_PartialsPostResponseCaptureMode_testPartialOutputRefreshUsesScopeCaptureMode(): void
{
    $src = (string) \file_get_contents((string) (new \ReflectionClass(Partials::class))->getFileName());
    self::assertMatchesRegularExpression('/function queuePartialOutputRefresh[\s\S]*PostResponseCaptureMode::Scope/', $src);
    self::assertMatchesRegularExpression('/function queuePartialOutputRefresh[\s\S]*PostResponseTaskQueue::isDraining\(\)/', $src);
}
}

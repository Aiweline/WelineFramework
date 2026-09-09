<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\View;

use PHPUnit\Framework\TestCase;

/** FAQ 布局：壳保留；业务 FAQ 由 Weline_Faq::FaqHubContent 供给。 */
final class FaqLayoutPolicyContentContractTest extends TestCase
{
    public function testFaqLayoutConsumesFaqModuleHubContent(): void
    {
        $path = dirname(__DIR__, 3) . '/view/theme/frontend/layouts/faq/default.phtml';
        self::assertFileExists($path);
        $source = (string)file_get_contents($path);

        self::assertStringContainsString('data-layout="faq"', $source);
        self::assertStringContainsString('FaqHubContent', $source);
        self::assertStringContainsString("getData('faq_topics')", $source);
        self::assertStringContainsString("getData('faq_faqs')", $source);
        self::assertStringContainsString("getData('faq_quick_links')", $source);
        self::assertStringNotContainsString("['q' => __('下单后多久发货？')", $source);
        self::assertStringContainsString('data-testid="faq-support"', $source);
        self::assertStringContainsString('faq-layout__support-grid', $source);
        self::assertStringContainsString('faq-layout__quick-grid', $source);
        self::assertStringContainsString('faq-layout__quick-link', $source);
        self::assertStringContainsString('faq-layout__sidebar-slot', $source);
        self::assertDoesNotMatchRegularExpression(
            '/\.faq-layout__support-grid,\s*\n\s*\.faq-layout__sidebar-slot\s*\{/',
            $source
        );
        self::assertStringContainsString('.faq-layout__sidebar-slot', $source);
        self::assertStringContainsString('display: block', $source);
        self::assertStringNotContainsString('faq-layout__grid', $source);
    }

    public function testFaqHubContentStillCoversPolicyShortcuts(): void
    {
        $hubPath = dirname(__DIR__, 4) . '/Faq/Service/FaqHubContent.php';
        self::assertFileExists($hubPath);
        $source = (string)file_get_contents($hubPath);
        self::assertStringContainsString("'url' => 'refund'", $source);
        self::assertStringContainsString("'url' => 'cookies'", $source);
        self::assertStringContainsString("'url' => 'privacy'", $source);
        self::assertStringContainsString("'url' => 'guide/returns'", $source);
        self::assertGreaterThanOrEqual(8, substr_count($source, "'q' =>"));
    }
}

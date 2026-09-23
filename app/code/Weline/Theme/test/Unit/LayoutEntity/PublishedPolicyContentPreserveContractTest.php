<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\LayoutEntity;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Weline\Theme\Service\LayoutEntity\ThemeLayoutEntityPublishedSlotHost;

/**
 * theme-published-policy-body: sparse content bake must not wipe policy/terms layout body.
 */
final class PublishedPolicyContentPreserveContractTest extends TestCase
{
    public function testHostDetectsPolicyBodyAndSparseNewsletterOverlay(): void
    {
        $policyBody = '<div class="policy-main" data-layout="policy-privacy">'
            . '<header class="amazon-policy__hero"><p class="amazon-policy__hero-lead">'
            . '我们会认真保护您的个人信息。</p></header></div>';
        $sparse = '<section data-widget-code="newsletter-popup" data-testid="newsletter-popup">'
            . '订阅我们的邮件</section>';

        $bodyDetect = new ReflectionMethod(
            ThemeLayoutEntityPublishedSlotHost::class,
            'defaultCarriesLayoutPolicyOrTermsBody',
        );
        $bodyDetect->setAccessible(true);
        self::assertTrue($bodyDetect->invoke(null, $policyBody));
        self::assertFalse($bodyDetect->invoke(null, $sparse));

        $sparseDetect = new ReflectionMethod(
            ThemeLayoutEntityPublishedSlotHost::class,
            'bakeIsSparseContentOverlay',
        );
        $sparseDetect->setAccessible(true);
        self::assertTrue($sparseDetect->invoke(null, $sparse, $policyBody));
        self::assertFalse($sparseDetect->invoke(null, $sparse, '   '));
    }

    public function testSlotFillerProtectsPolicyNestedContent(): void
    {
        $src = (string)\file_get_contents(
            \dirname(__DIR__, 3) . '/Service/LayoutEntity/ThemeLayoutEntitySlotFiller.php'
        );
        self::assertStringContainsString('shellContentCarriesProtectedNestedLayout', $src);
        self::assertStringContainsString('amazon-policy__', $src);
        self::assertStringContainsString('policy-main', $src);

        $observer = (string)\file_get_contents(
            \dirname(__DIR__, 3) . '/Observer/LayoutSlotRenderer.php'
        );
        self::assertStringContainsString('forcing HOME would plan content→newsletter', $observer);
        self::assertStringContainsString('amazon-policy__', $observer);
        self::assertStringContainsString('PAGE_TYPE_POLICY', $observer);
    }

    public function testPolicyLayoutsLinkCookieNotCookies(): void
    {
        $base = \dirname(__DIR__, 3) . '/view/theme/frontend/layouts/policy';
        foreach (['privacy.phtml', 'term-condition.phtml'] as $file) {
            $src = (string)\file_get_contents($base . '/' . $file);
            self::assertStringNotContainsString("@url{'cookies'}", $src, $file);
            self::assertStringContainsString("@url{'cookie'}", $src, $file);
        }
    }
}

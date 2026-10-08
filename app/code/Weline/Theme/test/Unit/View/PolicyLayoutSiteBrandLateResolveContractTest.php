<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\View;

use PHPUnit\Framework\TestCase;

/**
 * Policy document widget re-resolves SiteBrand so eyebrow never sticks Backend 默认站品牌.
 * (Previously inlined in each amazon policy layout; now owned by policy-document.)
 */
final class PolicyLayoutSiteBrandLateResolveContractTest extends TestCase
{
    public function testPolicyDocumentWidgetLateResolvesSiteBrandForEyebrow(): void
    {
        $path = dirname(__DIR__, 3) . '/view/theme/frontend/widgets/content/policy-document/default.phtml';
        self::assertFileExists($path);
        $src = (string)file_get_contents($path);
        self::assertStringContainsString('SiteBrand', $src);
        self::assertStringContainsString('resolveFrontendSiteName()', $src);
        self::assertStringContainsString('amazon-policy__eyebrow', $src);
        self::assertMatchesRegularExpression(
            '/resolveFrontendSiteName\\(\\)[\\s\\S]*amazon-policy__eyebrow/',
            $src,
            'SiteBrand resolve must feed eyebrow'
        );
    }
}

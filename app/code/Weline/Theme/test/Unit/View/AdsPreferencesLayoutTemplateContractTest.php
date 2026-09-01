<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\View;

use PHPUnit\Framework\TestCase;

final class AdsPreferencesLayoutTemplateContractTest extends TestCase
{
    public function testAdsPreferencesLayoutHasPolicyShellSlotsAndDefaultCopy(): void
    {
        $path = dirname(__DIR__, 3) . '/view/theme/frontend/layouts/policy/ads-preferences.phtml';
        self::assertFileExists($path);

        $source = (string)file_get_contents($path);
        self::assertStringContainsString('type="header"', $source);
        self::assertStringContainsString('type="footer"', $source);
        self::assertStringContainsString('data-testid="storefront-ads-preferences-page"', $source);
        self::assertStringContainsString('data-layout="policy-ads-preferences"', $source);
        self::assertStringContainsString('policy-ads-preferences__content-root-slot', $source);
        self::assertStringContainsString('id="content"', $source);
        self::assertStringContainsString('id="policy-ads-preferences-content"', $source);
        self::assertStringContainsString('<lang>广告偏好</lang>', $source);
        self::assertStringContainsString('<lang>一、什么是广告偏好？</lang>', $source);
        self::assertStringContainsString("@url{'privacy'}", $source);
        self::assertStringContainsString("@url{'cookies'}", $source);
        self::assertStringContainsString("@url{'terms'}", $source);
        self::assertStringContainsString('Weline_Theme::frontend::layouts::policy.ads-preferences::head-after', $source);
        self::assertStringContainsString('Weline_Theme::frontend::layouts::base::body-end', $source);
        self::assertStringContainsString('Weline_Theme::frontend::layouts::base::body-start', $source);
        self::assertSame(1, substr_count($source, 'id="content"'));
        self::assertStringNotContainsString('<?= __(', $source);
    }
}

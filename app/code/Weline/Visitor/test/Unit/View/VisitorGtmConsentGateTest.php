<?php

declare(strict_types=1);

namespace Weline\Visitor\Test\Unit\View;

use PHPUnit\Framework\TestCase;

/**
 * 分析/营销脚本必须同意门控：未取得同意前不得加载 GA4 与 GTM 容器。
 */
final class VisitorGtmConsentGateTest extends TestCase
{
    public function testGtmLoaderIsConsentGated(): void
    {
        $source = (string)\file_get_contents(BP . 'app/code/Weline/Visitor/view/statics/js/pixel.js');

        self::assertStringContainsString(
            'consentAllowed: __visitorMarketingConsentAllowsStorage()',
            $source,
            'GTM 配置必须携带营销同意标记',
        );
        self::assertStringContainsString(
            'runtime.consentAllowed !== true',
            $source,
            'GTM 加载器必须在未同意时提前返回',
        );
    }

    public function testGa4LoaderIsConsentGated(): void
    {
        $source = (string)\file_get_contents(BP . 'app/code/Weline/Visitor/view/statics/js/pixel.js');
        self::assertStringContainsString("__visitorConsentAllows('analytics')", $source);
        self::assertStringContainsString('runtime.eventsAllowed === true', $source);
    }

    public function testConsentStorageChangeReevaluatesLoaders(): void
    {
        $source = (string)\file_get_contents(BP . 'app/code/Weline/Visitor/view/statics/js/pixel.js');
        self::assertMatchesRegularExpression(
            "/addEventListener\\('storage'[\\s\\S]{0,400}__loadVisitorGa4\\(\\);[\\s\\S]{0,120}__loadVisitorGtm\\(\\);/",
            $source,
            '同意状态变化后应重估 GA4/GTM 加载',
        );
    }
}

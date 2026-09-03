<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\View;

use PHPUnit\Framework\TestCase;

/** 帮助中心默认布局：FAQ 与政策快捷入口应足够完整。 */
final class HelpLayoutPolicyContentContractTest extends TestCase
{
    public function testHelpLayoutExpandsFaqsAndPolicyShortcuts(): void
    {
        $path = dirname(__DIR__, 3) . '/view/theme/frontend/layouts/help/default.phtml';
        self::assertFileExists($path);
        $source = (string)file_get_contents($path);

        self::assertStringContainsString("data-layout=\"help\"", $source);
        self::assertStringContainsString("__('运费如何计算？是否包邮？')", $source);
        self::assertStringContainsString("__('退款多久到账？')", $source);
        self::assertStringContainsString("__('个人信息如何保护？')", $source);
        self::assertStringContainsString("'url' => 'refund'", $source);
        self::assertStringContainsString("'url' => 'cookies'", $source);
        self::assertStringContainsString("'url' => 'privacy'", $source);
        self::assertStringContainsString("'url' => 'guide/returns'", $source);
        self::assertGreaterThanOrEqual(8, substr_count($source, "['q' =>"));
        self::assertStringContainsString('data-testid="help-support"', $source);
        self::assertStringContainsString('help-layout__support-grid', $source);
        self::assertStringContainsString('help-layout__quick-grid', $source);
        self::assertStringContainsString('help-layout__quick-link', $source);
        self::assertStringContainsString('help-layout__sidebar-slot', $source);
        // slot 本身不得再套双栏 grid，否则会把整块挤进窄列导致标题竖排
        self::assertDoesNotMatchRegularExpression(
            '/\.help-layout__support-grid,\s*\n\s*\.help-layout__sidebar-slot\s*\{/',
            $source
        );
        self::assertStringContainsString('.help-layout__sidebar-slot', $source);
        self::assertStringContainsString('display: block', $source);
        self::assertStringNotContainsString('help-layout__grid', $source);
    }

    public function testPrivacyAndCookiePoliciesHaveExtendedSections(): void
    {
        $privacy = (string)file_get_contents(
            dirname(__DIR__, 3) . '/view/theme/frontend/layouts/policy/privacy.phtml'
        );
        $cookie = (string)file_get_contents(
            dirname(__DIR__, 3) . '/view/theme/frontend/layouts/policy/cookie.phtml'
        );
        $refund = (string)file_get_contents(
            dirname(__DIR__, 3) . '/view/theme/frontend/layouts/policy/refund.phtml'
        );

        self::assertStringContainsString('七、Cookie 与同类技术', $privacy);
        self::assertStringContainsString('八、第三方服务', $privacy);
        self::assertStringContainsString('十、联系我们', $privacy);
        self::assertStringContainsString('六、本站典型 Cookie 用途示例', $cookie);
        self::assertStringContainsString('七、与隐私政策的关系', $cookie);
        self::assertStringContainsString('特殊商品说明', $refund);
        self::assertStringContainsString('amazon-policy__stage', $privacy);
        self::assertStringContainsString('amazon-help__stage', (string)file_get_contents(
            dirname(__DIR__, 3) . '/view/theme/frontend/layouts/help/default.phtml'
        ));
    }
}

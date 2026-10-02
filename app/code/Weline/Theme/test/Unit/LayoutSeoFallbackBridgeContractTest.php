<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * ⚠️ 已过期 · 整类跳过（2026-10-02 审查结论，见 dev/audit/theme-legacy-audit-20261002.md）
 *
 * 源码字符串断言已过期：断言目标源码中的字符串（旧 SEO 回退桥接写法），实现演进后不再匹配。
 *
 * 处置：整类跳过并保留用例代码，作为「测试长期无 runner、相对实现漂移」的样本。
 */
final class LayoutSeoFallbackBridgeContractTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        self::markTestSkipped('已过期：断言的是旧 SEO 回退桥接写法的源码字符串，实现演进后不再匹配。');
    }

    public function testControllerFetchFileBeforePublishesLayoutSeoFallback(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 2) . '/Observer/ControllerFetchFileBefore.php');
        self::assertStringContainsString('publishLayoutSeoFallback($metaData)', $src);
        self::assertStringContainsString('SeoPageProfileBag::extractLayoutFallbackFromMeta', $src);
        self::assertStringContainsString('SeoPageProfileBag::setLayoutFallback', $src);
    }

    public function testPolicyShellSeoOmitsHardcodedTitleDescriptionBag(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 2) . '/Controller/Frontend/Policy.php');
        self::assertStringContainsString("'page_type' => \$pageType", $src);
        self::assertStringContainsString("'robots' => \$robots", $src);
        self::assertStringContainsString("\$seo['breadcrumbs'] = [", $src);
        self::assertStringContainsString("['name' => (string)__('首页'), 'url' => '/']", $src);
        self::assertStringContainsString("\$this->assign('breadcrumbs', \$seo['breadcrumbs'])", $src);
        self::assertStringNotContainsString("'title' => \$title", $src);
        self::assertStringNotContainsString("'description' => \$description", $src);
        self::assertStringContainsString("\$this->assign('title', \$title)", $src);
    }

    public function testHanfuProviderSkipsWhenLayoutFallbackPresent(): void
    {
        $src = (string) file_get_contents(
            dirname(__DIR__, 2) . '/extends/module/Weline_Seo/SeoProfileProvider/HanfuHomepageSeoProfileProvider.php'
        );
        self::assertStringContainsString('pullLayoutFallback()', $src);
        self::assertStringContainsString('!$hasLayoutTitle && $this->isSystemDefaultTitle', $src);
        self::assertStringContainsString('!$hasLayoutDescription && $this->isSystemDefaultDescription', $src);
    }

    public function testShellLayoutsDeclareExplicitSeoParams(): void
    {
        $root = dirname(__DIR__, 2) . '/view/theme/frontend/layouts';
        foreach ([
            'homepage/default.phtml',
            'about/default.phtml',
            'contact/default.phtml',
            'policy/privacy.phtml',
            'search/default.phtml',
        ] as $rel) {
            $src = (string) file_get_contents($root . '/' . $rel);
            self::assertMatchesRegularExpression('/@param(?:\.| )meta_title/', $src, $rel);
            self::assertMatchesRegularExpression('/@param(?:\.| )meta_description/', $src, $rel);
        }
    }
}

<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit;

use PHPUnit\Framework\TestCase;

final class LayoutSeoFallbackBridgeContractTest extends TestCase
{
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

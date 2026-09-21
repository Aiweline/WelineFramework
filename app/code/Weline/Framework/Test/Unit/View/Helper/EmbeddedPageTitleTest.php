<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\View\Helper;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Weline\Framework\View\Helper\EmbeddedPageTitle;
use Weline\Framework\View\Helper\HtmlCacheAdmission;
use Weline\Framework\View\Template;

final class EmbeddedPageTitleTest extends TestCase
{
    public function testTreatsModuleCodeAsPlaceholder(): void
    {
        self::assertTrue(EmbeddedPageTitle::isInternalIdentifier('Weline_Customer'));
        self::assertTrue(EmbeddedPageTitle::isInternalIdentifier('WeShop_Subscription'));
        self::assertFalse(EmbeddedPageTitle::isInternalIdentifier(''));
        self::assertFalse(EmbeddedPageTitle::isInternalIdentifier('我的订阅服务'));
        self::assertFalse(EmbeddedPageTitle::isInternalIdentifier('关于我们'));
        self::assertFalse(EmbeddedPageTitle::isInternalIdentifier('About Us'));

        self::assertTrue(EmbeddedPageTitle::isModulePlaceholder(''));
        self::assertTrue(EmbeddedPageTitle::isModulePlaceholder('Weline_Theme'));
        self::assertFalse(EmbeddedPageTitle::isModulePlaceholder('关于我们'));
    }

    public function testSanitizePublicSlotsClearsModuleTitles(): void
    {
        $data = EmbeddedPageTitle::sanitizePublicSlots([
            'title' => 'Weline_Theme',
            'meta_title' => 'Weline_Faq',
            'subtitle' => 'keep',
            'meta' => [
                'title' => 'Weline_Customer',
                'controller_title' => '真实标题',
                'meta_title' => '',
            ],
        ]);

        self::assertSame('', $data['title']);
        self::assertSame('', $data['meta_title']);
        self::assertSame('keep', $data['subtitle']);
        self::assertSame('', $data['meta']['title']);
        self::assertSame('真实标题', $data['meta']['controller_title']);
        self::assertSame('', $data['meta']['meta_title']);
    }

    public function testSanitizePublicSlotsLeavesNormalTitles(): void
    {
        $data = EmbeddedPageTitle::sanitizePublicSlots([
            'title' => '关于我们',
            'meta' => ['title' => 'About Us'],
        ]);
        self::assertSame('关于我们', $data['title']);
        self::assertSame('About Us', $data['meta']['title']);
    }

    public function testHtmlAdmissibleRejectsModuleCodeHeadings(): void
    {
        self::assertFalse(EmbeddedPageTitle::htmlAdmissible('<h1>Weline_Faq</h1>'));
        self::assertFalse(EmbeddedPageTitle::htmlAdmissible('<title>Weline_Theme</title>'));
        self::assertTrue(EmbeddedPageTitle::htmlAdmissible('<title>关于我们 | 店</title><h1>关于我们</h1>'));
    }

    public function testHtmlCacheAdmissionCombinesTitleAndProductCardRules(): void
    {
        self::assertFalse(HtmlCacheAdmission::admit(''));
        self::assertFalse(HtmlCacheAdmission::admit('<h1>Weline_Theme</h1>'));
        self::assertFalse(HtmlCacheAdmission::admit('<article data-testid="weline-product-card">x</article>'));
        self::assertTrue(HtmlCacheAdmission::admit(
            '<style data-weline-product-card-css="1"></style>'
            . '<article data-testid="weline-product-card">ok</article>'
            . '<h1>商品</h1>'
        ));
    }

    public function testTemplateSetDataClearsModuleTitle(): void
    {
        $template = (new \ReflectionClass(Template::class))->newInstanceWithoutConstructor();
        $template->setData('title', 'Weline_Theme');
        self::assertSame('', (string)$template->getData('title'));
        $template->setData('title', '关于我们');
        self::assertSame('关于我们', (string)$template->getData('title'));
    }

    public function testWithAssignedTitleInMetaSkipsModulePlaceholder(): void
    {
        $template = (new \ReflectionClass(Template::class))->newInstanceWithoutConstructor();
        $method = new ReflectionMethod(Template::class, 'withAssignedTitleInMeta');
        $method->setAccessible(true);

        $meta = $method->invoke($template, [], 'Weline_Customer');
        self::assertArrayNotHasKey('title', $meta);
        self::assertArrayNotHasKey('controller_title', $meta);

        $meta = $method->invoke($template, [], '关于我们');
        self::assertSame('关于我们', $meta['title'] ?? null);
        self::assertSame('关于我们', $meta['controller_title'] ?? null);

        $meta = $method->invoke($template, ['title' => '已有标题'], 'Weline_Theme');
        self::assertSame('已有标题', $meta['title']);
        self::assertArrayNotHasKey('controller_title', $meta);
    }

    public function testSyncAssignedTitleToMetaSkipsModulePlaceholder(): void
    {
        $template = (new \ReflectionClass(Template::class))->newInstanceWithoutConstructor();
        $method = new ReflectionMethod(Template::class, 'syncAssignedTitleToMeta');
        $method->setAccessible(true);

        $method->invoke($template, 'Weline_Customer');
        $meta = $template->getData('meta');
        self::assertTrue($meta === null || $meta === [] || !isset($meta['title']));

        $method->invoke($template, '页面标题');
        $meta = $template->getData('meta');
        self::assertIsArray($meta);
        self::assertSame('页面标题', $meta['title'] ?? null);
        self::assertSame('页面标题', $meta['controller_title'] ?? null);
    }
}

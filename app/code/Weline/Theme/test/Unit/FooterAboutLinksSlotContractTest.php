<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit;

use PHPUnit\Framework\TestCase;
use Weline\Theme\Helper\FooterDefaultLinksHelper;

/**
 * 页脚「了解我们」扩展槽迁入 footer-container；硬链仅「关于我们」。
 */
final class FooterAboutLinksSlotContractTest extends TestCase
{
    public function testFooterContainerExposesAboutExtrasSlot(): void
    {
        $path = dirname(__DIR__, 2) . '/view/theme/frontend/widgets/container/footer/default.phtml';
        $src = (string)file_get_contents($path);

        self::assertStringContainsString('<w:slot id="footer-about-links"', $src);
        self::assertStringContainsString('accept="footer-blog-link,footer-news-link,layout-footer-about-links"', $src);
        self::assertStringContainsString('class="footer-section__extras"', $src);
        self::assertStringContainsString('footer-section__link', $src);
        self::assertStringNotContainsString('<ul class="footer-section__list">', $src);
        self::assertStringNotContainsString('人才招聘', $src);
        self::assertStringNotContainsString('投资者关系', $src);
        self::assertStringNotContainsString('"/blog"', $src);
        self::assertStringNotContainsString('"/news"', $src);
    }

    public function testPartialNoLongerHostsAboutSlot(): void
    {
        $path = dirname(__DIR__, 2) . '/view/theme/frontend/partials/footer/default.phtml';
        $src = (string)file_get_contents($path);

        self::assertStringNotContainsString('<w:slot id="footer-about-links"', $src);
        self::assertStringContainsString('footer-container', $src);
    }

    public function testHelperDefaultAboutItem(): void
    {
        $items = FooterDefaultLinksHelper::defaultLinkItems();
        $about = array_values(array_filter(
            $items,
            static fn (array $row): bool => ($row['group_key'] ?? '') === 'about'
        ));
        self::assertCount(1, $about);
        self::assertSame('关于我们', $about[0]['label'] ?? null);
        self::assertSame('/about', $about[0]['url'] ?? null);
    }
}

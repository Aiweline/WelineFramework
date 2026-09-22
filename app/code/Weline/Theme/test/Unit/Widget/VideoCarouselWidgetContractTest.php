<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\Widget;

use PHPUnit\Framework\TestCase;

/**
 * video-carousel 部件 / ParamSchema / 登记 / 首页默认嵌件合同。
 */
final class VideoCarouselWidgetContractTest extends TestCase
{
    public function testParamSchemaDeclaresCarouselItemFields(): void
    {
        $path = dirname(__DIR__, 3) . '/Ui/ParamSchema/video_carousel_items.php';
        self::assertFileExists($path);
        /** @var array<string, mixed> $schema */
        $schema = require $path;

        self::assertSame('array', $schema['base_type'] ?? null);
        self::assertTrue((bool)($schema['sortable'] ?? false));
        self::assertSame(12, (int)($schema['max_items'] ?? 0));
        self::assertSame('添加视频', (string)($schema['add_label'] ?? ''));

        $itemSchema = $schema['item_schema'] ?? null;
        self::assertIsArray($itemSchema);
        self::assertSame('select', $itemSchema['video_type']['type'] ?? null);
        self::assertSame('url', $itemSchema['video_url']['type'] ?? null);
        self::assertSame('textarea', $itemSchema['embed_code']['type'] ?? null);
        self::assertSame('string', $itemSchema['title']['type'] ?? null);
        self::assertSame('string', $itemSchema['author']['type'] ?? null);
        self::assertSame('textarea', $itemSchema['description']['type'] ?? null);
        self::assertSame('media_image', $itemSchema['poster']['type'] ?? null);
        self::assertSame('product_picker', $itemSchema['product_ids']['type'] ?? null);

        $options = $itemSchema['video_type']['options'] ?? [];
        self::assertIsArray($options);
        foreach (['youtube', 'vimeo', 'bilibili', 'self', 'embed'] as $platform) {
            self::assertArrayHasKey($platform, $options);
        }
    }

    public function testWidgetTemplateExistsWithCarouselContractMarkers(): void
    {
        $path = dirname(__DIR__, 3) . '/view/theme/frontend/widgets/video/video-carousel/default.phtml';
        self::assertFileExists($path);
        $source = (string)file_get_contents($path);

        self::assertStringContainsString('@widget.code {video-carousel}', $source);
        self::assertStringContainsString('@widget.type {video}', $source);
        self::assertStringContainsString('@param items {default=[],type="video_carousel_items"', $source);
        self::assertStringContainsString('data-site-block="video-carousel"', $source);
        self::assertStringContainsString('data-testid="video-carousel"', $source);
        self::assertStringContainsString('VideoEmbedResolver', $source);
        self::assertStringContainsString('resolveBilibiliId', $source);
        self::assertStringContainsString('trustedEmbedHosts()', $source);
        self::assertStringContainsString('cardsByIds', $source);
        self::assertStringContainsString('data-w-component="dialog"', $source);
        self::assertStringContainsString('class="w-dialog', $source);
        self::assertStringContainsString('<w:product:card', $source);
        self::assertStringContainsString('data-action="carousel-next"', $source);
        self::assertStringContainsString('data-carousel-field="author"', $source);
        self::assertStringContainsString('data-carousel-field="description"', $source);
        self::assertStringContainsString('查看关联商品', $source);
        self::assertStringNotContainsString('fetch(', $source);
        self::assertStringNotContainsString('XMLHttpRequest', $source);
    }

    public function testWidgetPhpRegistersVideoCarouselTemplate(): void
    {
        $path = dirname(__DIR__, 3) . '/extends/module/Weline_Widget/Weline_Theme/widget.php';
        self::assertFileExists($path);
        $source = (string)file_get_contents($path);

        self::assertStringContainsString(
            'Weline_Theme::theme/frontend/widgets/video/video-carousel/default.phtml',
            $source
        );
        self::assertStringContainsString("'type' => 'video_carousel_items'", $source);
        self::assertStringContainsString(
            'Weline_Theme::theme/frontend/widgets/video/video-player/default.phtml',
            $source
        );
    }

    public function testHomepageLayoutsDefaultToVideoCarousel(): void
    {
        $themeHomepage = dirname(__DIR__, 3) . '/view/theme/frontend/layouts/homepage/default.phtml';
        $hanfuHomepage = dirname(__DIR__, 6) . '/design/Weline/hanfu/frontend/layouts/homepage/default.phtml';
        self::assertFileExists($themeHomepage);
        self::assertFileExists($hanfuHomepage);

        foreach ([$themeHomepage, $hanfuHomepage] as $path) {
            $source = (string)file_get_contents($path);
            self::assertStringContainsString('id="homepage-videos"', $source);
            self::assertStringContainsString('video-carousel', $source);
            self::assertStringContainsString('<w:widget type="video" name="video-carousel"', $source);
            self::assertStringContainsString(
                'accept="layout-homepage-videos,video,video-player,video-carousel,content"',
                $source
            );
            self::assertStringNotContainsString(
                '<w:widget type="video" name="video-player"',
                $source
            );
        }
    }

    public function testCarouselJsModuleRegistered(): void
    {
        $js = dirname(__DIR__, 3) . '/view/statics/js/widgets/video-carousel.js';
        $modules = dirname(__DIR__, 3) . '/view/statics/frontend/weline.modules.js';
        self::assertFileExists($js);
        self::assertFileExists($modules);

        $jsSource = (string)file_get_contents($js);
        $modulesSource = (string)file_get_contents($modules);

        self::assertStringContainsString('Weline.UI.dialog', $jsSource);
        self::assertStringContainsString('carousel-next', $jsSource);
        self::assertStringContainsString("removeAttribute('hidden')", $jsSource);
        self::assertStringNotContainsString('fetch(', $jsSource);
        self::assertStringContainsString('videoCarousel:', $modulesSource);
        self::assertStringContainsString('Weline_Theme::js/widgets/video-carousel.js', $modulesSource);
    }
}

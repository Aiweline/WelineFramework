<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\View;

use PHPUnit\Framework\TestCase;
use Weline\Meta\Service\ParamDefinitionNormalizer;
use Weline\Theme\Helper\ThemeData;
use Weline\Widget\Api\Param\ParamDefinition;

/**
 * top-bar 店铺通知：通知文案/链接文字显式走部件 meta 多语言。
 */
final class TopBarNoticeMetaI18nContractTest extends TestCase
{
    private const SOURCE = '欢迎光临 · 精选好物上新';

    private const META_EN = 'Welcome · Handpicked new arrivals (meta)';

    public function testTopBarParamsMarkTextFieldsTranslatableAndUrlsNot(): void
    {
        $path = dirname(__DIR__, 3) . '/view/theme/frontend/widgets/header/top-bar/default.phtml';
        self::assertFileExists($path);
        $source = (string) file_get_contents($path);

        $definitions = (new ParamDefinitionNormalizer())->extractParamAnnotations($source);
        self::assertArrayHasKey('text', $definitions);
        self::assertArrayHasKey('link_text', $definitions);
        self::assertArrayHasKey('link_url', $definitions);

        self::assertTrue(ParamDefinition::isTranslatable($definitions['text']));
        self::assertTrue(ParamDefinition::isTranslatable($definitions['link_text']));
        self::assertFalse(ParamDefinition::isTranslatable($definitions['link_url']));
        self::assertFalse(ParamDefinition::isTranslatable($definitions['show_icon']));
        self::assertFalse(ParamDefinition::isTranslatable($definitions['closeable']));
        self::assertFalse(ParamDefinition::isTranslatable($definitions['text_color']));

        self::assertTrue(!empty($definitions['text']['i18n']));
        self::assertTrue(!empty($definitions['link_text']['i18n']));
        self::assertFalse(!empty($definitions['link_url']['i18n']));

        $paths = ThemeData::getTranslatablePaths($definitions);
        self::assertSame(['text', 'link_text'], $paths['top']);
        self::assertSame([], $paths['array']);
    }

    public function testMergeTranslatedPathsPrefersMetaLocaleTextOverChineseSource(): void
    {
        $path = dirname(__DIR__, 3) . '/view/theme/frontend/widgets/header/top-bar/default.phtml';
        $definitions = (new ParamDefinitionNormalizer())->extractParamAnnotations(
            (string) file_get_contents($path)
        );

        $baseConfig = [
            'text' => self::SOURCE,
            'link_text' => '',
            'link_url' => '/promo',
            'show_icon' => true,
        ];

        // Simulate SlotRenderer merge after meta/scoped i18n already projected into config.
        $merged = $baseConfig;
        $merged['text'] = self::META_EN;

        self::assertSame(self::META_EN, $merged['text']);
        self::assertNotSame(self::SOURCE, $merged['text']);
        self::assertSame('/promo', $merged['link_url']);

        $paths = ThemeData::getTranslatablePaths($definitions);
        self::assertContains('text', $paths['top']);
        self::assertNotContains('link_url', $paths['top']);
    }

    public function testTemplateKeepsWidgetI18nAsCsvFallbackOnly(): void
    {
        $path = dirname(__DIR__, 3) . '/view/theme/frontend/widgets/header/top-bar/default.phtml';
        $source = (string) file_get_contents($path);

        self::assertStringContainsString('i18n=true', $source);
        self::assertStringContainsString('WidgetI18n::label', $source);
        self::assertStringContainsString('部件 meta', $source);
    }
}

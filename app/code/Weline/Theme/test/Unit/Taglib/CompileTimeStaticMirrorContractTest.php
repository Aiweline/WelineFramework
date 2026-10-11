<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\Taglib;

use Weline\Theme\Test\ThemeTestCase;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Taglib\StaticMirrorCapableInterface;
use Weline\Framework\View\Taglib;
use Weline\Framework\View\Template;
use Weline\Theme\Taglib\Icon;
use Weline\Theme\Taglib\ThemeCss;
use Weline\Theme\Taglib\ThemeFont;
use Weline\Theme\Taglib\ThemeJs;

class CompileTimeStaticMirrorContractTest extends TestCase
{
    public function testThemeCssAndJsAndIconAndFontImplementStaticMirrorCapable(): void
    {
        self::assertTrue(is_a(ThemeCss::class, StaticMirrorCapableInterface::class, true));
        self::assertTrue(is_a(ThemeJs::class, StaticMirrorCapableInterface::class, true));
        self::assertTrue(is_a(Icon::class, StaticMirrorCapableInterface::class, true));
        self::assertTrue(is_a(ThemeFont::class, StaticMirrorCapableInterface::class, true));
    }

    public function testThemeCssLiteralPathBakesLinkWithoutPhp(): void
    {
        $callback = ThemeCss::callback();
        $tagData = [
            0 => '<theme:css>Weline_Theme::frontend/assets/css/theme.css</theme:css>',
            1 => '',
            2 => 'Weline_Theme::frontend/assets/css/theme.css',
        ];
        $result = $callback('tag', [], $tagData, []);

        self::assertIsString($result);
        self::assertStringContainsString('<link', $result);
        self::assertStringContainsString('href=', $result);
        self::assertStringNotContainsString('<?php', $result, 'literal theme:css must bake HTML, not emit PHP');
        self::assertStringNotContainsString('fetchTagSource', $result);
    }

    public function testThemeCssDynamicPathStillEmitsPhp(): void
    {
        $callback = ThemeCss::callback();
        $tagData = [
            0 => '<theme:css>Weline_Theme::frontend/<?= $file ?></theme:css>',
            1 => '',
            2 => 'Weline_Theme::frontend/<?= $file ?>',
        ];
        $result = $callback('tag', [], $tagData, []);

        self::assertStringContainsString('<?php', $result);
        self::assertStringContainsString('fetchTagSource', $result);
    }

    public function testThemeJsLiteralPathBakesScriptWithoutPhp(): void
    {
        $callback = ThemeJs::callback();
        $tagData = [
            0 => '<theme:js>Weline_Theme::frontend/assets/js/theme.js</theme:js>',
            1 => '',
            2 => 'Weline_Theme::frontend/assets/js/theme.js',
        ];
        $result = $callback('tag', [], $tagData, []);

        self::assertIsString($result);
        self::assertStringContainsString('<script', $result);
        self::assertStringContainsString('src=', $result);
        self::assertStringNotContainsString('<?php', $result, 'literal theme:js must bake HTML, not emit PHP');
    }

    public function testIconLiteralAttrsBakeSvgWithoutPhp(): void
    {
        $callback = Icon::callback();
        $result = $callback('tag-self-close-with-attrs', [], [], [
            'name' => 'settings',
            'size' => 'sm',
            'label' => '设置',
            'class' => 'w-icon-extra',
        ]);

        self::assertStringContainsString('<svg', $result);
        self::assertStringContainsString('data-icon="settings"', $result);
        self::assertStringNotContainsString('<?php', $result);
        self::assertStringNotContainsString('IconRegistry', $result);
    }

    public function testIconDynamicNameStillEmitsPhp(): void
    {
        $callback = Icon::callback();
        $result = $callback('tag-self-close-with-attrs', [], [], [
            'name' => '<?= $iconName ?>',
            'size' => 'md',
        ]);

        self::assertStringContainsString('<?php', $result);
        self::assertStringContainsString('IconRegistry', $result);
    }

    public function testThemeCssTaglibParseLiteralProducesLink(): void
    {
        /** @var Taglib $taglib */
        $taglib = ObjectManager::getInstance(Taglib::class);
        /** @var Template $template */
        $template = ObjectManager::getInstance(Template::class);

        $result = $taglib->parse(
            $template,
            'static_mirror_theme_css.phtml',
            '<theme:css>Weline_Theme::frontend/assets/css/theme.css</theme:css>',
        );

        self::assertStringNotContainsString('<theme:css>', $result);
        self::assertStringContainsString('<link', $result);
        self::assertStringNotContainsString('<?php', $result);
    }

    public function testThemeFontLiteralAttrsBakeStyleWithoutPhp(): void
    {
        $callback = ThemeFont::callback();
        $result = $callback('tag-self-close-with-attrs', [], [], [
            'src' => 'NotoSansSC-Regular.ttf',
            'family' => 'Noto Sans SC',
            'lang' => 'en',
            'weight' => '400',
            'display' => 'swap',
        ]);

        self::assertIsString($result);
        self::assertStringContainsString('data-weline-font', $result);
        self::assertStringContainsString('@font-face', $result);
        self::assertStringContainsString('/pub/media/font-subset/', $result);
        self::assertStringNotContainsString('<?php', $result, 'literal theme:font must bake HTML, not emit PHP');
        self::assertStringNotContainsString('FontFaceService', $result);
    }

    public function testThemeFontDynamicLangStillEmitsPhp(): void
    {
        $callback = ThemeFont::callback();
        $result = $callback('tag-self-close-with-attrs', [], [], [
            'src' => 'NotoSansSC-Regular.ttf',
            'family' => 'Noto Sans SC',
            'lang' => '<?= $lang ?>',
            'weight' => '400',
        ]);

        self::assertStringContainsString('<?php', $result);
        self::assertStringContainsString('FontFaceService', $result);
    }

    // --- 原生属性透传契约 ---------------------------------------------------
    // 编译期静态镜像必须保留原生属性，否则 `<theme:js data-order="60" defer>`
    // 会被固化成无属性 <script>，com 阶段 HeadAssetOrderPass 与前端验收都失效。

    public function testThemeCssLiteralAttrsBakeLinkWithDataOrder(): void
    {
        $callback = ThemeCss::callback();
        $tagData = [
            0 => '<theme:css data-order="40">Weline_Theme::frontend/assets/css/theme.css</theme:css>',
            1 => 'Weline_Theme::frontend/assets/css/theme.css',
            2 => '',
        ];
        $result = $callback('tag', [], $tagData, ['data-order' => '40']);

        self::assertIsString($result);
        self::assertStringContainsString('<link', $result);
        self::assertStringContainsString('data-order="40"', $result, 'theme:css must keep native attrs');
        self::assertStringNotContainsString('<?php', $result);
    }

    public function testThemeJsLiteralAttrsBakeScriptWithDefer(): void
    {
        $callback = ThemeJs::callback();
        $tagData = [
            0 => '<theme:js data-order="60" defer>Weline_Theme::frontend/assets/js/theme.js</theme:js>',
            1 => 'Weline_Theme::frontend/assets/js/theme.js',
            2 => '',
        ];
        $result = $callback('tag', [], $tagData, ['data-order' => '60', 'defer' => true]);

        self::assertIsString($result);
        self::assertStringContainsString('<script', $result);
        self::assertStringContainsString('data-order="60"', $result, 'theme:js must keep native attrs');
        self::assertMatchesRegularExpression('/\bdefer\b/', $result, 'boolean defer must survive as bare attr');
        self::assertStringNotContainsString('<?php', $result);
    }

    public function testThemeJsDynamicAttrFallsBackToPhp(): void
    {
        $callback = ThemeJs::callback();
        $tagData = [
            0 => '<theme:js>Weline_Theme::frontend/assets/js/theme.js</theme:js>',
            1 => 'Weline_Theme::frontend/assets/js/theme.js',
            2 => '',
        ];
        $result = $callback('tag', [], $tagData, ['data-order' => '<?= $order ?>']);

        self::assertStringContainsString('<?php', $result, 'dynamic attr must not be baked into static mirror');
        self::assertStringContainsString('fetchTagSource', $result);
    }

    public function testThemeCssTaglibParseLiteralAttrsPreservesDataOrder(): void
    {
        /** @var Taglib $taglib */
        $taglib = ObjectManager::getInstance(Taglib::class);
        /** @var Template $template */
        $template = ObjectManager::getInstance(Template::class);

        $result = $taglib->parse(
            $template,
            'static_mirror_theme_css_attrs.phtml',
            '<theme:css data-order="40">Weline_Theme::frontend/assets/css/theme.css</theme:css>',
        );

        self::assertStringNotContainsString('<theme:css', $result);
        self::assertStringContainsString('<link', $result);
        self::assertStringContainsString('data-order="40"', $result);
    }

    public function testThemeCssLiteralMirrorSkipsPreviewTokenAndCarriesDevFingerprint(): void
    {
        if (!defined('DEV') || !DEV) {
            $this->markTestSkipped('内容指纹只属于开发环境。');
        }

        $callback = ThemeCss::callback();
        $tagData = [
            0 => '<theme:css>Weline_Theme::frontend/assets/css/theme.css</theme:css>',
            1 => '',
            2 => 'Weline_Theme::frontend/assets/css/theme.css',
        ];
        $result = $callback('tag', [], $tagData, []);

        self::assertIsString($result);
        self::assertStringNotContainsString('preview_', $result, 'static mirror must not bake preview Token');
        self::assertMatchesRegularExpression(
            '/theme\\.css\\?v=dev_[a-f0-9]{12}/',
            $result,
            'theme /static URL must still get DEV content fingerprint via Module:: source path'
        );
    }

    public function testThemeJsLiteralMirrorSkipsPreviewTokenAndCarriesDevFingerprint(): void
    {
        if (!defined('DEV') || !DEV) {
            $this->markTestSkipped('内容指纹只属于开发环境。');
        }

        $callback = ThemeJs::callback();
        $tagData = [
            0 => '<theme:js>Weline_Theme::frontend/assets/js/theme.js</theme:js>',
            1 => '',
            2 => 'Weline_Theme::frontend/assets/js/theme.js',
        ];
        $result = $callback('tag', [], $tagData, []);

        self::assertIsString($result);
        self::assertStringNotContainsString('preview_', $result, 'static mirror must not bake preview Token');
        self::assertMatchesRegularExpression(
            '/theme\\.js\\?v=dev_[a-f0-9]{12}/',
            $result,
            'theme /static URL must still get DEV content fingerprint via Module:: source path'
        );
    }
}

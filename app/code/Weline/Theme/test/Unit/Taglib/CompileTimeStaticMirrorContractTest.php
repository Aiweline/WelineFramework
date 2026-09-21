<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\Taglib;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Taglib\StaticMirrorCapableInterface;
use Weline\Framework\View\Taglib;
use Weline\Framework\View\Template;
use Weline\Theme\Taglib\Icon;
use Weline\Theme\Taglib\ThemeCss;
use Weline\Theme\Taglib\ThemeJs;

class CompileTimeStaticMirrorContractTest extends TestCase
{
    public function testThemeCssAndJsAndIconImplementStaticMirrorCapable(): void
    {
        self::assertTrue(is_a(ThemeCss::class, StaticMirrorCapableInterface::class, true));
        self::assertTrue(is_a(ThemeJs::class, StaticMirrorCapableInterface::class, true));
        self::assertTrue(is_a(Icon::class, StaticMirrorCapableInterface::class, true));
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
}

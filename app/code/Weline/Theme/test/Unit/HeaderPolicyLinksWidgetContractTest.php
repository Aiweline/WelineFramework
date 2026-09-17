<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Header 政策/关于链接槽与部件契约。
 */
final class HeaderPolicyLinksWidgetContractTest extends TestCase
{
    public function testHeaderPartialDeclaresPolicyLinksSlotAfterCategoryMenu(): void
    {
        $path = dirname(__DIR__, 2) . '/view/theme/frontend/partials/header/default.phtml';
        self::assertFileExists($path);
        $source = (string)file_get_contents($path);

        self::assertStringContainsString('<w:slot id="header-policy-links"', $source);
        self::assertStringContainsString('accept="header-policy-links,layout-header-policy-links"', $source);
        self::assertStringContainsString('multiple="true"', $source);
        self::assertStringContainsString('<w:widget type="header" name="header-policy-links"', $source);
        self::assertStringContainsString('class="header-policy-links-slot"', $source);
        self::assertStringContainsString('partials::header::policy-links', $source);
        self::assertLessThan(
            strpos($source, 'id="header-policy-links"'),
            strpos($source, 'id="category-menu"'),
            'header-policy-links 须紧挨 category-menu 之后'
        );
        self::assertLessThan(
            strpos($source, 'id="header-nav-extensions"'),
            strpos($source, 'id="header-policy-links"'),
            '政策槽须在右侧扩展槽之前'
        );
    }

    public function testWidgetDeclaresDefaultInjectionAndI18nLinksParam(): void
    {
        $widgetPath = dirname(__DIR__, 2) . '/view/theme/frontend/widgets/header/header-policy-links/default.phtml';
        $schemaPath = dirname(__DIR__, 2) . '/Ui/ParamSchema/header_policy_links.php';
        self::assertFileExists($widgetPath);
        self::assertFileExists($schemaPath);

        $widget = (string)file_get_contents($widgetPath);
        $schema = require $schemaPath;

        self::assertStringContainsString('@widget.code {header-policy-links}', $widget);
        self::assertStringContainsString('@widget.slot {header-policy-links}', $widget);
        self::assertStringContainsString('"slot":"header-policy-links"', $widget);
        self::assertStringContainsString('@widget.exclusive {false}', $widget);
        self::assertStringContainsString('type="header_policy_links"', $widget);
        self::assertStringContainsString('data-testid="header-policy-links"', $widget);
        self::assertStringContainsString('header-policy-links__inline', $widget);
        self::assertStringContainsString('header-policy-links__trigger', $widget);
        self::assertStringContainsString('inlineLinks', $widget);
        self::assertStringContainsString('menuGroups', $widget);
        self::assertStringContainsString('default="购物政策"', $widget);
        self::assertStringContainsString('measurePolicyLinksWidth', (string)file_get_contents(
            dirname(__DIR__, 2) . '/view/theme/frontend/partials/header/default.phtml'
        ));

        self::assertSame('array', $schema['base_type'] ?? null);
        self::assertTrue((bool)($schema['item_schema']['label']['i18n'] ?? false));
        self::assertArrayHasKey('enabled', $schema['item_schema'] ?? []);
    }

    public function testWidgetPhpRegistersHeaderPolicyLinksTemplate(): void
    {
        $widgetFile = dirname(__DIR__, 2) . '/extends/module/Weline_Widget/Weline_Theme/widget.php';
        self::assertFileExists($widgetFile);
        $widgets = require $widgetFile;
        $haystack = '';
        foreach ($widgets as $key => $entry) {
            if (is_string($entry)) {
                $haystack .= $entry . "\n";
            } elseif (is_array($entry)) {
                $haystack .= (string)($entry['template'] ?? '') . "\n";
            }
            if (is_string($key)) {
                $haystack .= $key . "\n";
            }
        }
        self::assertStringContainsString('header-policy-links/default.phtml', $haystack);
    }
}

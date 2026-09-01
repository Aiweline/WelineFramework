<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\View;

use PHPUnit\Framework\TestCase;

final class ThemeBrandBasicsContractTest extends TestCase
{
    public function testEditorTemplateDeclaresBrandEntryAndChromeControls(): void
    {
        $template = (string)\file_get_contents(
            \dirname(__DIR__, 3) . '/view/templates/backend/ThemeEditor/index.phtml'
        );
        self::assertStringContainsString('id="btnThemeBrandBasics"', $template);
        self::assertStringContainsString('id="themeBrandBasicsDrawer"', $template);
        self::assertStringContainsString('id="btnThemeChromeCollapse"', $template);
        self::assertStringContainsString('id="themeEditorChromeStrip"', $template);
        self::assertStringContainsString('data-w-brand-input="favicon"', $template);
        self::assertStringContainsString('data-w-brand-action="save"', $template);
        self::assertStringContainsString('w-theme-brand-upload', $template);
        self::assertStringContainsString('上传图片', $template);
        self::assertStringContainsString('w-theme-brand-field__row', $template);
        self::assertStringContainsString('建议尺寸 128 × 128 px', $template);
        self::assertStringContainsString('建议尺寸 180 × 180 px', $template);
        self::assertStringContainsString('建议尺寸 320 × 80 px', $template);
        self::assertStringContainsString('支持 ico / png / svg', $template);
        self::assertStringNotContainsString('@lang{支持 .ico, .png, .svg}', $template);
    }

    public function testAppearancePatchPathsAllowBrand(): void
    {
        $source = (string)\file_get_contents(
            \dirname(__DIR__, 3) . '/Service/Scoped/ThemeScopedWorkspace.php'
        );
        self::assertStringContainsString('(?:tokens|disks|brand)', $source);
        $differ = (string)\file_get_contents(
            \dirname(__DIR__, 3) . '/Service/Scoped/ThemeResourcePayloadDiffer.php'
        );
        self::assertStringContainsString("['tokens', 'disks', 'brand']", $differ);
    }

    public function testCompiledBundleIncludesBrandAndChromeModules(): void
    {
        $js = (string)\file_get_contents(
            \dirname(__DIR__, 3) . '/view/statics/ui/pages/weline-theme-editor.js'
        );
        self::assertStringContainsString('theme-brand-basics.js', $js);
        self::assertStringContainsString('theme-editor-chrome.js', $js);
        self::assertStringContainsString('theme-editor-fit-controls.js', $js);
        self::assertStringContainsString('btnThemeBrandBasics', $js);
        self::assertStringContainsString('weline-media-manager-select', $js);
        self::assertStringContainsString('openBrandMediaDialog', $js);
        self::assertStringContainsString('BRAND_PICKER_SPECS', $js);
        self::assertStringContainsString("aspectRatio: '1:1'", $js);
        self::assertStringContainsString('recommendWidth: 128', $js);
        self::assertStringContainsString('recommendWidth: 180', $js);
        self::assertStringContainsString('recommendWidth: 320', $js);
        $css = (string)\file_get_contents(
            \dirname(__DIR__, 3) . '/view/statics/ui/pages/weline-theme-editor.css'
        );
        self::assertStringContainsString('editor-chrome-strip', $css);
        self::assertStringContainsString('w-theme-brand-upload', $css);
        self::assertStringContainsString('w-theme-brand-upload__empty', $css);
        self::assertStringContainsString('w-theme-brand-field__row', $css);
    }

    public function testSiteBrandPrefersThemeScopeResolver(): void
    {
        $source = (string)\file_get_contents(
            \dirname(__DIR__, 3) . '/Helper/SiteBrand.php'
        );
        self::assertStringContainsString('ThemeBrandResolver', $source);
        self::assertStringContainsString('resolveThemeBrandUrl', $source);
        $resolver = (string)\file_get_contents(
            \dirname(__DIR__, 3) . '/Service/ThemeBrandResolver.php'
        );
        self::assertStringContainsString("\$includeDraft ? 'draft_payload' : 'published_payload'", $resolver);
    }
}

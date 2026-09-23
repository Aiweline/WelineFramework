<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\View;

use PHPUnit\Framework\TestCase;
use Weline\Backend\Api\Config\BackendConfigStore;
use Weline\Framework\Cache\Service\StorefrontScopeHotCache;
use Weline\Framework\Context;
use Weline\Framework\Runtime\RequestContext;
use Weline\Theme\Helper\SiteBrand;

final class ThemeBrandBasicsContractTest extends TestCase
{
    public function testEditorTemplateDeclaresBrandEntryAndChromeControls(): void
    {
        $template = (string)\file_get_contents(
            \dirname(__DIR__, 3) . '/view/templates/backend/ThemeEditor/index.phtml'
        );
        self::assertStringContainsString('id="btnThemeBrandBasics"', $template);
        self::assertStringContainsString('id="themeBrandBasicsDrawer"', $template);
        self::assertStringContainsString('id="themeBrandIdentitySlot"', $template);
        self::assertStringContainsString('Weline_Theme::backend::partials::theme-editor-brand-basics::identity', $template);
        self::assertStringContainsString('data-w-brand-identity-slot', $template);
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
        // Fit mirror must not re-enter from its own title/width writes (DevTools flash loop).
        $fitSource = (string)\file_get_contents(
            \dirname(__DIR__, 3) . '/view/statics/js/theme-editor-fit-controls.js'
        );
        self::assertStringContainsString('suppressObserve', $fitSource);
        self::assertStringContainsString('lastMirrorKey', $fitSource);
        self::assertStringContainsString("Do not watch `title`", $fitSource);
        self::assertStringContainsString('suppressObserve', $js);
        self::assertStringContainsString('lastMirrorKey', $js);
        self::assertStringContainsString('btnThemeBrandBasics', $js);
        self::assertStringContainsString('weline-media-manager-select', $js);
        self::assertStringContainsString('openBrandMediaDialog', $js);
        self::assertStringContainsString('BRAND_PICKER_SPECS', $js);
        self::assertStringContainsString("aspectRatio: '1:1'", $js);
        self::assertStringContainsString('recommendWidth: 128', $js);
        self::assertStringContainsString('recommendWidth: 180', $js);
        self::assertStringContainsString('recommendWidth: 320', $js);
        self::assertStringContainsString('brand-basics-identity', $js);
        self::assertStringContainsString('data-w-identity-input', $js);
        self::assertStringContainsString('renderIdentityFields', $js);
        $css = (string)\file_get_contents(
            \dirname(__DIR__, 3) . '/view/statics/ui/pages/weline-theme-editor.css'
        );
        self::assertStringContainsString('editor-chrome-strip', $css);
        self::assertStringContainsString('w-theme-brand-upload', $css);
        self::assertStringContainsString('w-theme-brand-upload__empty', $css);
        self::assertStringContainsString('w-theme-brand-field__row', $css);
        self::assertStringContainsString('w-theme-brand-identity', $css);
    }

    public function testIdentityProviderSlotIsRegistered(): void
    {
        $hook = (string)\file_get_contents(
            \dirname(__DIR__, 3) . '/hook.php'
        );
        self::assertStringContainsString(
            'Weline_Theme::backend::partials::theme-editor-brand-basics::identity',
            $hook
        );
        $interface = (string)\file_get_contents(
            \dirname(__DIR__, 3) . '/Api/BrandBasicsIdentityProviderInterface.php'
        );
        self::assertStringContainsString('function supports', $interface);
        self::assertStringContainsString('function load', $interface);
        self::assertStringContainsString('function save', $interface);
        $websites = (string)\file_get_contents(
            \dirname(__DIR__, 4) . '/Websites/etc/module.php'
        );
        self::assertStringContainsString('theme.brand_basics_identity.websites', $websites);
        $provider = (string)\file_get_contents(
            \dirname(__DIR__, 4) . '/Websites/Service/ThemeBrandBasicsIdentityProvider.php'
        );
        self::assertStringContainsString('implements BrandBasicsIdentityProviderInterface', $provider);
        $resolver = (string)\file_get_contents(
            \dirname(__DIR__, 3) . '/Service/ThemeBrandResolver.php'
        );
        self::assertStringNotContainsString("'site_name'", $resolver);
        self::assertStringNotContainsString("'site_description'", $resolver);

        $editor = (string)\file_get_contents(
            \dirname(__DIR__, 3) . '/Controller/Backend/ThemeEditor.php'
        );
        self::assertSame(1, \preg_match(
            '/#\[Acl\((?P<body>[\s\S]*?)\)\]\s*public function getBrandBasicsIdentity\(/',
            $editor,
            $matches
        ));
        $aclBody = (string)($matches['body'] ?? '');
        self::assertStringContainsString("'Weline_Theme::theme_visual_editor_scope_read'", $aclBody);
        self::assertStringContainsString("'Weline_Theme::theme_visual_editor'", $aclBody);
        self::assertStringNotContainsString(
            "'Weline_Theme::theme_visual_editor',\n        '读取主题基础信息身份'",
            $aclBody
        );
    }

    public function testSiteBrandPrefersThemeScopeResolver(): void
    {
        $source = (string)\file_get_contents(
            \dirname(__DIR__, 3) . '/Helper/SiteBrand.php'
        );
        self::assertStringContainsString('ThemeBrandResolver', $source);
        self::assertStringContainsString('resolveThemeBrandUrl', $source);
        self::assertStringContainsString('resolveFrontendSiteName', $source);
        self::assertStringContainsString('WebsiteData', $source);
        self::assertStringContainsString('isGenericBrandPlaceholder', $source);
        self::assertStringNotContainsString('resolveThemeBrandText', $source);
        $resolver = (string)\file_get_contents(
            \dirname(__DIR__, 3) . '/Service/ThemeBrandResolver.php'
        );
        self::assertStringContainsString("\$includeDraft ? 'draft_payload' : 'published_payload'", $resolver);
    }

    public function testSiteBrandConfigReadsAreMemoizedWithinOneRequest(): void
    {
        $backendConfig = $this->getMockBuilder(BackendConfigStore::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getConfig'])
            ->getMock();
        $backendConfig->expects(self::once())
            ->method('getConfig')
            ->with('site_icon', SiteBrand::CONFIG_MODULE)
            ->willReturn('/pub/media/site-icon.png');

        $context = new Context(['meta' => ['type' => 'request', 'mode' => 'fpm']]);
        Context::enter($context);
        RequestContext::setId('site-brand-config-memo');
        try {
            $siteBrand = new SiteBrand($backendConfig, new StorefrontScopeHotCache());
            self::assertSame('/pub/media/site-icon.png', $siteBrand->getRawConfig('site_icon'));
            self::assertSame('/pub/media/site-icon.png', $siteBrand->getRawConfig('site_icon'));
        } finally {
            RequestContext::cleanup();
            Context::leave();
        }
    }

    public function testFooterLocaleBrandUsesSiteBrandWebsiteName(): void
    {
        $footer = (string)\file_get_contents(
            \dirname(__DIR__, 3) . '/view/theme/frontend/widgets/container/footer/default.phtml'
        );
        self::assertStringContainsString('resolveFrontendWordmark', $footer);
        self::assertStringContainsString('footer-locale__logo-text', $footer);
        self::assertStringContainsString('留空使用当前网站名称', $footer);
        self::assertStringNotContainsString(
            '$siteLogoText = WidgetI18n::label(',
            $footer
        );

        $logoWidget = (string)\file_get_contents(
            \dirname(__DIR__, 3) . '/view/theme/frontend/widgets/header/logo/default.phtml'
        );
        self::assertStringContainsString('resolveFrontendWordmark', $logoWidget);
        self::assertStringNotContainsString('@param logo_text {default="Weline"', $logoWidget);

        $header = (string)\file_get_contents(
            \dirname(__DIR__, 3) . '/view/theme/frontend/partials/header/default.phtml'
        );
        self::assertStringContainsString('resolveFrontendWordmark', $header);
    }
}

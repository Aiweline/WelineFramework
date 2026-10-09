<?php

declare(strict_types=1);

namespace Weline\I18n\Test\Unit\Taglib;

use PHPUnit\Framework\TestCase;
use Weline\I18n\Taglib\LanguageSwitcher;

final class LanguageSwitcherPanelLazyContractTest extends TestCase
{
    public function testStorefrontLazyPanelSkipsFullCatalogSsrAndExposesQueryOp(): void
    {
        $taglib = (string)file_get_contents(dirname(__DIR__, 3) . '/Taglib/LanguageSwitcher.php');
        $runtime = (string)file_get_contents(dirname(__DIR__, 3) . '/view/statics/js/language-switcher.js');
        $query = (string)file_get_contents(
            dirname(__DIR__, 3) . '/extends/module/Weline_Framework/Query/I18nQueryProvider.php'
        );

        self::assertSame('component-27-panel-lazy', LanguageSwitcher::SWITCHER_MARKUP_VERSION);
        self::assertStringContainsString('$lazyPanel = !$isBackendArea', $taglib);
        self::assertStringContainsString('data-i18n-panel-lazy="1"', $taglib);
        self::assertStringContainsString('data-i18n-panel-pending="1"', $taglib);
        self::assertStringContainsString('正在加载语言…', $taglib);
        self::assertStringContainsString('buildSupportedScopeAttributesFromCodes', $taglib);
        self::assertStringContainsString('function renderLazyPanelCatalog', $taglib);
        self::assertStringContainsString('buildPanelListGroupsHtml', $taglib);

        // Backend keeps full SSR groups; storefront only SSR trigger languages.
        self::assertStringContainsString('Backend keeps full SSR', $taglib);
        self::assertStringContainsString('buildLanguagesFromCodes($triggerCodes', $taglib);

        self::assertStringContainsString("name: 'getLanguageSwitcherCatalog'", $query);
        self::assertStringContainsString('LanguageSwitcher::renderLazyPanelCatalog', $query);
        self::assertStringContainsString("ttl: '1h'", $query);
        self::assertStringContainsString('external: true', $query);
        // FrontendQueryGateway reads getDescriptor() ops; attribute-only is not enough.
        self::assertMatchesRegularExpression(
            "/'name'\\s*=>\\s*'getLanguageSwitcherCatalog'[\\s\\S]{0,220}'frontend'\\s*=>\\s*true/",
            $query,
        );

        self::assertStringContainsString('fetchLanguageSwitcherCatalogHtml', $runtime);
        self::assertStringContainsString("resource?.('i18n')", $runtime);
        self::assertStringContainsString('getLanguageSwitcherCatalog', $runtime);
        self::assertStringContainsString('panelCatalogPromises', $runtime);
        self::assertStringContainsString("weline:ui:menu:open", $runtime);
        self::assertStringContainsString('loadPanelCatalog', $runtime);
        self::assertStringContainsString('data-i18n-panel-lazy', $runtime);

        // Storefront loads Theme UI component mirror, not I18n statics path.
        $themeUi = (string)file_get_contents(
            dirname(__DIR__, 4) . '/Theme/view/statics/ui/components/weline-language-switcher.js'
        );
        self::assertStringContainsString('fetchLanguageSwitcherCatalogHtml', $themeUi);
        self::assertStringContainsString('loadPanelCatalog', $themeUi);
        self::assertStringContainsString('getLanguageSwitcherCatalog', $themeUi);
    }
}

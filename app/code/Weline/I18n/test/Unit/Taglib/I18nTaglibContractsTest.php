<?php

declare(strict_types=1);

namespace Weline\I18n\Test\Unit\Taglib;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Cache\CachePolicy;
use Weline\I18n\Service\LocaleDisplayRankService;
use Weline\I18n\Taglib\LanguageSelect;
use Weline\I18n\Taglib\LanguageSwitcher;

/**
 * Aggregated contract suite (merged from 12 *ContractTest files).
 * Observe one overall pass/fail via testAllI18nTaglibContractsPass().
 */
final class I18nTaglibContractsTest extends TestCase
{
    public function testAllI18nTaglibContractsPass(): void
    {
        $failures = [];
        foreach ($this->contractChecks() as $name => $fn) {
            try {
                $fn();
            } catch (\Throwable $e) {
                $failures[] = $name . ": " . $e->getMessage();
            }
        }
        self::assertSame([], $failures, implode("\n", $failures));
    }

    /** @return array<string, callable(): void> */
    private function contractChecks(): array
    {
        return [
            'LanguageInjectLocales::testDisplayNamePrefersNativeSelfNameOverEnglish' => function () {
            $this->assert_LanguageInjectLocales_testDisplayNamePrefersNativeSelfNameOverEnglish();
        },
            'LanguageInjectLocales::testLanguageSwitcherPinsCurrentCountryGroupFirst' => function () {
            $this->assert_LanguageInjectLocales_testLanguageSwitcherPinsCurrentCountryGroupFirst();
        },
            'LanguageInjectLocales::testLanguageSwitcherGroupsFollowDisplayRankThenPinCurrent' => function () {
            $this->assert_LanguageInjectLocales_testLanguageSwitcherGroupsFollowDisplayRankThenPinCurrent();
        },
            'LanguageInjectLocales::testLanguageSwitcherInjectsAuthoritativeLocalesWithoutWebsite' => function () {
            $this->assert_LanguageInjectLocales_testLanguageSwitcherInjectsAuthoritativeLocalesWithoutWebsite();
        },
            'LanguageInjectLocales::testLanguageSelectResolveInjectsMissingCodesInCallerOrder' => function () {
            $this->assert_LanguageInjectLocales_testLanguageSelectResolveInjectsMissingCodesInCallerOrder();
        },
            'LanguageInjectLocales::testInstalledAllowlistResolveDoesNotUpgradeToGlobalCatalogInSource' => function () {
            $this->assert_LanguageInjectLocales_testInstalledAllowlistResolveDoesNotUpgradeToGlobalCatalogInSource();
        },
            'LanguageInjectLocales::testRepeatedLanguageSwitcherRenderKeepsInstanceIdsUniqueAndAriaLinked' => function () {
            $this->assert_LanguageInjectLocales_testRepeatedLanguageSwitcherRenderKeepsInstanceIdsUniqueAndAriaLinked();
        },
            'LanguageInjectLocales::testLanguageSwitcherAttrDocumentsLocalesCurrentNavigation' => function () {
            $this->assert_LanguageInjectLocales_testLanguageSwitcherAttrDocumentsLocalesCurrentNavigation();
        },
            'LanguageSelectExcludeSiteLanguages::testLanguageSelectDeclaresExcludeSiteLanguagesAttribute' => function () {
            $this->assert_LanguageSelectExcludeSiteLanguages_testLanguageSelectDeclaresExcludeSiteLanguagesAttribute();
        },
            'LanguageSelectExcludeSiteLanguages::testLanguageSupportRequestFormUsesExcludeSiteLanguages' => function () {
            $this->assert_LanguageSelectExcludeSiteLanguages_testLanguageSupportRequestFormUsesExcludeSiteLanguages();
        },
            'LanguageSelectFieldNameLiteral::testFormNameIsCompiledAsHtmlLiteral' => function () {
            $this->assert_LanguageSelectFieldNameLiteral_testFormNameIsCompiledAsHtmlLiteral();
        },
            'LanguageSelectFieldNameLiteral::testOmittedMultipleForcesSingleSelectEvenAfterLeak' => function () {
            $this->assert_LanguageSelectFieldNameLiteral_testOmittedMultipleForcesSingleSelectEvenAfterLeak();
        },
            'LanguageSelectReorderUrl::testTaglibExposesReorderUrlAttributes' => function () {
            $this->assert_LanguageSelectReorderUrl_testTaglibExposesReorderUrlAttributes();
        },
            'LanguageSelectReorderUrl::testMarkupAndRuntimeWireReorderDataset' => function () {
            $this->assert_LanguageSelectReorderUrl_testMarkupAndRuntimeWireReorderDataset();
        },
            'LanguageSelectSearchFocus::testSearchFocusIsDeferredPastTriggerClick' => function () {
            $this->assert_LanguageSelectSearchFocus_testSearchFocusIsDeferredPastTriggerClick();
        },
            'LanguageSelectThemeTokens::testLanguageSelectStylesPreferThemeTokensOverBackendFallbacks' => function () {
            $this->assert_LanguageSelectThemeTokens_testLanguageSelectStylesPreferThemeTokensOverBackendFallbacks();
        },
            'LanguageSwitcherCatalogHotCache::testCatalogUsesStorefrontScopeHotCacheWithoutPathBoundHtml' => function () {
            $this->assert_LanguageSwitcherCatalogHotCache_testCatalogUsesStorefrontScopeHotCacheWithoutPathBoundHtml();
        },
            'LanguageSwitcherClickRace::testTaglibUsesTheLazyUiComponentAndAuthoritativeHrefs' => function () {
            $this->assert_LanguageSwitcherClickRace_testTaglibUsesTheLazyUiComponentAndAuthoritativeHrefs();
        },
            'LanguageSwitcherClickRace::testLanguageRequestUsesTheSameScopedComponent' => function () {
            $this->assert_LanguageSwitcherClickRace_testLanguageRequestUsesTheSameScopedComponent();
        },
            'LanguageSwitcherClickRace::testLanguageSupportRequestFormHasNoInlineSubmitScript' => function () {
            $this->assert_LanguageSwitcherClickRace_testLanguageSupportRequestFormHasNoInlineSubmitScript();
        },
            'LanguageSwitcherClickRace::testLiveHttpRequestBeatsStaleThemeDataBackendArea' => function () {
            $this->assert_LanguageSwitcherClickRace_testLiveHttpRequestBeatsStaleThemeDataBackendArea();
        },
            'LanguageSwitcherPanelLazy::testStorefrontLazyPanelSkipsFullCatalogSsrAndExposesQueryOp' => function () {
            $this->assert_LanguageSwitcherPanelLazy_testStorefrontLazyPanelSkipsFullCatalogSsrAndExposesQueryOp();
        },
            'LanguageSwitcherTagName::testCanonicalTagNameIsI18nSwitcher' => function () {
            $this->assert_LanguageSwitcherTagName_testCanonicalTagNameIsI18nSwitcher();
        },
            'LanguageSwitcherTagName::testLegacyAliasIsRemoved' => function () {
            $this->assert_LanguageSwitcherTagName_testLegacyAliasIsRemoved();
        },
            'LocalBulkMode::testLocalTagDeclaresBulkModeAndSharedDialog' => function () {
            $this->assert_LocalBulkMode_testLocalTagDeclaresBulkModeAndSharedDialog();
        },
            'LocalBulkMode::testResumableTaskRegistrationExists' => function () {
            $this->assert_LocalBulkMode_testResumableTaskRegistrationExists();
        },
            'LocalBulkMode::testBulkTranslationJsUsesRuntimeTaskAndModalLock' => function () {
            $this->assert_LocalBulkMode_testBulkTranslationJsUsesRuntimeTaskAndModalLock();
        },
            'LocalTranslationTriggerClamp::testTriggerTextUsesThreeLineClampAndAllowsWrap' => function () {
            $this->assert_LocalTranslationTriggerClamp_testTriggerTextUsesThreeLineClampAndAllowsWrap();
        },
        ];
    }

private function assert_LanguageInjectLocales_testDisplayNamePrefersNativeSelfNameOverEnglish(): void
{
    $method = new \ReflectionMethod(LanguageSelect::class, 'buildDisplayName');
    $method->setAccessible(true);
    $display = (string) $method->invoke(null, 'китайский (упрощенная, Китай)', 'Chinese (Simplified, China)', '中文（简体，中国）', 'zh_Hans_CN');
    self::assertSame('китайский (упрощенная, Китай) (中文（简体，中国）)', $display);
    self::assertStringNotContainsString('Chinese (Simplified, China)', $display);
    $sameLocale = (string) $method->invoke(null, 'русский (Россия)', 'Russian (Russia)', 'русский (Россия)', 'ru_RU');
    self::assertSame('русский (Россия)', $sameLocale);
}

private function assert_LanguageInjectLocales_testLanguageSwitcherPinsCurrentCountryGroupFirst(): void
{
    // Storefront SSR is panel-lazy (no data-lang options). Assert grouping order directly.
    $languages = ['zh_Hans_CN' => ['code' => 'zh_Hans_CN', 'country_code' => 'CN', 'country_name' => 'China'], 'ru_RU' => ['code' => 'ru_RU', 'country_code' => 'RU', 'country_name' => 'Russia'], 'bn_IN' => ['code' => 'bn_IN', 'country_code' => 'IN', 'country_name' => 'India'], 'as_IN' => ['code' => 'as_IN', 'country_code' => 'IN', 'country_name' => 'India']];
    $method = new \ReflectionMethod(LanguageSwitcher::class, 'groupLanguagesByCountry');
    $method->setAccessible(true);
    /** @var list<array{country_code:string,country_name:string,languages:array<string,array<string,mixed>>}> $groups */
    $groups = $method->invoke(null, $languages, 'zh_Hans_CN', 'bn_IN');
    $order = [];
    foreach ($groups as $group) {
        foreach (\array_keys($group['languages'] ?? []) as $code) {
            $order[] = (string) $code;
        }
    }
    self::assertSame(['bn_IN', 'as_IN', 'zh_Hans_CN', 'ru_RU'], $order, 'current Indian country group must sort before China/Russia; got: ' . \implode(',', $order));
}

private function assert_LanguageInjectLocales_testLanguageSwitcherGroupsFollowDisplayRankThenPinCurrent(): void
{
    // Simulate HotCache bag already ranked; current zh keeps CN first, rest follow market heat.
    $ranked = LocaleDisplayRankService::sortLanguageMap(['bg_BG' => ['code' => 'bg_BG', 'country_code' => 'BG', 'country_name' => 'Bulgaria'], 'ar_SA' => ['code' => 'ar_SA', 'country_code' => 'SA', 'country_name' => 'Saudi Arabia'], 'en_US' => ['code' => 'en_US', 'country_code' => 'US', 'country_name' => 'United States'], 'zh_Hans_CN' => ['code' => 'zh_Hans_CN', 'country_code' => 'CN', 'country_name' => 'China']]);
    $method = new \ReflectionMethod(LanguageSwitcher::class, 'groupLanguagesByCountry');
    $method->setAccessible(true);
    /** @var list<array{country_code:string,country_name:string,languages:array<string,array<string,mixed>>}> $groups */
    $groups = $method->invoke(null, $ranked, 'zh_Hans_CN', 'zh_Hans_CN');
    $countryOrder = [];
    foreach ($groups as $group) {
        $countryOrder[] = (string) ($group['country_code'] ?? '');
    }
    self::assertSame(['CN', 'US', 'SA', 'BG'], $countryOrder, 'current CN pinned, then US/SA before BG; got: ' . \implode(',', $countryOrder));
}

private function assert_LanguageInjectLocales_testLanguageSwitcherInjectsAuthoritativeLocalesWithoutWebsite(): void
{
    $html = LanguageSwitcher::render(['allowed_values' => ['as_IN', 'en_US', 'bn_IN'], 'current' => 'as_IN', 'navigation' => 'emit']);
    self::assertStringContainsString('data-i18n-switcher', $html);
    self::assertStringContainsString('data-i18n-navigation="emit"', $html);
    // Storefront panel is lazy; authoritative inject order lands on supported-locales.
    self::assertMatchesRegularExpression('/data-i18n-supported-locales="[^"]*as_IN[^"]*"/', $html);
    self::assertStringContainsString('en_US', (string) (\preg_match('/data-i18n-supported-locales="([^"]*)"/', $html, $m) ? $m[1] : ''));
    self::assertStringContainsString('bn_IN', (string) (\preg_match('/data-i18n-supported-locales="([^"]*)"/', $html, $m) ? $m[1] : ''));
    self::assertStringContainsString('data-i18n-current-locale="as_IN"', $html);
}

private function assert_LanguageInjectLocales_testLanguageSelectResolveInjectsMissingCodesInCallerOrder(): void
{
    $items = LanguageSelect::resolveLanguageItems('zh_Hans_CN', 'installed', ['as_IN', 'en_US', 'zz_QQ']);
    $codes = \array_values(\array_map(static fn(array $item): string => (string) ($item['code'] ?? ''), $items));
    self::assertSame(['as_IN', 'en_US', 'zz_QQ'], $codes);
    self::assertNotSame('', (string) ($items[0]['display_name'] ?? $items[0]['name'] ?? ''));
    self::assertSame('zz_QQ', (string) ($items[2]['name'] ?? ''));
}

private function assert_LanguageInjectLocales_testInstalledAllowlistResolveDoesNotUpgradeToGlobalCatalogInSource(): void
{
    $src = (string) \file_get_contents(BP . '/app/code/Weline/I18n/Taglib/LanguageSelect.php');
    $switcherSrc = (string) \file_get_contents(BP . '/app/code/Weline/I18n/Taglib/LanguageSwitcher.php');
    self::assertStringNotContainsString("\$catalog === 'installed' ? 'global'", $src, 'installed+allowlist must not call getLanguageItems(global)');
    self::assertStringContainsString('buildInstalledLanguageItemsForCodes', $src, 'website/inject allowlist must resolve only those codes from Locals');
    self::assertStringContainsString('countryNamesFromDbForCodes', $src, 'allowlist path must load country labels from i18n_countries_locale_name');
    self::assertStringContainsString("->where(Locals::schema_fields_CODE, \$normalized, 'IN')", $src, 'Locals subset query must use where(field, codes, IN) ORM order');
    self::assertStringNotContainsString("getLanguageItems(\$displayLocale, 'installed');\n        } else {", $src, 'allowlist path must not materialize full installed catalog then filter');
    self::assertStringNotContainsString('LanguageSelect::getLanguageItems($displayLocale)', $switcherSrc, 'groupLanguagesByCountry must not reload full LanguageSelect catalog');
    $builderStart = \strpos($src, 'function buildInstalledLanguageItemsForCodes');
    $builderEnd = \strpos($src, 'function pickLocalNameForTarget', (int) $builderStart);
    self::assertNotFalse($builderStart);
    self::assertNotFalse($builderEnd);
    $builderBody = \substr($src, (int) $builderStart, (int) $builderEnd - (int) $builderStart);
    self::assertStringNotContainsString('->getLocaleName(', $builderBody, 'allowlist builder must not call I18n/Symfony locale name APIs');
    self::assertStringNotContainsString('Locales::getName', $builderBody, 'allowlist builder must not call Symfony Locales::getName');
}

private function assert_LanguageInjectLocales_testRepeatedLanguageSwitcherRenderKeepsInstanceIdsUniqueAndAriaLinked(): void
{
    LanguageSwitcher::clearProcessCaches();
    $attributes = ['allowed_values' => ['zh_Hans_CN', 'en_US'], 'current' => 'zh_Hans_CN', 'navigation' => 'emit'];
    $first = LanguageSwitcher::render($attributes);
    $second = LanguageSwitcher::render($attributes);
    preg_match('/data-i18n-switcher-id="([^"]+)"/', $first, $firstMatch);
    preg_match('/data-i18n-switcher-id="([^"]+)"/', $second, $secondMatch);
    $firstId = (string) ($firstMatch[1] ?? '');
    $secondId = (string) ($secondMatch[1] ?? '');
    self::assertNotSame('', $firstId);
    self::assertNotSame('', $secondId);
    self::assertNotSame($firstId, $secondId);
    foreach ([[$first, $firstId], [$second, $secondId]] as [$html, $id]) {
        self::assertStringContainsString('id="' . $id . '-toggle"', $html);
        self::assertStringContainsString('aria-controls="' . $id . '-panel"', $html);
        self::assertStringContainsString('id="' . $id . '-panel"', $html);
        self::assertStringContainsString('aria-labelledby="' . $id . '-toggle"', $html);
    }
}

private function assert_LanguageInjectLocales_testLanguageSwitcherAttrDocumentsLocalesCurrentNavigation(): void
{
    $attrs = LanguageSwitcher::attr();
    self::assertArrayHasKey('locales', $attrs);
    self::assertArrayHasKey('current', $attrs);
    self::assertArrayHasKey('navigation', $attrs);
    $selectAttrs = LanguageSelect::attr();
    self::assertArrayHasKey('locales', $selectAttrs);
    self::assertArrayHasKey('catalog', $selectAttrs);
}

private function assert_LanguageSelectExcludeSiteLanguages_testLanguageSelectDeclaresExcludeSiteLanguagesAttribute(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/I18n' . '/Taglib/LanguageSelect.php';
    self::assertFileExists($path);
    $content = (string) file_get_contents($path);
    self::assertStringContainsString("'exclude-site-languages' => false", $content);
    self::assertStringContainsString('resolveSiteLanguageCodes', $content);
    self::assertStringContainsString('data-w-exclude-site-languages', $content);
    self::assertStringContainsString('data-w-site-language="true"', $content);
    self::assertStringContainsString('data-w-excluded-label', $content);
}

private function assert_LanguageSelectExcludeSiteLanguages_testLanguageSupportRequestFormUsesExcludeSiteLanguages(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/I18n' . '/view/templates/frontend/language-support-request.phtml';
    self::assertFileExists($path);
    $content = (string) file_get_contents($path);
    self::assertStringContainsString('exclude-site-languages="true"', $content);
    self::assertStringContainsString('data-language-request-form-shell', $content);
    self::assertStringNotContainsString('disabled-values="disabled_languages"', $content);
    self::assertStringNotContainsString('<script', $content);
}

private function assert_LanguageSelectFieldNameLiteral_testFormNameIsCompiledAsHtmlLiteral(): void
{
    $compiled = LanguageSelect::callback()('w:i18n:language:select', [], [], ['id' => 'cms-page-locale', 'name' => 'locale_code']);
    self::assertStringContainsString("\$Taglib__name = 'locale_code';", $compiled);
    self::assertStringContainsString('$Taglib__multiple = false;', $compiled);
}

private function assert_LanguageSelectFieldNameLiteral_testOmittedMultipleForcesSingleSelectEvenAfterLeak(): void
{
    $compiled = LanguageSelect::callback()('w:i18n:language:select', [], [], ['id' => 'website_default_language_selector', 'name' => 'default_language', 'allow-empty' => 'true']);
    self::assertStringContainsString('$Taglib__multiple = false;', $compiled);
    self::assertStringContainsString("\$Taglib__name = 'default_language';", $compiled);
}

private function assert_LanguageSelectReorderUrl_testTaglibExposesReorderUrlAttributes(): void
{
    $taglib = \file_get_contents(BP . '/app/code/Weline/I18n/Taglib/LanguageSelect.php');
    self::assertIsString($taglib);
    self::assertStringContainsString("'reorder-url' => false", $taglib);
    self::assertStringContainsString("'reorder-website-id' => false", $taglib);
    self::assertStringContainsString('Taglib__reorder_url', $taglib);
    self::assertStringContainsString('__wls_reorder_url', $taglib);
}

private function assert_LanguageSelectReorderUrl_testMarkupAndRuntimeWireReorderDataset(): void
{
    $markup = \file_get_contents(BP . '/app/code/Weline/I18n/view/templates/taglib/language-select-markup.phtml');
    self::assertIsString($markup);
    self::assertStringContainsString('data-w-reorder-url', $markup);
    self::assertStringContainsString('data-w-reorder-website-id', $markup);
    // Trigger is a div[role=button] so HTML5 DnD on capsules works; remove controls may still be <button>.
    self::assertStringContainsString('role="button"', $markup);
    self::assertMatchesRegularExpression('/<div\s+class="w-language-select__trigger"/', $markup);
    self::assertStringNotContainsString('<button\n        class="w-language-select__trigger"', $markup);
    self::assertStringContainsString('w-language-select__tag', $markup);
    self::assertStringContainsString('w-language-select__drag-handle', $markup);
    $js = \file_get_contents(BP . '/app/code/Weline/I18n/view/statics/js/language-select.js');
    $css = \file_get_contents(BP . '/app/code/Weline/I18n/view/statics/css/language-select.css');
    self::assertIsString($js);
    self::assertIsString($css);
    self::assertStringContainsString('is-sortable', $js);
    self::assertStringContainsString('syncSelectOptionOrder', $js);
    self::assertStringContainsString('scheduleReorderSave', $js);
    self::assertStringContainsString('resolveReorderUrl', $js);
    self::assertStringContainsString('w-language-select__drag-handle', $js);
    self::assertStringContainsString('elementFromPoint', $js);
    self::assertStringContainsString('event.target instanceof Element ? event.target : null', $js);
    self::assertStringContainsString('insertBefore(dragging', $js);
    self::assertStringContainsString("dropEffect = 'move'", $js);
    self::assertStringContainsString('cursor: grab', $css);
    self::assertStringContainsString('w-language-select__drag-handle', $css);
    self::assertMatchesRegularExpression('/\.w-language-select__tag\.is-dragging\s*\{[^}]*pointer-events:\s*none/s', $css);
    // :active 不得带 pointer-events:none，否则按下瞬间取消 HTML5 dragstart（拖不动）
    self::assertDoesNotMatchRegularExpression('/\.w-language-select__tag\.is-sortable:active[^{]*\{[^}]*pointer-events:\s*none/s', $css);
    self::assertStringContainsString("closest('.w-language-select__tag.is-sortable')", $js);
    self::assertStringContainsString('listen(tags, \'pointerdown\'', $js);
}

private function assert_LanguageSelectSearchFocus_testSearchFocusIsDeferredPastTriggerClick(): void
{
    $runtime = $this->read_LanguageSelectSearchFocus('view/statics/js/language-select.js');
    $theme = $this->readTheme_LanguageSelectSearchFocus('view/statics/ui/components/weline-language-select.js');
    self::assertStringContainsString('const focusSearch = () =>', $runtime);
    self::assertStringContainsString('window.setTimeout(() =>', $runtime);
    self::assertStringContainsString("getAttribute('data-w-search')", $runtime);
    self::assertStringContainsString("listen(search, 'compositionend'", $runtime);
    self::assertStringContainsString('focusSearch();', $runtime);
    self::assertStringContainsString('focusSearch', $theme);
    self::assertStringContainsString('window.setTimeout', $theme);
    self::assertStringContainsString("classList.toggle('has-value'", $runtime);
    self::assertStringContainsString('dataset.hasValue', $runtime);
    self::assertStringContainsString("classList.toggle('has-value'", $theme);
    self::assertStringContainsString('dataset.hasValue', $theme);
}

private function read_LanguageSelectSearchFocus(string $path): string
{
    $content = file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/I18n' . '/' . $path);
    self::assertIsString($content, $path . ' must be readable');
    return $content;
}

private function readTheme_LanguageSelectSearchFocus(string $path): string
{
    $content = file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline' . '/Theme/' . $path);
    self::assertIsString($content, $path . ' must be readable');
    return $content;
}

private function assert_LanguageSelectThemeTokens_testLanguageSelectStylesPreferThemeTokensOverBackendFallbacks(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/I18n' . '/Taglib/LanguageSelect.php';
    self::assertFileExists($path);
    $content = (string) file_get_contents($path);
    self::assertStringContainsString('--weline-theme-surface', $content);
    self::assertStringContainsString('--weline-theme-text', $content);
    self::assertStringContainsString('--weline-theme-border-strong', $content);
    self::assertStringContainsString('--weline-theme-surface-muted', $content);
    self::assertStringContainsString('--weline-theme-primary', $content);
    self::assertStringContainsString('background: var(--weline-theme-surface, var(--backend-color-card-bg, #fff));', $content);
    self::assertStringNotContainsString("background: var(--backend-color-card-bg, #fff);\n", $content);
}

private function assert_LanguageSwitcherCatalogHotCache_testCatalogUsesStorefrontScopeHotCacheWithoutPathBoundHtml(): void
{
    $taglib = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/I18n' . '/Taglib/LanguageSwitcher.php');
    self::assertSame('i18n.language_switcher.catalog', LanguageSwitcher::CATALOG_CACHE_RESOURCE);
    self::assertInstanceOf(CachePolicy::class, LanguageSwitcher::catalogCachePolicy());
    self::assertSame('i18n.language_switcher.catalog', LanguageSwitcher::catalogCachePolicy()->resource);
    self::assertSame('i18n', LanguageSwitcher::catalogCachePolicy()->pool);
    self::assertSame('website', LanguageSwitcher::catalogCachePolicy()->scope);
    self::assertContains('global/i18n', LanguageSwitcher::catalogCachePolicy()->dependencies);
    self::assertStringContainsString('StorefrontScopeHotCache', $taglib);
    self::assertStringNotContainsString('rememberForRequest', $taglib);
    self::assertStringContainsString('rememberPolicy', $taglib);
    self::assertStringContainsString('CATALOG_CACHE_RESOURCE', $taglib);
    self::assertStringContainsString('catalogCachePolicy()', $taglib);
    self::assertStringContainsString('buildLanguagesFromCodes', $taglib);
    // Path-bound HTML shell must stay request-local — never fold URL into catalog HotCache.
    self::assertStringContainsString('buildHtmlCacheKey', $taglib);
    self::assertStringContainsString('$htmlCache', $taglib);
    self::assertStringNotContainsString("self::\$languageCache", $taglib);
    self::assertDoesNotMatchRegularExpression('/rememberPolicy\([\s\S]{0,400}buildHtmlCacheKey|rememberPolicy\([\s\S]{0,400}\$htmlCache/', $taglib);
    // P2: storefront catalog warm exists; dead getLanguageOptions path removed.
    self::assertStringContainsString('function warmStorefrontCatalogCaches', $taglib);
    self::assertStringContainsString('buildLanguagesFromScope', $taglib);
    self::assertStringContainsString('LocaleCatalogScopeResolver', $taglib);
    self::assertStringNotContainsString('getLanguageOptions(', $taglib);
    self::assertStringContainsString('warmBackendCaches', $taglib);
    // Warm must not write path-bound HTML cache.
    $warmBodyStart = strpos($taglib, 'function warmStorefrontCatalogCaches');
    self::assertNotFalse($warmBodyStart);
    $warmSlice = substr($taglib, $warmBodyStart, 1200);
    self::assertStringNotContainsString('$htmlCache', $warmSlice);
    self::assertStringNotContainsString('buildHtmlCacheKey', $warmSlice);
}

private function assert_LanguageSwitcherClickRace_testTaglibUsesTheLazyUiComponentAndAuthoritativeHrefs(): void
{
    $taglib = $this->read_LanguageSwitcherClickRace('Taglib/LanguageSwitcher.php');
    $runtime = $this->read_LanguageSwitcherClickRace('view/statics/js/language-switcher.js');
    self::assertStringContainsString('weline-ui-2-language-switcher-', $taglib);
    self::assertStringContainsString('SWITCHER_MARKUP_VERSION', $taglib);
    self::assertSame('component-27-panel-lazy', \Weline\I18n\Taglib\LanguageSwitcher::SWITCHER_MARKUP_VERSION);
    self::assertStringContainsString('data-i18n-panel-lazy', $taglib);
    self::assertStringContainsString('renderLazyPanelCatalog', $taglib);
    self::assertStringContainsString('getLanguageSwitcherCatalog', $runtime);
    self::assertStringContainsString('fetchLanguageSwitcherCatalogHtml', $runtime);
    self::assertStringContainsString('panelCatalogPromises', $runtime);
    self::assertStringContainsString('PANEL_CATALOG_TIMEOUT_MS', $runtime);
    self::assertStringContainsString('Promise.race', $runtime);
    self::assertStringContainsString("aria-expanded') === 'true'", $runtime);
    self::assertStringContainsString('loadPanelCatalog', $runtime);
    self::assertStringContainsString('data-w-component="menu language-switcher"', $taglib);
    self::assertStringContainsString('data-w-anchor-mode="element"', $taglib);
    self::assertStringContainsString('data-w-language-search', $taglib);
    self::assertStringContainsString('w-language-switcher__list', $taglib);
    self::assertStringContainsString('data-w-language-list', $taglib);
    self::assertStringContainsString('w-language-switcher__footer', $taglib);
    self::assertStringContainsString('data-w-search=', $taglib);
    self::assertStringContainsString('translateChrome', $taglib);
    self::assertStringContainsString('loadChromeDictionary', $taglib);
    self::assertStringContainsString('applySearchFilter', $runtime);
    self::assertStringContainsString('w-language-switcher__footer', $runtime);
    self::assertStringContainsString('installGlobalLanguageOptionCapture', $runtime);
    self::assertStringContainsString('resolveSwitcherRootForOption', $runtime);
    self::assertStringContainsString('isThemeEditorPreviewFrame', $runtime);
    self::assertStringContainsString('postThemeEditorPreviewLocaleChange', $runtime);
    self::assertStringContainsString("type: 'locale-change'", $runtime);
    self::assertStringContainsString("source: 'weline-theme-preview'", $runtime);
    self::assertStringContainsString('editor_mode', $runtime);
    self::assertStringContainsString('syncLanguageSwitcherTriggerFlag', $runtime);
    self::assertStringContainsString('Never wipe a painted trigger', $runtime);
    self::assertStringContainsString("img.loading = 'eager'", $runtime);
    self::assertStringContainsString("mode === 'trigger'", $runtime);
    self::assertStringContainsString('CountryFlagMarkup::triggerHtml', $taglib);
    self::assertStringContainsString('capture-editor', $runtime);
    self::assertStringContainsString('traceLocaleSwitch', $runtime);
    $captureFn = strpos($runtime, 'function installGlobalLanguageOptionCapture');
    self::assertNotFalse($captureFn);
    $captureBody = substr($runtime, $captureFn, 2200);
    $editorCheck = strpos($captureBody, 'isThemeEditorPreviewFrame()');
    $rootResolve = strpos($captureBody, 'resolveSwitcherRootForOption(option)');
    self::assertNotFalse($editorCheck);
    self::assertNotFalse($rootResolve);
    self::assertLessThan($rootResolve, $editorCheck, 'installGlobalLanguageOptionCapture must test editor frame before root resolve');
    self::assertStringContainsString('Prefer the panel nested under this switcher root', $runtime);
    self::assertStringContainsString('bindSearch', $runtime);
    self::assertStringContainsString('focusSearch', $runtime);
    self::assertStringContainsString('window.setTimeout', $runtime);
    self::assertStringContainsString("navigation === 'emit'", $runtime);
    self::assertStringContainsString('writeLanguagePreference(locale', $runtime);
    self::assertStringContainsString('resolveLanguageNavigationHref', $runtime);
    self::assertStringContainsString('rebuildPathWithLocale', $runtime);
    self::assertStringContainsString('peelLivePreviewPathMount', $runtime);
    self::assertStringContainsString('LIVE_PREVIEW_TOKEN_PATTERN', $runtime);
    self::assertStringContainsString('never re-stack', $runtime);
    self::assertStringContainsString('refreshLanguageOptionHrefs', $runtime);
    self::assertStringContainsString('window.urlWithLang', $runtime);
    self::assertStringContainsString('navigateLanguageOption', $runtime);
    self::assertStringContainsString('window.location.assign(', $runtime);
    self::assertStringContainsString('window.location.reload();', $runtime);
    self::assertStringNotContainsString('<script', $taglib);
    self::assertStringNotContainsString('window.WelineI18n', $runtime);
    $i18nJs = file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline' . '/Framework/View/statics/js/i18n.js');
    self::assertIsString($i18nJs);
    self::assertStringContainsString('[data-country-flag], .w-language-switcher__flag, .weline-choice-flag', $i18nJs);
    self::assertStringContainsString('[data-w-menu-trigger] [data-country-flag]', $i18nJs);
    self::assertStringContainsString('Never wipe a painted', $i18nJs);
    self::assertStringContainsString('optionPainted', $i18nJs);
    $themeJs = file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline' . '/Theme/view/theme/frontend/assets/js/theme.js');
    self::assertIsString($themeJs);
    self::assertStringContainsString('Never wipe a painted', $themeJs);
    self::assertStringContainsString('optionPainted', $themeJs);
}

private function assert_LanguageSwitcherClickRace_testLanguageRequestUsesTheSameScopedComponent(): void
{
    $taglib = $this->read_LanguageSwitcherClickRace('Taglib/LanguageSwitcher.php');
    $runtime = $this->read_LanguageSwitcherClickRace('view/statics/js/language-switcher.js');
    self::assertStringContainsString('data-language-request-open', $taglib);
    self::assertStringContainsString('data-language-request-modal', $taglib);
    self::assertStringContainsString('data-language-request-body', $taglib);
    self::assertStringContainsString('data-w-component="dialog"', $taglib);
    self::assertStringContainsString('<dialog id="', $taglib);
    self::assertStringContainsString('getLanguageSupportRequestForm', $runtime);
    self::assertStringContainsString('submitLanguageSupportRequest', $runtime);
    self::assertStringContainsString('bindLanguageRequestForm', $runtime);
    self::assertStringContainsString('activateTrustedScripts', $runtime);
    self::assertStringContainsString('event.preventDefault()', $runtime);
    self::assertStringContainsString('i18n_language_requests', $runtime);
    self::assertStringContainsString('UI.dialog.open', $runtime);
    self::assertStringNotContainsString('WelineLanguageSupportRequest', $runtime);
    self::assertStringNotContainsString('/i18n/frontend/language-support-request', $taglib);
}

private function assert_LanguageSwitcherClickRace_testLanguageSupportRequestFormHasNoInlineSubmitScript(): void
{
    $form = $this->read_LanguageSwitcherClickRace('view/templates/frontend/language-support-request.phtml');
    self::assertStringContainsString('data-language-request-form-shell', $form);
    self::assertStringContainsString('data-language-request-feedback', $form);
    self::assertStringContainsString('data-msg-success=', $form);
    self::assertStringNotContainsString('<script', $form);
    self::assertStringNotContainsString('addEventListener(\'submit\'', $form);
}

private function assert_LanguageSwitcherClickRace_testLiveHttpRequestBeatsStaleThemeDataBackendArea(): void
{
    $taglib = $this->read_LanguageSwitcherClickRace('Taglib/LanguageSwitcher.php');
    self::assertStringContainsString('Live HTTP request is authoritative', $taglib);
    self::assertStringContainsString('ThemeData area can remain', $taglib);
}

private function read_LanguageSwitcherClickRace(string $path): string
{
    $content = file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/I18n' . '/' . $path);
    self::assertIsString($content, $path . ' must be readable');
    return $content;
}

private function assert_LanguageSwitcherPanelLazy_testStorefrontLazyPanelSkipsFullCatalogSsrAndExposesQueryOp(): void
{
    $taglib = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/I18n' . '/Taglib/LanguageSwitcher.php');
    $runtime = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/I18n' . '/view/statics/js/language-switcher.js');
    $query = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/I18n' . '/extends/module/Weline_Framework/Query/I18nQueryProvider.php');
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
    self::assertMatchesRegularExpression("/'name'\\s*=>\\s*'getLanguageSwitcherCatalog'[\\s\\S]{0,220}'frontend'\\s*=>\\s*true/", $query);
    self::assertStringContainsString('fetchLanguageSwitcherCatalogHtml', $runtime);
    self::assertStringContainsString("resource?.('i18n')", $runtime);
    self::assertStringContainsString('getLanguageSwitcherCatalog', $runtime);
    self::assertStringContainsString('panelCatalogPromises', $runtime);
    self::assertStringContainsString('PANEL_CATALOG_TIMEOUT_MS', $runtime);
    self::assertStringContainsString('Promise.race', $runtime);
    self::assertStringContainsString("aria-expanded') === 'true'", $runtime);
    self::assertStringContainsString("weline:ui:menu:open", $runtime);
    self::assertStringContainsString('loadPanelCatalog', $runtime);
    self::assertStringContainsString('data-i18n-panel-lazy', $runtime);
    // Storefront loads Theme UI component mirror, not I18n statics path.
    $themeUi = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline' . '/Theme/view/statics/ui/components/weline-language-switcher.js');
    self::assertStringContainsString('fetchLanguageSwitcherCatalogHtml', $themeUi);
    self::assertStringContainsString('PANEL_CATALOG_TIMEOUT_MS', $themeUi);
    self::assertStringContainsString("aria-expanded') === 'true'", $themeUi);
    self::assertStringContainsString('loadPanelCatalog', $themeUi);
    self::assertStringContainsString('getLanguageSwitcherCatalog', $themeUi);
}

private function assert_LanguageSwitcherTagName_testCanonicalTagNameIsI18nSwitcher(): void
{
    self::assertSame('i18n:switcher', LanguageSwitcher::name());
    self::assertSame('i18n:switcher', LanguageSwitcher::TAG_NAME);
}

private function assert_LanguageSwitcherTagName_testLegacyAliasIsRemoved(): void
{
    self::assertFileDoesNotExist('/Users/weline/Project/Official/框架/app/code/Weline/I18n' . '/Taglib/LanguageSwitcherLegacyAlias.php');
    self::assertFalse(defined(LanguageSwitcher::class . '::LEGACY_TAG_NAME'));
}

private function assert_LocalBulkMode_testLocalTagDeclaresBulkModeAndSharedDialog(): void
{
    $source = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/I18n' . '/Taglib/Local.php');
    self::assertStringContainsString("'mode' => false", $source);
    self::assertStringContainsString("'bulk-fields' => false", $source);
    self::assertStringContainsString('isBulkRenderTagKey', $source);
    self::assertStringContainsString('tag-self-close-with-attrs', $source);
    self::assertStringContainsString('data-w-local-bulk-root', $source);
    self::assertStringContainsString('data-w-local-bulk-dialog', $source);
    self::assertStringContainsString('data-w-closable="false"', $source);
    self::assertStringContainsString('resolveModuleStaticUrl', $source);
    self::assertStringContainsString('fetchTagSource', $source);
    self::assertStringNotContainsString("getStaticUrl('Weline_I18n::js/local-bulk-translation.js')", $source);
    self::assertStringContainsString('data-w-local-bulk-participant', $source);
}

private function assert_LocalBulkMode_testResumableTaskRegistrationExists(): void
{
    $source = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/I18n' . '/etc/resumable_tasks.php');
    self::assertStringContainsString('TaglibLocalBulkTranslationTaskHandler::TYPE_CODE', $source);
    self::assertStringContainsString('TaglibLocalBulkTranslationTaskHandler::class', $source);
    self::assertStringContainsString('Weline_I18n::i18n_dictionaries', $source);
}

private function assert_LocalBulkMode_testBulkTranslationJsUsesRuntimeTaskAndModalLock(): void
{
    $source = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/I18n' . '/view/statics/js/local-bulk-translation.js');
    self::assertStringContainsString('i18n.taglib_local_bulk', $source);
    self::assertStringContainsString('runTranslationTaskPolling', $source);
    self::assertStringContainsString('resolveRuntimeStartError', $source);
    self::assertStringNotContainsString('.createStream(', $source);
    self::assertStringContainsString('wClosable', $source);
    self::assertStringContainsString('finishDialog', $source);
    self::assertStringContainsString('getDialogCloseButtons', $source);
    self::assertStringContainsString("querySelectorAll('[data-w-local-bulk-close]')", $source);
}

private function assert_LocalTranslationTriggerClamp_testTriggerTextUsesThreeLineClampAndAllowsWrap(): void
{
    $css = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/I18n' . '/view/statics/css/local-translation.css');
    $taglib = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/I18n' . '/Taglib/Local.php');
    self::assertStringContainsString('Tag-level affordance: light primary wash', $css);
    self::assertStringContainsString('w-local-translation__trigger-text', $css);
    self::assertStringContainsString('-webkit-line-clamp: 3', $css);
    self::assertStringContainsString('line-clamp: 3', $css);
    self::assertStringContainsString('white-space: normal', $css);
    self::assertStringContainsString('inline-size: 100%', $css);
    self::assertStringContainsString('Override .w-button inline-flex/nowrap', $css);
    self::assertStringContainsString('w-local-translation__trigger-text', $taglib);
    self::assertStringContainsString('<div class="w-local-translation__wrap"', $taglib);
    self::assertStringContainsString('<div class="w-local-translation__trigger-text">', $taglib);
    self::assertStringNotContainsString('<span class="w-local-translation__wrap"', $taglib);
    self::assertStringContainsString('20260916-trigger-bg2', $taglib);
    self::assertStringContainsString('[data-tone="quiet"]', $css);
}
}

final class LanguageSelectDisplayNameContractTest extends TestCase
{
    public function testBuildDisplayNameDoesNotHardConcatEnglishAndSelfWhenSameLabel(): void
    {
        $name = LanguageSelect::buildDisplayName('中文（简体，中国）', 'Chinese (Simplified, China)', '中文（简体，中国）', 'zh_Hans_CN');
        self::assertSame('中文（简体，中国）', $name);
        self::assertStringNotContainsString('Chinese (Simplified, China)(', $name);
    }
    public function testBuildTagLabelPrefersShortLocalizedLabel(): void
    {
        $label = LanguageSelect::buildTagLabel('中文（简体，中国）', '中文（简体，中国）', 'Chinese (Simplified, China)', 'zh_Hans_CN');
        self::assertSame('中文（简体，中国）', $label);
    }
}

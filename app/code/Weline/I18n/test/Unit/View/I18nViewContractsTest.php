<?php

declare(strict_types=1);

namespace Weline\I18n\Test\Unit\View;

use PHPUnit\Framework\TestCase;


/**
 * Aggregated contract suite (merged from 7 *ContractTest files).
 * Observe one overall pass/fail via testAllI18nViewContractsPass().
 */
final class I18nViewContractsTest extends TestCase
{
    public function testAllI18nViewContractsPass(): void
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
            'AiTranslationExportDialog::testQueueErrorAndExportDialogSurface' => function () {
            $this->setUp_AiTranslationExportDialog();
            $this->assert_AiTranslationExportDialog_testQueueErrorAndExportDialogSurface();
        },
            'AiTranslationModuleWorkspace::testModuleTabTranslateWritebackSurface' => function () {
            $this->setUp_AiTranslationModuleWorkspace();
            $this->assert_AiTranslationModuleWorkspace_testModuleTabTranslateWritebackSurface();
        },
            'DictionaryModuleCatalogLazyLoad::testDictionaryPageNeverSsrsFullListModules' => function () {
            $this->setUp_DictionaryModuleCatalogLazyLoad();
            $this->assert_DictionaryModuleCatalogLazyLoad_testDictionaryPageNeverSsrsFullListModules();
        },
            'HeaderChoiceSelectorAssets::testHeaderChoiceAssetsDeclareI18nAndCurrencyModules' => function () {
            $this->assert_HeaderChoiceSelectorAssets_testHeaderChoiceAssetsDeclareI18nAndCurrencyModules();
        },
            'HeaderChoiceSelectorAssets::testChoiceFilterRegistersIdempotentlyAndExportsRegister' => function () {
            $this->assert_HeaderChoiceSelectorAssets_testChoiceFilterRegistersIdempotentlyAndExportsRegister();
        },
            'HeaderChoiceSelectorAssets::testCurrencySwitcherDedupesAssetsWithRequestContext' => function () {
            $this->assert_HeaderChoiceSelectorAssets_testCurrencySwitcherDedupesAssetsWithRequestContext();
        },
            'I18nEnhancementDoesNotOwnCoreJs::testNoCoreI18nModulesRegistrationInI18nModule' => function () {
            $this->assert_I18nEnhancementDoesNotOwnCoreJs_testNoCoreI18nModulesRegistrationInI18nModule();
        },
            'I18nEnhancementDoesNotOwnCoreJs::testLanguageSwitcherEnhancementScriptRemains' => function () {
            $this->assert_I18nEnhancementDoesNotOwnCoreJs_testLanguageSwitcherEnhancementScriptRemains();
        },
            'I18nJsSwitchLangSamePath::testSwitchLangReloadsWhenTargetUrlMatchesCurrentPath' => function () {
            $this->assert_I18nJsSwitchLangSamePath_testSwitchLangReloadsWhenTargetUrlMatchesCurrentPath();
        },
            'LocalesDictionaryManageLink::testPanelAndListLinkToDictionaryWithLocaleCode' => function () {
            $this->setUp_LocalesDictionaryManageLink();
            $this->assert_LocalesDictionaryManageLink_testPanelAndListLinkToDictionaryWithLocaleCode();
        },
        ];
    }

private function setUp_AiTranslationExportDialog(): void
{
    if (!\defined('BP')) {
        \define('BP', '/Users/weline/Project/Official/框架' . DIRECTORY_SEPARATOR);
    }
}

private function assert_AiTranslationExportDialog_testQueueErrorAndExportDialogSurface(): void
{
    $template = $this->read_AiTranslationExportDialog('app/code/Weline/I18n/view/templates/backend/AiTranslation/index.phtml');
    $js = $this->read_AiTranslationExportDialog('app/code/Weline/I18n/view/statics/js/backend-admin.js');
    $controller = $this->read_AiTranslationExportDialog('app/code/Weline/I18n/Controller/Backend/AiTranslation.php');
    $export = $this->read_AiTranslationExportDialog('app/code/Weline/I18n/Service/AiTranslationExportService.php');
    self::assertStringContainsString('queue_result_summary', $controller);
    self::assertStringContainsString('queue_is_error', $controller);
    self::assertStringContainsString('i18n-admin-queue-result', $template);
    self::assertStringContainsString('ai-export-modules-modal', $template);
    self::assertStringContainsString('data-ai-export-open', $template);
    self::assertStringContainsString('name="module_name"', $template);
    self::assertStringContainsString('bindAiExportDialog', $js);
    self::assertStringContainsString('listAiSourceModules', $export);
    self::assertStringContainsString("getPost('module_name'", $controller);
    self::assertStringContainsString('exportModuleTranslations', $export);
    self::assertStringContainsString('schema_fields_EXPORTED_AT', $export);
    self::assertStringContainsString('增量写回', $export);
}

private function read_AiTranslationExportDialog(string $relativePath): string
{
    $path = BP . DIRECTORY_SEPARATOR . ltrim($relativePath, '/\\');
    self::assertFileExists($path);
    return (string) file_get_contents($path);
}

private function setUp_AiTranslationModuleWorkspace(): void
{
    if (!\defined('BP')) {
        \define('BP', '/Users/weline/Project/Official/框架' . DIRECTORY_SEPARATOR);
    }
}

private function assert_AiTranslationModuleWorkspace_testModuleTabTranslateWritebackSurface(): void
{
    $template = $this->read_AiTranslationModuleWorkspace('app/code/Weline/I18n/view/templates/backend/AiTranslation/index.phtml');
    $js = $this->read_AiTranslationModuleWorkspace('app/code/Weline/I18n/view/statics/js/backend-admin.js');
    $controller = $this->read_AiTranslationModuleWorkspace('app/code/Weline/I18n/Controller/Backend/AiTranslation.php');
    $provider = $this->read_AiTranslationModuleWorkspace('app/code/Weline/I18n/extends/module/Weline_Framework/Query/I18nAdminQueryProvider.php');
    $service = $this->read_AiTranslationModuleWorkspace('app/code/Weline/I18n/Service/AiTranslationModuleWorkspaceService.php');
    $catalog = $this->read_AiTranslationModuleWorkspace('app/code/Weline/I18n/Service/DictionaryModuleCatalogService.php');
    self::assertStringContainsString('listModulesLite', $controller);
    self::assertStringContainsString('postModuleTranslateAndWriteback', $controller);
    self::assertStringContainsString('getModuleLocaleMatrix', $controller);
    self::assertStringContainsString('AiTranslationModuleWorkspaceService', $controller);
    self::assertMatchesRegularExpression('/function getModuleLocaleMatrix\(\)[\s\S]*?getLocaleName[\s\S]*?function /', $controller);
    if (\preg_match('/function getModuleLocaleMatrix\(\)[\s\S]*?\n    public function /', $controller, $matrixMethod) === 1) {
        self::assertStringNotContainsString('$this->buildLocaleRows', $matrixMethod[0]);
    } else {
        self::fail('getModuleLocaleMatrix method body not found');
    }
    self::assertStringContainsString('ai-module-matrix', $provider);
    self::assertStringContainsString('ai-module-translate-writeback', $provider);
    self::assertStringContainsString("'ai-module-matrix' => 'Weline_I18n::i18n_ai_translation'", $provider);
    self::assertStringContainsString('tab=modules', $template);
    self::assertStringContainsString('一键翻译并写回', $template);
    self::assertStringContainsString('data-ai-module-writeback-form', $template);
    self::assertStringContainsString('data-ai-module-expand', $template);
    self::assertStringContainsString('系统活跃模块', $template);
    self::assertStringContainsString('bindAiModuleWorkspace', $js);
    self::assertStringContainsString('DOMContentLoaded', $js);
    self::assertStringContainsString('ai-module-matrix', $js);
    self::assertStringContainsString('locale_codes[]', $js);
    self::assertStringContainsString('translateAndWriteback', $service);
    self::assertStringContainsString('summarizeModuleWorkOverview', $catalog);
    self::assertStringContainsString('summarizeModuleWorkOverview', $service);
    self::assertStringContainsString('locales_pending', $service);
    self::assertStringContainsString('words_pending', $service);
    self::assertStringContainsString('data-ai-module-status-detail', $template);
    self::assertStringContainsString('待处理 %{1}/%{2} 种语言 · %{3} 词', $template);
    self::assertStringContainsString('updateModuleRowStatusFromMatrix', $js);
    self::assertStringContainsString('listModulesLite', $service);
    self::assertStringContainsString('getModuleLocaleMatrix', $service);
    self::assertStringContainsString('getActiveModules', $service);
    self::assertStringContainsString('syncModuleCollectedWords', $catalog);
    self::assertStringContainsString('TranslationCollector', $catalog);
    self::assertStringContainsString('syncModuleCollectedWords', $service);
    self::assertStringContainsString('data-ai-module-matrix-url', $template);
    self::assertStringContainsString('summarizeModuleLocaleAlignment', $catalog);
    self::assertStringContainsString('countModuleSourceWords', $catalog);
    self::assertStringContainsString('getModuleLocaleMatrixBundle', $service);
    self::assertStringContainsString('summarizeModuleLocaleAlignment', $service);
    self::assertStringContainsString('streamModuleLocaleMatrix', $service);
    self::assertStringContainsString('SseWriter', $service);
    self::assertStringContainsString('countAiPendingExportByLocales', $catalog);
    self::assertStringContainsString('countAiPendingExportByLocales', $service);
    self::assertStringContainsString('streamModuleLocaleMatrix', $controller);
    self::assertStringContainsString('text/event-stream', $controller);
    self::assertStringContainsString('EventSource', $js);
    self::assertStringContainsString("searchParams.set('sse', '1')", $js);
    self::assertStringContainsString('__matrixXhr', $js);
    self::assertStringContainsString('parseSseBuffer', $js);
    self::assertStringContainsString('updateSourceBanner', $js);
    self::assertStringContainsString('data-ai-module-source-banner', $template);
    self::assertStringContainsString('data-col-words', $template);
    self::assertStringContainsString('data-col-translated', $template);
    self::assertStringContainsString('data-col-gap', $template);
    self::assertStringContainsString("setRequestHeader('Accept', 'text/event-stream')", $js);
    self::assertStringContainsString('closeMatrixStream', $js);
    self::assertStringContainsString('resolveModuleMatrixUrl', $js);
    self::assertStringContainsString('module-locale-matrix', $js);
    self::assertStringContainsString('data-has-work', $js);
    self::assertStringContainsString('data-ai-module-select-work', $js);
    self::assertStringContainsString('data-ai-module-matrix-hint', $js);
    self::assertStringNotContainsString('data-ai-module-locale-stat', $template);
    self::assertStringContainsString('loadTranslatedWordSet', $catalog);
    $collector = $this->read_AiTranslationModuleWorkspace('app/code/Weline/I18n/Service/TranslationCollector.php');
    self::assertStringContainsString('SchedulerSystem::yield()', $collector);
    self::assertStringContainsString('includeDb', $catalog);
    self::assertStringContainsString('Env::system(\'deploy\')', $catalog);
    self::assertStringContainsString("key === 'csrf'", $js);
    self::assertStringContainsString('requestHttpFormFallback', $js);
    self::assertStringContainsString('isAuthTransportError', $js);
    self::assertStringContainsString('data-ai-module-writeback-form', $js);
    self::assertStringContainsString('preferHttp', $js);
    self::assertStringContainsString('streamTranslateAndWriteback', $service);
    self::assertStringContainsString('runTranslateAndWriteback', $service);
    self::assertStringContainsString('streamTranslateAndWriteback', $controller);
    self::assertStringContainsString('getModuleTranslateAndWriteback', $controller);
    self::assertStringContainsString('startModuleWritebackSse', $js);
    self::assertStringContainsString('parseSseBuffer', $js);
    self::assertStringContainsString("setRequestHeader('Accept', 'text/event-stream')", $js);
    self::assertStringContainsString('locale_done', $js);
    self::assertStringContainsString('data-ai-module-writeback-sse', $template);
    self::assertStringContainsString('backendAdminJsVersion', $template);
    self::assertStringContainsString('/Weline/I18n/view/statics/js/backend-admin.js?v=', $template);
    self::assertStringContainsString('confirmWriteback', $js);
    self::assertStringContainsString('dismissStaleDialogs', $js);
    self::assertStringContainsString('onDocClick', $js);
    self::assertStringContainsString('I18N_ADMIN_UI_VERSION', $js);
    self::assertStringContainsString('currentHintEl', $js);
    self::assertStringContainsString('__writebackBusy', $js);
    self::assertStringContainsString('keepWriteback', $js);
    self::assertStringContainsString('createWritebackProgressUi', $js);
    self::assertStringContainsString('scheduleMatrixReconnect', $js);
    self::assertStringContainsString('scheduleWritebackReconnect', $js);
    self::assertStringContainsString('SSE_RECONNECT_MAX', $js);
    self::assertStringContainsString('连接中断，', $js);
    self::assertStringContainsString('openWritebackStream', $js);
    self::assertStringContainsString('data-ai-writeback-progress', $js);
    self::assertStringContainsString('data-ai-writeback-chip', $js);
    self::assertStringContainsString('data-ai-writeback-chip', $template);
    self::assertStringContainsString("'node' => 'collect'", $service);
    self::assertStringContainsString("'node' => 'verify'", $service);
    self::assertStringContainsString("id: 'verify'", $js);
    self::assertStringContainsString('校验对齐', $js);
    self::assertStringContainsString('未对齐', $js);
    self::assertStringContainsString('exportModuleCsvGaps', $service);
    $export = $this->read_AiTranslationModuleWorkspace('app/code/Weline/I18n/Service/AiTranslationExportService.php');
    self::assertStringContainsString('exportModuleCsvGaps', $export);
    self::assertStringContainsString('translateModule($moduleName, $localeCode, 0, false)', $service);
    self::assertStringContainsString('bool $syncFirst = true', $catalog);
    self::assertStringContainsString("'source_locale' => \$sourceLocale", $catalog);
    self::assertStringContainsString('Target locale CSVs are filled later', $catalog);
    self::assertStringContainsString('stageButtonLabel', $js);
    self::assertStringNotContainsString('data-async-action="ai-module-translate-writeback"', $template);
}

private function read_AiTranslationModuleWorkspace(string $relativePath): string
{
    $path = BP . DIRECTORY_SEPARATOR . ltrim($relativePath, '/\\');
    self::assertFileExists($path);
    return (string) file_get_contents($path);
}

private function setUp_DictionaryModuleCatalogLazyLoad(): void
{
    if (!\defined('BP')) {
        \define('BP', '/Users/weline/Project/Official/框架' . DIRECTORY_SEPARATOR);
    }
}

private function assert_DictionaryModuleCatalogLazyLoad_testDictionaryPageNeverSsrsFullListModules(): void
{
    $controller = $this->read_DictionaryModuleCatalogLazyLoad('app/code/Weline/I18n/Controller/Backend/Dictionary.php');
    $service = $this->read_DictionaryModuleCatalogLazyLoad('app/code/Weline/I18n/Service/DictionaryModuleCatalogService.php');
    $template = $this->read_DictionaryModuleCatalogLazyLoad('app/code/Weline/I18n/view/templates/backend/Dictionary.phtml');
    $provider = $this->read_DictionaryModuleCatalogLazyLoad('app/code/Weline/I18n/extends/module/Weline_Framework/Query/I18nAdminQueryProvider.php');
    self::assertStringContainsString("assign('module_catalog', [])", $controller);
    self::assertStringContainsString("assign('module_catalog_loaded', false)", $controller);
    self::assertStringContainsString('listModulesShell', $controller);
    self::assertStringContainsString('getModuleCatalogStats', $controller);
    self::assertStringContainsString('describeModule', $controller);
    if (\preg_match('/function get\(\)\s*\{[\s\S]*?\n    function /', $controller, $getMethod) === 1) {
        self::assertStringNotContainsString('listModules(', $getMethod[0]);
    } else {
        self::fail('Dictionary::get() method body not found');
    }
    if (\preg_match('/function getModuleCatalog\(\)\s*\{[\s\S]*?\n    (?:public |private |protected )?function /', $controller, $catalogMethod) === 1) {
        self::assertStringContainsString('listModulesShell', $catalogMethod[0]);
        self::assertStringNotContainsString('listModules(', $catalogMethod[0]);
    } else {
        self::fail('getModuleCatalog method body not found');
    }
    self::assertStringContainsString('function listModulesShell', $service);
    self::assertStringContainsString("'stats_ready' => false", $service);
    self::assertStringContainsString("'stats_ready' => true", $service);
    self::assertStringContainsString('dictionary-module-catalog-stats', $provider);
    self::assertStringContainsString("'dictionary-module-catalog-stats' => 'Weline_I18n::i18n_dictionaries'", $provider);
    self::assertStringContainsString('getModuleCatalogStats', $provider);
    self::assertStringContainsString('moduleCatalogStats', $template);
    self::assertStringContainsString('hydrateModuleCatalogStats', $template);
    self::assertStringContainsString('ensureModuleCatalogLoaded', $template);
    self::assertStringContainsString('stats_ready', $template);
}

private function read_DictionaryModuleCatalogLazyLoad(string $relative): string
{
    $path = BP . $relative;
    self::assertFileExists($path);
    return (string) \file_get_contents($path);
}

private function assert_HeaderChoiceSelectorAssets_testHeaderChoiceAssetsDeclareI18nAndCurrencyModules(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/I18n' . '/view/templates/frontend/header-choice-selector-assets.phtml';
    self::assertFileExists($path);
    $content = (string) file_get_contents($path);
    self::assertStringContainsString('data-weline-load="i18n,currency"', $content);
    self::assertStringNotContainsString('weline-choice-selector.css', $content);
    self::assertStringNotContainsString('weline-choice-selector.js', $content);
    self::assertStringNotContainsString('@static(Weline_Theme::ui/components/weline-choice-selector.js)', $content);
    self::assertStringNotContainsString('@static(Weline_Currency::js/currency.js)', $content);
    self::assertStringNotContainsString('@static(Weline_I18n::js/i18n.js)', $content);
    self::assertStringNotContainsString('WELINE_USER_LANG=', $content);
    self::assertStringNotContainsString('document.cookie', $content);
    self::assertStringNotContainsString('writeLanguagePreference', $content);
}

private function assert_HeaderChoiceSelectorAssets_testChoiceFilterRegistersIdempotentlyAndExportsRegister(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/I18n' . '/view/statics/js/header-choice-selector.js';
    self::assertFileExists($path);
    $content = (string) file_get_contents($path);
    self::assertStringContainsString('export function register(UI)', $content);
    self::assertStringContainsString("UI.define('choice-filter'", $content);
    self::assertStringContainsString('already (defined|registered)', $content);
    self::assertStringNotContainsString('if (window.Weline?.UI) register', $content);
}

private function assert_HeaderChoiceSelectorAssets_testCurrencySwitcherDedupesAssetsWithRequestContext(): void
{
    $builderPath = '/Users/weline/Project/Official/框架/app/code/Weline' . '/Currency/Service/CurrencySwitcherIslandBuilder.php';
    self::assertFileExists($builderPath);
    $content = (string) file_get_contents($builderPath);
    self::assertStringContainsString('view.i18n.header_choice_assets_rendered', $content);
    self::assertStringContainsString('CTX_ASSETS_RENDERED', $content);
    self::assertStringContainsString('RequestContext::get(self::CTX_ASSETS_RENDERED)', $content);
    self::assertStringContainsString('RequestContext::set(self::CTX_ASSETS_RENDERED, true)', $content);
    self::assertStringContainsString('data-weline-load="currency"', $content);
    self::assertStringNotContainsString('$GLOBALS[', $content);
}

private function assert_I18nEnhancementDoesNotOwnCoreJs_testNoCoreI18nModulesRegistrationInI18nModule(): void
{
    $modules = '/Users/weline/Project/Official/框架/app/code/Weline/I18n' . '/view/statics/frontend/weline.modules.js';
    $script = '/Users/weline/Project/Official/框架/app/code/Weline/I18n' . '/view/statics/js/i18n.js';
    self::assertFileDoesNotExist($modules);
    self::assertFileDoesNotExist($script);
}

private function assert_I18nEnhancementDoesNotOwnCoreJs_testLanguageSwitcherEnhancementScriptRemains(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/I18n' . '/view/statics/js/language-switcher.js';
    self::assertFileExists($path);
}

private function assert_I18nJsSwitchLangSamePath_testSwitchLangReloadsWhenTargetUrlMatchesCurrentPath(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/I18n' . '/view/statics/js/i18n.js';
    self::assertFileExists($path);
    $content = (string) file_get_contents($path);
    self::assertStringContainsString('async function switchLang(lang, authoritativeHref = \'\')', $content);
    self::assertStringContainsString('window.__WelineI18nNavigating', $content);
    self::assertStringContainsString("configuredHref || buildLanguageUrl", $content);
    self::assertStringContainsString('writeLanguagePreference(lang)', $content);
    self::assertStringContainsString('resolvePreferenceCookieNames', $content);
    self::assertStringContainsString('WELINE_USER_LANG', $content);
    self::assertStringContainsString('_w', $content);
    self::assertStringContainsString('samePath', $content);
    self::assertStringContainsString('window.location.reload()', $content);
    self::assertStringContainsString('window.location.replace(target.href)', $content);
    self::assertStringContainsString('setTimeout(navigate, 0)', $content);
    self::assertStringContainsString('empty document', $content);
    self::assertStringContainsString('__weline_i18n_recover', $content);
    self::assertStringContainsString('installBlankDocumentRecovery', $content);
}

private function setUp_LocalesDictionaryManageLink(): void
{
    if (!\defined('BP')) {
        \define('BP', '/Users/weline/Project/Official/框架' . DIRECTORY_SEPARATOR);
    }
}

private function assert_LocalesDictionaryManageLink_testPanelAndListLinkToDictionaryWithLocaleCode(): void
{
    $panel = $this->read_LocalesDictionaryManageLink('app/code/Weline/I18n/view/templates/backend/Countries/Locales/panel.phtml');
    $list = $this->read_LocalesDictionaryManageLink('app/code/Weline/I18n/view/templates/backend/Countries/Locales/getIndex.phtml');
    $queryProvider = $this->read_LocalesDictionaryManageLink('app/code/Weline/I18n/extends/module/Weline_Framework/Query/I18nAdminQueryProvider.php');
    foreach ([$panel, $list] as $source) {
        self::assertStringContainsString("@backend-url('*/backend/dictionary')?locale_code=", $source);
        self::assertStringContainsString('country_code=', $source);
        self::assertStringNotContainsString("@backend-url('*/backend/countries/locale/words')", $source);
        self::assertStringNotContainsString('countries/locale/words', $source);
    }
    self::assertFileDoesNotExist(BP . 'app/code/Weline/I18n/Controller/Backend/Countries/Locale/Words.php');
    self::assertFileDoesNotExist(BP . 'app/code/Weline/I18n/view/templates/backend/Countries/Locale/Words/index.phtml');
    self::assertStringNotContainsString('word-collect', $queryProvider);
    self::assertStringNotContainsString('Countries\Locale\Words::class', $queryProvider);
}

private function read_LocalesDictionaryManageLink(string $relativePath): string
{
    $path = BP . DIRECTORY_SEPARATOR . ltrim($relativePath, '/\\');
    self::assertFileExists($path);
    return (string) file_get_contents($path);
}
}

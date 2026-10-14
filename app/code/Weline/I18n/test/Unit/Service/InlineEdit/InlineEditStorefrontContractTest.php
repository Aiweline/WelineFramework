<?php

declare(strict_types=1);

namespace Weline\I18n\Test\Unit\Service\InlineEdit;

use PHPUnit\Framework\TestCase;
use Weline\I18n\Extends\Module\Weline_Framework\Fpc\Bypass\I18nInlineEditFpcBypassProvider;
use Weline\I18n\Service\InlineEdit\InlineEditSession;
use Weline\I18n\Service\InlineEdit\InlinePhraseMarker;

final class InlineEditStorefrontContractTest extends TestCase
{
    public function testBypassProviderRequiresPierceAndSessionCookieOrQuery(): void
    {
        $rules = (new I18nInlineEditFpcBypassProvider())->rules();
        self::assertCount(2, $rules);
        foreach ($rules as $rule) {
            self::assertTrue((bool)($rule['match']['require_pierce_stamp'] ?? false));
            self::assertSame('bypass_serve_and_publish', $rule['effect']);
        }
        self::assertSame([InlineEditSession::QUERY_FLAG], $rules[0]['match']['query_keys']);
        self::assertStringContainsString(InlineEditSession::COOKIE_NAME, (string)$rules[1]['match']['cookie_name_regex']);
        self::assertMatchesRegularExpression(
            (string)$rules[1]['match']['cookie_name_regex'],
            InlineEditSession::COOKIE_NAME . '_w0=1'
        );
    }

    public function testAttributeMarkerProtocolIsW18NBase64Url(): void
    {
        $wrapped = InlinePhraseMarker::wrapAttribute('提交', 'Submit');
        self::assertMatchesRegularExpression(
            '/^\[\[W18N:[A-Za-z0-9_-]+:[A-Za-z0-9_-]+\]\]$/',
            $wrapped
        );
        self::assertStringStartsWith(InlinePhraseMarker::ATTR_PREFIX, $wrapped);
        self::assertStringEndsWith(InlinePhraseMarker::ATTR_SUFFIX, $wrapped);
        self::assertSame(
            '提交',
            InlinePhraseMarker::decodeB64Url(\explode(':', \substr($wrapped, 7, -2))[0])
        );
        // Nested/polluted translate must unwrap before re-wrap (no double [[W18N:]]).
        $nestedTr = InlinePhraseMarker::wrapAttribute('购物车', '购物车');
        $flat = InlinePhraseMarker::wrapAttribute('购物车', $nestedTr);
        $parts = \explode(':', \substr($flat, 7, -2));
        self::assertSame('购物车', InlinePhraseMarker::decodeB64Url($parts[1]));
        self::assertSame('购物车', InlinePhraseMarker::unwrapPlain($nestedTr));
    }

    public function testBodyWrapUsesCustomElement(): void
    {
        $html = InlinePhraseMarker::wrapBody('提交', 'Submit');
        self::assertStringContainsString('<w-i18n-phrase', $html);
        self::assertStringContainsString('data-source="提交"', $html);
        self::assertStringContainsString('Submit</w-i18n-phrase>', $html);
    }

    public function testRuntimeEmitUsesHtmlSafeAttributeProtocol(): void
    {
        // emit(false) must not inject <w-i18n-phrase> (breaks title="/aria-label=").
        $marker = (string)\file_get_contents(
            \dirname(__DIR__, 4) . '/Service/InlineEdit/InlinePhraseMarker.php'
        );
        self::assertStringContainsString('Always attribute-protocol', $marker);
        self::assertMatchesRegularExpression(
            '/function body\([\s\S]*?return self::wrapAttribute\(/',
            $marker
        );
        self::assertStringContainsString('isBackendArea', (string)\file_get_contents(
            \dirname(__DIR__, 4) . '/Service/InlineEdit/InlineEditSession.php'
        ));
    }

    public function testOldOnlineCollectorSurfacesRemoved(): void
    {
        $collectorJs = \dirname(__DIR__, 4) . '/view/statics/js/online-translation-collector.js';
        self::assertFileDoesNotExist($collectorJs);
        self::assertFileDoesNotExist(\dirname(__DIR__, 4) . '/view/hooks/footer.phtml');
        $footer = (string)\file_get_contents(
            \dirname(__DIR__, 4) . '/view/hooks/Weline_Theme/frontend/layouts/base/body-end.phtml'
        );
        self::assertStringNotContainsString('online-translation-collector', $footer);
        self::assertStringContainsString('i18n-inline-edit-shell', $footer);
        self::assertStringContainsString('layouts::base::body-end', $footer);
        $dict = (string)\file_get_contents(\dirname(__DIR__, 4) . '/view/templates/backend/Dictionary.phtml');
        self::assertStringNotContainsString('data-mode-switch="online"', $dict);
        self::assertStringContainsString('dictionary-inline-edit-start', $dict);
        $provider = (string)\file_get_contents(
            \dirname(__DIR__, 4) . '/extends/module/Weline_Framework/Query/I18nAdminQueryProvider.php'
        );
        self::assertStringContainsString(
            "'dictionary-inline-edit-start' => 'Weline_I18n::i18n_dictionaries'",
            $provider
        );
        self::assertStringContainsString(
            "'dictionary-module-catalog' => 'Weline_I18n::i18n_dictionaries'",
            $provider
        );
        self::assertStringContainsString(
            "'dictionary-module-catalog-stats' => 'Weline_I18n::i18n_dictionaries'",
            $provider
        );
        $shell = \dirname(__DIR__, 4) . '/view/statics/js/inline-edit-shell.js';
        self::assertFileExists($shell);
        $shellSrc = (string)\file_get_contents($shell);
        self::assertStringContainsString('[[W18N:', $shellSrc);
        self::assertStringContainsString('w-i18n-phrase', $shellSrc);
        self::assertStringContainsString('更新当前页', $shellSrc);
        self::assertStringContainsString('w-i18n-inline-fab', $shellSrc);
        self::assertStringContainsString('w-i18n-inline-fab-panel', $shellSrc);
        self::assertStringContainsString('stampAllAnchors', $shellSrc);
        self::assertStringContainsString('ensureOperatorFab', $shellSrc);
        self::assertStringContainsString('weline_i18n_inline_fab_collapsed', $shellSrc);
        self::assertStringContainsString('defaultFabPos', $shellSrc);
        self::assertStringContainsString("innerHeight * 0.42", $shellSrc);
        self::assertStringContainsString('__welineI18nInlineShellBooted', $shellSrc);
        self::assertStringContainsString('isSkippedDecorateHost', $shellSrc);
        self::assertStringContainsString('isEditablePhraseAttr', $shellSrc);
        self::assertStringContainsString('unwrapResidualW18nText', $shellSrc);
        self::assertStringContainsString('decorateAllMarkers', $shellSrc);
        self::assertStringContainsString('unwrapW18nValue', $shellSrc);
        self::assertStringContainsString('sanitizeGlobalLabelTables', $shellSrc);
        self::assertStringContainsString('decorateScriptMarkers', $shellSrc);
        self::assertStringContainsString('WelineI18nInlineEdit', $shellSrc);
        self::assertStringContainsString('data-i18n-*', $shellSrc);
        self::assertStringContainsString('inline-edit-shell-20261014d.js', $footer);
        self::assertStringNotContainsString("makeBookButton('点译属性 '", $shellSrc);
        self::assertStringContainsString('postUpdatePageSse', $shellSrc);
        self::assertStringContainsString('text/event-stream', $shellSrc);
        self::assertStringContainsString('attachFloatingBook', $shellSrc);
        self::assertStringContainsString('applyPopoverStack', $shellSrc);
        self::assertStringContainsString('resolveClickStackBase', $shellSrc);
        self::assertStringContainsString("ui.stack.apply(pop, document.body", $shellSrc);
        self::assertStringContainsString('isBookHostVisible', $shellSrc);
        self::assertStringContainsString('unwrapW18nDisplay', $shellSrc);
        self::assertStringContainsString('purgeLegacyBookLayers', $shellSrc);
        self::assertStringContainsString('z-index:auto', $shellSrc);
        self::assertStringContainsString("host.appendChild(btn)", $shellSrc);
        self::assertStringContainsString('repositionAllBooks', $shellSrc);
        // Books ride the host stacking context — no invented mega fixed layer.
        self::assertStringNotContainsString('z-index:2147482900', $shellSrc);
        self::assertStringNotContainsString('resolveBookSurface', $shellSrc);
        self::assertStringNotContainsString('ensureSurfaceBookLayer', $shellSrc);
        self::assertStringNotContainsString('ensureBookLayer', $shellSrc);
        self::assertStringNotContainsString('w-i18n-book-host--inline', $shellSrc);
        self::assertStringNotContainsString('margin-left:4px', $shellSrc);
        self::assertStringNotContainsString('#w-i18n-book-layer .w-i18n-book{position:fixed', $shellSrc);
        self::assertStringContainsString("data-role=\"source\"", $shellSrc);
        // Phrase edit dialog: only save + close (exit/purge live on FAB shell).
        self::assertStringContainsString("data-role=\"fab-exit\"", $shellSrc);
        self::assertStringContainsString("data-role=\"fab-purge\"", $shellSrc);
        self::assertDoesNotMatchRegularExpression(
            '/id=[\'"]w-i18n-inline-popover[\'"][\s\S]*?data-role=[\'"]exit[\'"]/',
            $shellSrc
        );
        self::assertDoesNotMatchRegularExpression(
            '/id=[\'"]w-i18n-inline-popover[\'"][\s\S]*?data-role=[\'"]purge[\'"]/',
            $shellSrc
        );
        self::assertStringNotContainsString('bindStampNavigation', $shellSrc);
        self::assertStringContainsString('w_fpc_pierce', $shellSrc);
        $sessionPhp = (string)\file_get_contents(
            \dirname(__DIR__, 4) . '/Service/InlineEdit/InlineEditSession.php'
        );
        self::assertStringContainsString('function reinforceBrowserCookies', $sessionPhp);
        self::assertStringContainsString('reinforceBrowserCookies()', $footer);
        self::assertStringContainsString('isBackendArea', $sessionPhp);
        self::assertStringContainsString('/(admin|backend)(/|$)', $sessionPhp);
        self::assertStringContainsString('area_routes', $sessionPhp);
        self::assertStringContainsString('rest_backend', $sessionPhp);
        // Session-off footer must strip stale markers without injecting books.
        self::assertStringContainsString('Session off', $footer);
        self::assertStringContainsString('[[W18N:', $footer);
    }

    public function testLangRuntimeEmitAndNoConstantFold(): void
    {
        $taglib = (string)\file_get_contents(
            \dirname(__DIR__, 5) . '/Framework/View/Taglib.php'
        );
        self::assertStringContainsString('InlinePhraseMarker::emit', $taglib);
        self::assertStringContainsString('adjustLangInlineAttributeContext', $taglib);
        $fold = (string)\file_get_contents(
            \dirname(__DIR__, 5) . '/Framework/View/Taglib/Compiler/Pass/ConstantFoldingPass.php'
        );
        self::assertStringContainsString('禁止常量折叠为烤死译文', $fold);
    }

    public function testThemeExposesStorefrontPathPurgeWithoutPurgeAll(): void
    {
        $publisher = (string)\file_get_contents(
            \dirname(__DIR__, 5) . '/Theme/Service/ThemeStorefrontSlugFpcPublisher.php'
        );
        self::assertStringContainsString('function purgeByStorefrontPath', $publisher);
        self::assertStringContainsString('path_not_recognized', $publisher);
        self::assertStringNotContainsString('CODE_PURGE_FPC_ALL', $publisher);
        self::assertDoesNotMatchRegularExpression('/\bpurge_fpc_all\s*\(/', $publisher);
        $api = (string)\file_get_contents(
            \dirname(__DIR__, 4) . '/Controller/Frontend/InlineEdit/Api.php'
        );
        self::assertStringContainsString('InlineEditUpdatePageService', $api);
        self::assertStringContainsString('wantsUpdatePageSse', $api);
        self::assertStringContainsString('InlinePhraseMarker::plain', $api);
        $updateSvc = (string)\file_get_contents(
            \dirname(__DIR__, 4) . '/Service/InlineEdit/InlineEditUpdatePageService.php'
        );
        self::assertStringContainsString('dictionary_export', $updateSvc);
        self::assertStringContainsString('publishLocale', $updateSvc);
        self::assertStringContainsString('purgeByStorefrontPath', $updateSvc);
        self::assertStringContainsString('RuntimeCacheBroadcaster', $updateSvc);
        self::assertStringContainsString('FullPageCacheCoordinator', $updateSvc);
        self::assertStringContainsString('purgeCoordinatorUrls', $updateSvc);
        $marker = (string)\file_get_contents(
            \dirname(__DIR__, 4) . '/Service/InlineEdit/InlinePhraseMarker.php'
        );
        self::assertStringContainsString('function plain', $marker);
        self::assertStringContainsString('withoutInlinePhraseMark', $marker);
    }

    public function testFrontendCollectAndLocalFailClosed(): void
    {
        $collect = (string)\file_get_contents(
            \dirname(__DIR__, 4) . '/Controller/Frontend/Word/Collect.php'
        );
        self::assertStringContainsString('在线页收集已移除', $collect);
        $local = (string)\file_get_contents(
            \dirname(__DIR__, 4) . '/Controller/Frontend/Taglib/Local.php'
        );
        self::assertStringContainsString('店面前台本地编辑入口已关闭', $local);
        self::assertStringNotContainsString('translate_mode', $local);
    }
}

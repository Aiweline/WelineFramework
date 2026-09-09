<?php

declare(strict_types=1);

namespace Weline\Frontend\Test\Unit\View;

use PHPUnit\Framework\TestCase;

final class WelineAttributeModuleLoadContractTest extends TestCase
{
    public function testModulesLoadDeferByDefaultWithoutBusinessModuleNames(): void
    {
        $js = (string) \file_get_contents(
            \dirname(__DIR__, 3) . '/view/statics/js/weline.js'
        );

        self::assertStringContainsString('maxConcurrentScripts = 4', $js);
        self::assertStringContainsString('modulesLoad:', $js);
        self::assertStringContainsString('deferByDefault: true', $js);
        self::assertStringContainsString('eagerModules: []', $js);
        self::assertStringContainsString('deferredModules: []', $js);
        self::assertStringContainsString('loadDeclaredDeferred: true', $js);
        self::assertStringContainsString('shouldDeferAttributeModule', $js);
        self::assertStringContainsString("modLoad === 'defer'", $js);
        self::assertStringContainsString('requestIdleCallback', $js);
        self::assertStringContainsString('空闲延迟', $js);
        self::assertStringContainsString('NEVER put business module names', $js);
        self::assertStringNotContainsString('weline.cart.pending_coupon', $js);
        self::assertStringNotContainsString("preLoad('cart')", $js);
        self::assertStringContainsString('maintenanceAsyncWait', $js);
        self::assertStringContainsString('installMaintenanceHandlerLazyBridge', $js);
        self::assertStringNotContainsString('weline-maintenance-wait-modal', $js);
        self::assertStringNotContainsString('resolveMaintenanceMeta', $js);
        self::assertStringNotContainsString('card.innerHTML', $js);
        self::assertStringNotContainsString('维护补偿礼金', $js);
        self::assertStringNotContainsString('抱歉，网站正在升级维护', $js);
        self::assertStringNotContainsString('giftPanelHtml', $js);
        self::assertStringContainsString('installDevMutationObserverGuard', $js);
        self::assertStringContainsString('weline:dev:mutation-loop', $js);
        self::assertStringContainsString('MutationObserver 反馈环', $js);
        self::assertStringContainsString('delivery_storm', $js);
        self::assertStringContainsString('startMarkerDiscovery', $js);
        self::assertStringContainsString('scanScheduled', $js);
        self::assertStringNotContainsString('cartFlagStorageKey', $js);
    }

    public function testSecondaryModulesBootOnDomContentLoadedNotWindowLoad(): void
    {
        $js = (string) \file_get_contents(
            \dirname(__DIR__, 3) . '/view/statics/js/weline.js'
        );

        self::assertStringContainsString('whenDomReady', $js);
        self::assertStringContainsString("document.readyState === 'loading'", $js);
        self::assertStringContainsString("document.addEventListener('DOMContentLoaded'", $js);
        self::assertStringContainsString('whenDomReady(run)', $js);
        self::assertStringContainsString('whenDomReady(boot)', $js);
        self::assertStringContainsString('whenDomReady(preload)', $js);
        self::assertStringNotContainsString('ensureHtmlDocumentLoaded', $js);
        self::assertStringNotContainsString('await this.ensureHtmlDocumentLoaded()', $js);
        self::assertStringNotContainsString("window.addEventListener('load'", $js);
        self::assertStringNotContainsString("document.readyState === 'complete'", $js);
    }

    public function testGlobalVarFallbackIsFrameworkCoreOnly(): void
    {
        $js = (string) \file_get_contents(
            \dirname(__DIR__, 3) . '/view/statics/js/weline.js'
        );

        self::assertStringContainsString("'api': 'WelineApiModule'", $js);
        self::assertStringContainsString("'dom': 'WelineDomModule'", $js);
        self::assertStringNotContainsString("'account': 'WelineAccountModule'", $js);
        self::assertStringNotContainsString('Account:', $js);
        self::assertStringNotContainsString('Locale:', $js);
        self::assertStringNotContainsString('autoPreLoadModules', $js);
        self::assertStringNotContainsString('pathHeuristicModules', $js);
        self::assertStringNotContainsString('markPathHeuristic', $js);
        self::assertStringNotContainsString('isPathHeuristic', $js);
        self::assertStringNotContainsString('路径启发式', $js);
        self::assertStringNotContainsString('路径启发式预加载', $js);
        self::assertStringContainsString('已跳过（已加载/加载中）', $js);
        self::assertStringNotContainsString('persistLangPreference', $js);
        self::assertStringNotContainsString('i18n: {', $js);
        self::assertStringNotContainsString('switchLang: async (lang)', $js);
        self::assertStringContainsString('WelineI18n', $js);
        self::assertStringContainsString('Transport/microkernel aliases only', $js);
    }

    public function testModuleStaticPathKeepsQueryOutsideFilePath(): void
    {
        $js = (string) \file_get_contents(
            \dirname(__DIR__, 3) . '/view/statics/js/weline.js'
        );

        self::assertStringContainsString('Keep ?query out of the filesystem path', $js);
        self::assertStringContainsString('querySuffix', $js);
    }

    public function testWorkerScriptFetchHasTimeout(): void
    {
        $js = (string) \file_get_contents(
            \dirname(__DIR__, 3) . '/view/statics/js/weline-api.js'
        );

        self::assertStringContainsString('timeoutMs', $js);
        self::assertStringContainsString('AbortController', $js);
        self::assertStringContainsString('createDedicatedWorkerFromScriptUrl', $js);
    }
}

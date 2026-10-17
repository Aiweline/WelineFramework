<?php

declare(strict_types=1);

namespace Weline\Visitor\Test\Unit\View;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Test\TestCore;
use Weline\Visitor\Service\EventDictionaryService;

/**
 * Aggregated contract suite (merged from 17 *ContractTest files).
 * Observe one overall pass/fail via testAllVisitorViewContractsPass().
 */
final class VisitorViewContractsTest extends TestCase
{
    public function testAllVisitorViewContractsPass(): void
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
            'ConfigRevisionDisplay::test_admin_tracking_vendor_shows_config_revision' => function () {
            $this->assert_ConfigRevisionDisplay_test_admin_tracking_vendor_shows_config_revision();
        },
            'ConfigRevisionDisplay::test_monitor_and_assistant_show_config_revision' => function () {
            $this->assert_ConfigRevisionDisplay_test_monitor_and_assistant_show_config_revision();
        },
            'EventSandboxMonitor::testSandboxBusAndMonitorContract' => function () {
            $this->assert_EventSandboxMonitor_testSandboxBusAndMonitorContract();
        },
            'PixelDomDeclaredBridge::testDictionaryResolveAutoBridgesDomAndCustomEvents' => function () {
            $this->assert_PixelDomDeclaredBridge_testDictionaryResolveAutoBridgesDomAndCustomEvents();
        },
            'PixelErrorMonitorModal::testIndexUsesDialogInsteadOfDetailPageLink' => function () {
            $this->assert_PixelErrorMonitorModal_testIndexUsesDialogInsteadOfDetailPageLink();
        },
            'PixelErrorMonitorModal::testControllerServesJsonDetailAndRedirectsHtml' => function () {
            $this->assert_PixelErrorMonitorModal_testControllerServesJsonDetailAndRedirectsHtml();
        },
            'PixelErrorMonitorSeverityBadge::testIndexShowsSeverityBadgeColumn' => function () {
            $this->assert_PixelErrorMonitorSeverityBadge_testIndexShowsSeverityBadgeColumn();
        },
            'PixelErrorMonitorSeverityBadge::testDetailPageRemovedInFavorOfModal' => function () {
            $this->assert_PixelErrorMonitorSeverityBadge_testDetailPageRemovedInFavorOfModal();
        },
            'PixelGa4DebugMode::testGa4DebugModeHardeningPresentInPixelRuntime' => function () {
            $this->assert_PixelGa4DebugMode_testGa4DebugModeHardeningPresentInPixelRuntime();
        },
            'PixelJsdocTemplateSafety::testPixelPhtmlForbidsTypescriptObjectJsdocThatBreaksTaglib' => function () {
            $this->assert_PixelJsdocTemplateSafety_testPixelPhtmlForbidsTypescriptObjectJsdocThatBreaksTaglib();
        },
            'PixelListSelectCommerce::testPixelJsEmitsListSelectAndCtaLinkParams' => function () {
            $this->assert_PixelListSelectCommerce_testPixelJsEmitsListSelectAndCtaLinkParams();
        },
            'PixelPageExitSemantics::testVisibilityHiddenTracksPageHideNotPageExit' => function () {
            $this->assert_PixelPageExitSemantics_testVisibilityHiddenTracksPageHideNotPageExit();
        },
            'PixelPageExitSemantics::testDictionaryDefinesPageHideAndPageExit' => function () {
            $this->assert_PixelPageExitSemantics_testDictionaryDefinesPageHideAndPageExit();
        },
            'PixelPageExitSemantics::testBootstrapVersionBumped' => function () {
            $this->assert_PixelPageExitSemantics_testBootstrapVersionBumped();
        },
            'PixelPdpRouteCommerce::testPixelRuntimeAutoTracksProductPathViewItem' => function () {
            $this->assert_PixelPdpRouteCommerce_testPixelRuntimeAutoTracksProductPathViewItem();
        },
            'PixelPdpRouteCommerce::testBootstrapQueuesDeclaredCtaBeforePixelReady' => function () {
            $this->assert_PixelPdpRouteCommerce_testBootstrapQueuesDeclaredCtaBeforePixelReady();
        },
            'PixelPdpRouteCommerce::testPdpTemplateHasExactViewItemMarker' => function () {
            $this->assert_PixelPdpRouteCommerce_testPdpTemplateHasExactViewItemMarker();
        },
            'PixelPdpRouteCommerce::testStorefrontPixelBootstrapInjectsProductLayout' => function () {
            $this->assert_PixelPdpRouteCommerce_testStorefrontPixelBootstrapInjectsProductLayout();
        },
            'PixelProdConsoleGate::testPixelTrackLogsGateOnDeployModeNotAcceptanceHost' => function () {
            $this->assert_PixelProdConsoleGate_testPixelTrackLogsGateOnDeployModeNotAcceptanceHost();
        },
            'PixelProdConsoleGate::testBootstrapDevLogGatesOnDeployMode' => function () {
            $this->assert_PixelProdConsoleGate_testBootstrapDevLogGatesOnDeployMode();
        },
            'PixelRequiredParamGate::testPixelJsDropsIncompleteEcommerceAndSearch' => function () {
            $this->assert_PixelRequiredParamGate_testPixelJsDropsIncompleteEcommerceAndSearch();
        },
            'PixelRequiredParamGate::testPersistenceWritesPageViewAdditionalWhenUrlPresent' => function () {
            $this->assert_PixelRequiredParamGate_testPersistenceWritesPageViewAdditionalWhenUrlPresent();
        },
            'PixelRequiredParamGate::testServerSkipsEmptyCartAndRecoveryCheckout' => function () {
            $this->assert_PixelRequiredParamGate_testServerSkipsEmptyCartAndRecoveryCheckout();
        },
            'PixelSandboxInitResilience::testPixelJsDefersLoadedFlagAndGuardsTelemetry' => function () {
            $this->assert_PixelSandboxInitResilience_testPixelJsDefersLoadedFlagAndGuardsTelemetry();
        },
            'PixelSiteErrorJs::testPixelJsExposesReportIncidentAndSiteErrorMonitors' => function () {
            $this->assert_PixelSiteErrorJs_testPixelJsExposesReportIncidentAndSiteErrorMonitors();
        },
            'PixelSiteErrorJs::testTaglibPixelSynced' => function () {
            $this->assert_PixelSiteErrorJs_testTaglibPixelSynced();
        },
            'PixelSiteErrorJs::testFrontendWelineApiAlwaysNotifiesApiFailure' => function () {
            $this->assert_PixelSiteErrorJs_testFrontendWelineApiAlwaysNotifiesApiFailure();
        },
            'PixelSiteErrorJs::testEventDictionaryHasSiteErrorSkipGtm' => function () {
            $this->assert_PixelSiteErrorJs_testEventDictionaryHasSiteErrorSkipGtm();
        },
            'PixelStickyUtmJs::testBothFilesContainStickyApiAndVersion' => function () {
            $this->assert_PixelStickyUtmJs_testBothFilesContainStickyApiAndVersion();
        },
            'PixelStickyUtmJs::testA08GatesStickyByMarketingConsent' => function () {
            $this->assert_PixelStickyUtmJs_testA08GatesStickyByMarketingConsent();
        },
            'PixelStickyUtmJs::testA09RewritesInternalAnchorsWithObserver' => function () {
            $this->assert_PixelStickyUtmJs_testA09RewritesInternalAnchorsWithObserver();
        },
            'PixelStickyUtmJs::testA10RerunsLinkerOnSpaTransitions' => function () {
            $this->assert_PixelStickyUtmJs_testA10RerunsLinkerOnSpaTransitions();
        },
            'PixelStickyUtmJs::testA11GetFormMergeDefaultOff' => function () {
            $this->assert_PixelStickyUtmJs_testA11GetFormMergeDefaultOff();
        },
            'PixelStickyUtmJs::testA12Ga4GtmForwardIncludeStickyParams' => function () {
            $this->assert_PixelStickyUtmJs_testA12Ga4GtmForwardIncludeStickyParams();
        },
            'PixelStickyUtmJs::testStickyHelpersAlignedBetweenFiles' => function () {
            $this->assert_PixelStickyUtmJs_testStickyHelpersAlignedBetweenFiles();
        },
            'PixelStickyUtmJs::testPixelBootstrapUsesTheCanonicalStaticAssetVersion' => function () {
            $this->assert_PixelStickyUtmJs_testPixelBootstrapUsesTheCanonicalStaticAssetVersion();
        },
            'PixelStickyUtmJs::testLocalDevelopmentDefersThePixelBundleUntilIdle' => function () {
            $this->assert_PixelStickyUtmJs_testLocalDevelopmentDefersThePixelBundleUntilIdle();
        },
            'PixelStorefrontPrice::testProductMetaReadsOfferPriceMinorAndCurrency' => function () {
            $this->assert_PixelStorefrontPrice_testProductMetaReadsOfferPriceMinorAndCurrency();
        },
            'TrackingVendorAdminUi::testFormalPageHasToolbarWithoutDuplicatePageH1' => function () {
            $this->assert_TrackingVendorAdminUi_testFormalPageHasToolbarWithoutDuplicatePageH1();
        },
            'TrackingVendorAdminUi::testModeHintLivesInsideConfigPanelNotAboveTabs' => function () {
            $this->assert_TrackingVendorAdminUi_testModeHintLivesInsideConfigPanelNotAboveTabs();
        },
            'TrackingVendorAdminUi::testEnableControlIsSingleLabelCheckbox' => function () {
            $this->assert_TrackingVendorAdminUi_testEnableControlIsSingleLabelCheckbox();
        },
            'TrackingVendorAdminUi::testWorkScopeToolbarUsesOfficialScopeTaglib' => function () {
            $this->assert_TrackingVendorAdminUi_testWorkScopeToolbarUsesOfficialScopeTaglib();
        },
            'TrackingVendorAdminUi::testGa4AndGtmVendorsEmbedUnifiedSystemConfig' => function () {
            $this->assert_TrackingVendorAdminUi_testGa4AndGtmVendorsEmbedUnifiedSystemConfig();
        },
        ];
    }

private function assert_ConfigRevisionDisplay_test_admin_tracking_vendor_shows_config_revision(): void
{
    $php = (string) \file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Visitor' . '/Controller/Backend/TrackingVendor.php');
    $tpl = (string) \file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Visitor' . '/view/templates/backend/TrackingVendor/index.phtml');
    self::assertStringContainsString("assign('config_revision'", $php);
    self::assertStringContainsString('配置版本 %{2}', $php);
    self::assertStringContainsString('tv-config-revision', $tpl);
    self::assertStringContainsString('setConfigRevisionDisplay', $tpl);
    self::assertStringContainsString('formatScopeVersionLabel', $tpl);
    self::assertStringContainsString('站点 ', $tpl);
    self::assertStringContainsString('店铺 ', $tpl);
    self::assertStringContainsString('渠道 ', $tpl);
}

private function assert_ConfigRevisionDisplay_test_monitor_and_assistant_show_config_revision(): void
{
    $mon = (string) \file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Visitor' . '/view/statics/js/event-sandbox-monitor.js');
    $alias = (string) \file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Visitor' . '/view/statics/js/lifecycle-event-assistant.js');
    $cfg = (string) \file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Visitor' . '/Service/VisitorTrackingConfig.php');
    self::assertStringContainsString('wesm-config-revision', $mon);
    self::assertStringContainsString('formatScopeVersionLabel', $mon);
    self::assertStringContainsString('站点 ', $mon);
    self::assertStringContainsString('店铺 ', $mon);
    self::assertStringContainsString('渠道 ', $mon);
    self::assertStringContainsString('getConfigRevision', $mon);
    self::assertStringContainsString('configScope', $cfg);
    self::assertStringContainsString('buildConfigScopeContext', $cfg);
    self::assertStringContainsString('20260922-ga4-debug1', $mon);
    self::assertStringContainsString('weline:pixel-sandbox:event', $mon);
    self::assertStringContainsString('_earlyBuffer', $mon);
    self::assertStringContainsString('getConfigRevision', $alias);
    self::assertStringContainsString('20260922-ga4-debug1', $alias);
}

private function assert_EventSandboxMonitor_testSandboxBusAndMonitorContract(): void
{
    $root = '/Users/weline/Project/Official/框架/app/code/Weline/Visitor';
    $pixel = (string) \file_get_contents($root . '/view/statics/js/pixel.js');
    $phtml = (string) \file_get_contents($root . '/view/taglib/js/pixel.phtml');
    $monitor = (string) \file_get_contents($root . '/view/statics/js/event-sandbox-monitor.js');
    $panel = (string) \file_get_contents($root . '/view/statics/js/weline-panel-visitor.js');
    $bootstrap = (string) \file_get_contents($root . '/Service/VisitorPanelBootstrapHtmlService.php');
    $bodyEnd = (string) \file_get_contents($root . '/view/hooks/Weline_Theme/frontend/layouts/base/body-end.phtml');
    self::assertStringContainsString('WelineEventSandbox', $pixel);
    self::assertStringContainsString('normalizeEnvelope', $pixel);
    self::assertStringContainsString('subscribe', $pixel);
    self::assertStringContainsString('ensureLifecycleBridge', $pixel);
    self::assertStringContainsString('__publishSandboxClickPassthrough', $pixel);
    self::assertStringContainsString('__sandboxIsCustomEventHit', $pixel);
    self::assertStringContainsString('sandbox_passthrough', $pixel);
    self::assertStringContainsString('this.emit(detail', $pixel);
    self::assertStringContainsString('WelineEventSandbox', $phtml);
    self::assertStringContainsString('normalizeEnvelope', $phtml);
    self::assertStringContainsString('subscribe', $phtml);
    self::assertStringContainsString('__publishSandboxClickPassthrough', $phtml);
    self::assertStringContainsString('__sandboxIsCustomEventHit', $phtml);
    self::assertStringContainsString('WelineEventSandboxMonitor', $monitor);
    self::assertStringContainsString('data-tone="hit-system"', $monitor);
    self::assertStringContainsString('data-tone="hit-custom"', $monitor);
    self::assertStringContainsString('data-tone="hit-dedupe"', $monitor);
    self::assertStringContainsString('data-tone="anomaly"', $monitor);
    self::assertStringContainsString("return 'hit-system'", $monitor);
    self::assertStringContainsString("return 'hit-custom'", $monitor);
    self::assertStringContainsString("return 'hit-dedupe'", $monitor);
    self::assertStringContainsString("return 'anomaly'", $monitor);
    self::assertStringContainsString('--weline-color-success', $monitor);
    self::assertStringContainsString('--weline-color-info', $monitor);
    self::assertStringContainsString('--weline-color-warning', $monitor);
    self::assertStringContainsString('--weline-color-primary', $monitor);
    self::assertStringContainsString('绿=系统命中，蓝=自定义命中，黄=去重丢弃', $monitor);
    self::assertStringContainsString('去重丢弃', $monitor);
    self::assertStringContainsString('data-wesm-tab="dedupe"', $monitor);
    self::assertStringContainsString('全量数据流（含参数）', $monitor);
    self::assertStringContainsString('系统命中', $monitor);
    self::assertStringContainsString('自定义命中', $monitor);
    self::assertStringContainsString('data-wesm-tab="stream"', $monitor);
    self::assertStringContainsString('wesm-row__bridge', $monitor);
    self::assertStringContainsString('wesm-empty--warn', $monitor);
    self::assertStringContainsString('patchBridgeStatus', $monitor);
    self::assertStringContainsString('data-wesm-tab="system"', $monitor);
    self::assertStringContainsString('data-wesm-tab="custom"', $monitor);
    self::assertStringContainsString('data-wesm-tab="previous"', $monitor);
    self::assertStringContainsString('data-wesm-tab="chain"', $monitor);
    self::assertStringContainsString('累积链', $monitor);
    self::assertStringContainsString('上一页', $monitor);
    self::assertStringContainsString('PAGE_INSTANCE_ID', $monitor);
    self::assertStringContainsString('getPageInstanceId', $monitor);
    self::assertStringContainsString('displayPagePath', $monitor);
    self::assertStringContainsString("STYLE_VERSION = '20260922-ga4-debug1'", $monitor);
    self::assertStringContainsString('data-wesm-action="clear"', $monitor);
    self::assertStringContainsString('data-testid="wesm-clear"', $monitor);
    self::assertStringContainsString('clearSessionKeepOpen', $monitor);
    self::assertStringContainsString('点「清空」清会话记录并继续监听', $monitor);
    self::assertStringContainsString('ga4DebugBannerHtml', $monitor);
    self::assertStringContainsString('bridgeLineHtml', $monitor);
    self::assertStringContainsString('wesm-ga4-debug-banner', $monitor);
    self::assertStringContainsString('wesm-bridge-debug', $monitor);
    self::assertStringContainsString('GA4 Debug 模式已开启', $monitor);
    self::assertStringContainsString('不是 DebugView 送达证明', $monitor);
    self::assertStringContainsString('readGa4DebugRuntime', $monitor);
    self::assertStringContainsString('bridge_debug', $monitor);
    self::assertStringContainsString('CHAIN_KEY', $monitor);
    self::assertStringContainsString('chainRows', $monitor);
    self::assertStringContainsString('hitAggregate', $monitor);
    self::assertStringContainsString('data-wesm-copy', $monitor);
    self::assertStringContainsString('wesm-kv__key', $monitor);
    self::assertStringContainsString('wesm-kv__val', $monitor);
    self::assertStringContainsString('function copyText', $monitor);
    self::assertStringContainsString('wesm-copy-tip', $monitor);
    self::assertStringContainsString('已复制', $monitor);
    self::assertStringContainsString('wesm-row__bar', $monitor);
    self::assertStringContainsString('点事件栏展开', $monitor);
    self::assertStringContainsString('再点事件栏收起', $monitor);
    self::assertStringContainsString("closest('.wesm-row__params')", $monitor);
    self::assertStringContainsString('weline_event_sandbox_monitor_v1', $monitor);
    self::assertStringContainsString('WelineLifecycleAssistant', $monitor);
    self::assertStringContainsString('relayToAdminSession', $pixel);
    self::assertStringContainsString("sandbox_stream', '1'", $pixel);
    self::assertStringContainsString('/visitor/analytics/event-picker/observe', $pixel);
    // 生成路由表若缺 observe，事件监视器继电会整页刷 POST 404。
    $frontendPc = \dirname($root, 4) . '/generated/routers/frontend_pc.php';
    if (\is_file($frontendPc)) {
        $routers = require $frontendPc;
        self::assertIsArray($routers);
        self::assertArrayHasKey('visitor/analytics/event-picker/observe::POST', $routers);
    }
    self::assertStringContainsString('__isSandboxMonitorActiveForAutoRegister', $pixel);
    self::assertStringContainsString('__welineSandboxStreamRelayFp', $pixel);
    self::assertStringNotContainsString("websiteId === '0'", $pixel);
    self::assertStringContainsString('relayToAdminSession', $phtml);
    self::assertStringContainsString("sandbox_stream', '1'", $phtml);
    self::assertStringContainsString('__isSandboxMonitorActiveForAutoRegister', $phtml);
    self::assertStringContainsString('__welineSandboxStreamRelayFp', $phtml);
    self::assertStringNotContainsString("websiteId === '0'", $phtml);
    $pickerCtl = (string) \file_get_contents($root . '/Controller/Analytics/EventPicker.php');
    self::assertStringContainsString('function postStream', $pickerCtl);
    self::assertStringContainsString('sandbox_stream', $pickerCtl);
    self::assertStringContainsString('pushAccumulate', $pickerCtl);
    self::assertStringContainsString('if ($websiteId < 0)', $pickerCtl);
    $tvCtl = (string) \file_get_contents($root . '/Controller/Backend/TrackingVendor.php');
    self::assertStringContainsString('function getLiveEvents', $tvCtl);
    self::assertStringContainsString('function postClearSessionSandbox', $tvCtl);
    self::assertStringContainsString('listAccumulateSince', $tvCtl);
    self::assertStringContainsString('sandbox_only', $tvCtl);
    self::assertStringContainsString('clearAccumulate', $tvCtl);
    self::assertStringContainsString('cleared_website_ids', $tvCtl);
    $pickerSvc = (string) \file_get_contents($root . '/Service/EventPickerTokenService.php');
    self::assertStringContainsString('SharedBufferStateInterface', $pickerSvc);
    self::assertStringContainsString('visitor.pixel.picker_acc', $pickerSvc);
    self::assertStringContainsString('withBufferMutation', $pickerSvc);
    self::assertStringContainsString('bufferGeneration', $pickerSvc);
    self::assertStringNotContainsString('withBufferLock', $pickerSvc);
    self::assertStringContainsString("'generation' =>", $tvCtl);
    self::assertStringContainsString("'changed' =>", $tvCtl);
    // 沙盒会话流：店面已继电入缓冲，后台只做 since 即时读；禁止服务端长轮询堵 WLS worker。
    if (\preg_match('/function getLiveEvents\(\): string\s*\{(.*?)\n    public function /s', $tvCtl, $m)) {
        $liveBody = (string) ($m[1] ?? '');
    } else {
        $liveBody = $tvCtl;
    }
    self::assertStringNotContainsString('\usleep(', $liveBody);
    self::assertStringNotContainsString('usleep(', $liveBody);
    self::assertStringNotContainsString('while ($waitMs', $liveBody);
    self::assertStringContainsString("'mode' => 'short_poll'", $liveBody);
    self::assertStringContainsString('event-sandbox-monitor.js', $panel);
    self::assertStringContainsString('开启事件监视', $panel);
    self::assertStringContainsString('WelineEventSandbox', $panel);
    self::assertStringContainsString('event-sandbox-monitor.js', $bootstrap);
    self::assertStringContainsString("PANEL_SCRIPT_VERSION = '20260922-ga4-debug1'", $bootstrap);
    self::assertStringContainsString("PANEL_SCRIPT_VERSION . '-wesm'", $bootstrap);
    self::assertStringContainsString('weline_event_sandbox_monitor_v1', $bootstrap);
    self::assertStringContainsString('event-sandbox-monitor.js', $bodyEnd);
    self::assertStringContainsString('weline_event_sandbox_monitor_v1', $bodyEnd);
}

private function assert_PixelDomDeclaredBridge_testDictionaryResolveAutoBridgesDomAndCustomEvents(): void
{
    $root = '/Users/weline/Project/Official/框架/app/code/Weline/Visitor';
    $pixel = (string) \file_get_contents($root . '/view/statics/js/pixel.js');
    $phtml = (string) \file_get_contents($root . '/view/taglib/js/pixel.phtml');
    $bootstrap = (string) \file_get_contents($root . '/Service/PixelBootstrapHtmlService.php');
    foreach ([$pixel, $phtml] as $src) {
        self::assertStringContainsString("mapping_source: customMatch", $src);
        self::assertStringContainsString("'track_name'", $src);
        self::assertStringContainsString('把监视来源扁平进 payload', $src);
        self::assertStringContainsString('__findPixelEventNameFromElement(element)', $src);
        self::assertStringContainsString("payload.source === 'behavior_monitor'", $src);
        self::assertStringContainsString('already_tracked', $src);
        self::assertStringContainsString('自定义条件命中：与元素声明并行', $src);
        self::assertStringNotContainsString('先按自定义事件 match_conditions 命中（创建后 runtime 热更即可立刻识别）', $src);
        // CTA heuristic must not swallow declared business names
        self::assertStringContainsString("if (!normalized && __isGa4CtaElement(element))", $src);
    }
    self::assertStringContainsString("PIXEL_SCRIPT_VERSION = '2026.10.09-prod-console1'", $pixel);
    self::assertStringContainsString("PIXEL_SCRIPT_VERSION = '20261009-prod-console1'", $bootstrap);
}

private function assert_PixelErrorMonitorModal_testIndexUsesDialogInsteadOfDetailPageLink(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/Visitor' . '/view/templates/backend/PixelErrorMonitor/index.phtml';
    self::assertFileExists($path);
    $src = (string) file_get_contents($path);
    self::assertStringContainsString('pixel-error-detail-dialog', $src);
    self::assertStringContainsString('data-w-component="dialog"', $src);
    self::assertStringContainsString('data-pixel-error-open', $src);
    self::assertStringContainsString('format=json', $src);
    self::assertStringContainsString('requestJson', $src);
    self::assertStringContainsString('weline-pixel-error-glance', $src);
    self::assertStringContainsString('error_type_label', $src);
    self::assertStringContainsString('<details', $src);
    self::assertStringContainsString('glanceCell', $src);
    self::assertStringNotContainsString('adminRequest', $src);
    self::assertStringNotContainsString('pixel-error-monitor/detail?id=', $src);
    self::assertFileDoesNotExist('/Users/weline/Project/Official/框架/app/code/Weline/Visitor' . '/view/templates/backend/PixelErrorMonitor/detail.phtml');
}

private function assert_PixelErrorMonitorModal_testControllerServesJsonDetailAndRedirectsHtml(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/Visitor' . '/Controller/Backend/PixelErrorMonitor.php';
    self::assertFileExists($path);
    $src = (string) file_get_contents($path);
    self::assertStringContainsString('wantsJson()', $src);
    self::assertStringContainsString("fetchJson([", $src);
    self::assertStringContainsString("'open' => \$id", $src);
    self::assertStringContainsString('buildIncidentPayload', $src);
    self::assertStringContainsString('error_type_label', $src);
    self::assertStringContainsString('disposition_label', $src);
    self::assertStringContainsString('typeLabel', $src);
    self::assertStringContainsString('dispositionLabel', $src);
}

private function assert_PixelErrorMonitorSeverityBadge_testIndexShowsSeverityBadgeColumn(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/Visitor' . '/view/templates/backend/PixelErrorMonitor/index.phtml';
    self::assertFileExists($path);
    $src = (string) file_get_contents($path);
    self::assertStringContainsString('重要程度', $src);
    self::assertStringContainsString('w-badge', $src);
    self::assertStringContainsString('severityBadgeTone', $src);
    self::assertStringContainsString('box-shadow: inset 3px 0 0', $src);
    self::assertStringContainsString('pixel-error-detail-dialog', $src);
}

private function assert_PixelErrorMonitorSeverityBadge_testDetailPageRemovedInFavorOfModal(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/Visitor' . '/view/templates/backend/PixelErrorMonitor/detail.phtml';
    self::assertFileDoesNotExist($path);
}

private function assert_PixelGa4DebugMode_testGa4DebugModeHardeningPresentInPixelRuntime(): void
{
    $root = '/Users/weline/Project/Official/框架/app/code/Weline/Visitor';
    $pixel = (string) \file_get_contents($root . '/view/statics/js/pixel.js');
    $phtml = (string) \file_get_contents($root . '/view/taglib/js/pixel.phtml');
    $bootstrap = (string) \file_get_contents($root . '/Service/PixelBootstrapHtmlService.php');
    $tracking = (string) \file_get_contents($root . '/extends/module/Weline_SystemConfig/Config/backend/tracking.phtml');
    foreach ([$pixel, $phtml] as $src) {
        self::assertStringContainsString('var ga4ConfigParams = { send_page_view: true };', $src);
        self::assertStringContainsString('ga4ConfigParams.debug_mode = true;', $src);
        self::assertStringContainsString("send_page_view: false,\n                                debug_mode: true", $src);
        self::assertStringContainsString('params.debug_mode = true;', $src);
        self::assertStringNotContainsString('params.debug_mode = 1;', $src);
        self::assertStringContainsString("params.transport_type = 'beacon';", $src);
        self::assertStringContainsString('params.send_to = runtime.measurementId;', $src);
        self::assertStringContainsString('保留 __keepalive 到 Forwarders', $src);
        self::assertStringNotContainsString('debug_mode: runtime.debugMode', $src);
        self::assertStringContainsString('__buildBridgeDebugMeta', $src);
        self::assertStringContainsString('bridge_debug', $src);
    }
    self::assertStringContainsString("PIXEL_SCRIPT_VERSION = '2026.10.09-prod-console1'", $pixel);
    self::assertStringContainsString("PIXEL_SCRIPT_VERSION = '20261009-prod-console1'", $bootstrap);
    self::assertStringContainsString('内部流量', $tracking);
    self::assertStringContainsString('DebugView', $tracking);
}

private function assert_PixelJsdocTemplateSafety_testPixelPhtmlForbidsTypescriptObjectJsdocThatBreaksTaglib(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/Visitor' . '/view/taglib/js/pixel.phtml';
    self::assertFileExists($path);
    $src = (string) file_get_contents($path);
    self::assertStringNotContainsString('@returns {{', $src);
    self::assertStringNotContainsString('drop:boolean', $src);
    self::assertStringContainsString('function __evaluateRequiredParamGate(', $src);
}

private function assert_PixelListSelectCommerce_testPixelJsEmitsListSelectAndCtaLinkParams(): void
{
    $root = '/Users/weline/Project/Official/框架/app/code/Weline/Visitor';
    $pixel = (string) \file_get_contents($root . '/view/statics/js/pixel.js');
    $phtml = (string) \file_get_contents($root . '/view/taglib/js/pixel.phtml');
    foreach ([$pixel, $phtml] as $src) {
        self::assertStringContainsString("PIXEL_SCRIPT_VERSION = '2026.10.09-prod-console1'", $src);
        self::assertStringContainsString('function __getProductListMeta', $src);
        self::assertStringContainsString('function __getCtaLinkMeta', $src);
        self::assertStringContainsString('function __getRemoveFromCartMeta', $src);
        self::assertStringContainsString("return 'view_item_list'", $src);
        self::assertStringContainsString("track('select_item'", $src);
        self::assertStringContainsString("track('view_item_list'", $src);
        self::assertStringContainsString("return ['link_url', 'link_text']", $src);
    }
    $bootstrap = (string) \file_get_contents($root . '/Service/PixelBootstrapHtmlService.php');
    self::assertStringContainsString("PIXEL_SCRIPT_VERSION = '20261009-prod-console1'", $bootstrap);
}

/** @return list<string> */
private function sources_PixelPageExitSemantics(): array
{
    $root = '/Users/weline/Project/Official/框架/app/code/Weline/Visitor';
    return [(string) \file_get_contents($root . '/view/statics/js/pixel.js'), (string) \file_get_contents($root . '/view/taglib/js/pixel.phtml')];
}

private function assert_PixelPageExitSemantics_testVisibilityHiddenTracksPageHideNotPageExit(): void
{
    foreach ($this->sources_PixelPageExitSemantics() as $src) {
        self::assertStringContainsString("track('page_hide'", $src);
        self::assertStringContainsString("trackPageHide('visibility_hidden')", $src);
        self::assertStringNotContainsString("trackPageExit('visibility_hidden')", $src);
        self::assertStringContainsString("trackPageExit('pagehide'", $src);
        self::assertStringContainsString("trackPageHide('pagehide_persisted'", $src);
        self::assertStringContainsString('__pixelPageWasVisible', $src);
        self::assertStringContainsString("addEventListener('pageshow'", $src);
        self::assertStringNotContainsString('__pixelPageShown', $src);
        self::assertStringContainsString('__pixelPageHideCycleSent = false', $src);
        self::assertStringContainsString("PIXEL_SCRIPT_VERSION = '2026.10.09-prod-console1'", $src);
    }
}

private function assert_PixelPageExitSemantics_testDictionaryDefinesPageHideAndPageExit(): void
{
    $dict = new EventDictionaryService();
    $hide = $dict->resolve('page_hide');
    $exit = $dict->resolve('page_exit');
    self::assertNotNull($hide);
    self::assertNotNull($exit);
    self::assertSame('页面隐藏', $hide['label_zh'] ?? null);
    self::assertSame('页面退出', $exit['label_zh'] ?? null);
    self::assertTrue(!empty($hide['skip_gtm_push']));
    self::assertTrue(!empty($exit['skip_gtm_push']));
    self::assertSame('1.3.3', $dict->getVersion());
}

private function assert_PixelPageExitSemantics_testBootstrapVersionBumped(): void
{
    $bootstrap = (string) \file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Visitor' . '/Service/PixelBootstrapHtmlService.php');
    self::assertStringContainsString("PIXEL_SCRIPT_VERSION = '20261009-prod-console1'", $bootstrap);
}

private function assert_PixelPdpRouteCommerce_testPixelRuntimeAutoTracksProductPathViewItem(): void
{
    $root = '/Users/weline/Project/Official/框架/app/code/Weline/Visitor';
    $pixel = (string) \file_get_contents($root . '/view/statics/js/pixel.js');
    $phtml = (string) \file_get_contents($root . '/view/taglib/js/pixel.phtml');
    foreach ([$pixel, $phtml] as $src) {
        self::assertStringContainsString('function __trackRouteScopedCommerceEvents', $src);
        self::assertStringContainsString("path.indexOf('/product/') === 0", $src);
        self::assertStringContainsString("return 'view_item'", $src);
        self::assertStringContainsString('__trackRouteScopedCommerceEvents(', $src);
        self::assertStringContainsString('__WelinePixelRouteCommerceDomWait', $src);
        self::assertStringContainsString("PIXEL_SCRIPT_VERSION = '2026.10.09-prod-console1'", $src);
    }
}

private function assert_PixelPdpRouteCommerce_testBootstrapQueuesDeclaredCtaBeforePixelReady(): void
{
    $root = '/Users/weline/Project/Official/框架/app/code/Weline/Visitor';
    $bootstrap = (string) \file_get_contents($root . '/Service/PixelBootstrapHtmlService.php');
    $bodyEnd = (string) \file_get_contents($root . '/view/hooks/Weline_Theme/frontend/layouts/base/body-end.phtml');
    foreach ([$bootstrap, $bodyEnd] as $src) {
        self::assertStringContainsString('enqueuePendingDeclaredTrack', $src);
        self::assertStringContainsString('__WelinePixelPending', $src);
        self::assertStringContainsString('bootstrap_queued', $src);
        self::assertStringContainsString("loadWelinePixel('cta-click')", $src);
    }
    self::assertStringContainsString("PIXEL_SCRIPT_VERSION = '20261009-prod-console1'", $bootstrap);
}

private function assert_PixelPdpRouteCommerce_testPdpTemplateHasExactViewItemMarker(): void
{
    $tpl = (string) \file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline' . '/Product/view/templates/frontend/widgets/product-info.phtml');
    self::assertStringContainsString('weline-pixel::view_item', $tpl);
    self::assertStringContainsString('data-pixel-event="view_item"', $tpl);
    self::assertStringContainsString('data-testid="pixel-view-item-marker"', $tpl);
}

private function assert_PixelPdpRouteCommerce_testStorefrontPixelBootstrapInjectsProductLayout(): void
{
    $widgetPhp = '/Users/weline/Project/Official/框架/app/code/Weline/Visitor' . '/extends/module/Weline_Widget/Weline_Visitor/widget.php';
    $tpl = 'Weline_Visitor::templates/frontend/widgets/storefront-pixel-bootstrap.phtml';
    self::assertTrue(\Weline\Widget\Test\Support\SlimWidgetPhpListing::listsTemplate($widgetPhp, $tpl));
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Visitor' . '/view/templates/frontend/widgets/storefront-pixel-bootstrap.phtml');
    self::assertStringContainsString('@widget.code {storefront-pixel-bootstrap}', $src);
    self::assertStringContainsString('"layout_type":"homepage"', $src);
    self::assertStringContainsString('"layout_type":"product"', $src);
    self::assertStringContainsString('"layout_type":"checkout"', $src);
}

private function assert_PixelProdConsoleGate_testPixelTrackLogsGateOnDeployModeNotAcceptanceHost(): void
{
    $root = '/Users/weline/Project/Official/框架/app/code/Weline/Visitor';
    foreach ([$root . '/view/statics/js/pixel.js', $root . '/view/taglib/js/pixel.phtml'] as $path) {
        $src = (string) \file_get_contents($path);
        self::assertStringContainsString('function __isDevConsoleEnabled', $src);
        self::assertStringContainsString("PIXEL_SCRIPT_VERSION = '2026.10.09-prod-console1'", $src);
        self::assertStringContainsString('if (__isDevConsoleEnabled() && typeof console !== \'undefined\' && typeof console.log === \'function\')', $src);
        self::assertStringContainsString("console.log('[WelinePixel] track'", $src);
        self::assertStringContainsString("console.log('[WelinePixel] drop incomplete'", $src);
        // Host regex may remain for eager-load helpers elsewhere; track/drop must not OR it in.
        self::assertDoesNotMatchRegularExpression('/console\.log\(\'\[WelinePixel\] track\'[\s\S]{0,800}?test\\\\?\.weline\\\\?\.com/', $src);
        self::assertDoesNotMatchRegularExpression('/console\.log\(\'\[WelinePixel\] drop incomplete\'[\s\S]{0,800}?test\\\\?\.weline\\\\?\.com/', $src);
    }
}

private function assert_PixelProdConsoleGate_testBootstrapDevLogGatesOnDeployMode(): void
{
    $root = '/Users/weline/Project/Official/框架/app/code/Weline/Visitor';
    $bootstrap = (string) \file_get_contents($root . '/Service/PixelBootstrapHtmlService.php');
    $bodyEnd = (string) \file_get_contents($root . '/view/hooks/Weline_Theme/frontend/layouts/base/body-end.phtml');
    foreach ([$bootstrap, $bodyEnd] as $src) {
        self::assertStringContainsString('function isDevConsoleEnabled()', $src);
        self::assertStringContainsString('if (!isDevConsoleEnabled()', $src);
        self::assertStringContainsString('if (isDevConsoleEnabled())', $src);
        self::assertStringContainsString("console.log('[WelineCTA] click'", $src);
    }
    self::assertStringContainsString("PIXEL_SCRIPT_VERSION = '20261009-prod-console1'", $bootstrap);
}

private function assert_PixelRequiredParamGate_testPixelJsDropsIncompleteEcommerceAndSearch(): void
{
    $root = '/Users/weline/Project/Official/框架/app/code/Weline/Visitor';
    $pixel = (string) \file_get_contents($root . '/view/statics/js/pixel.js');
    self::assertStringContainsString('function __evaluateRequiredParamGate', $pixel);
    self::assertStringContainsString("reason: 'payment_recovery'", $pixel);
    self::assertStringContainsString("return ['search_term']", $pixel);
    self::assertStringContainsString("return ['currency', 'value', 'items']", $pixel);
    self::assertStringContainsString('weline-cart-shell__line', $pixel);
    self::assertStringContainsString('[WelinePixel] drop incomplete', $pixel);
    self::assertStringContainsString("PIXEL_SCRIPT_VERSION = '2026.10.09-prod-console1'", $pixel);
    self::assertStringContainsString('__isChromeLocaleSearchInput', $pixel);
    self::assertStringContainsString('__isStorefrontSearchResultPath', $pixel);
    self::assertStringContainsString('__isBenignSiteIncident', $pixel);
    self::assertStringContainsString("return ['error_message']", $pixel);
    self::assertStringContainsString('valueNum <= 0', $pixel);
}

private function assert_PixelRequiredParamGate_testPersistenceWritesPageViewAdditionalWhenUrlPresent(): void
{
    $src = (string) \file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Visitor' . '/Service/PixelEventPersistenceService.php');
    self::assertStringContainsString('page_location', $src);
    self::assertStringContainsString('page_title', $src);
    self::assertStringContainsString('PASSIVE_EVENTS_WITH_BROWSER_INFO', $src);
    // Must NOT unconditionally skip all passive events anymore.
    self::assertStringNotContainsString('return !isset(self::PASSIVE_EVENTS_WITH_BROWSER_INFO[$event]);', $src);
}

private function assert_PixelRequiredParamGate_testServerSkipsEmptyCartAndRecoveryCheckout(): void
{
    $src = (string) \file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Visitor' . '/Service/PixelEventService.php');
    self::assertStringContainsString('skipReasonForIncompleteRequiredParams', $src);
    self::assertStringContainsString('missing_required_params_items', $src);
    self::assertStringContainsString('payment_recovery_begin_checkout_forbidden', $src);
    self::assertStringContainsString('missing_required_params_search_term', $src);
    self::assertStringContainsString('missing_required_params_error_message', $src);
    self::assertStringContainsString('checkout_success', $src);
    self::assertStringContainsString('normalizeSearchParamsFromUrl', $src);
    self::assertStringContainsString('missing_required_params_search_term_chrome_label', $src);
    self::assertStringContainsString('site_error_benign_noise_skipped', $src);
}

private function assert_PixelSandboxInitResilience_testPixelJsDefersLoadedFlagAndGuardsTelemetry(): void
{
    $root = '/Users/weline/Project/Official/框架/app/code/Weline/Visitor';
    $pixel = (string) \file_get_contents($root . '/view/statics/js/pixel.js');
    $phtml = (string) \file_get_contents($root . '/view/taglib/js/pixel.phtml');
    $monitor = (string) \file_get_contents($root . '/view/statics/js/event-sandbox-monitor.js');
    $bootstrap = (string) \file_get_contents($root . '/Service/PixelBootstrapHtmlService.php');
    foreach ([$pixel, $phtml] as $source) {
        self::assertStringContainsString("PIXEL_SCRIPT_VERSION = '2026.10.09-prod-console1'", $source);
        self::assertStringContainsString('检测到半截初始化（缺沙盒），重入补建', $source);
        self::assertStringContainsString('function __ensurePixelSandboxBus', $source);
        self::assertStringContainsString('function __seedSandboxFromRecentEvents', $source);
        self::assertStringContainsString('__WelineVisitorForwardersBridgeWrapped', $source);
        self::assertStringContainsString('__WelinePixelSeedSandboxFromRecent', $source);
        self::assertStringContainsString('behavior telemetry init failed', $source);
        self::assertStringContainsString('pagebuilder impressions init failed', $source);
        // 行为遥测须在沙盒对象创建之后调用
        $sandboxPos = \strpos($source, 'window.WelinePixelSandbox = {');
        $telemetryPos = \strpos($source, '__initBehaviorTelemetry();');
        self::assertNotFalse($sandboxPos);
        self::assertNotFalse($telemetryPos);
        self::assertGreaterThan($sandboxPos, $telemetryPos);
        // __WelinePixelLoaded=true 仅在沙盒就绪尾部
        self::assertSame(1, \preg_match_all('/window\.__WelinePixelLoaded\s*=\s*true/', $source));
        self::assertStringContainsString("hit_kind: 'dedupe'", $source);
        self::assertStringContainsString('__ensurePixelSandboxBus()', $source);
        self::assertStringContainsString('__seedSandboxFromRecentEvents(true)', $source);
    }
    self::assertStringContainsString('__WelinePixelSeedSandboxFromRecent', $monitor);
    self::assertStringContainsString('runtime.recentEvents', $pixel);
    self::assertStringContainsString("PIXEL_SCRIPT_VERSION = '20261009-prod-console1'", $bootstrap);
    $module = (string) \file_get_contents($root . '/etc/module.php');
    self::assertStringContainsString("'1.1.43'", $module);
}

private function assert_PixelSiteErrorJs_testPixelJsExposesReportIncidentAndSiteErrorMonitors(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/Visitor' . '/view/statics/js/pixel.js';
    self::assertFileExists($path);
    $js = (string) file_get_contents($path);
    self::assertStringContainsString('reportIncident:', $js);
    self::assertStringContainsString('__reportSiteIncident', $js);
    self::assertStringContainsString('__installSiteErrorMonitors', $js);
    self::assertStringContainsString("track('site_error'", $js);
    self::assertStringContainsString('themePublishedVersionId', $js);
    self::assertStringContainsString('deployVersion', $js);
    self::assertStringContainsString("window.addEventListener('weline:api:error'", $js);
    self::assertStringContainsString('__onWelineApiError', $js);
    self::assertStringContainsString('detail && detail.silent', $js);
    self::assertStringContainsString("capture_source: 'api'", $js);
    self::assertStringContainsString('__isBenignSiteIncident', $js);
    self::assertStringContainsString('error_message: message', $js);
}

private function assert_PixelSiteErrorJs_testTaglibPixelSynced(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/Visitor' . '/view/taglib/js/pixel.phtml';
    self::assertFileExists($path);
    $js = (string) file_get_contents($path);
    self::assertStringContainsString('reportIncident:', $js);
    self::assertStringContainsString('__installSiteErrorMonitors', $js);
    self::assertStringContainsString("window.addEventListener('weline:api:error'", $js);
    self::assertStringContainsString('__onWelineApiError', $js);
    self::assertStringContainsString('detail && detail.silent', $js);
}

private function assert_PixelSiteErrorJs_testFrontendWelineApiAlwaysNotifiesApiFailure(): void
{
    $path = BP . '/app/code/Weline/Frontend/view/statics/js/weline-api.js';
    self::assertFileExists($path);
    $js = (string) file_get_contents($path);
    self::assertStringContainsString('notifyApiFailure(error, meta', $js);
    self::assertStringContainsString("window.dispatchEvent(new CustomEvent('weline:api:error'", $js);
    self::assertStringContainsString("provider === 'visitor' && /^trackPixel\$/i.test(operation)", $js);
    self::assertStringContainsString('this.notifyApiFailure(error, {', $js);
    self::assertStringContainsString('handleHttpError(status, error, silent, requestOptions, requestPayload)', $js);
}

private function assert_PixelSiteErrorJs_testEventDictionaryHasSiteErrorSkipGtm(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/Visitor' . '/etc/event_dictionary.json';
    $json = json_decode((string) file_get_contents($path), true);
    self::assertIsArray($json);
    $found = null;
    foreach ($json['events'] ?? [] as $event) {
        if (($event['weline_event'] ?? '') === 'site_error') {
            $found = $event;
            break;
        }
    }
    self::assertIsArray($found);
    self::assertTrue((bool) ($found['skip_gtm_push'] ?? false));
    self::assertSame('error', (string) ($found['event_family'] ?? ''));
}

private function jsPath_PixelStickyUtmJs(): string
{
    return BP . '/app/code/Weline/Visitor/view/statics/js/pixel.js';
}

private function phtmlPath_PixelStickyUtmJs(): string
{
    return BP . '/app/code/Weline/Visitor/view/taglib/js/pixel.phtml';
}

/** @return list<string> */
private function sources_PixelStickyUtmJs(): array
{
    return [(string) \file_get_contents($this->jsPath_PixelStickyUtmJs()), (string) \file_get_contents($this->phtmlPath_PixelStickyUtmJs())];
}

private function assert_PixelStickyUtmJs_testBothFilesContainStickyApiAndVersion(): void
{
    foreach ($this->sources_PixelStickyUtmJs() as $source) {
        self::assertStringContainsString("PIXEL_SCRIPT_VERSION = '2026.10.09-prod-console1'", $source);
        self::assertStringContainsString('function __getStickyUtmPack()', $source);
        self::assertStringContainsString('payload.sticky =', $source);
    }
}

private function assert_PixelStickyUtmJs_testA08GatesStickyByMarketingConsent(): void
{
    foreach ($this->sources_PixelStickyUtmJs() as $source) {
        self::assertStringContainsString('function __visitorMarketingConsentAllowsStorage()', $source);
        self::assertStringContainsString('function __stickyLinkerEnabled()', $source);
    }
}

private function assert_PixelStickyUtmJs_testA09RewritesInternalAnchorsWithObserver(): void
{
    foreach ($this->sources_PixelStickyUtmJs() as $source) {
        self::assertStringContainsString('function __rewriteStickyAnchors(', $source);
        self::assertStringContainsString('Weline.dom.observe', $source);
        self::assertStringContainsString('visitor-pixel:sticky-utm', $source);
        self::assertStringContainsString('new MutationObserver', $source);
        self::assertStringContainsString("querySelectorAll('a[href]')", $source);
        self::assertStringNotContainsString("attributeFilter: ['href']", $source);
    }
}

private function assert_PixelStickyUtmJs_testA10RerunsLinkerOnSpaTransitions(): void
{
    foreach ($this->sources_PixelStickyUtmJs() as $source) {
        self::assertMatchesRegularExpression('/function __trackPageTransition\([\s\S]*?__scheduleStickyAnchorRewrite\(160\);/', $source);
        self::assertStringContainsString("addEventListener('popstate'", $source);
    }
}

private function assert_PixelStickyUtmJs_testA11GetFormMergeDefaultOff(): void
{
    foreach ($this->sources_PixelStickyUtmJs() as $source) {
        self::assertStringContainsString('function __stickyFormMergeEnabled()', $source);
        self::assertStringContainsString('function __mergeStickyIntoForm(', $source);
        self::assertStringContainsString('function __initStickyFormMerge()', $source);
        self::assertStringContainsString('__initStickyFormMerge();', $source);
        self::assertStringContainsString('data-weline-sticky-field', $source);
        // 默认关：__visitorConfigBool(flag, false)
        self::assertMatchesRegularExpression('/function __stickyFormMergeEnabled\(\)[\s\S]{0,400}?__visitorConfigBool\(flag, false\)/', $source);
        self::assertStringContainsString('stickyFormMergeEnabled: __stickyFormMergeEnabled', $source);
        self::assertStringContainsString('mergeStickyIntoForm: __mergeStickyIntoForm', $source);
    }
}

private function assert_PixelStickyUtmJs_testA12Ga4GtmForwardIncludeStickyParams(): void
{
    foreach ($this->sources_PixelStickyUtmJs() as $source) {
        self::assertStringContainsString('function __appendStickyAttributionParams(', $source);
        self::assertStringContainsString('return __appendStickyAttributionParams(params);', $source);
        self::assertStringContainsString('__appendStickyAttributionParams(row);', $source);
        self::assertStringContainsString('sticky_locked_at', $source);
        self::assertStringContainsString("['utm_source', pack.utm_source]", $source);
    }
}

private function assert_PixelStickyUtmJs_testStickyHelpersAlignedBetweenFiles(): void
{
    [$js, $phtml] = $this->sources_PixelStickyUtmJs();
    foreach (['function __captureMarketingPackFromSearch', 'function __persistStickyUtmPack', 'function __rewriteStickyAnchors', 'function __initStickyUtmLinker', 'function __stickyFormMergeEnabled', 'function __mergeStickyIntoForm', 'function __initStickyFormMerge', 'function __appendStickyAttributionParams', '__scheduleStickyAnchorRewrite(160)', '__pixelStickyStorageKey'] as $needle) {
        self::assertStringContainsString($needle, $js);
        self::assertStringContainsString($needle, $phtml);
    }
}

private function assert_PixelStickyUtmJs_testPixelBootstrapUsesTheCanonicalStaticAssetVersion(): void
{
    $hook = (string) \file_get_contents(BP . '/app/code/Weline/Visitor/view/hooks/Weline_Theme/frontend/layouts/base/body-end.phtml');
    // @static inside a PHP-quoted assignment compiles to nested PHP and ParseErrors the homepage.
    self::assertStringNotContainsString("\$__pixel_script_url = '@static(Weline_Visitor::js/pixel.js)';", $hook);
    self::assertStringNotContainsString('@static(Weline_Visitor::js/pixel.js)?v=', $hook);
    self::assertStringContainsString("fetchTagSource('statics', 'Weline_Visitor::js/pixel.js')", $hook);
    self::assertStringContainsString('script.src = \'<?= $__pixel_script_url ?>\'', $hook);
}

private function assert_PixelStickyUtmJs_testLocalDevelopmentDefersThePixelBundleUntilIdle(): void
{
    $hook = (string) \file_get_contents(BP . '/app/code/Weline/Visitor/view/hooks/Weline_Theme/frontend/layouts/base/body-end.phtml');
    self::assertStringNotContainsString("loadWelinePixel('eager-dev');", $hook);
    self::assertStringContainsString("window.requestIdleCallback(function () { loadWelinePixel('idle-dev'); }, { timeout: 1500 });", $hook);
    self::assertStringContainsString("window.setTimeout(function () { loadWelinePixel('timeout-dev'); }, 500);", $hook);
    self::assertStringContainsString("loadWelinePixel('cta-click');", $hook);
}

private function assert_PixelStorefrontPrice_testProductMetaReadsOfferPriceMinorAndCurrency(): void
{
    $root = '/Users/weline/Project/Official/框架/app/code/Weline/Visitor';
    $pixel = (string) \file_get_contents($root . '/view/statics/js/pixel.js');
    $phtml = (string) \file_get_contents($root . '/view/taglib/js/pixel.phtml');
    $bootstrap = (string) \file_get_contents($root . '/Service/PixelBootstrapHtmlService.php');
    $pdp = (string) \file_get_contents(\dirname($root) . '/Product/view/templates/frontend/widgets/product-info.phtml');
    foreach ([$pixel, $phtml] as $src) {
        self::assertStringContainsString('function __readStorefrontPriceMajor', $src);
        self::assertStringContainsString('data-offer-price-minor', $src);
        self::assertStringContainsString('data-catalog-price-minor', $src);
        self::assertStringContainsString('.product-native-detail__price', $src);
        self::assertStringContainsString('currency: currency', $src);
        self::assertStringContainsString('price = __readStorefrontPriceMajor(element)', $src);
    }
    self::assertStringContainsString("PIXEL_SCRIPT_VERSION = '20261009-prod-console1'", $bootstrap);
    self::assertStringContainsString('data-offer-price-minor', $pdp);
    self::assertStringContainsString('data-price=', $pdp);
    self::assertStringContainsString('data-pixel-value=', $pdp);
    self::assertStringContainsString('data-pixel-currency=', $pdp);
    self::assertStringContainsString("root.setAttribute('data-price', offerMajor)", $pdp);
    self::assertStringContainsString("root.setAttribute('data-pixel-currency', code)", $pdp);
}

private function template_TrackingVendorAdminUi(): string
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/Visitor' . '/view/templates/backend/TrackingVendor/index.phtml';
    self::assertFileExists($path);
    return (string) \file_get_contents($path);
}

private function assert_TrackingVendorAdminUi_testFormalPageHasToolbarWithoutDuplicatePageH1(): void
{
    $src = $this->template_TrackingVendorAdminUi();
    self::assertStringContainsString('data-testid="tracking-vendor-admin"', $src);
    self::assertStringContainsString('data-variant-winner="2"', $src);
    // 后台壳已有页标题；内容区用工具条，禁止再叠一层「事件供应商」h1
    self::assertStringNotContainsString('<h1 class="h4"', $src);
    self::assertStringContainsString('data-testid="tv-toolbar"', $src);
    self::assertStringContainsString('tv-sync', $src);
    self::assertStringContainsString('tv-open-create', $src);
}

private function assert_TrackingVendorAdminUi_testModeHintLivesInsideConfigPanelNotAboveTabs(): void
{
    $src = $this->template_TrackingVendorAdminUi();
    $tabsPos = \strpos($src, 'id="tv-tabs"');
    $hintPos = \strpos($src, 'tvp-mode-hint');
    $configPos = \strpos($src, 'data-panel="config"');
    self::assertNotFalse($tabsPos);
    self::assertNotFalse($hintPos);
    self::assertNotFalse($configPos);
    self::assertGreaterThan($tabsPos, $hintPos, '模式说明须在 Tab 之后');
    self::assertGreaterThan($configPos, $hintPos - 1);
    self::assertLessThan($hintPos, $configPos, '模式说明须落在 config 面板内');
}

private function assert_TrackingVendorAdminUi_testEnableControlIsSingleLabelCheckbox(): void
{
    $src = $this->template_TrackingVendorAdminUi();
    self::assertStringContainsString('data-testid="tv-enable"', $src);
    self::assertStringContainsString('系统像素默认启用且不可关闭', $src);
    self::assertStringContainsString('data-locked="1"', $src);
    self::assertStringContainsString('class="tvp-check"', $src);
    // 禁止「启用」外层 label + 「启用此供应商」内层双标签叠层
    self::assertDoesNotMatchRegularExpression('/<label>\s*<lang>启用<\/lang>\s*<\/label>\s*<label>\s*<input[^>]*name="enabled"/', $src);
}

private function assert_TrackingVendorAdminUi_testWorkScopeToolbarUsesOfficialScopeTaglib(): void
{
    $src = $this->template_TrackingVendorAdminUi();
    self::assertStringContainsString('data-testid="tv-topbar"', $src);
    self::assertStringContainsString("fetch('Weline_Visitor::templates/backend/TrackingVendor/partials/scope-toolbar.phtml')", $src);
    self::assertStringContainsString('data-tab="scope"', $src);
    self::assertStringContainsString('路径过滤', $src);
    self::assertStringContainsString('配置范围', $src);
    self::assertStringContainsString('配置范围是继承制度', $src);
    self::assertStringContainsString('路径过滤不是网站/店铺/渠道范围', $src);
    self::assertStringContainsString('tv-config-scope-summary', $src);
    $toolbar = '/Users/weline/Project/Official/框架/app/code/Weline/Visitor' . '/view/templates/backend/TrackingVendor/partials/scope-toolbar.phtml';
    self::assertFileExists($toolbar);
    $tb = (string) \file_get_contents($toolbar);
    self::assertStringContainsString('<w:scope', $tb);
    self::assertStringContainsString('tracking-vendor-work-scope', $tb);
    self::assertStringContainsString('data-align="start"', $tb);
    self::assertStringContainsString('继承：渠道 ← 店铺 ← 网站', $tb);
    self::assertStringContainsString('tv-work-scope-inherit', $tb);
    $js = '/Users/weline/Project/Official/框架/app/code/Weline/Visitor' . '/view/statics/js/backend/tracking-vendor-work-scope.js';
    self::assertFileExists($js);
    $jsBody = (string) \file_get_contents($js);
    self::assertStringContainsString("getElementById('tracking-vendor-work-scope')", $jsBody);
    self::assertStringContainsString('target_scope', $jsBody);
    $css = (string) \file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Visitor' . '/view/statics/css/tracking-vendor-admin.css');
    self::assertStringContainsString('.tvp-topbar', $css);
    self::assertStringContainsString('justify-content: space-between', $css);
}

private function assert_TrackingVendorAdminUi_testGa4AndGtmVendorsEmbedUnifiedSystemConfig(): void
{
    $src = $this->template_TrackingVendorAdminUi();
    self::assertStringContainsString('data-testid="tv-system-config-embed"', $src);
    self::assertStringContainsString('<w:config:embed', $src);
    self::assertStringContainsString('module="Weline_Visitor"', $src);
    self::assertStringContainsString('group="visitor_ga4"', $src);
    self::assertStringContainsString('group="visitor_gtm"', $src);
    self::assertStringContainsString('data-testid="tv-open-system-config"', $src);
    self::assertStringContainsString('data-testid="tv-cred-legacy-hint"', $src);
    self::assertStringContainsString('weline_systemconfig/backend/config', $src);
    // 禁止在 w:config:embed 属性内插 PHP 开标签
    self::assertDoesNotMatchRegularExpression('/<w:config:embed[^>]*(<\?=|<\?php)/', $src);
}
}

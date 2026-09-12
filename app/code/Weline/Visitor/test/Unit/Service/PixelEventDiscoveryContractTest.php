<?php

declare(strict_types=1);

namespace Weline\Visitor\test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Visitor\Extends\Module\Weline_Visitor\PixelEventVendor\Ga4Vendor;
use Weline\Visitor\Extends\Module\Weline_Visitor\PixelEventVendor\GtmVendor;
use Weline\Visitor\Extends\Module\Weline_Visitor\PixelEventVendor\SystemVendor;
use Weline\Visitor\Service\EventDictionaryService;
use Weline\Visitor\Service\EventPickerTokenService;
use Weline\Visitor\Service\PixelEventVendorManager;

final class PixelEventDiscoveryContractTest extends TestCase
{
    public function testDictionaryHasLeadSubmitLoginRegisterAndLabels(): void
    {
        $dict = new EventDictionaryService();
        $by = [];
        foreach ($dict->getEvents() as $entry) {
            if (\is_array($entry) && isset($entry['weline_event'])) {
                $by[(string)$entry['weline_event']] = $entry;
            }
        }
        self::assertArrayHasKey('lead_submit', $by);
        self::assertSame('线索提交', $by['lead_submit']['label_zh'] ?? null);
        self::assertArrayHasKey('login', $by);
        self::assertSame('登录成功', $by['login']['label_zh'] ?? null);
        self::assertArrayHasKey('register', $by);
        self::assertSame('注册成功', $by['register']['label_zh'] ?? null);
        self::assertArrayNotHasKey('login_success', $by);

        $mappable = $dict->listMappableEvents();
        $names = \array_column($mappable, 'name');
        self::assertContains('login', $names);
        self::assertContains('register', $names);
        self::assertContains('lead_submit', $names);
        self::assertNotContains('page_view', $names);
    }

    public function testVendorDefaultsUseLoginRegisterNotSuccessSuffix(): void
    {
        foreach ([new SystemVendor(), new Ga4Vendor(), new GtmVendor()] as $vendor) {
            $map = $vendor->getDefaultEventMap();
            self::assertArrayHasKey('login', $map);
            self::assertArrayHasKey('register', $map);
            self::assertArrayHasKey('lead_submit', $map);
            self::assertArrayNotHasKey('login_success', $map);
            self::assertArrayNotHasKey('register_success', $map);
        }
        self::assertSame('login', (new Ga4Vendor())->getDefaultEventMap()['login']);
        self::assertSame('sign_up', (new Ga4Vendor())->getDefaultEventMap()['register']);
    }

    public function testFilterMappableMapKeepsCustomKeys(): void
    {
        $ref = new \ReflectionClass(PixelEventVendorManager::class);
        $manager = $ref->newInstanceWithoutConstructor();
        $filtered = $manager->filterMappableMap([
            'page_view' => 'page_view',
            'login' => 'login',
            'my_custom_event' => 'custom_tp',
        ]);
        self::assertArrayNotHasKey('page_view', $filtered);
        self::assertSame('login', $filtered['login'] ?? null);
        self::assertSame('custom_tp', $filtered['my_custom_event'] ?? null);
    }

    public function testDictionaryNormalizeKeepsCustomEventNames(): void
    {
        $dict = new EventDictionaryService();
        self::assertSame('custom_browser_verify_event', $dict->normalizeEventName('custom_browser_verify_event'));
        self::assertSame('all_add_cart', $dict->normalizeEventName('All-Add-Cart'));
        self::assertSame('page_exit', $dict->normalizeEventName('page_exit'));
        self::assertNotSame('', $dict->normalizeEventName('persist_probe_mtvdo0av'));
    }

    public function testPickerTokenIssueAndValidate(): void
    {
        $svc = new EventPickerTokenService(new EventDictionaryService());
        $issued = $svc->issue(1, 'ga4', 9);
        self::assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $issued['token']);
        $payload = $svc->validate($issued['token']);
        self::assertNotNull($payload);
        self::assertSame(1, $payload['website_id']);
        self::assertSame('ga4', $payload['vendor_code']);
        self::assertNull($svc->validate('deadbeefdeadbeefdeadbeefdeadbeef'));
    }

    public function testPickerAccumulateBuffer(): void
    {
        $svc = new EventPickerTokenService(new EventDictionaryService());
        $svc->pushAccumulate(99, [
            'weline_event' => 'Login-Success!!',
            'third_party_event' => 'login',
            'source' => 'test',
            'summary' => 'x',
            'path' => '/a',
        ]);
        $list = $svc->listAccumulate(99, 10);
        self::assertNotEmpty($list);
        self::assertSame('login_success', $list[0]['weline_event'] ?? null);
    }

    public function testPickerAccumulateStoresChainPayload(): void
    {
        $svc = new EventPickerTokenService(new EventDictionaryService());
        $svc->pushAccumulate(77, [
            'weline_event' => 'checkout_flow',
            'third_party_event' => 'begin_checkout',
            'source' => 'picker_chain_record',
            'summary' => '操作链 3 步',
            'path' => '/cart',
            'kind' => 'chain',
            'chain_steps' => 3,
            'chain' => [
                'version' => 1,
                'steps' => [
                    ['type' => 'page', 'path' => '/'],
                    ['type' => 'click', 'label' => 'Buy'],
                    ['type' => 'submit', 'label' => 'form'],
                ],
            ],
        ]);
        $list = $svc->listAccumulate(77, 5);
        self::assertSame('checkout_flow', $list[0]['weline_event'] ?? null);
        self::assertSame('chain', $list[0]['kind'] ?? null);
        self::assertSame(3, (int)($list[0]['chain_steps'] ?? 0));
        self::assertIsArray($list[0]['chain'] ?? null);
    }

    public function testPickerAccumulateStoresParams(): void
    {
        $svc = new EventPickerTokenService(new EventDictionaryService());
        $svc->pushAccumulate(66, [
            'weline_event' => 'cta_click',
            'third_party_event' => 'click',
            'source' => 'picker_observe',
            'path' => '/x?utm_source=ad',
            'params' => ['utm_source' => 'ad', 'value' => 12.5],
            'hit_kind' => 'system',
        ]);
        $list = $svc->listAccumulate(66, 5);
        self::assertNotEmpty($list);
        self::assertSame('cta_click', $list[0]['weline_event'] ?? null);
        self::assertIsArray($list[0]['params'] ?? null);
        self::assertSame('ad', $list[0]['params']['utm_source'] ?? null);
        self::assertSame('system', $list[0]['hit_kind'] ?? null);
    }

    public function testPickerAccumulateSinceAndClear(): void
    {
        $svc = new EventPickerTokenService(new EventDictionaryService());
        $wid = 55;
        $svc->clearAccumulate($wid);
        $svc->pushAccumulate($wid, [
            'weline_event' => 'click',
            'source' => 'sandbox_stream',
            'path' => '/',
            'params' => ['x' => 1],
            'event_hit' => false,
        ]);
        $svc->pushAccumulate($wid, [
            'weline_event' => 'page_view',
            'source' => 'sandbox_stream',
            'path' => '/',
            'hit_kind' => 'system',
        ]);
        $all = $svc->listAccumulate($wid, 10);
        self::assertCount(2, $all);
        self::assertGreaterThan(0, (int)($all[0]['seq'] ?? 0));
        $firstSeq = (int)($all[1]['seq'] ?? 0);
        $since = $svc->listAccumulateSince($wid, $firstSeq, 10);
        self::assertNotEmpty($since['events']);
        self::assertSame('page_view', $since['events'][0]['weline_event'] ?? null);
        self::assertGreaterThan($firstSeq, (int)$since['cursor']);
        self::assertTrue($svc->clearAccumulate($wid));
        self::assertSame([], $svc->listAccumulate($wid, 10));
        $empty = $svc->listAccumulateSince($wid, 0, 10);
        self::assertSame([], $empty['events']);
        self::assertSame(0, (int)$empty['cursor']);
    }

    public function testAdminTemplateHasPickerAndAccumulate(): void
    {
        $path = \dirname(__DIR__, 3) . '/view/templates/Backend/TrackingVendor/index.phtml';
        $src = (string)\file_get_contents($path);
        self::assertStringContainsString('tv-start-picker', $src);
        self::assertStringContainsString('tv-map-accumulate', $src);
        self::assertStringContainsString('本会话沙盒', $src);
        self::assertStringContainsString('短间隔读取前台沙盒继电缓冲', $src);
        self::assertStringContainsString('tv-acc-clear', $src);
        self::assertStringContainsString('liveEventsUrl', $src);
        self::assertStringContainsString('startLiveTunnel', $src);
        self::assertStringContainsString('clearSessionSandbox', $src);
        self::assertStringContainsString('sandboxClearEpoch', $src);
        self::assertStringContainsString('sandboxGeneration', $src);
        self::assertStringContainsString('generation=', $src);
        self::assertStringContainsString('sandbox_only=1', $src);
        // 纠偏：禁止 wait_ms 服务端长轮询；map 可见时短间隔短请求即可。
        self::assertStringNotContainsString('wait_ms=2500', $src);
        self::assertStringNotContainsString('wait_ms=', $src);
        self::assertStringContainsString('LIVE_POLL_MS', $src);
        self::assertStringContainsString('setTimeout(tick, LIVE_POLL_MS)', $src);
        self::assertStringContainsString('启动事件拾取', $src);
        self::assertStringContainsString('tv-ga4-event-form', $src);
        self::assertStringContainsString('tv-ga4-create-dialog', $src);
        self::assertStringContainsString('data-w-component="dialog"', $src);
        self::assertStringContainsString('openGa4CreateDialog', $src);
        self::assertStringContainsString('closeGa4CreateDialog', $src);
        self::assertStringContainsString('Weline.UI.dialog.open', $src);
        self::assertStringContainsString('tv-open-ga4-create', $src);
        self::assertStringContainsString('创建', $src);
        self::assertStringContainsString('匹配条件', $src);
        self::assertStringContainsString('tv-ga4-conditions', $src);
        self::assertStringContainsString('tv-match-type-event', $src);
        self::assertStringContainsString('tv-match-type-url', $src);
        self::assertStringContainsString('seedConditionsForType', $src);
        self::assertStringContainsString('conditions_json', $src);
        self::assertStringContainsString('match_param_options_json', $src);
        self::assertStringContainsString('tv-ga4-param-list', $src);
        self::assertStringContainsString('list="tv-ga4-param-list"', $src);
        self::assertStringContainsString('tv-ga4-param-pick', $src);
        self::assertStringContainsString('param_mappings_json', $src);
        self::assertStringContainsString('seedParamMappings', $src);
        self::assertStringContainsString('tv-ga4-map-table', $src);
        self::assertStringContainsString('data-cond-param', $src);
        self::assertStringContainsString('ensureMatchParamDatalist', $src);
        self::assertStringContainsString('参数可点选预设，也可手写', $src);
        self::assertStringContainsString('elementInfo.text', $src);
        self::assertStringContainsString('创建事件', $src);
        self::assertStringContainsString('tv-custom-create', $src);
        self::assertStringContainsString('focusCreateEvent', $src);
        self::assertStringContainsString('is_custom', $src);
        self::assertStringContainsString('parseUrlInsights', $src);
        self::assertStringContainsString('add_custom_event_url', $src);
        self::assertStringContainsString('data-map-variant="subtabs-stream"', $src);
        self::assertStringContainsString('tvp-map-stream', $src);
        self::assertStringContainsString('本会话沙盒数据流', $src);
        self::assertStringContainsString('data-testid="tv-stream-tabs"', $src);
        self::assertStringContainsString('data-testid="tv-stream-tab-system"', $src);
        self::assertStringContainsString('data-testid="tv-stream-tab-custom"', $src);
        self::assertStringContainsString('data-testid="tv-stream-tab-stream"', $src);
        self::assertStringContainsString('tvp_session_stream_v1', $src);
        self::assertStringContainsString('mergeSessionStream', $src);
        self::assertStringContainsString('hit_kind', $src);
        self::assertStringContainsString('data-hit-kind', $src);
        self::assertStringContainsString('eventParamsHtml', $src);
        self::assertStringContainsString('showSandboxCompanion', $src);
        self::assertStringContainsString('tvp-map-stream--float', $src);
        self::assertStringContainsString('data-float', $src);
        self::assertStringContainsString('tv-sandbox-float-close', $src);
        self::assertStringContainsString('copyableParamsHtml', $src);
        self::assertStringContainsString('data-testid="tv-acc-copy-key"', $src);
        self::assertStringContainsString('data-testid="tv-acc-copy-value"', $src);
        // 沙盒：按事件名搜索；点事件栏单开；禁止底部「收起参数」按钮。
        self::assertStringContainsString('data-testid="tv-acc-search"', $src);
        self::assertStringContainsString('id="tv-acc-search"', $src);
        self::assertStringContainsString('streamEventExpandKey', $src);
        self::assertStringContainsString('data-acc-bar', $src);
        self::assertStringContainsString('data-testid="tv-acc-bar"', $src);
        self::assertStringNotContainsString('收起参数', $src);
        self::assertStringNotContainsString('data-testid="tv-acc-expand"', $src);
        self::assertStringNotContainsString('tv-sandbox-companion', $src);
        self::assertStringContainsString('data-testid="tv-map-subtab-custom"', $src);
        self::assertStringContainsString('class="w-button"', $src);
        self::assertStringNotContainsString('data-map-subtab="sandbox"', $src);
        self::assertStringContainsString('confirm_signup', $src);
        self::assertStringNotContainsString('placeholder="add_to_cart"', $src);
        self::assertStringContainsString('mappable_event_rows', $src);
        self::assertFileExists(\dirname(__DIR__, 3) . '/view/statics/js/pixel-event-picker.js');
        self::assertFileExists(\dirname(__DIR__, 3) . '/Controller/Analytics/EventPicker.php');
        self::assertFileExists(\dirname(__DIR__, 3) . '/Api/Rest/V1/EventPicker.php');
        $pickerJs = (string)\file_get_contents(\dirname(__DIR__, 3) . '/view/statics/js/pixel-event-picker.js');
        self::assertStringContainsString('/visitor/analytics/event-picker/observe', $pickerJs);
        self::assertStringContainsString('/visitor/analytics/event-picker/record', $pickerJs);
        self::assertStringContainsString('data-wpp-mode="chain"', $pickerJs);
        self::assertStringContainsString('自动发现', $pickerJs);
        self::assertStringContainsString('进自定义池仍须', $pickerJs);
        self::assertStringContainsString("source: 'track'", $pickerJs);
        self::assertStringContainsString('suggestCustomName', $pickerJs);
        self::assertStringContainsString('flashCaptureNotice', $pickerJs);
        self::assertStringContainsString('点击捕捉', $pickerJs);
        self::assertStringContainsString('任意可点', $pickerJs);
        self::assertStringContainsString('data-testid="wpp-discover-empty"', $pickerJs);
        self::assertStringContainsString('data-testid="wpp-capture-flash"', $pickerJs);
        self::assertStringContainsString('1.1.12-record-bump-rev', $pickerJs);
        self::assertStringContainsString('applyRecordRevisionAfterPersist', $pickerJs);
        self::assertStringContainsString('noteConfigRevision', $pickerJs);
        self::assertStringNotContainsString('禁止点击 invent custom_*', $pickerJs);
        self::assertStringNotContainsString('已打标点击仅对照系统事件', $pickerJs);
        self::assertStringContainsString('高级事件链', $pickerJs);
        self::assertStringContainsString('已默认无需重新录入', $pickerJs);
        self::assertStringContainsString('isSystemOwned', $pickerJs);
        self::assertStringContainsString('录入自定义', $pickerJs);
        self::assertStringContainsString('named_custom', $pickerJs);
        self::assertStringContainsString('自定义池门禁', $pickerJs);
        self::assertStringContainsString('进自定义池仍须改名并点「录入自定义」', $pickerJs);
        self::assertStringContainsString('recordErrorText', $pickerJs);
        self::assertStringContainsString('invalid_token', $pickerJs);
        self::assertStringContainsString('maintenance', $pickerJs);
        self::assertStringContainsString('sessionStorage', $pickerJs);
        self::assertStringContainsString('chain_json', $pickerJs);
        self::assertStringContainsString('data-wpp-mode="mapped"', $pickerJs);
        self::assertStringContainsString('已录入', $pickerJs);
        self::assertStringContainsString('/visitor/analytics/event-picker/mapped', $pickerJs);
        self::assertStringContainsString('confirmDuplicateRecord', $pickerJs);
        self::assertStringContainsString('duplicate', $pickerJs);
        self::assertStringContainsString('force', $pickerJs);
        self::assertStringContainsString('wpp_session_v1', $pickerJs);
        self::assertStringContainsString('persistPickerSession', $pickerJs);
        self::assertStringContainsString('weline_pixel_picker_token', $pickerJs);
        self::assertStringContainsString('rewriteSameOriginNav', $pickerJs);
        self::assertStringContainsString('valuePick', $pickerJs);
        self::assertStringContainsString('enterValuePick', $pickerJs);
        self::assertStringContainsString('extractElementValue', $pickerJs);
        self::assertStringContainsString('wpp-custom-pick-value', $pickerJs);
        self::assertStringContainsString('选值模式', $pickerJs);
        self::assertStringContainsString('attachValueFields', $pickerJs);
        self::assertStringContainsString('1.1.12-record-bump-rev', $pickerJs);
        self::assertStringNotContainsString('window.confirm(', $pickerJs);
        self::assertStringContainsString('Weline.UI.dialog.confirm', $pickerJs);
        self::assertStringNotContainsString('window.confirm(', $src);
        self::assertStringContainsString('Weline.UI.dialog.confirm', $src);
        self::assertStringContainsString('themeConfirm', $src);
        self::assertStringNotContainsString('alert(', $src);
        $bodyEnd = (string)\file_get_contents(\dirname(__DIR__, 3) . '/view/hooks/Weline_Theme/frontend/layouts/base/body-end.phtml');
        self::assertStringContainsString('wpp_session_v1', $bodyEnd);
        self::assertStringContainsString('weline_pixel_picker', $bodyEnd);
        self::assertStringContainsString('data-wpp-src', $bodyEnd);
        self::assertStringContainsString('tv-custom-event-pool', $src);
        self::assertStringContainsString('tv-custom-scope-tip', $src);
        self::assertStringContainsString('本范围自定义事件', $src);
        self::assertStringContainsString('custom_events_url', $src);
        self::assertStringContainsString('refreshCustomEvents', $src);
        $scopeToolbar = (string)\file_get_contents(\dirname(__DIR__, 3) . '/view/templates/Backend/TrackingVendor/partials/scope-toolbar.phtml');
        self::assertStringContainsString('tv-work-scope-custom-tip', $scopeToolbar);
        self::assertStringContainsString('自定义事件不随范围继承', $scopeToolbar);
        $discoveredSrc = (string)\file_get_contents(\dirname(__DIR__, 3) . '/Service/DiscoveredEventService.php');
        self::assertStringContainsString('scopeKeyFromStorage', $discoveredSrc);
        self::assertStringContainsString('listCustomEvents', $discoveredSrc);
        $tvPhp = (string)\file_get_contents(\dirname(__DIR__, 3) . '/Controller/Backend/TrackingVendor.php');
        self::assertStringContainsString('function getCustomEvents', $tvPhp);
        self::assertStringContainsString('function postDeleteCustomEvent', $tvPhp);
        self::assertStringContainsString('function postAddCustomEvent', $tvPhp);
        self::assertStringContainsString('add-custom-event', $tvPhp);
        self::assertStringContainsString('match_param_options_json', $tvPhp);
        self::assertStringContainsString('ResponseTerminateException', $tvPhp);
        self::assertStringContainsString('// redirect() 以 Error 终止响应，不可当业务失败', $tvPhp);
        self::assertStringContainsString('tv-map-subtabs', $src);
        self::assertStringContainsString('data-map-subtab', $src);
        self::assertStringContainsString('tv-custom-search', $src);
        self::assertStringContainsString('data-custom-edit', $src);
        self::assertStringContainsString('focusEditEvent', $src);
        self::assertStringContainsString('activateMapSubtab', $src);
        self::assertStringContainsString('tvp_map_subtab_v1', $src);
        self::assertStringContainsString('data-custom-del', $src);
        self::assertStringContainsString('deleteCustomEvent', $src);
        self::assertStringContainsString('delete_custom_event_url', $src);
        self::assertStringContainsString('不会一直累积', $src);
        self::assertStringContainsString('data-event-star', $src);
        self::assertStringContainsString('annotate_event_url', $src);
        self::assertStringContainsString('data-event-filter', $src);
        self::assertStringContainsString('有值', $src);
        $annSrc = (string)\file_get_contents(\dirname(__DIR__, 3) . '/Service/EventAnnotationService.php');
        self::assertStringContainsString('function setStarred', $annSrc);
        self::assertStringContainsString('function setCategory', $annSrc);
        self::assertStringContainsString('function markHasValue', $annSrc);
        self::assertStringContainsString('function enrichRows', $annSrc);
        self::assertStringContainsString('function normalizeMatchConditions', $annSrc);
        self::assertStringContainsString('MATCH_PARAM_LABELS', $annSrc);
        self::assertStringContainsString("'className'", $annSrc);
        self::assertStringContainsString('a-zA-Z0-9_', $annSrc);
        $tvPhp = (string)\file_get_contents(\dirname(__DIR__, 3) . '/Controller/Backend/TrackingVendor.php');
        self::assertStringContainsString('function postAnnotateEvent', $tvPhp);
        $discoveredSrc = (string)\file_get_contents(\dirname(__DIR__, 3) . '/Service/DiscoveredEventService.php');
        self::assertStringContainsString('function remove', $discoveredSrc);
        $pickerPhp = (string)\file_get_contents(\dirname(__DIR__, 3) . '/Controller/Analytics/EventPicker.php');
        self::assertStringContainsString('function postMapped', $pickerPhp);
        self::assertStringContainsString("'error' => 'duplicate'", $pickerPhp);
        self::assertStringContainsString('自定义池门禁', $pickerPhp);
        self::assertStringContainsString("\$kind === 'chain' && \$persistMap && \$chain !== null", $pickerPhp);
        self::assertStringContainsString("'category' => 'chain'", $pickerPhp);
        self::assertStringContainsString("'chain' => '事件链'", $annSrc);
        self::assertMatchesRegularExpression(
            '/自定义池门禁[\\s\\S]{0,200}if \\(\\$persistMap\\) \\{[\\s\\S]{0,80}\\$discovered->add/',
            $pickerPhp
        );
        $restPhp = (string)\file_get_contents(\dirname(__DIR__, 3) . '/Api/Rest/V1/EventPicker.php');
        self::assertStringContainsString('自定义池门禁', $restPhp);
        self::assertMatchesRegularExpression(
            '/自定义池门禁[\\s\\S]{0,200}if \\(\\$persistMap\\) \\{[\\s\\S]{0,80}\\$discovered->add/',
            $restPhp
        );
    }

    public function testDiscoveredScopeKeysAreIsolatedAndCustomLabelled(): void
    {
        $svc = new \Weline\Visitor\Service\DiscoveredEventService(new EventDictionaryService());
        $a = 'site_a.store_x.channel_1';
        $b = 'site_a.store_y.channel_1';
        self::assertNotSame(
            $svc->scopeKeyFromStorage($a, 1),
            $svc->scopeKeyFromStorage($b, 1)
        );
        self::assertStringStartsWith('scope.', $svc->scopeKeyFromStorage($a, 1));
        self::assertSame('website.3', $svc->scopeKeyFromStorage('', 3));
    }
}

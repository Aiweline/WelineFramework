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

    public function testAdminTemplateHasPickerAndAccumulate(): void
    {
        $path = \dirname(__DIR__, 3) . '/view/templates/Backend/TrackingVendor/index.phtml';
        $src = (string)\file_get_contents($path);
        self::assertStringContainsString('tv-start-picker', $src);
        self::assertStringContainsString('tv-map-accumulate', $src);
        self::assertStringContainsString('本会话沙盒', $src);
        self::assertStringContainsString('本会话累计', $src);
        self::assertStringContainsString('启动事件拾取', $src);
        self::assertStringContainsString('tv-ga4-event-form', $src);
        self::assertStringContainsString('创建', $src);
        self::assertStringContainsString('匹配条件', $src);
        self::assertStringContainsString('tv-ga4-conditions', $src);
        self::assertStringContainsString('tv-match-type-event', $src);
        self::assertStringContainsString('tv-match-type-url', $src);
        self::assertStringContainsString('seedConditionsForType', $src);
        self::assertStringContainsString('conditions_json', $src);
        self::assertStringContainsString('match_param_options_json', $src);
        self::assertStringContainsString('创建事件', $src);
        self::assertStringContainsString('tv-custom-create', $src);
        self::assertStringContainsString('focusCreateEvent', $src);
        self::assertStringContainsString('is_custom', $src);
        self::assertStringContainsString('parseUrlInsights', $src);
        self::assertStringContainsString('add_custom_event_url', $src);
        self::assertStringContainsString('data-map-variant="intake-sandbox"', $src);
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
        self::assertStringContainsString('不进自定义池', $pickerJs);
        self::assertStringContainsString("source: 'track'", $pickerJs);
        self::assertStringContainsString('禁止点击 invent custom_*', $pickerJs);
        self::assertStringNotContainsString('suggestCustomName', $pickerJs);
        self::assertStringNotContainsString('点击发现', $pickerJs);
        self::assertStringContainsString('高级事件链', $pickerJs);
        self::assertStringContainsString('已默认无需重新录入', $pickerJs);
        self::assertStringContainsString('isSystemOwned', $pickerJs);
        self::assertStringContainsString('录入自定义', $pickerJs);
        self::assertStringContainsString('named_custom', $pickerJs);
        self::assertStringContainsString('自定义池门禁', $pickerJs);
        self::assertStringContainsString('进自定义池须「输入名字并录入」', $pickerJs);
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
        self::assertStringContainsString('1.1.10-theme-confirm', $pickerJs);
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
        $tvPhp = (string)\file_get_contents(\dirname(__DIR__, 3) . '/Controller/Backend/TrackingVendor.php');
        self::assertStringContainsString('function postAnnotateEvent', $tvPhp);
        $discoveredSrc = (string)\file_get_contents(\dirname(__DIR__, 3) . '/Service/DiscoveredEventService.php');
        self::assertStringContainsString('function remove', $discoveredSrc);
        $pickerPhp = (string)\file_get_contents(\dirname(__DIR__, 3) . '/Controller/Analytics/EventPicker.php');
        self::assertStringContainsString('function postMapped', $pickerPhp);
        self::assertStringContainsString("'error' => 'duplicate'", $pickerPhp);
        self::assertStringContainsString('自定义池门禁', $pickerPhp);
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

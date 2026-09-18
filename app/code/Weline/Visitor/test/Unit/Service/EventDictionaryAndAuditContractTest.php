<?php
declare(strict_types=1);

namespace Weline\Visitor\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Visitor\Service\EventDictionaryService;
use Weline\Visitor\Service\PixelMarkerScanner;
use Weline\Visitor\Service\PixelPageTypeClassifier;

class EventDictionaryAndAuditContractTest extends TestCase
{
    public function testResolveCheckoutSuccessMapsToPurchase(): void
    {
        $dict = new EventDictionaryService();
        $resolved = $dict->resolve('checkout_success');
        $this->assertNotNull($resolved);
        $this->assertSame('purchase', $resolved['ga4_event'] ?? null);
        $this->assertSame('dictionary', $resolved['weline_mapping_source'] ?? null);
    }

    public function testPageViewSkipsGtmPush(): void
    {
        $dict = new EventDictionaryService();
        $resolved = $dict->resolve('page_view');
        $this->assertNotNull($resolved);
        $this->assertTrue(!empty($resolved['skip_gtm_push']));
    }

    public function testCtaOverride(): void
    {
        $dict = new EventDictionaryService();
        $resolved = $dict->resolve('cta_click', ['cta_event_name' => 'generate_lead']);
        $this->assertNotNull($resolved);
        $this->assertSame('generate_lead', $resolved['ga4_event'] ?? null);
        $this->assertSame('site_cta_override', $resolved['weline_mapping_source'] ?? null);
    }

    public function testClassifierHomeAndCheckout(): void
    {
        $c = new PixelPageTypeClassifier();
        $this->assertSame('home', $c->classify(['is_home' => true]));
        $this->assertSame('checkout', $c->classify(['url' => 'https://x.test/checkout']));
        $this->assertSame('account', $c->classify(['type' => 'login']));
        $this->assertSame('content', $c->classify(['handle' => 'about', 'url' => 'https://x.test/about']));
        $this->assertSame('page_type_unknown', $c->classify([]));
    }

    public function testScannerFindsMarkers(): void
    {
        $scanner = new PixelMarkerScanner();
        $html = '<a class="weline-pixel::cta_click pb-c-cta" data-pixel-event="cta_click" href="/go">Go</a>';
        $scan = $scanner->scanHtml($html);
        $this->assertContains('cta_click', $scan['events']);
        $this->assertTrue($scanner->matchesMarkers([
            'classes' => ['weline-pixel::cta_click'],
            'attrs' => ['data-pixel-event=cta_click'],
        ], $scan));
    }

    public function testScannerMissingMarkers(): void
    {
        $scanner = new PixelMarkerScanner();
        $html = '<div class="hero"><button>Buy</button></div>';
        $scan = $scanner->scanHtml($html);
        $this->assertSame([], $scan['events']);
        $this->assertFalse($scanner->matchesMarkers([
            'classes' => ['weline-pixel::cta_click'],
            'attrs' => ['data-cta'],
        ], $scan));
    }

    public function testScannerIgnoresInlineEventDictionaryBleed(): void
    {
        $scanner = new PixelMarkerScanner();
        $html = <<<'HTML'
<html><body>
<button class="pb-c-cta weline-pixel::cta_click">Go</button>
<script>
var visitorTrackingConfig = {"eventDictionary":{"events":[
  {"weline_event":"add_to_cart","markers":{"classes":["weline-pixel::add_to_cart"],"attrs":["data-pixel-event=add_to_cart"]}},
  {"weline_event":"begin_checkout","markers":{"classes":["weline-pixel::begin_checkout"],"attrs":["data-pixel-event=begin_checkout"]}}
]}};
</script>
</body></html>
HTML;
        $scan = $scanner->scanHtml($html);
        $this->assertContains('cta_click', $scan['events']);
        $this->assertNotContains('add_to_cart', $scan['events']);
        $this->assertNotContains('begin_checkout', $scan['events']);
        $this->assertFalse($scanner->matchesMarkers([
            'classes' => ['weline-pixel::add_to_cart'],
            'attrs' => ['data-pixel-event=add_to_cart'],
        ], $scan));
    }

    public function testEcommerceEventsDoNotScopeToContentViaStar(): void
    {
        $dict = new EventDictionaryService();
        $events = $dict->getEvents();
        $byName = [];
        foreach ($events as $entry) {
            if (\is_array($entry) && isset($entry['weline_event'])) {
                $byName[(string)$entry['weline_event']] = $entry;
            }
        }
        $this->assertArrayHasKey('add_to_cart', $byName);
        $this->assertArrayHasKey('friend_help_pay', $byName);
        $this->assertArrayHasKey('selection_share', $byName);
        $this->assertArrayHasKey('quick_buy', $byName);
        $this->assertArrayHasKey('express_pay', $byName);
        $this->assertArrayHasKey('express_pay_started', $byName);
        $this->assertArrayHasKey('express_pay_confirmed', $byName);
        $this->assertArrayHasKey('express_pay_transaction', $byName);
        $this->assertArrayHasKey('express_pay_checkout_success', $byName);
        $this->assertSame('找朋友代付', $byName['friend_help_pay']['label_zh'] ?? null);
        $this->assertSame('分享给朋友', $byName['selection_share']['label_zh'] ?? null);
        $this->assertSame('快捷购买', $byName['quick_buy']['label_zh'] ?? null);
        $this->assertSame('快捷支付', $byName['express_pay']['label_zh'] ?? null);
        $this->assertSame('快捷支付确认方式', $byName['express_pay_confirmed']['label_zh'] ?? null);
        $this->assertSame('快捷支付交易', $byName['express_pay_transaction']['label_zh'] ?? null);
        $this->assertSame('share', $byName['selection_share']['ga4_event'] ?? null);
        $this->assertSame('add_payment_info', $byName['express_pay']['ga4_event'] ?? null);
        $this->assertSame(['currency', 'value', 'items', 'payment_type'], $byName['express_pay']['required_params'] ?? null);
        $this->assertSame('begin_checkout', $byName['express_pay_started']['ga4_event'] ?? null);
        $this->assertSame(['currency', 'value', 'items'], $byName['express_pay_started']['required_params'] ?? null);
        $this->assertSame('add_shipping_info', $byName['express_pay_confirmed']['ga4_event'] ?? null);
        $this->assertSame(['currency', 'value', 'items', 'shipping_tier'], $byName['express_pay_confirmed']['required_params'] ?? null);
        $this->assertTrue(!empty($byName['express_pay_transaction']['skip_gtm_push']));
        $this->assertSame('purchase', $byName['express_pay_checkout_success']['ga4_event'] ?? null);
        $this->assertSame(['checkout'], $byName['add_to_cart']['page_scopes'] ?? null);
        $this->assertSame(['checkout'], $byName['view_item']['page_scopes'] ?? null);
        $this->assertSame(['checkout'], $byName['begin_checkout']['page_scopes'] ?? null);
    }

    public function testGa4RecommendedEcommerceEventsExistAsIdentity(): void
    {
        $dict = new EventDictionaryService();
        $byName = [];
        foreach ($dict->getEvents() as $entry) {
            if (\is_array($entry) && isset($entry['weline_event'])) {
                $byName[(string)$entry['weline_event']] = $entry;
            }
        }
        $identity = [
            'view_item',
            'view_item_list',
            'select_item',
            'view_cart',
            'add_to_cart',
            'remove_from_cart',
            'add_to_wishlist',
            'begin_checkout',
            'add_shipping_info',
            'add_payment_info',
            'purchase',
            'refund',
            'view_promotion',
            'select_promotion',
            'share',
            'search',
            'select_content',
            'login',
            'sign_up',
            'generate_lead',
            'page_view',
        ];
        foreach ($identity as $name) {
            $this->assertArrayHasKey($name, $byName, $name);
            $this->assertSame($name, $byName[$name]['ga4_event'] ?? null, $name);
            $this->assertTrue(!empty($byName[$name]['google_recommended']), $name);
        }
        $ga4 = new \Weline\Visitor\Extends\Module\Weline_Visitor\PixelEventVendor\Ga4Vendor();
        $map = $ga4->getDefaultEventMap();
        $this->assertSame('add_payment_info', $map['express_pay'] ?? null);
        $this->assertSame('purchase', $map['express_pay_checkout_success'] ?? null);
        $this->assertSame('purchase', $map['purchase'] ?? null);
    }

    public function testPixelGa4ParamsAliasPaymentAndShipping(): void
    {
        $js = (string) file_get_contents(dirname(__DIR__, 3) . '/view/statics/js/pixel.js');
        $this->assertStringContainsString('params.payment_type', $js);
        $this->assertStringContainsString('params.shipping_tier', $js);
        $this->assertStringContainsString('transaction_no', $js);
        $this->assertStringContainsString("'payment_type'", $js);
        $this->assertStringContainsString('express_pay_confirmed', $js);
        $phtml = (string) file_get_contents(dirname(__DIR__, 3) . '/view/taglib/js/pixel.phtml');
        $this->assertStringContainsString('params.payment_type', $phtml);
        $this->assertStringContainsString('params.shipping_tier', $phtml);
    }
}

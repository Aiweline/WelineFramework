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
        $this->assertSame(['checkout'], $byName['add_to_cart']['page_scopes'] ?? null);
        $this->assertSame(['checkout'], $byName['view_item']['page_scopes'] ?? null);
        $this->assertSame(['checkout'], $byName['begin_checkout']['page_scopes'] ?? null);
    }
}

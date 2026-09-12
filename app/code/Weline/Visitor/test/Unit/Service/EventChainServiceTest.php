<?php

declare(strict_types=1);

namespace Weline\Visitor\test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Visitor\Service\EventChainService;
use Weline\Visitor\Service\EventDictionaryService;

final class EventChainServiceTest extends TestCase
{
    public function testNormalizeAndMatchOrderedSteps(): void
    {
        $svc = new EventChainService(new EventDictionaryService());
        $chain = $svc->chainFromPickerSteps('Demo-Funnel_Complete!!', [
            ['type' => 'page', 'path' => '/'],
            ['type' => 'track', 'event' => 'chain_demo_step_b'],
        ], 'demo_cross_page', '演示');
        self::assertNotNull($chain);
        self::assertSame('demo_cross_page', $chain['id']);
        self::assertSame('demo_funnel_complete', $chain['complete_event']);
        self::assertCount(2, $chain['steps']);

        self::assertTrue($svc->stepMatches($chain['steps'][0], [
            'type' => 'page',
            'path' => '/',
        ]));
        self::assertFalse($svc->stepMatches($chain['steps'][0], [
            'type' => 'page',
            'path' => '/cart',
        ]));
        self::assertFalse($svc->stepMatches($chain['steps'][1], [
            'type' => 'track',
            'event' => 'cta_click',
        ]));
        self::assertTrue($svc->stepMatches($chain['steps'][1], [
            'type' => 'track',
            'event' => 'chain_demo_step_b',
        ]));
    }

    public function testAnyPageStepMatchesAllPaths(): void
    {
        $svc = new EventChainService(new EventDictionaryService());
        $chain = $svc->chainFromPickerSteps('demo_funnel_complete', [
            ['type' => 'page', 'label' => 'any'],
            ['type' => 'track', 'event' => 'chain_demo_step_b'],
        ]);
        self::assertNotNull($chain);
        self::assertTrue($svc->stepMatches($chain['steps'][0], [
            'type' => 'page',
            'path' => '/product/x',
        ]));
    }

    public function testRuntimeFragmentShapeInSource(): void
    {
        $base = \dirname(__DIR__, 3);
        $src = (string)\file_get_contents($base . '/Service/VisitorTrackingConfig.php');
        self::assertStringContainsString("'eventChains'", $src);
        self::assertStringContainsString('buildConfigScopeContext', $src);
        self::assertStringContainsString('EventChainService', $src);
        self::assertStringContainsString(
            'pixel-event-chains.js',
            (string)\file_get_contents($base . '/view/hooks/Weline_Theme/frontend/layouts/base/body-end.phtml')
        );
        $js = (string)\file_get_contents($base . '/view/statics/js/pixel-event-chains.js');
        self::assertStringContainsString('路径回放', $js);
        self::assertStringContainsString('weline_evch_ver', $js);
        self::assertStringContainsString('funnel_complete', $js);
        self::assertStringContainsString('__event_chain_complete', $js);
        self::assertStringContainsString('严格按序匹配', $js);
        self::assertStringContainsString('WelineEventChains', $js);
    }

    public function testUnlabeledClickStepFallsBackToPagePath(): void
    {
        $svc = new EventChainService(new EventDictionaryService());
        $chain = $svc->chainFromPickerSteps('funnel_done', [
            ['type' => 'page', 'path' => '/a'],
            ['type' => 'click', 'path' => '/a', 'label' => 'buy'],
            ['type' => 'page', 'path' => '/b'],
        ], 'chain_path_replay', 'funnel_done');
        self::assertNotNull($chain);
        self::assertSame('funnel_done', $chain['complete_event']);
        self::assertSame('page', $chain['steps'][1]['type']);
        self::assertSame('/a', $chain['steps'][1]['path']);
        self::assertTrue($svc->stepMatches($chain['steps'][1], ['type' => 'page', 'path' => '/a']));
    }

    public function testClickStepPathReplayAdvancesWithoutReselect(): void
    {
        $svc = new EventChainService(new EventDictionaryService());
        $chain = $svc->chainFromPickerSteps('cart_done', [
            ['type' => 'page', 'path' => '/'],
            ['type' => 'click', 'event' => 'add_to_cart', 'path' => '/product/x', 'selector' => '.buy'],
        ], 'chain_click_path', 'cart_done');
        self::assertNotNull($chain);
        self::assertSame('click', $chain['steps'][1]['type']);
        self::assertTrue($svc->stepMatches($chain['steps'][1], ['type' => 'page', 'path' => '/product/x']));
        self::assertTrue($svc->stepMatches($chain['steps'][1], [
            'type' => 'click',
            'event' => 'add_to_cart',
            'selector' => '.buy',
        ]));
    }

    public function testAllAddCartStyleOrderedCompleteRequiresStrictSequence(): void
    {
        $svc = new EventChainService(new EventDictionaryService());
        $chain = $svc->chainFromPickerSteps('all_add_cart', [
            ['type' => 'page', 'path' => '/'],
            ['type' => 'track', 'event' => 'route_click'],
            ['type' => 'page', 'path' => '/products'],
            ['type' => 'track', 'event' => 'add_to_cart'],
        ], 'chain_all_add_cart_min', 'all_add_cart');
        self::assertNotNull($chain);
        self::assertSame('all_add_cart', $chain['complete_event']);
        self::assertTrue($svc->stepMatches($chain['steps'][0], ['type' => 'page', 'path' => '/']));
        self::assertFalse($svc->stepMatches($chain['steps'][1], ['type' => 'track', 'event' => 'add_to_cart']));
        self::assertTrue($svc->stepMatches($chain['steps'][1], ['type' => 'track', 'event' => 'route_click']));
        self::assertTrue($svc->stepMatches($chain['steps'][3], ['type' => 'track', 'event' => 'add_to_cart']));
    }

    public function testPayloadMarksChainCompleteFlag(): void
    {
        $svc = new EventChainService(new EventDictionaryService());
        self::assertFalse($svc->payloadMarksChainComplete(['eventName' => 'all_add_cart']));
        self::assertTrue($svc->payloadMarksChainComplete(['__event_chain_complete' => true]));
        self::assertTrue($svc->payloadMarksChainComplete([
            'additionalInfo' => ['meta' => ['funnel_complete' => true]],
        ]));
        self::assertTrue($svc->payloadMarksChainComplete([
            'additionalInfo' => ['meta' => ['__event_chain_complete' => 1]],
        ]));
    }

    public function testPixelEventServiceSkipsUnsealedCompleteEvents(): void
    {
        $src = (string)\file_get_contents(\dirname(__DIR__, 3) . '/Service/PixelEventService.php');
        self::assertStringContainsString('chain_complete_required', $src);
        self::assertStringContainsString('shouldSkipUnsealedChainComplete', $src);
        $js = (string)\file_get_contents(\dirname(__DIR__, 3) . '/view/statics/js/pixel-event-chains.js');
        self::assertStringContainsString('skip direct complete track', $js);
        self::assertStringContainsString('completeEventSet', $js);
        self::assertStringContainsString('进度只在客户端', $js);
    }
}

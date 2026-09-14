<?php

declare(strict_types=1);

namespace Weline\Visitor\test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Visitor\Service\EventChainCollector;
use Weline\Visitor\Service\EventChainService;
use Weline\Visitor\Service\EventDictionaryService;

final class EventChainModuleRegisterContractTest extends TestCase
{
    public function testCollectorEventNameConstant(): void
    {
        self::assertSame('Weline_Visitor::event_chain_collect', EventChainCollector::EVENT_COLLECT);
    }

    public function testExtendsDeclaresEventChainProvider(): void
    {
        $extends = require dirname(__DIR__, 3) . '/extends.php';
        self::assertArrayHasKey('EventChainProvider', $extends['extends'] ?? []);
        self::assertSame(
            \Weline\Visitor\Interface\EventChainProviderInterface::class,
            $extends['extends']['EventChainProvider']['interface'] ?? null
        );
    }

    public function testDocDescribesCollectEvent(): void
    {
        $doc = (string) file_get_contents(dirname(__DIR__, 3) . '/doc/event/事件链注册.md');
        self::assertStringContainsString('Weline_Visitor::event_chain_collect', $doc);
        self::assertStringContainsString('EventChainProviderInterface', $doc);
        self::assertStringContainsString('complete_event', $doc);
    }

    public function testServiceMergesRegisteredChainsById(): void
    {
        $dict = new EventDictionaryService();
        $collector = new class extends EventChainCollector {
            public function collect(int $websiteId = 0): array
            {
                return [
                    [
                        'id' => 'checkout_express_pay',
                        'name' => '快捷支付结账',
                        'owner' => 'Weline_Checkout',
                        'complete_event' => 'express_pay_checkout_success',
                        'steps' => [
                            ['type' => 'track', 'event' => 'express_pay'],
                            ['type' => 'track', 'event' => 'express_pay_started'],
                            ['type' => 'track', 'event' => 'checkout_success'],
                        ],
                    ],
                ];
            }
        };
        $svc = new EventChainService($dict, null, null, $collector);
        $bundle = $svc->getBundle(0);
        self::assertNotEmpty($bundle['chains']);
        $ids = array_map(static fn ($c) => (string)($c['id'] ?? ''), $bundle['chains']);
        self::assertContains('checkout_express_pay', $ids);
        self::assertTrue($svc->isRegisteredCompleteEvent(0, 'express_pay_checkout_success'));
        self::assertFalse($svc->isRegisteredCompleteEvent(0, 'checkout_success'));
    }
}

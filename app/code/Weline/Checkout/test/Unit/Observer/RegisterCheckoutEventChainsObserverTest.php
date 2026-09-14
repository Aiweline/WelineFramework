<?php

declare(strict_types=1);

namespace Weline\Checkout\Test\Unit\Observer;

use PHPUnit\Framework\TestCase;
use Weline\Checkout\Observer\RegisterCheckoutEventChainsObserver;
use Weline\Framework\Event\Event;

final class RegisterCheckoutEventChainsObserverTest extends TestCase
{
    public function testAppendsFourCheckoutFunnels(): void
    {
        $event = new Event(['website_id' => 0, 'chains' => []]);
        (new RegisterCheckoutEventChainsObserver())->execute($event);
        $chains = $event->getData('chains');
        self::assertIsArray($chains);
        self::assertCount(4, $chains);
        $ids = array_map(static fn ($c) => (string)($c['id'] ?? ''), $chains);
        self::assertSame(
            [
                'checkout_friend_help_pay',
                'checkout_selection_share',
                'checkout_quick_buy',
                'checkout_express_pay',
            ],
            $ids
        );
        foreach ($chains as $chain) {
            self::assertSame('Weline_Checkout', $chain['owner'] ?? null);
            $steps = $chain['steps'] ?? [];
            self::assertIsArray($steps);
            self::assertGreaterThanOrEqual(3, count($steps));
            $last = $steps[count($steps) - 1];
            self::assertSame('checkout_success', $last['event'] ?? null);
            self::assertStringEndsWith('_checkout_success', (string)($chain['complete_event'] ?? ''));
            self::assertNotSame('checkout_success', $chain['complete_event'] ?? null);
        }
    }

    public function testEventXmlRegistersObserver(): void
    {
        $xml = (string) file_get_contents(dirname(__DIR__, 3) . '/etc/event.xml');
        self::assertStringContainsString('Weline_Visitor::event_chain_collect', $xml);
        self::assertStringContainsString('RegisterCheckoutEventChainsObserver', $xml);
    }
}

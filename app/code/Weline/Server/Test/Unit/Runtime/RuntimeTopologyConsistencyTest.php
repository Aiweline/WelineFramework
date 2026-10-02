<?php
declare(strict_types=1);

namespace Weline\Server\Test\Unit\Runtime;

use PHPUnit\Framework\TestCase;
use Weline\Server\Service\Runtime\EffectiveTopology;
use Weline\Server\Service\Runtime\RequestedTopology;
use Weline\Server\Service\Runtime\RuntimeSelection;
use Weline\Server\Service\Runtime\RuntimeTopologyConsistency;

final class RuntimeTopologyConsistencyTest extends TestCase
{
    public function testAcceptsIdenticalPreflightAndEffectiveTopology(): void
    {
        self::assertTrue(RuntimeTopologyConsistency::accepts(
            $this->selection(RequestedTopology::Auto, EffectiveTopology::Direct, ['posix_auto_direct']),
            RequestedTopology::Auto,
            EffectiveTopology::Direct,
        ));
    }

    public function testAcceptsOnlyTheAutoDirectToDispatcherDowngrade(): void
    {
        self::assertTrue(RuntimeTopologyConsistency::accepts(
            $this->selection(
                RequestedTopology::Auto,
                EffectiveTopology::Dispatcher,
                [RuntimeTopologyConsistency::AUTO_DISPATCHER_FALLBACK_REASON_CODE],
            ),
            RequestedTopology::Auto,
            EffectiveTopology::Direct,
        ));
    }

    public function testRejectsDispatcherDowngradeWithoutTheFallbackReasonCode(): void
    {
        self::assertFalse(RuntimeTopologyConsistency::accepts(
            $this->selection(RequestedTopology::Auto, EffectiveTopology::Dispatcher, ['explicit_dispatcher']),
            RequestedTopology::Auto,
            EffectiveTopology::Direct,
        ));
    }

    public function testRejectsDispatcherDowngradeForExplicitDirectRequests(): void
    {
        self::assertFalse(RuntimeTopologyConsistency::accepts(
            $this->selection(
                RequestedTopology::Direct,
                EffectiveTopology::Dispatcher,
                [RuntimeTopologyConsistency::AUTO_DISPATCHER_FALLBACK_REASON_CODE],
            ),
            RequestedTopology::Direct,
            EffectiveTopology::Direct,
        ));
    }

    /**
     * @param string[] $reasonCodes
     */
    private function selection(
        RequestedTopology $requested,
        EffectiveTopology $effective,
        array $reasonCodes
    ): RuntimeSelection {
        return new RuntimeSelection(
            requestedTopology: $requested,
            effectiveTopology: $effective,
            source: 'test',
            osFamily: 'Darwin',
            eventLoopDriver: 'event',
            sslEngine: 'none',
            listenerMode: $effective->isDirect() ? 'shared_fd' : 'single',
            policyCompatible: true,
            reasonCodes: $reasonCodes,
            reason: 'test',
        );
    }
}

<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\IPC;

use PHPUnit\Framework\TestCase;
use Weline\Server\IPC\ControlMessage;
use Weline\Server\Service\Runtime\WorkerReadinessState;

final class ControlMessageReadyTest extends TestCase
{
    protected function tearDown(): void
    {
        WorkerReadinessState::reset('');
    }

    public function testGatewayFallbackReadyCarriesFailClosedWorkerEvidence(): void
    {
        $policyDigest = \str_repeat('a', 64);
        WorkerReadinessState::reset('direct');
        WorkerReadinessState::markPolicyLoaded($policyDigest);
        WorkerReadinessState::markListenerBound(
            false,
            'select',
            'openssl',
            'shared_fd',
            3,
        );

        $message = ControlMessage::decode(ControlMessage::ready(
            ControlMessage::ROLE_GATEWAY_FALLBACK,
            1,
            24567,
            7,
            'launch-1',
            'launch-1',
            'gateway_fallback#1',
            'launch-1',
            7,
        ));

        self::assertSame(
            WorkerReadinessState::READINESS_PROTOCOL_VERSION,
            $message['readiness_protocol_version'] ?? null,
        );
        self::assertSame('direct', $message['topology'] ?? null);
        self::assertSame($policyDigest, $message['policy_digest'] ?? null);
        self::assertSame('shared_fd', $message['listen_capabilities']['mode'] ?? null);
        self::assertTrue((bool)($message['listen_capabilities']['bound'] ?? false));
        self::assertTrue((bool)($message['listen_capabilities']['shared_listener'] ?? false));
        self::assertSame(3, $message['listen_capabilities']['inherited_fd'] ?? null);
    }
}

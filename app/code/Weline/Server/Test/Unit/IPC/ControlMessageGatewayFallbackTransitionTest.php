<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\IPC;

use PHPUnit\Framework\TestCase;
use Weline\Server\IPC\ControlMessage;

final class ControlMessageGatewayFallbackTransitionTest extends TestCase
{
    public function testDrainTransitionCarriesAnExactDigestBoundIdentity(): void
    {
        self::assertTrue(
            \method_exists(ControlMessage::class, 'gatewayFallbackListenerActionDigest'),
            'The fallback listener protocol must expose one canonical action digest.',
        );
        self::assertTrue(
            \method_exists(ControlMessage::class, 'validateGatewayFallbackListenerTransition'),
            'The Worker must be able to validate the complete transition envelope.',
        );

        $identity = $this->identity();
        $transitionId = \str_repeat('a', 32);
        $digest = ControlMessage::gatewayFallbackListenerActionDigest(
            ControlMessage::GATEWAY_FALLBACK_LISTENER_ACTION_DRAIN,
            ControlMessage::GATEWAY_FALLBACK_LISTENER_STATE_DRAINING,
            $transitionId,
            '',
            $identity,
        );
        $message = ControlMessage::decode(ControlMessage::gatewayFallbackListenerTransition(
            ControlMessage::GATEWAY_FALLBACK_LISTENER_ACTION_DRAIN,
            ControlMessage::GATEWAY_FALLBACK_LISTENER_STATE_DRAINING,
            $transitionId,
            $digest,
            '',
            $identity,
            300,
        ));

        self::assertSame(ControlMessage::TYPE_DRAIN, $message['type']);
        self::assertSame('wls-gateway-fallback-listener/1', $message['protocol']);
        self::assertSame('DRAIN', $message['action']);
        self::assertSame('DRAINING', $message['target_listener_state']);
        self::assertArrayNotHasKey('listener_state', $message);
        self::assertSame($transitionId, $message['transition_id']);
        self::assertSame($digest, $message['action_digest']);
        self::assertSame('', $message['predecessor_action_digest']);
        self::assertSame($identity, $message['identity']);
        self::assertSame(300, $message['drain_timeout_sec']);
        self::assertSame(
            $message,
            ControlMessage::validateGatewayFallbackListenerTransition($message),
        );
    }

    public function testUndrainMustReferenceTheAcknowledgedDrainDigest(): void
    {
        if (!\method_exists(ControlMessage::class, 'gatewayFallbackListenerActionDigest')) {
            self::fail('The fallback listener action digest is not implemented.');
        }

        $identity = $this->identity();
        $transitionId = \str_repeat('b', 32);
        $drainDigest = ControlMessage::gatewayFallbackListenerActionDigest(
            ControlMessage::GATEWAY_FALLBACK_LISTENER_ACTION_DRAIN,
            ControlMessage::GATEWAY_FALLBACK_LISTENER_STATE_DRAINING,
            $transitionId,
            '',
            $identity,
        );
        $undrainDigest = ControlMessage::gatewayFallbackListenerActionDigest(
            ControlMessage::GATEWAY_FALLBACK_LISTENER_ACTION_UNDRAIN,
            ControlMessage::GATEWAY_FALLBACK_LISTENER_STATE_ACTIVE,
            $transitionId,
            $drainDigest,
            $identity,
        );
        self::assertNotSame(
            $drainDigest,
            $undrainDigest,
            'DRAIN and UNDRAIN share one transaction id but never one action digest.',
        );

        $message = ControlMessage::decode(ControlMessage::gatewayFallbackListenerTransition(
            ControlMessage::GATEWAY_FALLBACK_LISTENER_ACTION_UNDRAIN,
            ControlMessage::GATEWAY_FALLBACK_LISTENER_STATE_ACTIVE,
            $transitionId,
            $undrainDigest,
            $drainDigest,
            $identity,
        ));

        self::assertSame(ControlMessage::TYPE_UNDRAIN, $message['type']);
        self::assertSame($drainDigest, $message['predecessor_action_digest']);
        self::assertSame(
            $message,
            ControlMessage::validateGatewayFallbackListenerTransition($message),
        );
    }

    public function testTransitionRejectsAnActionDigestMutation(): void
    {
        if (!\method_exists(ControlMessage::class, 'gatewayFallbackListenerActionDigest')) {
            self::fail('The fallback listener action digest is not implemented.');
        }

        $this->expectException(\InvalidArgumentException::class);
        ControlMessage::gatewayFallbackListenerTransition(
            ControlMessage::GATEWAY_FALLBACK_LISTENER_ACTION_DRAIN,
            ControlMessage::GATEWAY_FALLBACK_LISTENER_STATE_DRAINING,
            \str_repeat('c', 32),
            \str_repeat('d', 64),
            '',
            $this->identity(),
            300,
        );
    }

    public function testTransitionRejectsUnexpectedIdentityFields(): void
    {
        if (!\method_exists(ControlMessage::class, 'gatewayFallbackListenerActionDigest')) {
            self::fail('The fallback listener action digest is not implemented.');
        }

        $identity = $this->identity();
        $identity['untrusted_extension'] = 'accepted-by-mistake';
        $this->expectException(\InvalidArgumentException::class);
        ControlMessage::gatewayFallbackListenerActionDigest(
            ControlMessage::GATEWAY_FALLBACK_LISTENER_ACTION_DRAIN,
            ControlMessage::GATEWAY_FALLBACK_LISTENER_STATE_DRAINING,
            \str_repeat('e', 32),
            '',
            $identity,
        );
    }

    public function testAcknowledgementEchoesTheRequestAndReportsActualState(): void
    {
        if (!\method_exists(ControlMessage::class, 'gatewayFallbackListenerActionDigest')) {
            self::fail('The fallback listener action digest is not implemented.');
        }

        $identity = $this->identity();
        $transitionId = \str_repeat('f', 32);
        $digest = ControlMessage::gatewayFallbackListenerActionDigest(
            ControlMessage::GATEWAY_FALLBACK_LISTENER_ACTION_DRAIN,
            ControlMessage::GATEWAY_FALLBACK_LISTENER_STATE_DRAINING,
            $transitionId,
            '',
            $identity,
        );
        $ack = ControlMessage::decode(ControlMessage::gatewayFallbackListenerAck(
            ControlMessage::GATEWAY_FALLBACK_LISTENER_ACTION_DRAIN,
            ControlMessage::GATEWAY_FALLBACK_LISTENER_STATE_DRAINING,
            ControlMessage::GATEWAY_FALLBACK_LISTENER_STATE_ACTIVE,
            $transitionId,
            $digest,
            '',
            $identity,
            false,
            'listener_not_transitioned',
        ));

        self::assertSame(
            ControlMessage::TYPE_GATEWAY_FALLBACK_LISTENER_ACK,
            $ack['type'],
        );
        self::assertSame('DRAINING', $ack['target_listener_state']);
        self::assertSame('ACTIVE', $ack['listener_state']);
        self::assertSame($identity, $ack['identity']);
        self::assertFalse($ack['success']);
        self::assertSame(
            $ack,
            ControlMessage::validateGatewayFallbackListenerAck($ack),
        );
    }

    public function testSuccessfulAcknowledgementCannotClaimTheWrongActualState(): void
    {
        $identity = $this->identity();
        $transitionId = \str_repeat('0', 32);
        $digest = ControlMessage::gatewayFallbackListenerActionDigest(
            ControlMessage::GATEWAY_FALLBACK_LISTENER_ACTION_DRAIN,
            ControlMessage::GATEWAY_FALLBACK_LISTENER_STATE_DRAINING,
            $transitionId,
            '',
            $identity,
        );

        $this->expectException(\InvalidArgumentException::class);
        ControlMessage::gatewayFallbackListenerAck(
            ControlMessage::GATEWAY_FALLBACK_LISTENER_ACTION_DRAIN,
            ControlMessage::GATEWAY_FALLBACK_LISTENER_STATE_DRAINING,
            ControlMessage::GATEWAY_FALLBACK_LISTENER_STATE_ACTIVE,
            $transitionId,
            $digest,
            '',
            $identity,
            true,
        );
    }

    public function testTransitionValidatorRejectsUnknownEnvelopeFields(): void
    {
        $identity = $this->identity();
        $transitionId = \str_repeat('a', 32);
        $digest = ControlMessage::gatewayFallbackListenerActionDigest(
            ControlMessage::GATEWAY_FALLBACK_LISTENER_ACTION_DRAIN,
            ControlMessage::GATEWAY_FALLBACK_LISTENER_STATE_DRAINING,
            $transitionId,
            '',
            $identity,
        );
        $message = ControlMessage::decode(ControlMessage::gatewayFallbackListenerTransition(
            ControlMessage::GATEWAY_FALLBACK_LISTENER_ACTION_DRAIN,
            ControlMessage::GATEWAY_FALLBACK_LISTENER_STATE_DRAINING,
            $transitionId,
            $digest,
            '',
            $identity,
            300,
        ));
        $message['untrusted_extension'] = true;

        $this->expectException(\InvalidArgumentException::class);
        ControlMessage::validateGatewayFallbackListenerTransition($message);
    }

    /** @return array<string,mixed> */
    private function identity(): array
    {
        $pidNamespaceId = PHP_OS_FAMILY === 'Linux'
            ? 'pid:[4026531836]'
            : '';

        return [
            'schema' => 'wls-gateway-fallback-listener/1',
            'project_uuid' => '123e4567-e89b-42d3-a456-426614174099',
            'wls_instance' => 'ai-test-fallback-transition',
            'role' => 'gateway_fallback',
            'slot_id' => 'gateway_fallback#1',
            'service_generation' => 9,
            'service_lease_id' => \str_repeat('1', 32),
            'worker_pid' => 22001,
            'worker_process_birth' => \str_repeat('2', 64),
            'worker_pid_namespace_id' => $pidNamespaceId,
            'worker_launch_id' => \str_repeat('3', 32),
            'master_pid' => 22000,
            'master_epoch' => 7,
            'master_launch_id' => \str_repeat('4', 32),
            'master_process_birth' => \str_repeat('5', 64),
            'master_pid_namespace_id' => $pidNamespaceId,
            'port' => 24567,
            'host_lease_instance' => 'ai-test-fallback-transition-gateway-fallback',
            'host_lease_id' => \str_repeat('6', 32),
            'host_boot_id' => \str_repeat('7', 64),
            'bind_host' => '127.0.0.1',
            'listener_proof_digest' => \str_repeat('8', 64),
            'listener_transport' => 'posix_inherited_fd',
            'listener_receipt_digest' => \str_repeat('9', 64),
        ];
    }
}

<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Event\Async;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Event\Async\AsyncErrorRedactor;
use Weline\Framework\Event\Async\DeliveryRetryPolicy;
use Weline\Framework\Event\Async\DeliveryStateMachine;
use Weline\Framework\Model\Event\Delivery;

/** Plan coverage: QUEUE03, UI02, UI03. */
final class DeliveryPolicyContractTest extends TestCase
{
    public function testQueue03RetryAttemptsAndDeterministicJitterStayWithinPolicy(): void
    {
        $policy = new DeliveryRetryPolicy($this->createStub(Delivery::class));

        self::assertSame(1, $policy->maxAttempts('none'));
        self::assertSame(6, $policy->maxAttempts('standard'));
        self::assertFalse($policy->shouldRetry('none', 1));
        self::assertTrue($policy->shouldRetry('standard', 5));
        self::assertFalse($policy->shouldRetry('standard', 6));

        $attemptBases = [1 => 30, 2 => 120, 3 => 600, 4 => 1800, 5 => 7200, 6 => 7200];
        foreach ($attemptBases as $attempt => $base) {
            $delay = $policy->retryDelaySeconds(2005, $attempt);
            self::assertSame($delay, $policy->retryDelaySeconds(2005, $attempt));
            self::assertGreaterThanOrEqual((int)round($base * 0.8), $delay, 'attempt ' . $attempt);
            self::assertLessThanOrEqual((int)round($base * 1.2), $delay, 'attempt ' . $attempt);
        }

        $now = 1_784_776_000;
        self::assertSame(
            gmdate('Y-m-d H:i:s', $now + $policy->retryDelaySeconds(2005, 3)),
            $policy->nextRetryAt(2005, 3, $now),
        );
    }

    public function testQueue03TransportProvisionBackoffIsBounded(): void
    {
        $policy = new DeliveryRetryPolicy($this->createStub(Delivery::class));

        self::assertSame(5, $policy->transportDelaySeconds(0));
        self::assertSame(5, $policy->transportDelaySeconds(1));
        self::assertSame(30, $policy->transportDelaySeconds(2));
        self::assertSame(120, $policy->transportDelaySeconds(3));
        self::assertSame(600, $policy->transportDelaySeconds(4));
        self::assertSame(600, $policy->transportDelaySeconds(99));
    }

    public function testUi02ErrorRedactorRemovesCredentialShapesBeforePersistence(): void
    {
        $redactor = new AsyncErrorRedactor();
        $input = implode("\n", [
            'Authorization: Bearer abc.def.ghi',
            'Cookie: session=secret-cookie; csrf=secret-csrf',
            'https://deploy-user:deploy-password@example.test/path',
            '{"api_token":"secret-json","safe":"visible"}',
            'password=secret-query&message=visible',
            "-----BEGIN PRIVATE KEY-----\nsecret-pem\n-----END PRIVATE KEY-----",
        ]);

        $redacted = $redactor->redact($input);

        foreach (['abc.def.ghi', 'secret-cookie', 'secret-csrf', 'deploy-password', 'secret-json', 'secret-query', 'secret-pem'] as $secret) {
            self::assertStringNotContainsString($secret, $redacted);
        }
        self::assertStringContainsString('Authorization: [redacted]', $redacted);
        self::assertStringContainsString('"api_token":"[redacted]"', $redacted);
        self::assertStringContainsString('message=visible', $redacted);
        self::assertStringContainsString('[redacted-pem]', $redacted);
        self::assertSame('abcd', $redactor->redact('abcdefgh', 4));
    }

    public function testUi03TerminalStateContractKeepsDeadAndSupersededImmutable(): void
    {
        self::assertSame(
            ['succeeded', 'dead', 'superseded', 'skipped'],
            DeliveryStateMachine::TERMINAL,
        );
        self::assertContains('dead', DeliveryStateMachine::TERMINAL);
        self::assertContains('superseded', DeliveryStateMachine::TERMINAL);
        self::assertSame(
            'transport_termination_unconfirmed',
            DeliveryStateMachine::TERMINAL_REASON_TRANSPORT_TERMINATION_UNCONFIRMED,
        );
    }
}

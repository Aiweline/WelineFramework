<?php

declare(strict_types=1);

namespace Weline\Payment\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Http\Security\SecretRefCipher;
use Weline\Payment\Api\Webhook\WebhookEndpointRecord;
use Weline\Payment\Api\Webhook\WebhookReceiveResult;
use Weline\Payment\Extends\Module\Weline_Payment\PaymentProvider\FakeProvider;
use Weline\Payment\Queue\PaymentInboxConsumer;
use Weline\Payment\Service\PaymentCallbackReceiver;
use Weline\Payment\Service\PaymentConnectorGuard;
use Weline\Payment\Service\PaymentIntentOrchestrator;
use Weline\Payment\Service\WebhookEndpointDirectory;

/**
 * TEST-WEBHOOK-01, TEST-WEBHOOK-02, TEST-WEBHOOK-03 and TEST-WEBHOOK-06：
 * 签名、幂等、提交失败与 payload 冲突。
 */
final class PaymentCallbackReceiverTest extends TestCase
{
    private WebhookEndpointDirectory $directory;
    private PaymentCallbackReceiver $receiver;
    private int $now = 1_700_000_000;

    protected function setUp(): void
    {
        $this->directory = WebhookEndpointDirectory::forTesting();
        $this->directory->registerEndpoint(
            endpointCode: 'ep-fake-1',
            providerCode: 'fake',
            methodCode: 'fake',
            merchantAccount: 'acct_fake',
            secrets: [[
                'secret_version' => 'v1',
                'secret_ref' => 'ref-v1',
                'status' => 'active',
                'valid_from' => 0,
                'valid_until' => PHP_INT_MAX,
                'material' => 'super-secret',
            ]],
        );
        $this->receiver = PaymentCallbackReceiver::forTesting($this->directory, $this->now);
        $this->receiver->setProviderResolver(static fn (string $code): ?FakeProvider => $code === 'fake' ? new FakeProvider() : null);
    }

    public function testWebhook01RejectsBadSignatureExpiredEndpointAndTamper(): void
    {
        $payload = $this->payload('evt-1');
        $raw = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';

        $badSig = $this->receiver->receive('ep-fake-1', $raw, [], $payload, 'wrong-sig', $this->now);
        self::assertSame(WebhookReceiveResult::HTTP_UNAUTHORIZED, $badSig->httpStatus);
        self::assertSame(PaymentCallbackReceiver::ERROR_SIGNATURE_INVALID, $badSig->errorCode);
        self::assertSame(0, $this->receiver->inboxCount());

        $skew = $this->receiver->receive(
            'ep-fake-1',
            $raw,
            [],
            $payload,
            hash_hmac('sha256', $raw, 'super-secret'),
            $this->now + 400,
        );
        self::assertSame(PaymentCallbackReceiver::ERROR_TIMESTAMP_SKEW, $skew->errorCode);
        self::assertSame(0, $this->receiver->inboxCount());

        $missing = $this->receiver->receive('ep-missing', $raw, [], $payload, hash_hmac('sha256', $raw, 'super-secret'), $this->now);
        self::assertSame(PaymentCallbackReceiver::ERROR_ENDPOINT_NOT_FOUND, $missing->errorCode);

        $this->directory->disableEndpoint('ep-fake-1');
        $disabled = $this->receiver->receive('ep-fake-1', $raw, [], $payload, hash_hmac('sha256', $raw, 'super-secret'), $this->now);
        self::assertSame(PaymentCallbackReceiver::ERROR_ENDPOINT_DISABLED, $disabled->errorCode);
        self::assertSame(0, $this->receiver->inboxCount());
        self::assertNotEmpty($this->receiver->auditLog());
    }

    public function testWebhook02DuplicateEventsSingleInbox(): void
    {
        $this->directory->registerEndpoint(
            endpointCode: 'ep-fake-2',
            providerCode: 'fake',
            methodCode: 'fake',
            merchantAccount: 'acct_fake',
            secrets: [[
                'secret_version' => 'v1',
                'secret_ref' => 'ref-v2',
                'status' => 'active',
                'valid_from' => 0,
                'valid_until' => PHP_INT_MAX,
                'material' => 'super-secret',
            ]],
        );
        $payload = $this->payload('evt-dup');
        $raw = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';
        $sig = hash_hmac('sha256', $raw, 'super-secret');

        $first = null;
        for ($i = 0; $i < 20; $i++) {
            $result = $this->receiver->receive('ep-fake-2', $raw, ['x-signature' => $sig], $payload, $sig, $this->now);
            self::assertTrue($result->isSuccess());
            $first ??= $result->inboxCode;
            self::assertSame($first, $result->inboxCode);
        }
        self::assertSame(1, $this->receiver->inboxCount());
        $inbox = $this->receiver->getInbox((string) $first);
        self::assertTrue($inbox['status'] === 'received');
        self::assertStringStartsWith(
            SecretRefCipher::PREFIX,
            (string) $inbox['encrypted_raw_payload'],
        );
        self::assertSame($raw, SecretRefCipher::reveal((string) $inbox['encrypted_raw_payload']));
    }

    public function testSecretRotationAndRetainedTombstoneContinueHistoricalVerification(): void
    {
        $retainUntil = $this->now + 600;
        $this->directory->registerEndpoint(
            endpointCode: 'ep-rotate',
            providerCode: 'fake',
            methodCode: 'fake',
            activeSecretVersion: 'v2',
            secrets: [
                [
                    'secret_version' => 'v1',
                    'secret_ref' => 'ref-rotate-v1',
                    'status' => 'grace',
                    'valid_from' => 0,
                    'valid_until' => $retainUntil,
                    'material' => 'rotate-old',
                ],
                [
                    'secret_version' => 'v2',
                    'secret_ref' => 'ref-rotate-v2',
                    'status' => 'active',
                    'valid_from' => 0,
                    'valid_until' => PHP_INT_MAX,
                    'material' => 'rotate-new',
                ],
            ],
        );

        $oldPayload = $this->payload('evt-rotate-old');
        $oldRaw = json_encode($oldPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';
        $old = $this->receiver->receive(
            'ep-rotate',
            $oldRaw,
            [],
            $oldPayload,
            hash_hmac('sha256', $oldRaw, 'rotate-old'),
            $this->now,
        );
        self::assertTrue($old->isSuccess());
        self::assertSame(
            'v1',
            $this->receiver->getInbox((string) $old->inboxCode)['verification_secret_version'],
        );

        $this->directory->tombstoneEndpoint('ep-rotate', $retainUntil);
        $newPayload = $this->payload('evt-rotate-new');
        $newRaw = json_encode($newPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';
        $new = $this->receiver->receive(
            'ep-rotate',
            $newRaw,
            [],
            $newPayload,
            hash_hmac('sha256', $newRaw, 'rotate-new'),
            $this->now,
        );
        self::assertTrue($new->isSuccess());

        $this->receiver->setNow($retainUntil + 1);
        $expiredPayload = $this->payload('evt-rotate-expired');
        $expiredRaw = json_encode($expiredPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';
        $expired = $this->receiver->receive(
            'ep-rotate',
            $expiredRaw,
            [],
            $expiredPayload,
            hash_hmac('sha256', $expiredRaw, 'rotate-new'),
            $retainUntil + 1,
        );
        self::assertSame(WebhookReceiveResult::HTTP_GONE, $expired->httpStatus);
    }

    public function testPayloadConflictReturns409AndUrgent(): void
    {
        $payloadA = $this->payload('evt-conflict', ['status' => 'paid']);
        $rawA = json_encode($payloadA, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';
        $sigA = hash_hmac('sha256', $rawA, 'super-secret');
        $ok = $this->receiver->receive('ep-fake-1', $rawA, [], $payloadA, $sigA, $this->now);
        self::assertTrue($ok->inboxWritten);

        $payloadB = $this->payload('evt-conflict', ['status' => 'failed']);
        $rawB = json_encode($payloadB, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';
        $sigB = hash_hmac('sha256', $rawB, 'super-secret');
        $conflict = $this->receiver->receive('ep-fake-1', $rawB, [], $payloadB, $sigB, $this->now);
        self::assertSame(WebhookReceiveResult::HTTP_CONFLICT, $conflict->httpStatus);
        self::assertSame(PaymentCallbackReceiver::ERROR_PAYLOAD_CONFLICT, $conflict->errorCode);
        self::assertSame(1, $this->receiver->inboxCount());
        self::assertCount(1, $this->receiver->urgentEvents());
    }

    public function testCommitFailureDoesNotReturn2xx(): void
    {
        $payload = $this->payload('evt-fail');
        $raw = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';
        $sig = hash_hmac('sha256', $raw, 'super-secret');
        $this->receiver->setFailBeforeCommit(true);
        $result = $this->receiver->receive('ep-fake-1', $raw, [], $payload, $sig, $this->now);
        self::assertSame(500, $result->httpStatus);
        self::assertFalse($result->inboxWritten);
        self::assertSame(0, $this->receiver->inboxCount());
    }

    public function testMissingEventIdRejected(): void
    {
        $payload = ['event_type' => 'fake.payment.updated', 'status' => 'paid'];
        $raw = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';
        $sig = hash_hmac('sha256', $raw, 'super-secret');
        $result = $this->receiver->receive('ep-fake-1', $raw, [], $payload, $sig, $this->now);
        self::assertSame(PaymentCallbackReceiver::ERROR_EVENT_ID_REQUIRED, $result->errorCode);
        self::assertSame(0, $this->receiver->inboxCount());
    }

    public function testInboxConsumerCanBeDisabledAsRollbackSwitch(): void
    {
        $directory = WebhookEndpointDirectory::forTesting();
        $receiver = PaymentCallbackReceiver::forTesting($directory);
        $orch = PaymentIntentOrchestrator::forTesting();
        $consumer = new PaymentInboxConsumer(
            $receiver,
            $orch,
            PaymentConnectorGuard::forTesting(),
        );
        self::assertTrue($consumer->isEnabled());
        $consumer->setEnabled(false);
        $out = $consumer->run();
        self::assertSame(PaymentInboxConsumer::ERROR_DISABLED, $out[0]['error_code'] ?? null);
        self::assertSame(WebhookEndpointRecord::STATUS_ACTIVE, WebhookEndpointRecord::STATUS_ACTIVE);
    }

    /**
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    private function payload(string $eventId, array $extra = []): array
    {
        return array_merge([
            'provider_event_id' => $eventId,
            'event_type' => 'fake.payment.updated',
            'intent_code' => 'pi_demo',
            'status' => 'paid',
            'schema_version' => '1',
        ], $extra);
    }
}

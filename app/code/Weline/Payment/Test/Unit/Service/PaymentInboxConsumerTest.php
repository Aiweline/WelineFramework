<?php

declare(strict_types=1);

namespace Weline\Payment\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Inventory\Service\InventoryService;
use Weline\Order\Extends\Module\Weline_Payment\PayableResolver\OrderPayableResolver;
use Weline\Payment\Api\Data\Actor;
use Weline\Payment\Api\Data\PaymentStartCommand;
use Weline\Payment\Extends\Module\Weline_Payment\PaymentProvider\FakeProvider;
use Weline\Payment\Model\PaymentWebhookInbox;
use Weline\Payment\Queue\PaymentInboxConsumer;
use Weline\Payment\Service\PayableResolverRegistry;
use Weline\Payment\Service\PaymentCallbackReceiver;
use Weline\Payment\Service\PaymentConnectorGuard;
use Weline\Payment\Service\PaymentFacadeV2;
use Weline\Payment\Service\PaymentIntentOrchestrator;
use Weline\Payment\Service\WebhookEndpointDirectory;

/**
 * TEST-PAY-05, TEST-WEBHOOK-02, TEST-WEBHOOK-04, TEST-WEBHOOK-05 and
 * TEST-WEBHOOK-06（consumer 侧）.
 */
final class PaymentInboxConsumerTest extends TestCase
{
    private PaymentIntentOrchestrator $orch;
    private PaymentFacadeV2 $facade;
    private PaymentCallbackReceiver $receiver;
    private InventoryService $inventory;
    private int $now = 1_700_000_000;

    protected function setUp(): void
    {
        $resolver = OrderPayableResolver::forTesting([
            'ord-1' => [
                'order_uuid' => 'ord-1',
                'status' => 'pending',
                'payment_status' => 'pending',
                'currency' => 'CNY',
                'website_id' => 0,
                'store_id' => 0,
                'customer_id' => '42',
                'money' => [
                    'currency' => 'CNY',
                    'subtotal_minor' => 1000,
                    'shipping_amount_minor' => 0,
                    'tax_amount_minor' => 0,
                    'grand_total_minor' => 1000,
                ],
                'scope' => ['website_id' => 0, 'store_id' => 0, 'currency' => 'CNY'],
                'items' => [['item_uuid' => 'i1', 'name' => 'A', 'qty_minor' => 1, 'row_total_minor' => 1000]],
            ],
        ]);
        $registry = new PayableResolverRegistry();
        $registry->register($resolver);
        $this->orch = PaymentIntentOrchestrator::forTesting($this->now);
        $this->facade = PaymentFacadeV2::forTesting($registry, $this->orch);
        $this->facade->setEntryEnabled(true);
        $this->facade->setMerchantAccount('fake', 'acct_fake');

        $directory = WebhookEndpointDirectory::forTesting();
        $directory->registerEndpoint(
            endpointCode: 'ep-1',
            providerCode: 'fake',
            methodCode: 'fake',
            merchantAccount: 'acct_fake',
            secrets: [[
                'secret_version' => 'v1',
                'secret_ref' => 'ref-1',
                'status' => 'active',
                'valid_from' => 0,
                'valid_until' => PHP_INT_MAX,
                'material' => 'secret',
            ]],
        );
        $this->receiver = PaymentCallbackReceiver::forTesting($directory, $this->now);
        $this->receiver->setProviderResolver(static fn (): FakeProvider => new FakeProvider());
        $this->inventory = InventoryService::forTesting();
        $this->inventory->setOnHand(0, 0, 1001, 10, 'stock-1', hash('sha256', 'stock-1'), 'strict');
    }

    public function testPay05SameConnectorCommitsInventoryOnceWithoutInventoryCommandOutbox(): void
    {
        $start = $this->facade->start($this->cmd('idem-pay5', 'hash-pay5'));
        $attemptCode = (string) $start->getAttemptCode();
        $intentCode = (string) $start->getIntentCode();
        $reservation = $this->inventory->reserve(0, 0, 1001, 1, 'res-pay5', hash('sha256', 'res-pay5'));
        $this->orch->bindReservation($attemptCode, $reservation->reservationUuid);

        $inboxCode = $this->seedPaidInbox($intentCode, 'evt-pay5');
        $consumer = PaymentInboxConsumer::forTesting(
            $this->receiver,
            $this->orch,
            PaymentConnectorGuard::forTesting(),
            $this->inventory,
        );
        $once = $consumer->applyOne($inboxCode);
        self::assertTrue($once['ok']);
        self::assertTrue($once['inventory_committed']);
        self::assertSame(0, $once['inventory_command_outbox_count']);
        self::assertCount(1, $consumer->inventoryCommits());
        self::assertCount(3, $consumer->effectOutbox());

        // Replay applied inbox：无重复 inventory / effects。
        $again = $consumer->applyOne($inboxCode);
        self::assertTrue($again['replayed'] ?? false);
        self::assertCount(1, $consumer->inventoryCommits());
        self::assertCount(3, $consumer->effectOutbox());

        $mismatch = PaymentInboxConsumer::forTesting(
            $this->receiver,
            $this->orch,
            PaymentConnectorGuard::forTesting('default', 'default', 'other-db'),
            $this->inventory,
        );
        $inbox2 = $this->seedPaidInbox($intentCode, 'evt-pay5b');
        $blocked = $mismatch->applyOne($inbox2);
        self::assertSame(PaymentConnectorGuard::ERROR_CONNECTOR_MISMATCH, $blocked['error_code']);
        self::assertSame(PaymentWebhookInbox::STATUS_RECEIVED, $this->receiver->getInbox($inbox2)['status'] ?? null);

        $allNonDefault = PaymentConnectorGuard::forTesting('other-db', 'other-db', 'other-db');
        self::assertFalse($allNonDefault->isAligned(), 'P2 只允许 default connector，同一非默认连接也必须 fail-fast。');
    }

    public function testWebhook02ConsumerIdempotentEffects(): void
    {
        $start = $this->facade->start($this->cmd('idem-w2', 'hash-w2'));
        $intentCode = (string) $start->getIntentCode();
        $payload = [
            'provider_event_id' => 'evt-w2',
            'event_type' => 'fake.payment.updated',
            'intent_code' => $intentCode,
            'status' => 'paid',
            'schema_version' => '1',
        ];
        $raw = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';
        $sig = hash_hmac('sha256', $raw, 'secret');
        $firstCode = null;
        for ($i = 0; $i < 20; $i++) {
            $r = $this->receiver->receive('ep-1', $raw, [], $payload, $sig, $this->now);
            self::assertTrue($r->isSuccess());
            $firstCode ??= $r->inboxCode;
            self::assertSame($firstCode, $r->inboxCode);
        }
        self::assertSame(1, $this->receiver->inboxCount());

        $consumer = PaymentInboxConsumer::forTesting($this->receiver, $this->orch, null, $this->inventory);
        $this->orch->bindReservation((string) $start->getAttemptCode(), $this->inventory->reserve(0, 0, 1001, 1, 'res-w2', hash('sha256', 'res-w2'))->reservationUuid);
        $consumer->run();
        self::assertCount(3, $consumer->effectOutbox());
        $consumer->run();
        self::assertCount(3, $consumer->effectOutbox());
    }

    public function testWebhook04StalePendingIgnoredAfterSucceeded(): void
    {
        $start = $this->facade->start($this->cmd('idem-w4', 'hash-w4'));
        $intentCode = (string) $start->getIntentCode();
        $paid = $this->seedPaidInbox($intentCode, 'evt-w4-paid');
        $consumer = PaymentInboxConsumer::forTesting($this->receiver, $this->orch);
        $consumer->applyOne($paid);
        self::assertSame('succeeded', $this->orch->getIntent($intentCode)['status'] ?? null);

        $stale = $this->receiver->seedInbox([
            'endpoint_code' => 'ep-1',
            'provider_event_id' => 'evt-w4-stale',
            'provider_code' => 'fake',
            'merchant_account' => 'acct_fake',
            'environment' => 'sandbox',
            'schema_version' => '1',
            'payload_hash' => hash('sha256', 'stale'),
            'intent_code' => $intentCode,
            'status_transition' => 'pending',
            'status' => PaymentWebhookInbox::STATUS_RECEIVED,
        ]);
        $result = $consumer->applyOne($stale);
        self::assertTrue($result['ignored'] ?? false);
        self::assertSame('succeeded', $this->orch->getIntent($intentCode)['status'] ?? null);
        self::assertSame(PaymentWebhookInbox::STATUS_IGNORED, $this->receiver->getInbox($stale)['status'] ?? null);
    }

    public function testWebhook05ReplayAfterInboxCommittedNoDuplicateEffects(): void
    {
        $start = $this->facade->start($this->cmd('idem-w5', 'hash-w5'));
        $intentCode = (string) $start->getIntentCode();
        $payload = [
            'provider_event_id' => 'evt-w5',
            'intent_code' => $intentCode,
            'status' => 'paid',
            'schema_version' => '1',
            'event_type' => 'fake.payment.updated',
        ];
        $raw = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';
        $sig = hash_hmac('sha256', $raw, 'secret');
        $r1 = $this->receiver->receive('ep-1', $raw, [], $payload, $sig, $this->now);
        $consumer = PaymentInboxConsumer::forTesting($this->receiver, $this->orch);
        $consumer->applyOne((string) $r1->inboxCode);
        self::assertCount(3, $consumer->effectOutbox());

        $r2 = $this->receiver->receive('ep-1', $raw, [], $payload, $sig, $this->now);
        self::assertTrue($r2->replayed);
        self::assertSame($r1->inboxCode, $r2->inboxCode);
        $consumer->applyOne((string) $r2->inboxCode);
        self::assertCount(3, $consumer->effectOutbox());
    }

    public function testWebhook06ConflictDoesNotChangeOutbox(): void
    {
        $start = $this->facade->start($this->cmd('idem-w6', 'hash-w6'));
        $intentCode = (string) $start->getIntentCode();
        $a = [
            'provider_event_id' => 'evt-w6',
            'intent_code' => $intentCode,
            'status' => 'paid',
            'schema_version' => '1',
            'event_type' => 'fake.payment.updated',
        ];
        $rawA = json_encode($a, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';
        $this->receiver->receive('ep-1', $rawA, [], $a, hash_hmac('sha256', $rawA, 'secret'), $this->now);
        $consumer = PaymentInboxConsumer::forTesting($this->receiver, $this->orch);
        $consumer->run();
        $effectsBefore = count($consumer->effectOutbox());

        $b = $a;
        $b['status'] = 'failed';
        $rawB = json_encode($b, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';
        $conflict = $this->receiver->receive('ep-1', $rawB, [], $b, hash_hmac('sha256', $rawB, 'secret'), $this->now);
        self::assertSame(409, $conflict->httpStatus);
        self::assertCount($effectsBefore, $consumer->effectOutbox());
        self::assertSame('succeeded', $this->orch->getIntent($intentCode)['status'] ?? null);
    }

    public function testConsumerCanBeDisabledAsRollbackSwitch(): void
    {
        $consumer = new PaymentInboxConsumer(
            $this->receiver,
            $this->orch,
            PaymentConnectorGuard::forTesting(),
        );
        self::assertTrue($consumer->isEnabled());
        $consumer->setEnabled(false);
        self::assertFalse($consumer->isEnabled());
        self::assertSame(PaymentInboxConsumer::ERROR_DISABLED, $consumer->run()[0]['error_code'] ?? null);
    }

    private function seedPaidInbox(string $intentCode, string $eventId): string
    {
        return $this->receiver->seedInbox([
            'endpoint_code' => 'ep-1',
            'provider_event_id' => $eventId,
            'provider_code' => 'fake',
            'merchant_account' => 'acct_fake',
            'environment' => 'sandbox',
            'schema_version' => '1',
            'payload_hash' => hash('sha256', $eventId),
            'intent_code' => $intentCode,
            'status_transition' => 'paid',
            'status' => PaymentWebhookInbox::STATUS_RECEIVED,
        ]);
    }

    private function cmd(string $idem, string $hash): PaymentStartCommand
    {
        return PaymentStartCommand::create(
            payableType: OrderPayableResolver::PAYABLE_TYPE,
            payableId: 'ord-1',
            methodCode: 'fake',
            idempotencyKey: $idem,
            requestHash: $hash,
            actor: Actor::fromArray(['actor_type' => 'customer', 'actor_id' => '42']),
            websiteId: 0,
            storeId: 0,
        );
    }
}

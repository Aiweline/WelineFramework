<?php

declare(strict_types=1);

namespace Weline\Payment\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Order\Extends\Module\Weline_Payment\PayableResolver\OrderPayableResolver;
use Weline\Payment\Api\Data\Actor;
use Weline\Payment\Api\Data\PaymentStartCommand;
use Weline\Payment\Model\PaymentAttempt;
use Weline\Payment\Model\PaymentIntent;
use Weline\Payment\Queue\PaymentProviderCommandConsumer;
use Weline\Payment\Service\PayableResolverRegistry;
use Weline\Payment\Service\PaymentFacadeV2;
use Weline\Payment\Service\PaymentIntentOrchestrator;

/**
 * TEST-IDEM-01、TEST-IDEM-02、TEST-PAY-01、TEST-PAY-02、TEST-PAY-03、TEST-PAY-04（memory harness）。
 */
final class PaymentIntentOrchestratorTest extends TestCase
{
    private PaymentFacadeV2 $facade;
    private PaymentIntentOrchestrator $orch;

    protected function setUp(): void
    {
        $resolver = OrderPayableResolver::forTesting([
            'ord-1' => $this->orderRow('ord-1', 1200),
        ]);
        $registry = new PayableResolverRegistry();
        $registry->register($resolver);
        $this->orch = PaymentIntentOrchestrator::forTesting(1_700_000_000);
        $this->facade = PaymentFacadeV2::forTesting($registry, $this->orch);
        $this->facade->setEntryEnabled(true);
        $this->facade->setMerchantAccount('fake', 'acct_server_fake');
    }

    public function testIdem01SameKeyYieldsSingleActiveIntentAndAttempt(): void
    {
        $results = [];
        for ($i = 0; $i < 20; $i++) {
            $results[] = $this->facade->start($this->cmd('idem-a', 'hash-a'));
        }
        $codes = array_unique(array_map(static fn ($r) => $r->getIntentCode(), $results));
        self::assertCount(1, $codes);
        self::assertSame(1, $this->orch->countActiveGuardsForPayable('sandbox', OrderPayableResolver::PAYABLE_TYPE, 'ord-1'));
        self::assertSame(1, $this->orch->countOpenNonterminalGuards((string) $results[0]->getIntentCode()));
        self::assertCount(1, $this->orch->pendingOutbox());
    }

    public function testIdem02HashConflictKeepsOriginal(): void
    {
        $first = $this->facade->start($this->cmd('idem-b', 'hash-b'));
        $conflict = $this->facade->start($this->cmd('idem-b', 'hash-other'));
        self::assertTrue($first->isOk());
        self::assertSame(PaymentFacadeV2::ERROR_IDEMPOTENCY_CONFLICT, $conflict->getErrorCode());
        self::assertSame($first->getIntentCode(), $this->orch->getIntent((string) $first->getIntentCode())['intent_code'] ?? null);
    }

    public function testPay01RetryAfterTerminalFailedCreatesNewAttempt(): void
    {
        $start = $this->facade->start($this->cmd('idem-c', 'hash-c'));
        $intentCode = (string) $start->getIntentCode();
        $this->orch->setProviderHandler(static fn (): array => [
            'status' => PaymentIntentOrchestrator::STATUS_FAILED,
            'error_code' => 'card_declined',
        ]);
        (new PaymentProviderCommandConsumer($this->orch))->run();

        $attempts = $this->orch->listAttemptsForIntent($intentCode);
        self::assertCount(1, $attempts);
        self::assertSame(PaymentAttempt::STATUS_FAILED, $attempts[0]['status']);
        self::assertNull($attempts[0]['nonterminal_guard']);
        self::assertSame(0, $this->orch->countOpenNonterminalGuards($intentCode));

        $retry = $this->facade->start($this->cmd('idem-c2', 'hash-c2'));
        self::assertTrue($retry->isOk());
        self::assertSame($intentCode, $retry->getIntentCode());
        self::assertNotSame($start->getAttemptCode(), $retry->getAttemptCode());
        self::assertSame(1, $this->orch->countOpenNonterminalGuards($intentCode));
        self::assertCount(2, $this->orch->listAttemptsForIntent($intentCode));
    }

    public function testPay02LeaseHardCapWithControlledClock(): void
    {
        $start = $this->facade->start($this->cmd('idem-lease', 'hash-lease'));
        $attemptCode = (string) $start->getAttemptCode();
        self::assertTrue($this->orch->extendLease($attemptCode));
        $this->orch->setNow(1_700_000_000 + PaymentIntentOrchestrator::LEASE_HARD_CAP_SECONDS + 1);
        self::assertFalse($this->orch->extendLease($attemptCode));
        self::assertTrue($this->orch->isLeaseExpired($attemptCode));
    }

    public function testPay03SucceededWithExpiredReservationRaisesConflict(): void
    {
        $start = $this->facade->start($this->cmd('idem-d', 'hash-d'));
        $this->orch->setReservationValid(false);
        (new PaymentProviderCommandConsumer($this->orch))->run();
        $intent = $this->orch->getIntent((string) $start->getIntentCode());
        self::assertSame(PaymentIntentOrchestrator::ERROR_INVENTORY_CONFLICT, $intent['status'] ?? null);
        self::assertSame('attention_required', $intent['attention'] ?? null);
        self::assertCount(1, $this->orch->effectOutbox());
        self::assertSame('compensation', $this->orch->effectOutbox()[0]['type']);
    }

    public function testPay04CrashBeforeSecondTxReplayUsesSameProviderKey(): void
    {
        $start = $this->facade->start($this->cmd('idem-e', 'hash-e'));
        $this->orch->setCrashBeforeSecondTx(true);
        $consumer = new PaymentProviderCommandConsumer($this->orch);
        $first = $consumer->run();
        self::assertSame('crash_before_second_tx', $first[0]['error_code'] ?? null);
        self::assertCount(1, $this->orch->providerCalls());

        $this->orch->setCrashBeforeSecondTx(false);
        $second = $consumer->run();
        self::assertTrue($second[0]['ok'] ?? false);
        self::assertCount(1, $this->orch->providerCalls(), 'Provider 仅一笔（同 request key）');
        self::assertCount(1, $this->orch->ledgerEntries());
        $attempt = $this->orch->getAttempt((string) $start->getAttemptCode());
        self::assertNotNull($attempt);
        self::assertSame(PaymentAttempt::STATUS_SUCCEEDED, $attempt['status'] ?? null);
        self::assertArrayHasKey('nonterminal_guard', $attempt);
        self::assertNull($attempt['nonterminal_guard']);
    }

    public function testUnknownStatusBlocksNewAttempt(): void
    {
        $start = $this->facade->start($this->cmd('idem-u', 'hash-u'));
        $this->orch->setProviderHandler(static fn (): array => [
            'status' => PaymentIntentOrchestrator::STATUS_UNKNOWN,
        ]);
        (new PaymentProviderCommandConsumer($this->orch))->run();
        $blocked = $this->facade->start($this->cmd('idem-u2', 'hash-u2'));
        self::assertSame(PaymentIntentOrchestrator::ERROR_NONTERMINAL_ATTEMPT, $blocked->getErrorCode());
        self::assertSame(1, $this->orch->countOpenNonterminalGuards((string) $start->getIntentCode()));
    }

    public function testActiveGuardConstant(): void
    {
        self::assertSame('active', PaymentIntent::ACTIVE_GUARD_VALUE);
        self::assertSame('open', PaymentAttempt::NONTERMINAL_GUARD_VALUE);
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

    /**
     * @return array<string, mixed>
     */
    private function orderRow(string $uuid, int $grand): array
    {
        return [
            'order_uuid' => $uuid,
            'checkout_group_uuid' => 'grp-1',
            'status' => 'pending',
            'payment_status' => 'pending',
            'currency' => 'CNY',
            'website_id' => 0,
            'store_id' => 0,
            'customer_id' => '42',
            'money' => [
                'currency' => 'CNY',
                'subtotal_minor' => $grand - 200,
                'shipping_amount_minor' => 200,
                'tax_amount_minor' => 0,
                'discount_amount_minor' => 0,
                'grand_total_minor' => $grand,
            ],
            'scope' => [
                'website_id' => 0,
                'store_id' => 0,
                'currency' => 'CNY',
                'locale' => 'zh_Hans_CN',
            ],
            'items' => [
                ['item_uuid' => 'item-1', 'name' => 'Demo', 'qty_minor' => 1, 'row_total_minor' => $grand - 200],
            ],
            'display_number' => 'DN-1',
        ];
    }
}

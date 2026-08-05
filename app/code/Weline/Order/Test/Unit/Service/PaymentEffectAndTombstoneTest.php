<?php

declare(strict_types=1);

namespace Weline\Order\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Order\Queue\OrderPaymentEffectConsumer;
use Weline\Order\Service\AccountCheckoutGroupPresenter;
use Weline\Order\Service\FulfillmentService;
use Weline\Order\Service\InvoiceService;
use Weline\Order\Service\PaymentEffectConsumer;
use Weline\Order\Service\TombstoneHistoricalResourcePolicy;
use Weline\Payment\Api\Data\PaymentEffectRecord;
use Weline\Payment\Queue\PaymentInboxConsumer;
use Weline\Queue\Api\QueueConsumerInterface;
use Weline\Websites\Api\Catalog\Data\StoreSummary;
use Weline\Websites\Api\Catalog\StoreCatalogInterface;

/**
 * Pure contract coverage. Durable fault/replay evidence lives in the task's
 * registered-clone verification script; this test deliberately has no fake
 * Invoice/Fulfillment memory store.
 */
/** TEST-INVOICE-01 and TEST-TOMBSTONE-01: idempotent invoice effects and historical-only routing. */
final class PaymentEffectAndTombstoneTest extends TestCase
{
    public function testEffectIdentityAndQueueContract(): void
    {
        $attempt = 'att-inv-1';
        $effectKey = InvoiceService::effectKeyForAttempt($attempt);
        self::assertSame('attempt:att-inv-1:invoice:create:v1', $effectKey);
        self::assertSame(PaymentInboxConsumer::EFFECT_INVOICE, PaymentEffectConsumer::EFFECT_INVOICE);
        self::assertSame(
            PaymentInboxConsumer::EFFECT_FULFILLMENT,
            PaymentEffectConsumer::EFFECT_FULFILLMENT,
        );
        self::assertTrue(is_subclass_of(
            OrderPaymentEffectConsumer::class,
            QueueConsumerInterface::class,
        ));

        $record = new PaymentEffectRecord(
            'po-1',
            $effectKey,
            'intent-1',
            $attempt,
            InvoiceService::EFFECT_TYPE,
            'order',
            '00000000-0000-4000-8000-000000000001',
            '1',
        );
        self::assertSame($effectKey, $record->effectKey);
        self::assertSame(
            'attempt:att-f1:fulfillment:action:v1',
            FulfillmentService::effectKeyForAttempt('att-f1'),
        );
    }

    public function testTombstone01WhitelistVsDeniedFromPublicCatalog(): void
    {
        $catalog = $this->catalog(new StoreSummary(
            id: 42,
            websiteId: 0,
            code: 'historical-store',
            name: 'Historical Store',
            storeMode: 'normal',
            isDefault: false,
            enabled: false,
            lifecycleStatus: 'tombstone',
            tombstonedAt: '2026-07-27 12:00:00',
        ));
        $policy = TombstoneHistoricalResourcePolicy::forTesting($catalog);

        foreach (TombstoneHistoricalResourcePolicy::whitelist() as $action) {
            $result = $policy->assertAllowed(42, $action, 'allow-' . $action);
            self::assertTrue($result['ok'], $action);
            self::assertTrue($result['allowed']);
            self::assertSame(
                TombstoneHistoricalResourcePolicy::RESOURCE_MODE_HISTORICAL_ONLY,
                $result['resource_mode'],
            );
        }

        foreach ([
            TombstoneHistoricalResourcePolicy::ACTION_INDEX,
            TombstoneHistoricalResourcePolicy::ACTION_SEO,
            TombstoneHistoricalResourcePolicy::ACTION_NEW_TRADE,
            TombstoneHistoricalResourcePolicy::ACTION_CATALOG_WRITE,
            TombstoneHistoricalResourcePolicy::ACTION_CONFIG_DISTRIBUTE,
        ] as $action) {
            $result = $policy->assertAllowed(42, $action, 'deny-' . $action);
            self::assertFalse($result['ok'], $action);
            self::assertSame(
                TombstoneHistoricalResourcePolicy::ERROR_DENIED,
                $result['error_code'],
            );
            self::assertTrue($result['urgent']);
        }

        self::assertCount(11, $policy->audit());
        self::assertCount(5, $policy->urgent());
    }

    public function testBrowser02GroupSummaryAndPartialExpand(): void
    {
        $presenter = new AccountCheckoutGroupPresenter();

        $summary = $presenter->present([
            'group_uuid' => 'g1',
            'display_number' => 'G-100',
            'status' => 'paid',
            'grand_total_minor' => 10000,
            'orders' => [
                [
                    'order_uuid' => 'o1',
                    'display_number' => 'O-1',
                    'status' => 'paid',
                    'amount_minor' => 5000,
                    'fulfillment_status' => 'pending',
                ],
                [
                    'order_uuid' => 'o2',
                    'display_number' => 'O-2',
                    'status' => 'paid',
                    'amount_minor' => 5000,
                    'fulfillment_status' => 'pending',
                ],
            ],
        ]);
        self::assertSame(AccountCheckoutGroupPresenter::VIEW_SUMMARY, $summary['view']);
        self::assertFalse($summary['partial']);
        self::assertSame([], $summary['orders']);
        self::assertSame('account.sidebar', $summary['hook']);

        $partial = $presenter->present([
            'group_uuid' => 'g2',
            'display_number' => 'G-200',
            'status' => 'paid',
            'grand_total_minor' => 10000,
            'orders' => [
                [
                    'order_uuid' => 'o1',
                    'display_number' => 'O-1',
                    'status' => 'paid',
                    'amount_minor' => 7000,
                    'refund_status' => 'processing',
                    'invoice_status' => 'issued',
                    'fulfillment_status' => 'partial',
                ],
                [
                    'order_uuid' => 'o2',
                    'display_number' => 'O-2',
                    'status' => 'paid',
                    'amount_minor' => 3000,
                    'refund_status' => 'none',
                    'invoice_status' => 'issued',
                    'fulfillment_status' => 'pending',
                ],
            ],
        ]);
        self::assertSame(AccountCheckoutGroupPresenter::VIEW_PARTIAL_EXPANDED, $partial['view']);
        self::assertTrue($partial['partial']);
        self::assertCount(2, $partial['orders']);
        self::assertContains(__('退款处理中'), $partial['refund_semantics']);
        self::assertContains(__('已开票'), $partial['invoice_semantics']);
        self::assertContains(__('部分履约'), $partial['fulfillment_semantics']);
    }

    private function catalog(StoreSummary $store): StoreCatalogInterface
    {
        return new class($store) implements StoreCatalogInterface {
            public function __construct(private readonly StoreSummary $store)
            {
            }

            public function byWebsite(int $websiteId): array
            {
                return $websiteId === $this->store->websiteId ? [$this->store] : [];
            }

            public function byCode(int $websiteId, string $storeCode): ?StoreSummary
            {
                return $websiteId === $this->store->websiteId
                    && $storeCode === $this->store->code
                    ? $this->store
                    : null;
            }

            public function byId(int $storeId): ?StoreSummary
            {
                return $storeId === $this->store->id ? $this->store : null;
            }

            public function defaultStore(int $websiteId): ?StoreSummary
            {
                return null;
            }

            public function all(): array
            {
                return [$this->store];
            }
        };
    }
}

<?php

declare(strict_types=1);

namespace Weline\B2B\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\B2B\Api\B2BCheckoutRecheckInterface;
use Weline\B2B\Model\B2BQuoteToken;
use Weline\B2B\Service\B2BAclGuard;
use Weline\B2B\Service\B2BCheckoutRecheckService;
use Weline\B2B\Service\B2BOrderSnapshotStore;
use Weline\B2B\Service\B2BPriceEngine;
use Weline\B2B\Service\B2BService;

/**
 * TEST-P4C-03, TEST-P4C-04 and TEST-P4C-05：Channel 覆盖、报价版本冲突、旧单快照冻结。
 */
final class B2BCheckoutRecheckSnapshotTest extends TestCase
{
    private B2BService $service;

    protected function setUp(): void
    {
        $this->service = B2BService::forTesting();
        $this->service->seedGroup('g-dealer', 0, 'dealer');
        $this->service->assignCustomer('cust-b2b', 'g-dealer');
        $this->service->seedPriceList('pl-dealer', 'g-dealer', 0, 1, [
            'SKU-A' => 800,
        ]);
        $this->service->seedPriceList('pl-dealer-ch-a', 'g-dealer', 0, 2, [
            'SKU-A' => 700,
        ], 'ch-a');
        $this->service->seedPriceList('pl-dealer-ch-b', 'g-dealer', 0, 2, [
            'SKU-A' => 720,
        ], 'ch-b');
        $this->service->enableShadow();
        self::assertInstanceOf(
            B2BCheckoutRecheckInterface::class,
            $this->service->checkout(),
        );
    }

    public function testChannelOverrideOnlyAffectsTargetChannel(): void
    {
        $a = $this->service->issueQuote([
            'customer_id' => 'cust-b2b',
            'website_id' => 0,
            'channel_id' => 'ch-a',
            'sku' => 'SKU-A',
            'retail_amount_minor' => 1000,
        ]);
        $b = $this->service->issueQuote([
            'customer_id' => 'cust-b2b',
            'website_id' => 0,
            'channel_id' => 'ch-b',
            'sku' => 'SKU-A',
            'retail_amount_minor' => 1000,
        ]);
        $web = $this->service->issueQuote([
            'customer_id' => 'cust-b2b',
            'website_id' => 0,
            'sku' => 'SKU-A',
            'retail_amount_minor' => 1000,
        ]);

        self::assertTrue($a['ok']);
        self::assertSame(700, $a['token']['amount_minor']);
        self::assertSame('pl-dealer-ch-a', $a['token']['price_list_id']);
        self::assertSame(720, $b['token']['amount_minor']);
        self::assertSame('pl-dealer-ch-b', $b['token']['price_list_id']);
        self::assertSame(800, $web['token']['amount_minor']);
        self::assertSame('pl-dealer', $web['token']['price_list_id']);
    }

    public function testSubmitAfterPriceListChangeConflictsAndCreatesZeroOrder(): void
    {
        $quote = $this->service->issueQuote([
            'customer_id' => 'cust-b2b',
            'website_id' => 0,
            'sku' => 'SKU-A',
            'retail_amount_minor' => 1000,
        ]);
        self::assertTrue($quote['ok']);
        $tokenId = (string) $quote['token']['token_id'];
        self::assertSame(1, $quote['token']['version']);

        $this->service->seedPriceList('pl-dealer', 'g-dealer', 0, 2, [
            'SKU-A' => 780,
        ]);

        $submit = $this->service->submit($tokenId, 'cust-b2b', 0, 'order-price-change');
        self::assertFalse($submit['ok']);
        self::assertSame(B2BCheckoutRecheckService::ERROR_QUOTE_VERSION_CONFLICT, $submit['error']);
        self::assertSame(1, $submit['quoted_version']);
        self::assertSame(2, $submit['current_version']);
        self::assertSame(0, $this->service->checkout()->acceptedOrderCount());
    }

    public function testSubmitAfterGroupChangeIsRejectedByAcl(): void
    {
        $quote = $this->service->issueQuote([
            'customer_id' => 'cust-b2b',
            'website_id' => 0,
            'sku' => 'SKU-A',
            'retail_amount_minor' => 1000,
        ]);
        $tokenId = (string) $quote['token']['token_id'];

        $this->service->seedGroup('g-other', 0, 'other');
        $this->service->assignCustomer('cust-b2b', 'g-other');

        $submit = $this->service->submit($tokenId, 'cust-b2b', 0, 'order-group-change');
        self::assertFalse($submit['ok']);
        self::assertSame(B2BAclGuard::ERROR_NOT_MEMBER, $submit['error']);
        self::assertSame(0, $this->service->checkout()->acceptedOrderCount());
    }

    public function testConcurrentSubmitConsumesTokenOnce(): void
    {
        $quote = $this->service->issueQuote([
            'customer_id' => 'cust-b2b',
            'website_id' => 0,
            'sku' => 'SKU-A',
            'retail_amount_minor' => 1000,
        ]);
        $tokenId = (string) $quote['token']['token_id'];

        $first = $this->service->submit($tokenId, 'cust-b2b', 0, 'order-once');
        $second = $this->service->submit($tokenId, 'cust-b2b', 0, 'order-replay');

        self::assertTrue($first['ok']);
        self::assertFalse($second['ok']);
        self::assertSame(B2BCheckoutRecheckService::ERROR_QUOTE_NOT_OPEN, $second['error']);
        self::assertSame(1, $this->service->checkout()->acceptedOrderCount());
    }

    public function testOldOrderSnapshotFrozenAfterRuleChange(): void
    {
        $quote = $this->service->issueQuote([
            'customer_id' => 'cust-b2b',
            'website_id' => 0,
            'sku' => 'SKU-A',
            'retail_amount_minor' => 1000,
        ]);
        $accepted = $this->service->submit(
            (string)$quote['token']['token_id'],
            'cust-b2b',
            0,
            'order-frozen',
        );
        self::assertTrue($accepted['ok']);
        $orderRef = (string) $accepted['order_ref'];
        $frozenHash = (string) $accepted['snapshot']['hash'];
        $frozenAmount = (int) $accepted['snapshot']['amount_minor'];
        $frozenVersion = (int) $accepted['snapshot']['version'];

        $this->service->seedPriceList('pl-dealer', 'g-dealer', 0, 9, [
            'SKU-A' => 1,
        ]);

        $read = $this->service->checkout()->readSnapshot($orderRef, 'cust-b2b', 0);
        self::assertNotNull($read);
        self::assertSame($frozenAmount, $read['amount_minor']);
        self::assertSame($frozenVersion, $read['version']);
        self::assertSame($frozenHash, $read['hash']);
        self::assertSame(800, $read['amount_minor']);
        self::assertSame(1, $read['version']);
    }

    public function testModeOffKeepsRetailPathAndBlocksB2bListOnQuote(): void
    {
        $this->service->modeOff();
        $quote = $this->service->issueQuote([
            'customer_id' => 'cust-b2b',
            'website_id' => 0,
            'sku' => 'SKU-A',
            'retail_amount_minor' => 1000,
        ]);

        self::assertTrue($quote['ok']);
        self::assertSame(B2BCheckoutRecheckService::ERROR_MODE_OFF_QUOTE, $quote['error']);
        self::assertNull($quote['token']['price_list_id']);
        self::assertSame(1000, $quote['token']['amount_minor']);
        self::assertSame(B2BPriceEngine::SOURCE_RETAIL, $quote['token']['source']);

        $submit = $this->service->submit(
            (string)$quote['token']['token_id'],
            'cust-b2b',
            0,
            'order-retail-off',
        );
        self::assertTrue($submit['ok']);
        self::assertSame(1000, $submit['snapshot']['amount_minor']);
        self::assertNull($submit['snapshot']['price_list_id']);
    }

    public function testExpiredQuoteIsRejectedBeforeSnapshotWrite(): void
    {
        $now = 1_700_000_000;
        $service = B2BService::forTesting(
            clock: static function () use (&$now): int {
                return $now;
            },
            quoteTtlSeconds: 30,
        );
        $service->seedGroup('g-expiry', 0, 'expiry');
        $service->assignCustomer('cust-expiry', 'g-expiry');
        $service->seedPriceList('pl-expiry', 'g-expiry', 0, 1, ['SKU-A' => 500]);
        $service->enableShadow();
        $quote = $service->issueQuote([
            'customer_id' => 'cust-expiry',
            'website_id' => 0,
            'sku' => 'SKU-A',
            'retail_amount_minor' => 1000,
        ]);
        self::assertSame($now + 30, $quote['token']['expires_at_epoch']);

        $now += 30;
        $submit = $service->submit(
            (string)$quote['token']['token_id'],
            'cust-expiry',
            0,
            'order-expired',
        );
        self::assertFalse($submit['ok']);
        self::assertSame(B2BCheckoutRecheckService::ERROR_QUOTE_EXPIRED, $submit['error']);
        self::assertSame(0, $service->checkout()->acceptedOrderCount());
    }

    public function testRetailTokenIsRecheckedWhenRolloutChanges(): void
    {
        $this->service->modeOff();
        $quote = $this->service->issueQuote([
            'customer_id' => 'cust-b2b',
            'website_id' => 0,
            'sku' => 'SKU-A',
            'retail_amount_minor' => 1000,
        ]);
        self::assertNull($quote['token']['price_list_id']);

        $this->service->enableShadow();
        $submit = $this->service->submit(
            (string)$quote['token']['token_id'],
            'cust-b2b',
            0,
            'order-retail-transition',
        );
        self::assertFalse($submit['ok']);
        self::assertSame(B2BCheckoutRecheckService::ERROR_QUOTE_VERSION_CONFLICT, $submit['error']);
        self::assertSame(0, $this->service->checkout()->acceptedOrderCount());
    }

    public function testSubmitAndSnapshotReadRequireExactWebsiteAndCustomer(): void
    {
        $quote = $this->service->issueQuote([
            'customer_id' => 'cust-b2b',
            'website_id' => 0,
            'sku' => 'SKU-A',
            'retail_amount_minor' => 1000,
        ]);
        $tokenId = (string)$quote['token']['token_id'];

        $wrongWebsite = $this->service->submit($tokenId, 'cust-b2b', 1, 'order-wrong-scope');
        self::assertFalse($wrongWebsite['ok']);
        self::assertSame(B2BAclGuard::ERROR_WEBSITE_MISMATCH, $wrongWebsite['error']);
        self::assertSame(0, $this->service->checkout()->acceptedOrderCount());

        $accepted = $this->service->submit($tokenId, 'cust-b2b', 0, 'order-scoped');
        self::assertTrue($accepted['ok']);
        self::assertNotNull($this->service->checkout()->readSnapshot('order-scoped', 'cust-b2b', 0));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('customer mismatch');
        $this->service->checkout()->readSnapshot('order-scoped', 'cust-other', 0);
    }

    public function testOrderRefAndTokenAreWriteOnce(): void
    {
        $first = $this->service->issueQuote([
            'customer_id' => 'cust-b2b',
            'website_id' => 0,
            'sku' => 'SKU-A',
            'retail_amount_minor' => 1000,
        ]);
        $second = $this->service->issueQuote([
            'customer_id' => 'cust-b2b',
            'website_id' => 0,
            'sku' => 'SKU-A',
            'retail_amount_minor' => 1000,
        ]);
        self::assertTrue($this->service->submit(
            (string)$first['token']['token_id'],
            'cust-b2b',
            0,
            'order-write-once',
        )['ok']);

        $collision = $this->service->submit(
            (string)$second['token']['token_id'],
            'cust-b2b',
            0,
            'order-write-once',
        );
        self::assertFalse($collision['ok']);
        self::assertSame(B2BOrderSnapshotStore::ERROR_IMMUTABLE, $collision['error']);
        self::assertSame(
            B2BQuoteToken::STATUS_OPEN,
            $this->service->checkout()->quotes()->get(
                (string)$second['token']['token_id'],
            )?->status(),
        );
        self::assertSame(1, $this->service->checkout()->acceptedOrderCount());

        $this->expectException(\RuntimeException::class);
        $this->service->checkout()->snapshots()->update('order-write-once', ['amount_minor' => 1]);
    }
}

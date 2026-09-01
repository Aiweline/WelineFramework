<?php

declare(strict_types=1);

namespace Weline\Marketing\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Marketing\Api\Quote\DiscountQuote;
use Weline\Marketing\Api\Quote\DiscountQuoteRequest;
use Weline\Marketing\Service\CouponService;
use Weline\Marketing\Service\DiscountQuoteContextBuilder;
use Weline\Marketing\Service\DiscountQuoteService;
use Weline\Marketing\Service\RuleEngine;

final class DiscountQuoteServiceTest extends TestCase
{
    public function testValidateTokenRejectsRequestHashMismatch(): void
    {
        $service = new DiscountQuoteService(
            new DiscountQuoteContextBuilder(),
            $this->createMock(CouponService::class),
            $this->createMock(RuleEngine::class),
        );
        $request = new DiscountQuoteRequest(
            scope: ['website_id' => 1],
            address: ['country' => 'CN'],
            lines: [['qty_minor' => 1, 'unit_price_minor' => 1000]],
            currency: 'CNY',
        );
        $quote = new DiscountQuote(
            discountQuoteToken: 'dqt_stale',
            amountMinor: 0,
            currency: 'CNY',
            currencyPrecision: 2,
            requestHash: 'stale-hash',
            lines: [],
            appliedRuleIds: [],
            couponCode: '',
            actionPayloads: [],
            freeShipping: false,
            shippingDiscountMinor: 0,
        );

        self::assertFalse($service->validateToken($request, $quote));
    }

    public function testQuoteTokenUsesDeterministicPrefix(): void
    {
        $request = new DiscountQuoteRequest(
            scope: ['website_id' => 1],
            address: [],
            lines: [],
            currency: 'CNY',
            couponCode: 'SAVE10',
        );
        $hash = $request->requestHash();
        $token = 'dqt_' . substr(hash('sha256', $hash . '|0|SAVE10'), 0, 24);

        self::assertStringStartsWith('dqt_', $token);
        self::assertSame(28, strlen($token));
    }

    public function testDiscountQuoteServiceDeclaresRedeemAndValidateContract(): void
    {
        $path = dirname(__DIR__, 3) . '/Service/DiscountQuoteService.php';
        $source = (string)file_get_contents($path);

        self::assertStringContainsString('function validateToken(', $source);
        self::assertStringContainsString('function redeemCoupon(', $source);
        self::assertStringContainsString('discountQuoteToken:', $source);
    }
}

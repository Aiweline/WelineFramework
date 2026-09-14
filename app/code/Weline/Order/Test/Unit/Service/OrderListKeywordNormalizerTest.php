<?php

declare(strict_types=1);

namespace Weline\Order\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Order\Service\BackendOrderListPresenter;
use Weline\Order\Service\OrderListKeywordNormalizer;

final class OrderListKeywordNormalizerTest extends TestCase
{
    public function testTokensStripGPrefixAndKeepOriginal(): void
    {
        self::assertSame(['G-985b4913', '985b4913'], OrderListKeywordNormalizer::tokens('G-985b4913'));
        self::assertSame(['985b4913'], OrderListKeywordNormalizer::tokens('985b4913'));
        self::assertSame(['9779203997'], OrderListKeywordNormalizer::tokens('9779203997'));
        self::assertSame([], OrderListKeywordNormalizer::tokens('  '));
    }

    public function testGroupDisplayNumberMatchesAccountLoader(): void
    {
        self::assertSame(
            'G-985b4913',
            OrderListKeywordNormalizer::groupDisplayNumber('985b4913-aaaa-bbbb-cccc-dddddddddddd')
        );
        self::assertSame('', OrderListKeywordNormalizer::groupDisplayNumber(''));
    }

    public function testPresenterExposesCheckoutGroupDisplay(): void
    {
        $presented = (new BackendOrderListPresenter())->present([
            'checkout_group_uuid' => '985b4913-aaaa-bbbb-cccc-dddddddddddd',
            'order_number' => '9779203997',
            'customer_id' => 47,
        ]);
        self::assertSame('G-985b4913', $presented['checkout_group_display']);
    }

    public function testOrderServiceAndIndexContractsMentionGroupSearch(): void
    {
        $service = (string) file_get_contents(dirname(__DIR__, 3) . '/Service/OrderService.php');
        self::assertStringContainsString('OrderListKeywordNormalizer::tokens', $service);
        self::assertStringContainsString('CHECKOUT_GROUP_UUID', $service);

        $index = (string) file_get_contents(dirname(__DIR__, 3) . '/view/templates/Backend/Order/index.phtml');
        self::assertStringContainsString('order-list-keyword', $index);
        self::assertStringContainsString('order-list-group-display', $index);
        self::assertStringContainsString('G-xxx', $index);
    }
}

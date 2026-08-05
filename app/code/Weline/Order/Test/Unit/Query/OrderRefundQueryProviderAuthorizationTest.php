<?php

declare(strict_types=1);

namespace Weline\Order\Test\Unit\Query;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Weline\Order\Extends\Module\Weline_Framework\Query\OrderRefundQueryProvider;

final class OrderRefundQueryProviderAuthorizationTest extends TestCase
{
    public function testFrontendRefundResourceIsCustomerOwnedReadOnlyProjection(): void
    {
        $provider = (new ReflectionClass(OrderRefundQueryProvider::class))
            ->newInstanceWithoutConstructor();
        $descriptor = $provider->getDescriptor();
        $operations = is_array($descriptor['operations'] ?? null)
            ? $descriptor['operations']
            : [];

        self::assertCount(1, $operations);
        self::assertSame('customerView', $operations[0]['name'] ?? null);
        self::assertSame('customer', $operations[0]['auth'] ?? null);
        self::assertSame('read', $operations[0]['mode'] ?? null);
        self::assertTrue((bool)($operations[0]['frontend'] ?? false));

        $names = array_column($operations, 'name');
        foreach ([
            'seedPaidOrder',
            'requestRefund',
            'applyChannelResult',
            'getPayment',
            'occupiedAmount',
            'clearHarness',
        ] as $forbidden) {
            self::assertNotContains($forbidden, $names);
        }
        foreach ($operations as $operation) {
            self::assertNotSame('any', $operation['auth'] ?? null);
            self::assertNotSame('write', $operation['mode'] ?? null);
        }
    }
}

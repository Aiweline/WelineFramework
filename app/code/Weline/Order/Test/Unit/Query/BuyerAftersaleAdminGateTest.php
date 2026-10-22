<?php

declare(strict_types=1);

namespace Weline\Order\Test\Unit\Query;

use PHPUnit\Framework\TestCase;
use Weline\Order\Extends\Module\Weline_Framework\Query\OrderBuyerAftersaleAdminQueryProvider;
use Weline\Order\Service\BuyerAftersaleConfig;
use Weline\Order\Service\BuyerAftersaleService;

final class BuyerAftersaleAdminGateTest extends TestCase
{
    public function testWritesAreRejectedWhenMasterGateIsOff(): void
    {
        $provider = new OrderBuyerAftersaleAdminQueryProvider($this->closedConfig());
        $result = $provider->execute('approveBuyerAftersale', [
            'request_uuid' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
        ]);

        self::assertFalse($result['success']);
        self::assertSame(BuyerAftersaleService::ERROR_DISABLED, $result['error_code']);
    }

    private function closedConfig(): BuyerAftersaleConfig
    {
        return new class extends BuyerAftersaleConfig {
            public function isBuyerAftersaleEnabled(?string $storageScope = null): bool
            {
                return false;
            }
        };
    }
}

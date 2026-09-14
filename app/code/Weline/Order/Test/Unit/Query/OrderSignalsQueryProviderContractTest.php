<?php

declare(strict_types=1);

namespace {
    if (!\function_exists('__')) {
        function __(string $text, array $arguments = []): string
        {
            return $text;
        }
    }
}

namespace Weline\Order\Test\Unit\Query {

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Weline\Order\Extends\Module\Weline_Framework\Query\OrderSignalsQueryProvider;
use Weline\Order\Service\UnpaidOrderSignalService;

final class OrderSignalsQueryProviderContractTest extends TestCase
{
    public function testProviderNameAndOperations(): void
    {
        $provider = (new ReflectionClass(OrderSignalsQueryProvider::class))
            ->newInstanceWithoutConstructor();
        self::assertSame('order_signals', $provider->getProviderName());

        $descriptor = $provider->getDescriptor();
        $ops = array_column(is_array($descriptor['operations'] ?? null) ? $descriptor['operations'] : [], 'name');
        self::assertSame(['list_unpaid_orders', 'get_unpaid_order'], $ops);
        foreach ($descriptor['operations'] as $operation) {
            self::assertFalse((bool)($operation['frontend'] ?? false));
            self::assertSame('backend', $operation['auth'] ?? null);
            self::assertSame('read', $operation['mode'] ?? null);
            self::assertIsArray($operation['backend_acl'] ?? null);
            self::assertSame('source', $operation['backend_acl']['kind'] ?? null);
            self::assertNotSame('', (string)($operation['backend_acl']['source_id'] ?? ''));
        }
    }

    public function testUnpaidSignalServiceExcludesTobHangAndCapsLookback(): void
    {
        $src = (string)file_get_contents(dirname(__DIR__, 3) . '/Service/UnpaidOrderSignalService.php');
        self::assertStringContainsString('MAX_LOOKBACK_DAYS = 90', $src);
        self::assertStringContainsString("['tob', 'hang']", $src);
        self::assertStringContainsString('listUnpaid', $src);
        self::assertStringContainsString('getUnpaid', $src);
        self::assertStringContainsString('ContinuePayUrlBuilder', $src);
        self::assertStringContainsString('continue_pay_url', $src);
        self::assertStringContainsString('grand_total_minor', $src);
        self::assertStringContainsString('localeFromScopeSnapshotJson', $src);
        self::assertStringContainsString("'locale'", $src);
        self::assertSame(90, UnpaidOrderSignalService::MAX_LOOKBACK_DAYS);
    }
}

}

<?php

declare(strict_types=1);

namespace Weline\Marketing\Test\Unit\Query;

use PHPUnit\Framework\TestCase;

final class CustomerSignalsQueryProviderContractTest extends TestCase
{
    public function testCustomerModuleOwnsCustomerSignalsFacts(): void
    {
        $path = \dirname(__DIR__, 4) . '/Customer/extends/module/Weline_Framework/Query/CustomerSignalsQueryProvider.php';
        self::assertFileExists($path);
        $src = (string)\file_get_contents($path);
        self::assertStringContainsString("return 'customer_signals'", $src);
        self::assertStringContainsString("'get_customer_facts'", $src);
        self::assertStringContainsString("'backend_acl'", $src);
        self::assertStringContainsString('Weline_Customer::customer_index', $src);
        self::assertStringNotContainsString("'matches_segment'", $src);
    }
}

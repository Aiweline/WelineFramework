<?php
declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Service\Query;

use PHPUnit\Framework\TestCase;

/**
 * JSON `{}` becomes PHP `[]` and array_is_list([]) is true.
 * Bin/Frontend Query gateways must still accept that as type=map|object
 * (e.g. i18n_admin.action payload:{} for dictionary-inline-edit-start).
 */
final class QueryParamMapEmptyObjectContractTest extends TestCase
{
    public function testBinAndFrontendGatewaysAcceptEmptyArrayAsMap(): void
    {
        $needle = "(\$value === [] || !\\array_is_list(\$value))";
        $queryDir = \dirname(__DIR__, 4) . '/Service/Query';
        $bin = (string)\file_get_contents($queryDir . '/BinQueryGateway.php');
        $frontend = (string)\file_get_contents($queryDir . '/FrontendQueryGateway.php');

        self::assertStringContainsString($needle, $bin);
        self::assertStringContainsString($needle, $frontend);
        self::assertTrue(\array_is_list([]));
        self::assertTrue(\array_is_list(\json_decode('{}', true)));
    }
}

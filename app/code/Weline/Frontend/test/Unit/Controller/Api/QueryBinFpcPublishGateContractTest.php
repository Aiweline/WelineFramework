<?php

declare(strict_types=1);

namespace Weline\Frontend\Test\Unit\Controller\Api;

use PHPUnit\Framework\TestCase;

require_once \dirname(__DIR__, 8) . '/app/bootstrap_phpunit.php';

/**
 * Source contracts: business-fail skip publish + origin gate before execute.
 */
final class QueryBinFpcPublishGateContractTest extends TestCase
{
    public function testBinaryResponseSkipsPublishWhenBusinessSuccessFalse(): void
    {
        $src = (string)\file_get_contents(
            BP . 'app/code/Weline/Frontend/Controller/Api/QueryBin.php'
        );
        self::assertStringContainsString('binQueryBusinessSucceeded', $src);
        self::assertStringContainsString('assertSearchFilterOriginGate', $src);
        self::assertStringContainsString('search_filter_origin_gate', $src);
        self::assertStringContainsString("binQueryBusinessSucceeded(\$payload['data'] ?? null)", $src);
        self::assertStringContainsString('SearchFilterOriginGate::isCountedBinQuery', $src);
    }

    public function testSearchProviderDocumentsDiskLeafAllowedDespiteTtlZero(): void
    {
        $src = (string)\file_get_contents(
            BP . 'app/code/Weline/Search/extends/module/Weline_Framework/Query/SearchQueryProvider.php'
        );
        self::assertStringContainsString('disk bq leaf still allowed', $src);
    }
}

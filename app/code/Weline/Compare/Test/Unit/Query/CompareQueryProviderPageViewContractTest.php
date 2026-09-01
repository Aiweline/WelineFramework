<?php

declare(strict_types=1);

namespace Weline\Compare\Test\Unit\Query;

use PHPUnit\Framework\TestCase;

final class CompareQueryProviderPageViewContractTest extends TestCase
{
    public function testQueryProviderExposesPageViewOperation(): void
    {
        $source = (string)file_get_contents(
            dirname(__DIR__, 3) . '/extends/module/Weline_Framework/Query/CompareQueryProvider.php',
        );

        self::assertStringContainsString("'pageView'", $source);
        self::assertStringContainsString('pageViewPayload', $source);
        self::assertStringContainsString('ComparePagePresenter', $source);
    }
}

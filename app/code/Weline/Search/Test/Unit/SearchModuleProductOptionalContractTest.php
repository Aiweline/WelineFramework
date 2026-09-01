<?php

declare(strict_types=1);

namespace Weline\Search\Test\Unit;

use PHPUnit\Framework\TestCase;

final class SearchModuleProductOptionalContractTest extends TestCase
{
    public function testProductIsOptionalDependency(): void
    {
        $source = (string)file_get_contents(dirname(__DIR__, 2) . '/etc/module.php');
        self::assertStringContainsString("'optional' => [", $source);
        self::assertStringContainsString("'Weline_Product' => '*'", $source);
        self::assertStringContainsString("'Weline_Queue' => '*',", $source);
        self::assertStringContainsString(
            "'optional' => [\n        'Weline_Product' => '*',\n    ],",
            $source,
        );
    }
}

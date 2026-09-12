<?php

declare(strict_types=1);

namespace Weline\Dropship\Test\Unit\Taglib;

use PHPUnit\Framework\TestCase;
use Weline\Dropship\Taglib\RemoteWarehouseSelect;

final class RemoteWarehouseSelectContractTest extends TestCase
{
    public function testTagNameAndRequiredId(): void
    {
        self::assertSame('dropship:remote-warehouse:select', RemoteWarehouseSelect::name());
        self::assertTrue(RemoteWarehouseSelect::attr()['id']);
        self::assertFalse(RemoteWarehouseSelect::attr()['name']);
        self::assertFalse(RemoteWarehouseSelect::attr()['provider-select']);
        self::assertFalse(RemoteWarehouseSelect::attr()['country-input']);
        $src = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Taglib/RemoteWarehouseSelect.php'
        );
        self::assertStringContainsString('country_code=', $src);
        self::assertStringContainsString('fillCountryFromLabel', $src);
    }
}

<?php

declare(strict_types=1);

namespace Weline\Order\Test\Unit\Service;

use PHPUnit\Framework\TestCase;

final class UnpaidOrderMailLineProjectorContractTest extends TestCase
{
    public function testSignalServiceWiresLineItems(): void
    {
        $svc = (string)file_get_contents(dirname(__DIR__, 3) . '/Service/UnpaidOrderSignalService.php');
        self::assertStringContainsString('UnpaidOrderMailLineProjector', $svc);
        self::assertStringContainsString("'line_items'", $svc);
        self::assertStringContainsString('resolveCreatedAtDisplay', $svc);
        self::assertStringContainsString('schema_fields_CREATE_TIME', $svc);
        $proj = (string)file_get_contents(dirname(__DIR__, 3) . '/Service/UnpaidOrderMailLineProjector.php');
        self::assertStringContainsString('BackendOrderLinePresenter', $proj);
        self::assertStringContainsString('image_url', $proj);
        self::assertStringContainsString('options_text', $proj);
    }
}

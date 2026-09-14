<?php

declare(strict_types=1);

namespace Weline\Order\Test\Unit\Service;

use PHPUnit\Framework\TestCase;

/**
 * Contract: OrderFacade plannedItem must keep Cart image chrome.
 */
final class OrderFacadeImageChromeContractTest extends TestCase
{
    public function testPlannedItemPersistsImageFromCommandLine(): void
    {
        $src = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Service/OrderFacade.php'
        );
        self::assertNotSame('', $src);
        self::assertStringContainsString("\$plannedItem['image']", $src);
        self::assertStringContainsString("\$line['image']", $src);
    }
}

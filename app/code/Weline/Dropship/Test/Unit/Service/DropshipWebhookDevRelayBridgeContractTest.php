<?php

declare(strict_types=1);

namespace Weline\Dropship\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Dropship\Service\DropshipWebhookDevRelayBridge;

final class DropshipWebhookDevRelayBridgeContractTest extends TestCase
{
    public function testInboxCodePrefixHelpers(): void
    {
        self::assertTrue(DropshipWebhookDevRelayBridge::isDropshipInboxCode('dropship:12'));
        self::assertFalse(DropshipWebhookDevRelayBridge::isDropshipInboxCode('pay_abc'));
        self::assertSame(12, DropshipWebhookDevRelayBridge::parseInboxId('dropship:12'));
        self::assertSame(0, DropshipWebhookDevRelayBridge::parseInboxId('pay_abc'));
        self::assertSame(DropshipWebhookDevRelayBridge::INBOX_PREFIX, 'dropship:');
    }

    public function testCallbackStoresRawBodyAndBridgeCallSite(): void
    {
        $ctrl = file_get_contents(dirname(__DIR__, 3) . '/Controller/Frontend/Callback.php');
        self::assertIsString($ctrl);
        self::assertStringContainsString("'raw_body' => \$body", $ctrl);
        self::assertStringContainsString('DropshipWebhookDevRelayBridge', $ctrl);
        self::assertStringContainsString('publishInbox', $ctrl);
    }
}

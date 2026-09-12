<?php

declare(strict_types=1);

namespace Weline\Payment\Test\Unit\Service;

use PHPUnit\Framework\TestCase;

final class DevRelayDropshipBridgeContractTest extends TestCase
{
    public function testEventStoreLoadsDropshipInboxPrefix(): void
    {
        $src = file_get_contents(dirname(__DIR__, 3) . '/Service/DevRelayEventStore.php');
        self::assertIsString($src);
        self::assertStringContainsString("str_starts_with(\$inboxCode, 'dropship:')", $src);
        self::assertStringContainsString("'module' => 'dropship'", $src);
        self::assertStringContainsString('raw_body', $src);
    }

    public function testLocalConnectReplaysDropshipNotify(): void
    {
        $src = file_get_contents(dirname(__DIR__, 3) . '/Service/DevRelayLocalConnectService.php');
        self::assertIsString($src);
        self::assertStringContainsString('resolveDropshipNotifyUrl', $src);
        self::assertStringContainsString('/dropship/frontend/callback/notify', $src);
        self::assertStringContainsString("\$module === 'dropship'", $src);
    }

    public function testStreamPayloadIncludesModule(): void
    {
        $src = file_get_contents(dirname(__DIR__, 3) . '/Service/DevRelayStreamService.php');
        self::assertIsString($src);
        self::assertStringContainsString("'module' =>", $src);
        self::assertStringContainsString("'inbox_code' =>", $src);
    }
}

<?php

declare(strict_types=1);

namespace Weline\Payment\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Payment\Service\DevRelayDispatcher;
use Weline\Payment\Service\DevRelayTokenService;

final class DevRelayDispatcherTest extends TestCase
{
    public function testEventNameConstant(): void
    {
        self::assertSame('Weline_Payment::webhook_inbox_received', DevRelayDispatcher::EVENT_INBOX_RECEIVED);
    }

    public function testTokenHashIsDeterministic(): void
    {
        $tokens = new DevRelayTokenService();
        self::assertTrue($tokens->verifyToken('abc', $tokens->hashToken('abc')));
        self::assertFalse($tokens->verifyToken('abc', $tokens->hashToken('def')));
    }
}

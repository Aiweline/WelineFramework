<?php

declare(strict_types=1);

namespace Weline\Payment\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Payment\Model\PaymentDevRelaySession;
use Weline\Payment\Service\DevRelayDispatcher;

final class DevRelayGateServiceTest extends TestCase
{
    public function testOutboundModeConstants(): void
    {
        self::assertSame('local_direct', PaymentDevRelaySession::OUTBOUND_LOCAL_DIRECT);
        self::assertSame('online_proxy', PaymentDevRelaySession::OUTBOUND_ONLINE_PROXY);
    }

    public function testSessionStatusConstants(): void
    {
        self::assertSame('active', PaymentDevRelaySession::STATUS_ACTIVE);
        self::assertSame('closed', PaymentDevRelaySession::STATUS_CLOSED);
    }

    public function testWebhookInboxReceivedEventName(): void
    {
        self::assertSame('Weline_Payment::webhook_inbox_received', DevRelayDispatcher::EVENT_INBOX_RECEIVED);
    }
}

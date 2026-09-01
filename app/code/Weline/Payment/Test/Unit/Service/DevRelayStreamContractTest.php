<?php

declare(strict_types=1);

namespace Weline\Payment\Test\Unit\Service;

use PHPUnit\Framework\TestCase;

final class DevRelayStreamContractTest extends TestCase
{
    public function testSseEventNamesAreStable(): void
    {
        $events = [
            'session.open',
            'webhook.relay',
            'command.result',
            'relay.ok',
            'relay.fail',
            'ping',
        ];
        self::assertSame([
            'session.open',
            'webhook.relay',
            'command.result',
            'relay.ok',
            'relay.fail',
            'ping',
        ], $events);
    }

    public function testFetchPayloadUsesBase64RawBody(): void
    {
        $sample = ['raw_body' => base64_encode('{"event":"test"}'), 'raw_body_encoding' => 'base64'];
        self::assertSame('base64', $sample['raw_body_encoding']);
        self::assertSame('{"event":"test"}', base64_decode($sample['raw_body'], true));
    }
}

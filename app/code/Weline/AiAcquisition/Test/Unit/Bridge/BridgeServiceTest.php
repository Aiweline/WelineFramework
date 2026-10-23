<?php

declare(strict_types=1);

namespace Weline\AiAcquisition\Test\Unit\Bridge;

use PHPUnit\Framework\TestCase;
use Weline\AiAcquisition\Model\BridgeEvent;
use Weline\AiAcquisition\Service\Bridge\BridgeService;

/**
 * 浏览器桥服务纯逻辑测试：不依赖数据库与运行服务。
 */
final class BridgeServiceTest extends TestCase
{
    public function testEmptyTicketIsRejected(): void
    {
        $service = new BridgeService();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionCode(401);
        $service->validateTicket('   ');
    }

    public function testMalformedTicketIsRejected(): void
    {
        $service = new BridgeService();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionCode(401);
        $service->validateTicket('not-a-ticket');
    }

    public function testOverlongTicketIsRejected(): void
    {
        $service = new BridgeService();

        $this->expectException(\RuntimeException::class);
        $service->validateTicket(\str_repeat('a', 129));
    }

    public function testEnqueueRejectsInvalidRunId(): void
    {
        $service = new BridgeService();

        $this->expectException(\InvalidArgumentException::class);
        $service->enqueue(0, 'ping', []);
    }

    public function testAckWithoutEventIdsIsNoop(): void
    {
        $service = new BridgeService();

        self::assertSame(0, $service->ack(12, []));
        self::assertSame(0, $service->ack(12, [0, -3, 'x']));
    }

    public function testRateLimitRejectsInvalidRunId(): void
    {
        $service = new BridgeService();

        $this->expectException(\InvalidArgumentException::class);
        $service->assertRateLimit(0);
    }

    public function testNormalizeLimitClampsToRange(): void
    {
        self::assertSame(1, BridgeService::normalizeLimit(0));
        self::assertSame(1, BridgeService::normalizeLimit(-8));
        self::assertSame(20, BridgeService::normalizeLimit(20));
        self::assertSame(BridgeService::PULL_LIMIT_MAX, BridgeService::normalizeLimit(9999));
    }

    public function testNormalizeTypeAcceptsBridgeEventTypes(): void
    {
        self::assertSame('tool_call', BridgeService::normalizeType(' tool_call '));
        self::assertSame('search/result', BridgeService::normalizeType('search/result'));
        self::assertSame('cancel', BridgeService::normalizeType('cancel'));
    }

    public function testNormalizeTypeRejectsEmptyAndIllegalCharacters(): void
    {
        foreach (['', '   ', 'bad type', 'a<script>', \str_repeat('x', 65)] as $type) {
            try {
                BridgeService::normalizeType($type);
                self::fail('事件类型应当被拒绝：' . $type);
            } catch (\InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }

    public function testNormalizeDirectionOnlyAllowsTwoDirections(): void
    {
        self::assertSame(
            BridgeEvent::DIRECTION_SERVER_TO_BROWSER,
            BridgeService::normalizeDirection('SERVER_TO_BROWSER')
        );
        self::assertSame(
            BridgeEvent::DIRECTION_BROWSER_TO_SERVER,
            BridgeService::normalizeDirection(' browser_to_server ')
        );

        $this->expectException(\InvalidArgumentException::class);
        BridgeService::normalizeDirection('both');
    }

    public function testPayloadRoundTrip(): void
    {
        $payload = [
            'query' => '汉服 批发 进口商',
            'count' => 3,
            'nested' => ['a' => 1, 'b' => null],
        ];

        $encoded = BridgeService::encodePayload($payload);

        self::assertSame($payload, BridgeService::decodePayload($encoded));
    }

    public function testDecodePayloadFallsBackToEmptyArray(): void
    {
        self::assertSame([], BridgeService::decodePayload(null));
        self::assertSame([], BridgeService::decodePayload(''));
        self::assertSame([], BridgeService::decodePayload('{not json'));
        self::assertSame([], BridgeService::decodePayload('"scalar"'));
        self::assertSame(['ok' => true], BridgeService::decodePayload(['ok' => true]));
    }
}

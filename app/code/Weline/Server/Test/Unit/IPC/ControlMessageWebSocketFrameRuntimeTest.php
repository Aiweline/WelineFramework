<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\IPC;

use PHPUnit\Framework\TestCase;
use Weline\Server\IPC\ControlMessage;

final class ControlMessageWebSocketFrameRuntimeTest extends TestCase
{
    public function testValidApplicationUpgradeEnablesTheFrameRuntime(): void
    {
        $request = $this->upgradeRequest();
        $response = "HTTP/1.1 101 Switching Protocols\r\n"
            . "Upgrade: websocket\r\n"
            . "Connection: Upgrade\r\n"
            . "Sec-WebSocket-Accept: s3pPLMBiTxaQ9kYGzzhZRbK+xOo=\r\n\r\n";

        self::assertTrue(ControlMessage::webSocketUpgradeAccepted($request, $response));
        self::assertFalse(ControlMessage::webSocketUpgradeAccepted(
            $request,
            \str_replace('s3pPLMBiTxaQ9kYGzzhZRbK+xOo=', 'invalid', $response),
        ));
        self::assertFalse(ControlMessage::webSocketUpgradeAccepted(
            $request,
            \str_replace('101 Switching Protocols', '200 OK', $response),
        ));
        self::assertFalse(ControlMessage::webSocketUpgradeAccepted(
            $request,
            \str_replace("\r\n\r\n", "\r\nSec-WebSocket-Extensions: permessage-deflate\r\n\r\n", $response),
        ));
        self::assertFalse(ControlMessage::webSocketUpgradeAccepted(
            $request,
            \str_replace("\r\n\r\n", "\r\nSec-WebSocket-Protocol: unoffered\r\n\r\n", $response),
        ));

        $protocolRequest = \str_replace(
            "Sec-WebSocket-Version: 13\r\n",
            "Sec-WebSocket-Version: 13\r\nSec-WebSocket-Protocol: chat, superchat\r\n",
            $request,
        );
        self::assertTrue(ControlMessage::webSocketUpgradeAccepted(
            $protocolRequest,
            \str_replace("\r\n\r\n", "\r\nSec-WebSocket-Protocol: chat\r\n\r\n", $response),
        ));
    }

    public function testMaskedClientTextProducesAReachableServerTextFrame(): void
    {
        $result = ControlMessage::webSocketConsumeClientBytes(
            ControlMessage::webSocketInitialState(),
            $this->maskedClientFrame(0x1, 'hi'),
        );

        self::assertSame([['type' => 'text', 'data' => 'hi']], $result['events']);
        self::assertSame(["\x81\x02hi"], $result['outbound']);
        self::assertFalse($result['close_transport']);
        self::assertNull($result['error_code']);
    }

    public function testPingProducesPongAndCloseIsEchoedBeforeTransportClose(): void
    {
        $ping = ControlMessage::webSocketConsumeClientBytes(
            ControlMessage::webSocketInitialState(),
            $this->maskedClientFrame(0x9, 'ok'),
        );

        self::assertSame([['type' => 'ping', 'data' => 'ok']], $ping['events']);
        self::assertSame(["\x8a\x02ok"], $ping['outbound']);
        self::assertFalse($ping['close_transport']);

        $close = ControlMessage::webSocketConsumeClientBytes(
            $ping['state'],
            $this->maskedClientFrame(0x8, \pack('n', 1000)),
        );

        self::assertSame(
            [['type' => 'close', 'code' => 1000, 'reason' => '']],
            $close['events'],
        );
        self::assertSame(["\x88\x02\x03\xe8"], $close['outbound']);
        self::assertTrue($close['close_transport']);
        self::assertTrue($close['state']['close_received']);
        self::assertTrue($close['state']['close_sent']);
    }

    public function testFragmentedTextIsReassembledWhileControlFramesRemainResponsive(): void
    {
        $first = ControlMessage::webSocketConsumeClientBytes(
            ControlMessage::webSocketInitialState(),
            $this->maskedClientFrame(0x1, 'hel', false),
        );
        self::assertSame([], $first['events']);
        self::assertSame([], $first['outbound']);

        $ping = ControlMessage::webSocketConsumeClientBytes(
            $first['state'],
            $this->maskedClientFrame(0x9, 'p'),
        );
        self::assertSame(["\x8a\x01p"], $ping['outbound']);

        $last = ControlMessage::webSocketConsumeClientBytes(
            $ping['state'],
            $this->maskedClientFrame(0x0, 'lo'),
        );
        self::assertSame([['type' => 'text', 'data' => 'hello']], $last['events']);
        self::assertSame(["\x81\x05hello"], $last['outbound']);
        self::assertNull($last['state']['fragment_opcode']);
        self::assertSame('', $last['state']['fragment_payload']);
    }

    public function testIncompleteFrameIsBufferedWithoutBlocking(): void
    {
        $frame = $this->maskedClientFrame(0x1, 'incremental');
        $first = ControlMessage::webSocketConsumeClientBytes(
            ControlMessage::webSocketInitialState(),
            \substr($frame, 0, 5),
        );

        self::assertSame([], $first['events']);
        self::assertSame([], $first['outbound']);
        self::assertSame(5, \strlen($first['state']['buffer']));

        $last = ControlMessage::webSocketConsumeClientBytes(
            $first['state'],
            \substr($frame, 5),
        );
        self::assertSame(["\x81\x0bincremental"], $last['outbound']);
        self::assertSame('', $last['state']['buffer']);
    }

    /**
     * @dataProvider protocolErrorFrames
     */
    public function testProtocolErrorsFailClosedWithA1002Close(string $frame): void
    {
        $result = ControlMessage::webSocketConsumeClientBytes(
            ControlMessage::webSocketInitialState(),
            $frame,
        );

        self::assertSame(1002, $result['error_code']);
        self::assertSame(["\x88\x02\x03\xea"], $result['outbound']);
        self::assertTrue($result['close_transport']);
    }

    /**
     * @return iterable<string,array{string}>
     */
    public static function protocolErrorFrames(): iterable
    {
        yield 'unmasked client data' => ["\x81\x02hi"];
        yield 'reserved opcode' => [self::maskedFrame(0x3, 'x')];
        yield 'fragmented control frame' => [self::maskedFrame(0x9, 'x', false)];
        yield 'continuation without a fragment' => [self::maskedFrame(0x0, 'x')];
        yield 'one-byte close payload' => [self::maskedFrame(0x8, "\x03")];
    }

    public function testOversizedFrameFailsBeforeItsPayloadIsBuffered(): void
    {
        $max = ControlMessage::WEBSOCKET_DEFAULT_MAX_MESSAGE_BYTES;
        $headerOnly = "\x81\xff" . \pack('NN', 0, $max + 1) . "\x01\x02\x03\x04";
        $result = ControlMessage::webSocketConsumeClientBytes(
            ControlMessage::webSocketInitialState(),
            $headerOnly,
        );

        self::assertSame(1009, $result['error_code']);
        self::assertSame(["\x88\x02\x03\xf1"], $result['outbound']);
        self::assertTrue($result['close_transport']);
        self::assertSame('', $result['state']['buffer']);
    }

    public function testInvalidUtf8FailsClosedWithA1007Close(): void
    {
        $result = ControlMessage::webSocketConsumeClientBytes(
            ControlMessage::webSocketInitialState(),
            $this->maskedClientFrame(0x1, "\xc3\x28"),
        );

        self::assertSame(1007, $result['error_code']);
        self::assertSame(["\x88\x02\x03\xef"], $result['outbound']);
        self::assertTrue($result['close_transport']);
    }

    public function testFragmentedMessageCannotExceedTheConfiguredBudget(): void
    {
        $first = ControlMessage::webSocketConsumeClientBytes(
            ControlMessage::webSocketInitialState(),
            $this->maskedClientFrame(0x1, 'abc', false),
            5,
        );
        $oversized = ControlMessage::webSocketConsumeClientBytes(
            $first['state'],
            $this->maskedClientFrame(0x0, 'def'),
            5,
        );

        self::assertSame(1009, $oversized['error_code']);
        self::assertSame(["\x88\x02\x03\xf1"], $oversized['outbound']);
        self::assertTrue($oversized['close_transport']);
    }

    public function testNewDataFrameDuringFragmentationFailsClosed(): void
    {
        $first = ControlMessage::webSocketConsumeClientBytes(
            ControlMessage::webSocketInitialState(),
            $this->maskedClientFrame(0x1, 'a', false),
        );
        $invalid = ControlMessage::webSocketConsumeClientBytes(
            $first['state'],
            $this->maskedClientFrame(0x1, 'b'),
        );

        self::assertSame(1002, $invalid['error_code']);
        self::assertSame(["\x88\x02\x03\xea"], $invalid['outbound']);
        self::assertTrue($invalid['close_transport']);
    }

    public function testFrameBudgetSignalsARequiredEventLoopResume(): void
    {
        $frames = '';
        for ($index = 0; $index < ControlMessage::WEBSOCKET_MAX_FRAMES_PER_TICK + 1; ++$index) {
            $frames .= $this->maskedClientFrame(0x9, '');
        }

        $first = ControlMessage::webSocketConsumeClientBytes(
            ControlMessage::webSocketInitialState(),
            $frames,
        );
        self::assertCount(ControlMessage::WEBSOCKET_MAX_FRAMES_PER_TICK, $first['outbound']);
        self::assertTrue($first['frame_budget_exhausted']);
        self::assertNotSame('', $first['state']['buffer']);

        $last = ControlMessage::webSocketConsumeClientBytes($first['state'], '');
        self::assertSame(["\x8a\x00"], $last['outbound']);
        self::assertFalse($last['frame_budget_exhausted']);
        self::assertSame('', $last['state']['buffer']);
    }

    public function testServerDrainCloseWaitsForThePeerUntilItsCloseArrives(): void
    {
        $started = ControlMessage::webSocketInitiateServerClose(
            ControlMessage::webSocketInitialState(),
            1001,
        );

        self::assertSame(["\x88\x02\x03\xe9"], $started['outbound']);
        self::assertTrue($started['state']['close_sent']);
        self::assertFalse($started['close_transport']);
        self::assertSame(
            ControlMessage::DRAIN_ACTION_WAIT,
            ControlMessage::drainLifecycleDecision(9.0, 9.0, 10.0, 1, 0, 1, 1, 0),
        );

        $peerClose = ControlMessage::webSocketConsumeClientBytes(
            $started['state'],
            $this->maskedClientFrame(0x8, \pack('n', 1000)),
        );
        self::assertSame([], $peerClose['outbound']);
        self::assertTrue($peerClose['close_transport']);
        self::assertSame(
            ControlMessage::DRAIN_ACTION_FORCE,
            ControlMessage::drainLifecycleDecision(10.0, 9.0, 10.0, 1, 0, 1, 1, 0),
        );
    }

    private function upgradeRequest(): string
    {
        return "GET /chat HTTP/1.1\r\n"
            . "Host: server.example.com\r\n"
            . "Upgrade: websocket\r\n"
            . "Connection: keep-alive, Upgrade\r\n"
            . "Sec-WebSocket-Key: dGhlIHNhbXBsZSBub25jZQ==\r\n"
            . "Sec-WebSocket-Version: 13\r\n\r\n";
    }

    private function maskedClientFrame(int $opcode, string $payload, bool $fin = true): string
    {
        return self::maskedFrame($opcode, $payload, $fin);
    }

    private static function maskedFrame(int $opcode, string $payload, bool $fin = true): string
    {
        $length = \strlen($payload);
        self::assertLessThan(126, $length, 'Test helper intentionally covers short frames only.');
        $mask = "\x01\x02\x03\x04";
        $masked = '';
        for ($index = 0; $index < $length; ++$index) {
            $masked .= $payload[$index] ^ $mask[$index % 4];
        }

        return \chr(($fin ? 0x80 : 0x00) | $opcode)
            . \chr(0x80 | $length)
            . $mask
            . $masked;
    }
}

<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Binary;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Binary\WelineBinaryCodec;

final class WelineBinaryCodecTest extends TestCase
{
    public function testV1GoldenPacketRemainsWireCompatible(): void
    {
        $codec = new WelineBinaryCodec();

        self::assertSame(
            '57514231010801026f6b02',
            \bin2hex($codec->encodePacket(['ok' => true]))
        );
    }

    public function testRoundTripPreservesSupportedValueTypes(): void
    {
        $codec = new WelineBinaryCodec();
        $payload = [
            'null' => null,
            'false' => false,
            'true' => true,
            'negative' => -42,
            'positive' => 42,
            'float' => 3.25,
            'string' => '微蓝',
            'list' => [1, 'two', false],
        ];

        self::assertSame($payload, $codec->decodePacket($codec->encodePacket($payload)));
    }

    public function testStringLimitMessageMatchesTheTwoMegabyteProtocolLimit(): void
    {
        $codec = new WelineBinaryCodec();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('String exceeds 2MB limit.');
        $codec->encodePacket(\str_repeat('x', 2_097_153));
    }

    public function testPacketLimitMessageMatchesTheFourMegabyteProtocolLimit(): void
    {
        $codec = new WelineBinaryCodec();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Weline binary packet exceeds 4MB limit.');
        $codec->decodePacket(\str_repeat('x', 4_194_305));
    }
}

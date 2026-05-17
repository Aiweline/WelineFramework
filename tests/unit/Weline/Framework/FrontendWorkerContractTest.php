<?php

declare(strict_types=1);

namespace Tests\Unit\Weline\Framework;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Binary\WelineBinaryCodec;

final class FrontendWorkerContractTest extends TestCase
{
    public function testBinaryCodecRoundTripsSupportedV1Values(): void
    {
        $codec = new WelineBinaryCodec();
        $payload = [
            'null' => null,
            'bool' => true,
            'int' => 9007199254740991,
            'negative' => -42,
            'float' => 12.5,
            'string' => 'hello',
            'list' => [1, 'two', false],
            'map' => ['nested' => ['ok' => true]],
        ];

        $packet = $codec->encodePacket($payload);

        self::assertStringStartsWith(WelineBinaryCodec::MAGIC . chr(WelineBinaryCodec::VERSION), $packet);
        self::assertSame($payload, $codec->decodePacket($packet));
    }

    public function testBinaryCodecRejectsInvalidPacketsAndValues(): void
    {
        $codec = new WelineBinaryCodec();

        $this->expectException(\InvalidArgumentException::class);
        $codec->decodePacket('BAD!');
    }

    public function testBinaryCodecRejectsDuplicateMapKeys(): void
    {
        $codec = new WelineBinaryCodec();
        $packet = WelineBinaryCodec::MAGIC . chr(WelineBinaryCodec::VERSION)
            . "\x08\x02"
            . "\x01a\x03\x00\x01"
            . "\x01a\x03\x00\x02";

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Duplicate map key');
        $codec->decodePacket($packet);
    }

    public function testBinaryCodecRejectsInvalidUtf8AndNonFiniteFloat(): void
    {
        $codec = new WelineBinaryCodec();

        try {
            $codec->decodePacket(WelineBinaryCodec::MAGIC . chr(WelineBinaryCodec::VERSION) . "\x05\x01\xff");
            self::fail('Invalid UTF-8 string was accepted.');
        } catch (\InvalidArgumentException $exception) {
            self::assertStringContainsString('Invalid UTF-8', $exception->getMessage());
        }

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Non-finite float');
        $codec->decodePacket(WelineBinaryCodec::MAGIC . chr(WelineBinaryCodec::VERSION) . "\x04" . pack('E', INF));
    }

    public function testQueryBinControllerKeepsWorkerRequestContractChecks(): void
    {
        $source = (string)file_get_contents(BP . 'app/code/Weline/Framework/Controller/Api/QueryBin.php');

        foreach ([
            'X-Weline-Protocol',
            'X-Weline-Worker-Protocol',
            'X-Weline-Worker-Session',
            'X-Weline-Worker-Capability',
            'X-Weline-Worker-Nonce',
            'X-Weline-Worker-Timestamp',
            'X-Weline-Worker-Body-Hash',
            'X-Weline-Worker-Signature',
            'X-Weline-Deploy-Version',
            'X-Weline-Worker-Build-Id',
            "hash('sha256', \$rawBody)",
            "hash_hmac('sha256'",
            "'POST'",
            'SIGNED_PATH',
            "Cache-Control', 'no-store'",
            "X-Content-Type-Options', 'nosniff'",
        ] as $needle) {
            self::assertStringContainsString($needle, $source);
        }
    }

    public function testFrontendApiResourceBlocksPromiseAndPrototypeKeys(): void
    {
        $source = (string)file_get_contents(BP . 'app/code/Weline/Frontend/view/statics/js/weline-api.js');

        foreach (['then', 'catch', 'finally', '__proto__', 'prototype', 'constructor', 'toString', 'valueOf'] as $key) {
            self::assertStringContainsString("'" . $key . "'", $source);
        }

        self::assertStringContainsString('typeof property === \'symbol\'', $source);
        self::assertStringContainsString('sameOriginUrl', $source);
        self::assertStringContainsString('worker is unavailable', strtolower($source));
    }
}

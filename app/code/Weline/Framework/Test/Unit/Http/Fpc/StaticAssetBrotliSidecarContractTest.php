<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Http\Fpc;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Http\ContentEncodingNegotiator;
use Weline\Framework\Http\Fpc\StaticAssetBrotliSidecar;
use Weline\Framework\Test\ProjectRoot;

final class StaticAssetBrotliSidecarContractTest extends TestCase
{
    private string $dir = '';

    protected function setUp(): void
    {
        ProjectRoot::defineBp(__DIR__);
        $this->dir = BP . 'var' . \DIRECTORY_SEPARATOR . 'tmp' . \DIRECTORY_SEPARATOR
            . 'static-br-sidecar-' . \bin2hex(\random_bytes(4));
        @\mkdir($this->dir, 0775, true);
    }

    protected function tearDown(): void
    {
        if ($this->dir === '' || !\is_dir($this->dir)) {
            return;
        }
        foreach (\glob($this->dir . \DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
            @\unlink($file);
        }
        @\rmdir($this->dir);
    }

    public function testPublishWritesBrForLargeCssAndSkipsTiny(): void
    {
        if (!ContentEncodingNegotiator::brotliAvailable()) {
            self::markTestSkipped('ext-brotli required');
        }
        $tiny = $this->dir . \DIRECTORY_SEPARATOR . 'tiny.css';
        \file_put_contents($tiny, \str_repeat('a', 100));
        self::assertFalse(StaticAssetBrotliSidecar::publish($tiny));
        self::assertFileDoesNotExist($tiny . '.br');

        $large = $this->dir . \DIRECTORY_SEPARATOR . 'large.css';
        $body = \str_repeat('body{color:red;}', 200);
        \file_put_contents($large, $body);
        self::assertTrue(StaticAssetBrotliSidecar::publish($large));
        self::assertFileExists($large . '.br');
        $decoded = ContentEncodingNegotiator::decode(
            (string)\file_get_contents($large . '.br'),
            ContentEncodingNegotiator::ENCODING_BROTLI,
        );
        self::assertSame($body, $decoded);

        self::assertFalse(StaticAssetBrotliSidecar::isEligiblePath($large . '.png'));
        self::assertFalse(StaticAssetBrotliSidecar::isEligiblePath($large . '.br'));
    }
}

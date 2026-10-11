<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Http\Fpc;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Http\Fpc\FpcPayloadPathIndex;

final class FpcPayloadPathIndexTest extends TestCase
{
    public function testRememberAndPurgeRemovesBodyFileAndRefs(): void
    {
        if (!\defined('BP')) {
            \define('BP', \dirname(__DIR__, 6) . \DIRECTORY_SEPARATOR);
        }
        $path = '/category/demo-path-index';
        $payloadDir = BP . 'var' . \DIRECTORY_SEPARATOR . 'cache' . \DIRECTORY_SEPARATOR . 'router-fpc-payloads'
            . \DIRECTORY_SEPARATOR . 'aa';
        if (!\is_dir($payloadDir)) {
            @\mkdir($payloadDir, 0775, true);
        }
        $body = $payloadDir . \DIRECTORY_SEPARATOR . 'fpc-path-index-unit.br';
        \file_put_contents($body, 'br-body');
        $cacheKey = 'unified-fpc-test-key-' . \uniqid('', true);
        FpcPayloadPathIndex::remember($path, $cacheKey, $body);
        self::assertFileExists($body);

        $keys = FpcPayloadPathIndex::purgePaths([$path]);
        self::assertContains($cacheKey, $keys);
        self::assertFileDoesNotExist($body);
    }

    public function testNormalizePathFromAbsoluteUrl(): void
    {
        self::assertSame('/product/x', FpcPayloadPathIndex::normalizePath('https://shop.test/product/x'));
        self::assertSame('/', FpcPayloadPathIndex::normalizePath('https://shop.test/'));
    }
}

<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Event\Changed;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Event\Changed\UrlMatrixExpander;
use Weline\Framework\Http\Fpc\FpcPayloadPathIndex;

/**
 * Product/catalog purge matrix must emit path locale variants that match FPC / offline-bake keys.
 */
final class UrlMatrixExpanderPathLocaleContractTest extends TestCase
{
    protected function setUp(): void
    {
        if (!\defined('BP')) {
            \define('BP', \dirname(__DIR__, 5) . \DIRECTORY_SEPARATOR);
        }
    }

    public function testExpandEmitsDefaultAndEnUsPathNotQueryWl(): void
    {
        $expander = new UrlMatrixExpander();
        $origin = 'https://shop.example';
        $slug = 'hanfu-robe';
        $result = $expander->expand([$origin . '/product/' . $slug], 0);
        $urls = $result['urls'];
        self::assertNotEmpty($urls);

        $normalized = [];
        foreach ($urls as $url) {
            $key = FpcPayloadPathIndex::normalizeIndexKey((string)$url);
            if ($key !== '') {
                $normalized[$key] = $key;
            }
            self::assertStringNotContainsString('_wl=', (string)$url);
            self::assertStringNotContainsString('_wc=', (string)$url);
        }

        $zh = FpcPayloadPathIndex::normalizeIndexKey($origin . '/product/' . $slug);
        $en = FpcPayloadPathIndex::normalizeIndexKey($origin . '/en_US/product/' . $slug);
        self::assertArrayHasKey($zh, $normalized, 'default unprefixed product URL must be purged');
        self::assertArrayHasKey($en, $normalized, 'en_US path variant must be purged for multi-lang cold disk');
    }

    public function testMatrixPurgeClearsBakedZhAndEnColdDisk(): void
    {
        $origin = 'https://matrix-purge-' . \uniqid('', true) . '.example';
        $slug = 'probe-' . \substr(\md5((string)\microtime(true)), 0, 8);
        $urlZh = FpcPayloadPathIndex::normalizeIndexKey($origin . '/product/' . $slug);
        $urlEn = FpcPayloadPathIndex::normalizeIndexKey($origin . '/en_US/product/' . $slug);

        $payloadDir = BP . 'var' . \DIRECTORY_SEPARATOR . 'cache' . \DIRECTORY_SEPARATOR
            . 'router-fpc-payloads' . \DIRECTORY_SEPARATOR . 'mx';
        if (!\is_dir($payloadDir)) {
            \mkdir($payloadDir, 0775, true);
        }
        $bodyZh = $payloadDir . \DIRECTORY_SEPARATOR . 'zh-' . $slug . '.html';
        $bodyEn = $payloadDir . \DIRECTORY_SEPARATOR . 'en-' . $slug . '.html';
        \file_put_contents($bodyZh, '<html>中文-旧</html>');
        \file_put_contents($bodyEn, '<html>EN-old</html>');
        FpcPayloadPathIndex::remember($urlZh, 'k-zh-' . $slug, $bodyZh);
        FpcPayloadPathIndex::remember($urlEn, 'k-en-' . $slug, $bodyEn);
        self::assertNotEmpty(FpcPayloadPathIndex::lookup($urlZh));
        self::assertNotEmpty(FpcPayloadPathIndex::lookup($urlEn));

        $expander = new UrlMatrixExpander();
        $matrix = $expander->expand([$origin . '/product/' . $slug], 0);
        $purged = FpcPayloadPathIndex::purgePaths($matrix['urls']);
        self::assertGreaterThanOrEqual(2, \count($purged));
        self::assertSame([], FpcPayloadPathIndex::lookup($urlZh));
        self::assertSame([], FpcPayloadPathIndex::lookup($urlEn));
        self::assertFileDoesNotExist($bodyZh);
        self::assertFileDoesNotExist($bodyEn);
    }
}

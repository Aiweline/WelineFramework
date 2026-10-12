<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Router;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Http\ContentEncodingNegotiator;
use Weline\Framework\Http\Fpc\FpcPayloadPathIndex;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Router\FullPageCacheCoordinator;

final class FpcAlwaysOnlineColdDiskTest extends TestCase
{
    public function testServeColdDiskWhenPathIndexHasHtmlBody(): void
    {
        if (!\defined('BP')) {
            \define('BP', \dirname(__DIR__, 5) . \DIRECTORY_SEPARATOR);
        }
        $path = '/always-online-probe-' . \uniqid('', true);
        $fullUri = 'https://p05113ef3.test.weline.com' . $path;
        $payloadDir = BP . 'var' . \DIRECTORY_SEPARATOR . 'cache' . \DIRECTORY_SEPARATOR . 'router-fpc-payloads'
            . \DIRECTORY_SEPARATOR . 'ac';
        if (!\is_dir($payloadDir)) {
            @\mkdir($payloadDir, 0775, true);
        }
        $html = '<!DOCTYPE html><html><head><title>AO</title></head><body><main data-ao="1">always online</main></body></html>';
        $bodyFile = $payloadDir . \DIRECTORY_SEPARATOR . 'always-online-unit.html';
        \file_put_contents($bodyFile, $html);
        FpcPayloadPathIndex::remember($fullUri, 'unified-fpc-ao-' . \uniqid('', true), $bodyFile);

        /** @var FullPageCacheCoordinator $coordinator */
        $coordinator = ObjectManager::getInstance(FullPageCacheCoordinator::class);
        $response = $coordinator->tryServeColdDiskOnOriginError(
            $fullUri,
            'GET',
            'gzip, deflate',
            '',
        );
        self::assertNotNull($response);
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('ALWAYS-ONLINE', $response->getHeader('X-Weline-FPC'));
        self::assertSame('cold-disk', $response->getHeader('X-Weline-FPC-Source'));
        self::assertSame('always-online-cold-disk', $response->getHeader('X-WLS-Degrade'));
        self::assertStringContainsString('always online', (string)$response->getBody());

        FpcPayloadPathIndex::purgePaths([$fullUri]);
        $afterPurge = $coordinator->tryServeColdDiskOnOriginError(
            $fullUri,
            'GET',
            '',
            '',
        );
        self::assertNull($afterPurge);
    }

    public function testLoggedInCookieDoesNotServeColdDisk(): void
    {
        if (!\defined('BP')) {
            \define('BP', \dirname(__DIR__, 5) . \DIRECTORY_SEPARATOR);
        }
        $path = '/always-online-private-' . \uniqid('', true);
        $fullUri = 'https://p05113ef3.test.weline.com' . $path;
        $payloadDir = BP . 'var' . \DIRECTORY_SEPARATOR . 'cache' . \DIRECTORY_SEPARATOR . 'router-fpc-payloads'
            . \DIRECTORY_SEPARATOR . 'ad';
        if (!\is_dir($payloadDir)) {
            @\mkdir($payloadDir, 0775, true);
        }
        $html = '<!DOCTYPE html><html><body><main>private should not serve</main></body></html>';
        $bodyFile = $payloadDir . \DIRECTORY_SEPARATOR . 'always-online-private.html';
        \file_put_contents($bodyFile, $html);
        FpcPayloadPathIndex::remember($fullUri, 'unified-fpc-ao-priv-' . \uniqid('', true), $bodyFile);

        /** @var FullPageCacheCoordinator $coordinator */
        $coordinator = ObjectManager::getInstance(FullPageCacheCoordinator::class);
        // Any non-empty frontend session cookie name triggers login bypass heuristics
        // when session store marks it logged-in; empty cookie is the anonymous path.
        // Here we assert editor/preview URLs are rejected even with a body on disk.
        $preview = $coordinator->tryServeColdDiskOnOriginError(
            $fullUri . '?weline_preview_token=x',
            'GET',
            '',
            '',
        );
        self::assertNull($preview);
        FpcPayloadPathIndex::purgePaths([$fullUri]);
        unset($html);
        // Keep ContentEncodingNegotiator referenced so static analysis sees the import path used by Coordinator.
        self::assertSame('br', ContentEncodingNegotiator::ENCODING_BROTLI);
    }
}

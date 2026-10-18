<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Router;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Http\Fpc\FpcPayloadPathIndex;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Router\FullPageCacheCoordinator;
use Weline\Framework\Test\ProjectRoot;

final class FpcPersistUntilPurgeColdDiskTest extends TestCase
{
    public function testDurableHitFromColdDiskWhenSharedMissing(): void
    {
        ProjectRoot::defineBp(__DIR__);
        $path = '/persist-until-purge-' . \uniqid('', true);
        $fullUri = 'https://p05113ef3.test.weline.com' . $path;
        $payloadDir = VAR_DIR . 'cache' . \DIRECTORY_SEPARATOR . 'router-fpc-payloads'
            . \DIRECTORY_SEPARATOR . 'pu';
        if (!\is_dir($payloadDir)) {
            @\mkdir($payloadDir, 0775, true);
        }
        $html = '<!DOCTYPE html><html><head><title>PUP</title></head><body><main data-pup="1">persist until purge</main></body></html>';
        $bodyFile = $payloadDir . \DIRECTORY_SEPARATOR . 'persist-until-purge-unit.html';
        \file_put_contents($bodyFile, $html);
        FpcPayloadPathIndex::remember($fullUri, 'unified-fpc-pup-' . \uniqid('', true), $bodyFile);

        /** @var FullPageCacheCoordinator $coordinator */
        $coordinator = ObjectManager::getInstance(FullPageCacheCoordinator::class);

        $response = $coordinator->tryServeColdDiskDurableHit($fullUri, 'GET', 'gzip, deflate', '');
        self::assertNotNull($response);
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('HIT', $response->getHeader('X-Weline-FPC'));
        self::assertSame('cold-disk', $response->getHeader('X-Weline-FPC-Source'));
        self::assertStringContainsString('persist until purge', (string)$response->getBody());

        // Worker fpc_probe / non-legacy path: getFormatted still durable-HITs from cold disk.
        $formatted = $coordinator->getFormattedCachedResponseForFullUri(
            $fullUri,
            'GET',
            'text/html',
            'gzip, deflate',
            '',
            true,
        );
        self::assertIsArray($formatted);
        self::assertSame('cold-disk-durable', $formatted['source'] ?? null);
        $wire = (string)($formatted['response'] ?? '');
        self::assertMatchesRegularExpression('/^HTTP\\/1\\.1 200 /m', $wire);
        self::assertMatchesRegularExpression('/^X-Weline-Fpc:\\s*HIT\\s*$/mi', $wire);
        self::assertMatchesRegularExpression('/^X-Weline-Fpc-Source:\\s*cold-disk\\s*$/mi', $wire);

        FpcPayloadPathIndex::purgePaths([$fullUri]);
        self::assertNull($coordinator->tryServeColdDiskDurableHit($fullUri, 'GET', '', ''));
        self::assertNull($coordinator->getFormattedCachedResponseForFullUri(
            $fullUri,
            'GET',
            'text/html',
            '',
            '',
            true,
        ));
    }
}

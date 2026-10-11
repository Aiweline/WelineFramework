<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Theme\Service\StorefrontFpcWarmer;

final class StorefrontFpcWarmerTest extends TestCase
{
    public function testWarmerIdentity(): void
    {
        $warmer = new StorefrontFpcWarmer();
        self::assertSame('theme.storefront_fpc', $warmer->getName());
        self::assertSame('fpc', $warmer->getTargetPool());
        self::assertSame(800, $warmer->getPriority());
        self::assertTrue($warmer->canWarm());
    }

    public function testPriorityPathsForceLocales(): void
    {
        $warmer = new StorefrontFpcWarmer();
        $method = new \ReflectionMethod(StorefrontFpcWarmer::class, 'priorityPaths');
        $method->setAccessible(true);
        /** @var list<string> $paths */
        $paths = $method->invoke($warmer, ['/products/']);

        self::assertContains('/', $paths);
        self::assertContains('/en_US/', $paths);
        self::assertContains('/zh_Hans_CN/', $paths);
        self::assertContains('/en_US/products', $paths);
        self::assertContains('/zh_Hans_CN/products', $paths);
        self::assertContains('/products/', $paths);
        self::assertLessThanOrEqual(8, \count($paths));
    }

    public function testRequestHostIncludesNonStandardPortUsedByTheFpcKey(): void
    {
        $warmer = new StorefrontFpcWarmer();
        $method = new \ReflectionMethod(StorefrontFpcWarmer::class, 'requestHostWithPort');
        $method->setAccessible(true);

        self::assertSame('shop.test:9555', $method->invoke($warmer, 'shop.test', 9555));
        self::assertSame('shop.test', $method->invoke($warmer, 'shop.test', 443));
        self::assertSame('[::1]:9555', $method->invoke($warmer, '::1', 9555));
    }

    public function testResolveWarmTargetsPrefersPublicOriginAndAvoidsHttpsOnPlainWorker(): void
    {
        $instancePath = \defined('BP') ? BP . 'var/server/instances/default.json' : '';
        if ($instancePath === '' || !\is_file($instancePath)) {
            self::markTestSkipped('default WLS instance.json absent');
        }
        $cfg = \json_decode((string)\file_get_contents($instancePath), true);
        if (!\is_array($cfg) || !empty($cfg['ssl_enabled'])) {
            self::markTestSkipped('fixture expects ssl_enabled=false worker bind');
        }

        $warmer = new StorefrontFpcWarmer();
        $method = new \ReflectionMethod(StorefrontFpcWarmer::class, 'resolveWarmTargets');
        $method->setAccessible(true);
        /** @var list<array{scheme:string,connect:string,port:int,host:string}> $targets */
        $targets = $method->invoke($warmer, ['127.0.0.1', 'p05113ef3.test.weline.com'], 9555);
        self::assertNotEmpty($targets);

        $hasPublicHttps = false;
        $hasLoopbackHttp = false;
        $httpsLoopbackOnly = \count($targets) === 1
            && $targets[0]['scheme'] === 'https'
            && $targets[0]['connect'] === '127.0.0.1';
        foreach ($targets as $t) {
            if ($t['connect'] !== '127.0.0.1' && $t['scheme'] === 'https' && \in_array($t['port'], [443, 80], true)) {
                $hasPublicHttps = true;
            }
            if ($t['connect'] === '127.0.0.1' && $t['scheme'] === 'http' && $t['port'] === 9555) {
                $hasLoopbackHttp = true;
            }
        }
        self::assertFalse($httpsLoopbackOnly, 'must not sole-target https://127.0.0.1 when ssl_enabled=false');
        self::assertTrue(
            $hasPublicHttps || $hasLoopbackHttp,
            'expected public https:443 and/or loopback http; got ' . \json_encode($targets),
        );
    }
}

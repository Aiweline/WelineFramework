<?php

declare(strict_types=1);

namespace Weline\Seo\Test\Unit\Service\Audit;

use PHPUnit\Framework\TestCase;
use Weline\Seo\Service\Audit\LocalDevelopmentHostPolicy;

final class LocalDevelopmentHostPolicyTest extends TestCase
{
    public function testRelaxesTlsForWelineLocalHosts(): void
    {
        self::assertTrue(LocalDevelopmentHostPolicy::shouldRelaxHttpsTls(
            'https://p05113ef3.test.weline.com:9555/sitemap.xml'
        ));
        self::assertTrue(LocalDevelopmentHostPolicy::isLocalDevelopmentHost('p05113ef3.test.weline.com'));
        self::assertTrue(LocalDevelopmentHostPolicy::isLocalDevelopmentHost('shop.test'));
        self::assertTrue(LocalDevelopmentHostPolicy::isLocalDevelopmentHost('app.localhost'));
        self::assertTrue(LocalDevelopmentHostPolicy::isLocalDevelopmentHost('127.0.0.1'));
        self::assertTrue(LocalDevelopmentHostPolicy::isLocalDevelopmentHost('p05113ef3.weline.test'));
    }

    public function testKeepsStrictTlsForPublicHosts(): void
    {
        self::assertFalse(LocalDevelopmentHostPolicy::shouldRelaxHttpsTls('https://www.example.com/sitemap.xml'));
        self::assertFalse(LocalDevelopmentHostPolicy::isLocalDevelopmentHost('www.google.com'));
        self::assertFalse(LocalDevelopmentHostPolicy::shouldRelaxHttpsTls('http://p05113ef3.test.weline.com/sitemap.xml'));
    }
}

<?php

declare(strict_types=1);

namespace Weline\Websites\Test\Unit\Api;

use PHPUnit\Framework\TestCase;
use Weline\Websites\Api\DomainStartPageConfig;

final class DomainStartPageConfigTest extends TestCase
{
    public function testPortAndSchemeResolveToTheSameDomainScopedKey(): void
    {
        self::assertSame(
            DomainStartPageConfig::key('local-demo.weline.test'),
            DomainStartPageConfig::key('https://LOCAL-DEMO.WELINE.TEST:9502/')
        );
    }

    public function testDifferentSessionDomainsNeverShareAStartPageKey(): void
    {
        self::assertNotSame(
            DomainStartPageConfig::key('session-a.weline.test'),
            DomainStartPageConfig::key('session-b.weline.test')
        );
    }

    public function testInvalidDomainDoesNotProduceAConfigKey(): void
    {
        self::assertSame('', DomainStartPageConfig::key(''));
        self::assertSame('', DomainStartPageConfig::key('not a domain'));
    }
}

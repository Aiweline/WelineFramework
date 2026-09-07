<?php
declare(strict_types=1);

namespace Weline\Server\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Server\Service\LocalDomainPolicy;

final class LocalDomainPolicyTest extends TestCase
{
    public function testCurrentRootAndProjectHostPreferPublicTld(): void
    {
        self::assertSame('test.weline.com', LocalDomainPolicy::TEST_ROOT_DOMAIN);
        self::assertSame('*.test.weline.com', LocalDomainPolicy::TEST_WILDCARD_DOMAIN);
        self::assertSame('test.weline.com', LocalDomainPolicy::currentRootDomain('dev'));
        self::assertSame('p05113ef3.test.weline.com', LocalDomainPolicy::buildProjectHost('05113ef3', 'dev'));
        self::assertSame('*.test.weline.com', LocalDomainPolicy::currentWildcardDomain('dev'));
    }

    public function testLegacyWelineTestRemainsManaged(): void
    {
        self::assertSame('weline.test', LocalDomainPolicy::resolveRootDomain('p05113ef3.weline.test'));
        self::assertTrue(LocalDomainPolicy::isManagedLocalDomain('shop.weline.test'));
        self::assertTrue(LocalDomainPolicy::requiresHostsEntry('shop.weline.test'));
        self::assertTrue(LocalDomainPolicy::isStandardProjectHost('p05113ef3.weline.test'));
    }

    public function testNewPublicTldIsManagedAndNeedsHosts(): void
    {
        self::assertSame('test.weline.com', LocalDomainPolicy::resolveRootDomain('p05113ef3.test.weline.com'));
        self::assertTrue(LocalDomainPolicy::isManagedSingleLabelSubdomain('p05113ef3.test.weline.com'));
        self::assertTrue(LocalDomainPolicy::requiresHostsEntry('p05113ef3.test.weline.com'));
        self::assertTrue(LocalDomainPolicy::isManagedWildcardDomain('*.test.weline.com'));
        self::assertFalse(LocalDomainPolicy::isManagedSingleLabelSubdomain('a.b.test.weline.com'));
    }

    public function testLoopbackSuffixSkipsHosts(): void
    {
        self::assertTrue(LocalDomainPolicy::resolvesViaLoopbackSuffix('p05113ef3.weline.localhost'));
        self::assertFalse(LocalDomainPolicy::requiresHostsEntry('p05113ef3.weline.localhost'));
    }
}

<?php

declare(strict_types=1);

namespace Weline\Websites\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Server\Api\Domain\LocalDomainPolicy;
use Weline\Websites\Service\DefaultWebsiteService;

final class DefaultWebsiteServiceLocalDomainContractTest extends TestCase
{
    public function testResolveLocalDefaultDomainsIncludesCurrentProjectHost(): void
    {
        $service = (new \ReflectionClass(DefaultWebsiteService::class))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod(DefaultWebsiteService::class, 'resolveLocalDefaultDomains');
        $method->setAccessible(true);

        /** @var list<string> $domains */
        $domains = $method->invoke($service);

        self::assertContains('127.0.0.1', $domains);
        self::assertContains('localhost', $domains);

        $expected = LocalDomainPolicy::buildProjectHost(
            \substr(\sha1(\strtolower(\rtrim(\str_replace('\\', '/', (string)\getcwd()), '/'))), 0, 8),
            'dev'
        );
        self::assertContains($expected, $domains);
        self::assertStringEndsWith('.test.weline.com', $expected);
    }

    public function testEnsureWebsiteDomainBindingMethodExistsForWlsSync(): void
    {
        self::assertTrue(\method_exists(DefaultWebsiteService::class, 'ensureWebsiteDomainBinding'));
    }
}

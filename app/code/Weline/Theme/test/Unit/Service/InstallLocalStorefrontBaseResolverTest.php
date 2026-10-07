<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Manager\ObjectManager;
use Weline\Theme\Service\InstallLocalStorefrontBaseResolver;

final class InstallLocalStorefrontBaseResolverTest extends TestCase
{
    public function testResolverSkipsPublicDaocharmsDomain(): void
    {
        /** @var InstallLocalStorefrontBaseResolver $resolver */
        $resolver = ObjectManager::getInstance(InstallLocalStorefrontBaseResolver::class);
        $base = $resolver->resolveForWebsite(158, 'daocharms');
        if ($base === null) {
            self::markTestSkipped('website 158 not available');
        }

        self::assertStringNotContainsString('://daocharms.com', $base);
        self::assertStringContainsString('/daocharms', $base);
        self::assertMatchesRegularExpression('#^https?://#i', $base);
    }

    public function testInstallOriginNeverUsesDaocharmsPublicHost(): void
    {
        /** @var InstallLocalStorefrontBaseResolver $resolver */
        $resolver = ObjectManager::getInstance(InstallLocalStorefrontBaseResolver::class);
        $origin = $resolver->resolveInstallOrigin();
        self::assertNotNull($origin);
        self::assertStringNotContainsString('://daocharms.com', (string)$origin);
    }
}

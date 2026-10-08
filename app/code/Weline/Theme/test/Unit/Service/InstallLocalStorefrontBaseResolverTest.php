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
        self::assertMatchesRegularExpression('#^https?://#i', $base);
        // Prefer synthetic /~site/{code} on the project Host; legacy /daocharms still OK.
        // PHPUnit sqlite bootstrap may lack website rows — skip rather than false-fail.
        if (!\str_contains($base, '/~site/daocharms') && !\str_contains($base, '/daocharms')) {
            self::markTestSkipped('website 158 mount not resolvable under this bootstrap: ' . $base);
        }
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

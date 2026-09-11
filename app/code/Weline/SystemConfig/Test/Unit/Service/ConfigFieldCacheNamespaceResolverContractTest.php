<?php

declare(strict_types=1);

namespace Weline\SystemConfig\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Cache\Namespace\NamespacePath;
use Weline\SystemConfig\Service\ConfigFieldCacheNamespaceResolver;

final class ConfigFieldCacheNamespaceResolverContractTest extends TestCase
{
    private ConfigFieldCacheNamespaceResolver $resolver;

    protected function setUp(): void
    {
        $this->resolver = new ConfigFieldCacheNamespaceResolver(new NamespacePath());
    }

    public function testDeclarationNormalizesLeafPrefixAndStarSugar(): void
    {
        $paths = $this->resolver->resolve([
            'storefront/captcha',
            'storefront',
            'storefront/*',
            'captcha',
        ]);
        self::assertContains('global/storefront/captcha', $paths);
        self::assertContains('global/storefront', $paths);
        self::assertSame($paths, array_values(array_unique($paths)));
    }

    public function testRequestWithoutDeclarationRejectsArbitraryGlobal(): void
    {
        $paths = $this->resolver->resolve([], ['global/storefront/evil-custom', 'storefront/captcha']);
        self::assertSame(['global/storefront/captcha'], $paths);
    }

    public function testDeclarationWinsOverIncompatibleRequest(): void
    {
        $paths = $this->resolver->resolve(['storefront/captcha'], ['storefront/auth']);
        self::assertSame(['global/storefront/captcha'], $paths);
    }

    public function testKeyBindingMapsCaptchaKeys(): void
    {
        $paths = $this->resolver->resolve([], [], true, ['captcha/google/api_key']);
        self::assertSame(['global/storefront/captcha'], $paths);
    }

    public function testControlReinforcesDeclaredParentChild(): void
    {
        $paths = $this->resolver->resolve(
            ['storefront/captcha'],
            ['storefront/captcha', 'captcha'],
            true,
        );
        self::assertSame(['global/storefront/captcha'], $paths);
    }

    public function testBindKeyPrefixesAttributeActivatesRule(): void
    {
        $paths = $this->resolver->fromConfigKeys([], ['captcha/']);
        self::assertSame(['global/storefront/captcha'], $paths);
    }
}

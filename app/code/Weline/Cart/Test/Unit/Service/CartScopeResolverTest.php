<?php

declare(strict_types=1);

namespace Weline\Cart\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Cart\Api\CartScopeResolverInterface;
use Weline\Cart\Service\CartScopeResolver;
use Weline\Framework\Compilation\ModuleRegistryCompiler;
use Weline\Framework\Compilation\ServiceProviderRegistry;
use Weline\Framework\Context;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\RequestContext;
use Weline\Framework\Runtime\RuntimeProviderResolver;
use Weline\Framework\Runtime\ScopeIdentity;
use Weline\Framework\Runtime\StorefrontNavigationScope;
use Weline\Framework\Runtime\StorefrontScopeInstallerInterface;
use Weline\Framework\Service\Query\Value\FrontendWorkerExecutionContext;
use Weline\Framework\Service\Query\Value\FrontendWorkerScopeBinding;

final class CartScopeResolverTest extends TestCase
{
    public function testResolverPublishesStableCrossModuleInterface(): void
    {
        self::assertInstanceOf(CartScopeResolverInterface::class, new CartScopeResolver());
    }

    public function testMissingWorkerBindingFallsBackToServerResolvedDefaultChannel(): void
    {
        $trusted = ScopeIdentity::channel(
            0,
            'default',
            'default',
            'default',
            ScopeIdentity::MODE_NORMAL,
        );

        self::assertSame(
            $trusted->canonicalKey(),
            (new CartScopeResolver(static fn(): ScopeIdentity => $trusted))
                ->fromParams([])
                ->canonicalKey(),
        );
    }

    public function testServerInstallerNavigationScopeUsesIdentityContract(): void
    {
        $trusted = ScopeIdentity::channel(
            0,
            'default',
            'default',
            'default',
            ScopeIdentity::MODE_NORMAL,
        );
        $installer = new CartScopeResolverTestStorefrontInstaller(
            new StorefrontNavigationScope($trusted, '/products'),
        );
        $registryFile = \tempnam(\sys_get_temp_dir(), 'weline-cart-provider-');
        self::assertIsString($registryFile);
        self::assertNotFalse(\file_put_contents(
            $registryFile,
            "<?php\n\nreturn " . \var_export([
                'format' => ModuleRegistryCompiler::FORMAT_VERSION,
                'order' => ['Weline_Cart_Test'],
                'modules' => [
                    'Weline_Cart_Test' => [
                        'provides' => [
                            StorefrontScopeInstallerInterface::class
                                => CartScopeResolverTestStorefrontInstaller::class,
                        ],
                    ],
                ],
            ], true) . ";\n",
        ));

        $previousRuntimeResolver = ObjectManager::_getInstance(RuntimeProviderResolver::class);
        $previousInstaller = ObjectManager::_getInstance(CartScopeResolverTestStorefrontInstaller::class);
        $runtimeResolver = new RuntimeProviderResolver(new ServiceProviderRegistry($registryFile));
        ObjectManager::setInstance(RuntimeProviderResolver::class, $runtimeResolver);
        ObjectManager::setInstance(CartScopeResolverTestStorefrontInstaller::class, $installer);
        Context::enter(new Context([
            'input' => [
                'server' => ['HTTP_HOST' => 'shop.example'],
                'scheme' => 'https',
                'host' => 'shop.example',
            ],
        ]));

        try {
            self::assertSame(
                $trusted->canonicalKey(),
                (new CartScopeResolver())->fromParams([])->canonicalKey(),
            );
            self::assertSame('https://shop.example/', $installer->requestedUri);
        } finally {
            Context::leave();
            ObjectManager::removeInstance(RuntimeProviderResolver::class);
            ObjectManager::removeInstance(CartScopeResolverTestStorefrontInstaller::class);
            if (\is_object($previousRuntimeResolver)) {
                ObjectManager::setInstance(RuntimeProviderResolver::class, $previousRuntimeResolver);
            }
            if (\is_object($previousInstaller)) {
                ObjectManager::setInstance(CartScopeResolverTestStorefrontInstaller::class, $previousInstaller);
            }
            @\unlink($registryFile);
        }
    }

    public function testWebsiteProjectionCannotDowngradeServerResolvedChannel(): void
    {
        $trusted = ScopeIdentity::channel(
            0,
            'default',
            'default',
            'default',
            ScopeIdentity::MODE_NORMAL,
        );

        self::assertSame(
            $trusted->canonicalKey(),
            (new CartScopeResolver(static fn(): ScopeIdentity => $trusted))
                ->fromParams(['scope' => ScopeIdentity::website(0, 'default')->toArray()])
                ->canonicalKey(),
        );
    }

    public function testMissingClientScopeUsesSignedFrontendWorkerBindingWhenRequestScopeIsNotInstalled(): void
    {
        Context::enter(new Context());
        try {
            $trusted = ScopeIdentity::channel(
                0,
                'default',
                'default',
                'default',
                ScopeIdentity::MODE_NORMAL,
            );
            $binding = new FrontendWorkerScopeBinding(
                $trusted,
                '127.0.0.1:9514',
                \str_repeat('a', 64),
                \time() - 10,
                \time() + 350,
            );
            RequestContext::set(
                FrontendWorkerExecutionContext::REQUEST_CONTEXT_KEY,
                FrontendWorkerExecutionContext::frontend($binding),
            );

            self::assertSame(
                $trusted->canonicalKey(),
                (new CartScopeResolver())->fromParams([])->canonicalKey(),
            );
        } finally {
            Context::leave();
        }
    }

    public function testMissingClientScopeUsesCurrentTrustedRequestScope(): void
    {
        Context::enter(new Context());
        try {
            $trusted = ScopeIdentity::channel(
                0,
                'default',
                'default',
                'web',
                ScopeIdentity::MODE_NORMAL,
            );
            RequestContext::installScopeIdentity($trusted);

            self::assertSame(
                $trusted->canonicalKey(),
                (new CartScopeResolver())->fromParams([])->canonicalKey(),
            );
        } finally {
            Context::leave();
        }
    }

    public function testExplicitClientScopeMustMatchTrustedRequestScope(): void
    {
        Context::enter(new Context());
        try {
            $trusted = ScopeIdentity::channel(
                0,
                'default',
                'default',
                'web',
                ScopeIdentity::MODE_NORMAL,
            );
            RequestContext::installScopeIdentity($trusted);

            $explicit = (new CartScopeResolver())->fromParams([
                'website_id' => 0,
                'website_code' => 'default',
                'store_code' => 'default',
                'channel_code' => 'web',
                'store_mode' => ScopeIdentity::MODE_NORMAL,
            ]);

            self::assertSame($trusted->canonicalKey(), $explicit->canonicalKey());
        } finally {
            Context::leave();
        }
    }

    public function testExplicitCrossWebsiteScopeIsRejectedInsideTrustedRequest(): void
    {
        Context::enter(new Context());
        try {
            RequestContext::installScopeIdentity(ScopeIdentity::channel(
                2,
                'site-b',
                'scope',
                'default',
                ScopeIdentity::MODE_NORMAL,
            ));

            try {
                (new CartScopeResolver())->fromParams([
                    'website_id' => 1,
                    'website_code' => 'site-a',
                    'store_code' => 'scope',
                    'channel_code' => 'default',
                    'store_mode' => ScopeIdentity::MODE_NORMAL,
                ]);
                self::fail('Cross-Website browser scope must fail closed.');
            } catch (\Weline\Cart\Service\CartConflictException $exception) {
                self::assertSame('cart_scope_request_conflict', $exception->errorCode());
                self::assertStringContainsString('channel|2|site-b', $exception->context()['trusted_scope_key']);
                self::assertStringContainsString('channel|1|site-a', $exception->context()['requested_scope_key']);
            }
        } finally {
            Context::leave();
        }
    }

    public function testExplicitWebsiteOnlyParamsRefineToDefaultChannelWithoutRequestContext(): void
    {
        $scope = (new CartScopeResolver())->fromParams([
            'website_id' => 7,
            'website_code' => 'worker-site',
        ]);

        // Cart rows are Channel-keyed; Website-only params must not open a
        // parallel empty website|… namespace (QueryBin website projection).
        self::assertSame(
            ScopeIdentity::channel(
                7,
                'worker-site',
                'default',
                'default',
                ScopeIdentity::MODE_NORMAL,
            )->canonicalKey(),
            $scope->canonicalKey(),
        );
    }

    public function testWebsiteRequestContextRefinesToDefaultChannelWhenInstallerUnavailable(): void
    {
        Context::enter(new Context());
        try {
            RequestContext::installScopeIdentity(ScopeIdentity::website(0, 'default'));

            self::assertSame(
                ScopeIdentity::channel(
                    0,
                    'default',
                    'default',
                    'default',
                    ScopeIdentity::MODE_NORMAL,
                )->canonicalKey(),
                (new CartScopeResolver())->fromParams([])->canonicalKey(),
            );
        } finally {
            Context::leave();
        }
    }
}

final class CartScopeResolverTestStorefrontInstaller implements StorefrontScopeInstallerInterface
{
    public string $requestedUri = '';

    public function __construct(
        private readonly StorefrontNavigationScope $navigationScope,
    ) {
    }

    public function installNavigationScope(string $fullUri): StorefrontNavigationScope
    {
        $this->requestedUri = $fullUri;
        return $this->navigationScope;
    }
}

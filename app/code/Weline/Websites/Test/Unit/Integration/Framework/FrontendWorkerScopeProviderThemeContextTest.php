<?php

declare(strict_types=1);

namespace Weline\Websites\Test\Unit\Integration\Framework;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Context;
use Weline\Framework\Event\EventsManager;
use Weline\Framework\Runtime\RequestContext;
use Weline\Framework\Runtime\ScopeIdentity;
use Weline\Framework\Runtime\ThemeApplicationContext;
use Weline\Websites\Api\Catalog\SalesChannelCatalogInterface;
use Weline\Websites\Api\Catalog\StoreCatalogInterface;
use Weline\Websites\Integration\Framework\FrontendWorkerScopeProvider;
use Weline\Websites\Model\Website;
use Weline\Websites\Service\ScopeKernelRolloutPolicy;
use Weline\Websites\Service\ScopeTokenService;

/**
 * Query-bin defers Scope install; trusted Worker restore must re-dispatch
 * application_context_ready so ThemeApplicationContext is present for Slot render.
 */
final class FrontendWorkerScopeProviderThemeContextTest extends TestCase
{
    protected function setUp(): void
    {
        if (!\function_exists('__')) {
            eval('function __(string $text, array $args = []): string { return $text; }');
        }
    }

    protected function tearDown(): void
    {
        RequestContext::remove(ThemeApplicationContext::REQUEST_KEY_PREFIX . 'frontend.runtime');
        RequestContext::cleanup();
        while (Context::getCurrent() !== null) {
            Context::leave();
        }
    }

    public function testEnsureThemeApplicationContextReadyRedispatchesAfterDeferredScope(): void
    {
        Context::enter(new Context([
            'meta' => ['type' => 'request'],
            'route' => ['area' => RequestContext::AREA_REST_FRONTEND],
        ]));
        RequestContext::init();
        RequestContext::setWelineArea(RequestContext::AREA_REST_FRONTEND);

        self::assertNull(ThemeApplicationContext::current('frontend'));

        $scope = ScopeIdentity::channel(0, 'default', 'main', 'web', 'normal');
        $events = $this->createMock(EventsManager::class);
        $events->expects(self::once())
            ->method('dispatch')
            ->with(
                'Weline_Framework::App::application_context_ready',
                self::callback(static function (array &$data) use ($scope): bool {
                    self::assertSame('frontend', $data['area'] ?? null);
                    self::assertArrayHasKey('navigation_scope', $data);
                    self::assertNull($data['navigation_scope']);
                    self::assertSame($scope, $data['scope_identity'] ?? null);
                    // Simulate the Websites observer installing runtime theme input.
                    (new ThemeApplicationContext(
                        provider: 'websites',
                        scopeKey: 'default.main.web',
                        storeMode: 'normal',
                        area: 'frontend',
                        themeId: 3,
                        versionOwnerScope: 'default.main.web',
                        versionOwnerStoreMode: 'normal',
                        themeVersionId: 11,
                        contentRevision: 4,
                        defaultLocale: 'zh_Hans_CN',
                        contentScopes: [[
                            'provider' => 'websites',
                            'scope_key' => 'default.main.web',
                            'store_mode' => 'normal',
                        ]],
                        purpose: 'runtime',
                    ))->install();

                    return true;
                }),
            )
            ->willReturnSelf();

        $provider = new FrontendWorkerScopeProvider(
            (new \ReflectionClass(ScopeKernelRolloutPolicy::class))->newInstanceWithoutConstructor(),
            (new \ReflectionClass(ScopeTokenService::class))->newInstanceWithoutConstructor(),
            $this->createStub(StoreCatalogInterface::class),
            $this->createStub(SalesChannelCatalogInterface::class),
            $this->createStub(Website::class),
            $events,
        );

        $method = new \ReflectionMethod(FrontendWorkerScopeProvider::class, 'ensureThemeApplicationContextReady');
        $method->setAccessible(true);
        $method->invoke($provider, $scope);

        $context = ThemeApplicationContext::current('frontend');
        self::assertInstanceOf(ThemeApplicationContext::class, $context);
        self::assertSame(3, $context->themeId);
        self::assertSame('runtime', $context->purpose);
    }

    public function testSourceDocumentsDeferredApplicationContextRedispatch(): void
    {
        $src = (string)\file_get_contents(
            \dirname(__DIR__, 4) . '/Integration/Framework/FrontendWorkerScopeProvider.php',
        );
        self::assertStringContainsString('ensureThemeApplicationContextReady', $src);
        self::assertStringContainsString('Weline_Framework::App::application_context_ready', $src);
        self::assertStringContainsString('theme_runtime_consumer_context_required', $src);
        self::assertMatchesRegularExpression(
            '/installTrustedScope[\s\S]*ensureThemeApplicationContextReady/',
            $src,
        );
    }
}

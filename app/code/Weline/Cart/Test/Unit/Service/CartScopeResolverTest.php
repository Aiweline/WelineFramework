<?php

declare(strict_types=1);

namespace Weline\Cart\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Cart\Api\CartScopeResolverInterface;
use Weline\Cart\Service\CartScopeResolver;
use Weline\Framework\Context;
use Weline\Framework\Runtime\RequestContext;
use Weline\Framework\Runtime\ScopeIdentity;
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
            } catch (\Weline\Cart\Service\CartV2ConflictException $exception) {
                self::assertSame('cart_scope_request_conflict', $exception->errorCode());
                self::assertStringContainsString('channel|2|site-b', $exception->context()['trusted_scope_key']);
                self::assertStringContainsString('channel|1|site-a', $exception->context()['requested_scope_key']);
            }
        } finally {
            Context::leave();
        }
    }

    public function testExplicitScopeRemainsAvailableWithoutRequestContext(): void
    {
        $scope = (new CartScopeResolver())->fromParams([
            'website_id' => 7,
            'website_code' => 'worker-site',
        ]);

        self::assertSame(
            ScopeIdentity::website(7, 'worker-site')->canonicalKey(),
            $scope->canonicalKey(),
        );
    }
}

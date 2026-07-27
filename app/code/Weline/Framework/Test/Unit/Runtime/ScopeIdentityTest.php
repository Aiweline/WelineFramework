<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Runtime;

use Fiber;
use PHPUnit\Framework\TestCase;
use Weline\Framework\Context;
use Weline\Framework\Env\WelineEnv;
use Weline\Framework\Runtime\RequestContext;
use Weline\Framework\Runtime\ScopeEnvelope;
use Weline\Framework\Runtime\ScopeIdentity;
use Weline\Framework\Runtime\StorefrontWebsiteContext;
use Weline\Framework\Service\Query\Value\FrontendWorkerExecutionContext;
use Weline\Framework\Service\Query\Value\FrontendWorkerScopeBinding;

final class ScopeIdentityTest extends TestCase
{
    public function testCanonicalIdentitiesRoundTripAndKeepGlobalSeparateFromZeroWebsite(): void
    {
        $identities = [
            ScopeIdentity::global(),
            ScopeIdentity::website(0, 'default'),
            ScopeIdentity::store(0, 'default', 'default', ScopeIdentity::MODE_NORMAL),
            ScopeIdentity::channel(0, 'default', 'default', 'default', ScopeIdentity::MODE_NORMAL),
        ];

        foreach ($identities as $identity) {
            self::assertSame($identity->toArray(), ScopeIdentity::fromArray($identity->toArray())->toArray());
        }

        self::assertNotSame($identities[0]->canonicalKey(), $identities[1]->canonicalKey());
        self::assertNull($identities[0]->websiteId);
        self::assertSame(0, $identities[1]->websiteId);
    }

    public function testFromArrayRejectsEveryMissingSerializedField(): void
    {
        $canonical = ScopeIdentity::channel(
            0,
            'default',
            'default',
            'default',
            ScopeIdentity::MODE_NORMAL,
        )->toArray();

        foreach (array_keys($canonical) as $field) {
            $claims = $canonical;
            unset($claims[$field]);
            try {
                ScopeIdentity::fromArray($claims);
                self::fail('Missing field must be rejected: ' . $field);
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testFromArrayRejectsTypeCoercionAndNonCanonicalClaims(): void
    {
        $canonical = ScopeIdentity::channel(
            0,
            'default',
            'default',
            'default',
            ScopeIdentity::MODE_NORMAL,
        )->toArray();
        $invalidClaims = [
            'kind_case' => ['scope_kind' => 'CHANNEL'],
            'kind_whitespace' => ['scope_kind' => ' channel '],
            'website_id_string' => ['website_id' => '0'],
            'website_code_case' => ['website_code' => 'Default'],
            'website_code_whitespace' => ['website_code' => ' default'],
            'store_code_case' => ['store_code' => 'Default'],
            'channel_code_whitespace' => ['channel_code' => 'default '],
            'store_mode_case' => ['store_mode' => 'NORMAL'],
            'context_version_whitespace' => ['context_version' => ' v1'],
            'context_version_empty' => ['context_version' => ''],
        ];

        foreach ($invalidClaims as $label => $patch) {
            try {
                ScopeIdentity::fromArray(array_replace($canonical, $patch));
                self::fail('Non-canonical claims must be rejected: ' . $label);
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testFromArrayAllowsNonIdentityTokenMetadataWithoutWeakeningIdentityClaims(): void
    {
        $claims = ScopeIdentity::channel(
            0,
            'default',
            'default',
            'default',
            ScopeIdentity::MODE_NORMAL,
        )->toArray() + [
            'aud' => 'weline.storefront.v1',
            'host' => 'example.test',
            'iat' => 1,
            'exp' => 2,
        ];

        self::assertSame(
            'channel|0|default|default|default|normal|v1',
            ScopeIdentity::fromArray($claims)->canonicalKey(),
        );
    }

    public function testEnvelopeCanonicalArrayRequiresExplicitVersion(): void
    {
        $data = ScopeEnvelope::of(ScopeIdentity::global())->toArray();
        unset($data['envelope_version']);

        $this->expectException(\InvalidArgumentException::class);
        ScopeEnvelope::fromArray($data);
    }

    public function testFrontendWorkerBindingKeepsCanonicalSevenFieldScopeRoundTrip(): void
    {
        $binding = new FrontendWorkerScopeBinding(
            ScopeIdentity::channel(
                0,
                'default',
                'default',
                'default',
                ScopeIdentity::MODE_NORMAL,
            ),
            'shop.example.test',
            str_repeat('a', 64),
            1,
            2,
            true,
        );

        $restored = FrontendWorkerScopeBinding::fromArray($binding->toArray());

        self::assertSame($binding->toArray(), $restored->toArray());
        self::assertSame(0, $restored->scope->websiteId);
    }

    public function testRequestContextRestoresOnlyCanonicalSerializedIdentity(): void
    {
        $context = new Context();
        $context->set(
            'runtime.request_context.scope_identity',
            ScopeIdentity::channel(
                0,
                'default',
                'default',
                'default',
                ScopeIdentity::MODE_NORMAL,
            )->toArray(),
        );
        Context::enter($context);

        try {
            $restored = RequestContext::scopeIdentity();

            self::assertSame(0, $restored?->websiteId);
            self::assertSame(
                'channel|0|default|default|default|normal|v1',
                $restored?->canonicalKey(),
            );
            self::assertInstanceOf(
                ScopeIdentity::class,
                $context->get('runtime.request_context.scope_identity'),
            );
        } finally {
            Context::leave();
        }
    }

    public function testScopeMetadataMatchesFrozenZeroWebsiteContextAndResetClearsIt(): void
    {
        $server = $_SERVER;
        Context::enter(new Context());

        try {
            RequestContext::setWelineWebsiteId(0);
            RequestContext::setWelineWebsiteCode('default');
            RequestContext::setWelineStoreId(11);
            RequestContext::setWelineStoreCode('default');
            RequestContext::setWelineStoreMode('normal');
            RequestContext::setWelineChannelId(21);
            RequestContext::setWelineChannelCode('default');
            RequestContext::installScopeIdentity(ScopeIdentity::channel(
                0,
                'default',
                'default',
                'default',
                ScopeIdentity::MODE_NORMAL,
            ));
            RequestContext::setWelineUserLang('en_US');
            RequestContext::setWelineUserCurrency('USD');
            RequestContext::setWelineTimezone('UTC');
            RequestContext::setStorefrontRoutePath('/catalog');

            self::assertSame([
                'scope_kind' => 'channel',
                'website_id' => 0,
                'website_code' => 'default',
                'store_id' => 11,
                'store_code' => 'default',
                'store_mode' => 'normal',
                'channel_id' => 21,
                'channel_code' => 'default',
                'locale' => 'en_US',
                'currency' => 'USD',
                'timezone' => 'UTC',
                'context_version' => 'v1',
            ], RequestContext::scopeMetadata());
            self::assertSame('/catalog', RequestContext::getStorefrontRoutePath());

            RequestContext::resetWelineVars();
            self::assertNull(RequestContext::scopeMetadata());
            self::assertNull(RequestContext::getStorefrontRoutePath());
        } finally {
            $_SERVER = $server;
            Context::leave();
        }
    }

    public function testTrustedWorkerCanRefineRestScopeWithinTheSameWebsite(): void
    {
        $server = $_SERVER;
        Context::enter(new Context(['route' => ['area' => RequestContext::AREA_REST_FRONTEND]]));

        try {
            self::installFrozenScope(103, 'shop_b', 40, 'default', 50, '/api/framework/query-bin', 'Asia/Shanghai');
            $replacement = ScopeIdentity::channel(
                103,
                'shop_b',
                'test_store',
                'default',
                ScopeIdentity::MODE_TEST,
            );
            $binding = new FrontendWorkerScopeBinding(
                $replacement,
                'shop.example.test:19738',
                hash('sha256', 'trusted-worker-token'),
                1_000,
                2_800,
                true,
            );
            RequestContext::set(
                FrontendWorkerExecutionContext::REQUEST_CONTEXT_KEY,
                FrontendWorkerExecutionContext::frontend($binding),
            );

            RequestContext::replaceScopeIdentityForTrustedWorker($binding, 41, 51);

            self::assertTrue(RequestContext::scopeIdentity()?->equals($replacement) ?? false);
            self::assertSame(41, RequestContext::getWelineStoreId());
            self::assertSame(51, RequestContext::getWelineChannelId());
            self::assertSame('en_US', RequestContext::getWelineUserLang());
            self::assertSame('USD', RequestContext::getWelineUserCurrency());
            self::assertSame('Asia/Shanghai', RequestContext::getWelineTimezone());
            self::assertNull(RequestContext::getStorefrontRoutePath());
        } finally {
            $_SERVER = $server;
            Context::leave();
        }
    }

    public function testTrustedWorkerScopeReplacementRejectsCrossWebsiteBinding(): void
    {
        $server = $_SERVER;
        Context::enter(new Context(['route' => ['area' => RequestContext::AREA_REST_FRONTEND]]));

        try {
            self::installFrozenScope(103, 'shop_b', 40, 'default', 50, '/api/framework/query-bin', 'UTC');
            $binding = new FrontendWorkerScopeBinding(
                ScopeIdentity::channel(104, 'shop_c', 'test_store', 'default', ScopeIdentity::MODE_TEST),
                'shop.example.test:19738',
                hash('sha256', 'cross-website-token'),
                1_000,
                2_800,
                true,
            );
            RequestContext::set(
                FrontendWorkerExecutionContext::REQUEST_CONTEXT_KEY,
                FrontendWorkerExecutionContext::frontend($binding),
            );

            try {
                RequestContext::replaceScopeIdentityForTrustedWorker($binding, 41, 51);
                self::fail('Cross-Website Worker binding replaced the frozen navigation Scope.');
            } catch (\LogicException $exception) {
                self::assertSame('Trusted Worker Scope replacement precondition failed.', $exception->getMessage());
                self::assertSame(103, RequestContext::scopeIdentity()?->websiteId);
                self::assertSame(40, RequestContext::getWelineStoreId());
                self::assertSame('/api/framework/query-bin', RequestContext::getStorefrontRoutePath());
            }
        } finally {
            $_SERVER = $server;
            Context::leave();
        }
    }

    public function testTrustedWorkerScopeReplacementRejectsEveryTrustBoundaryViolation(): void
    {
        $server = $_SERVER;
        $cases = [
            'non_rest_frontend' => [
                'area' => RequestContext::AREA_FRONTEND,
                'replacement_code' => 'shop_b',
                'execution_matches' => true,
                'store_id' => 41,
                'channel_id' => 51,
            ],
            'execution_binding_digest_mismatch' => [
                'area' => RequestContext::AREA_REST_FRONTEND,
                'replacement_code' => 'shop_b',
                'execution_matches' => false,
                'store_id' => 41,
                'channel_id' => 51,
            ],
            'website_code_mismatch' => [
                'area' => RequestContext::AREA_REST_FRONTEND,
                'replacement_code' => 'shop_b_alt',
                'execution_matches' => true,
                'store_id' => 41,
                'channel_id' => 51,
            ],
            'invalid_store_id' => [
                'area' => RequestContext::AREA_REST_FRONTEND,
                'replacement_code' => 'shop_b',
                'execution_matches' => true,
                'store_id' => 0,
                'channel_id' => 51,
            ],
            'invalid_channel_id' => [
                'area' => RequestContext::AREA_REST_FRONTEND,
                'replacement_code' => 'shop_b',
                'execution_matches' => true,
                'store_id' => 41,
                'channel_id' => 0,
            ],
        ];

        try {
            foreach ($cases as $label => $case) {
                Context::enter(new Context(['route' => ['area' => $case['area']]]));
                try {
                    self::installFrozenScope(
                        103,
                        'shop_b',
                        40,
                        'default',
                        50,
                        '/api/framework/query-bin',
                        'UTC',
                    );
                    $binding = new FrontendWorkerScopeBinding(
                        ScopeIdentity::channel(
                            103,
                            $case['replacement_code'],
                            'test_store',
                            'default',
                            ScopeIdentity::MODE_TEST,
                        ),
                        'shop.example.test:19738',
                        hash('sha256', 'boundary-' . $label),
                        1_000,
                        2_800,
                        true,
                    );
                    $executionBinding = $case['execution_matches']
                        ? $binding
                        : new FrontendWorkerScopeBinding(
                            $binding->scope,
                            $binding->authorityHost,
                            hash('sha256', 'different-execution-binding'),
                            $binding->tokenIssuedAt,
                            $binding->tokenExpiresAt,
                            $binding->authoritativeAtIssue,
                        );
                    RequestContext::set(
                        FrontendWorkerExecutionContext::REQUEST_CONTEXT_KEY,
                        FrontendWorkerExecutionContext::frontend($executionBinding),
                    );

                    try {
                        RequestContext::replaceScopeIdentityForTrustedWorker(
                            $binding,
                            $case['store_id'],
                            $case['channel_id'],
                        );
                        self::fail('Invalid trusted Worker refinement was accepted: ' . $label);
                    } catch (\LogicException $exception) {
                        self::assertSame(
                            'Trusted Worker Scope replacement precondition failed.',
                            $exception->getMessage(),
                        );
                        self::assertSame(103, RequestContext::scopeIdentity()?->websiteId);
                        self::assertSame('shop_b', RequestContext::scopeIdentity()?->websiteCode);
                        self::assertSame(40, RequestContext::getWelineStoreId());
                        self::assertSame(50, RequestContext::getWelineChannelId());
                        self::assertSame('/api/framework/query-bin', RequestContext::getStorefrontRoutePath());
                    }
                } finally {
                    Context::leave();
                }
            }
        } finally {
            $_SERVER = $server;
        }
    }

    public function testFrozenNavigationStateSurvivesSnapshotRebuildAndRejectsMutation(): void
    {
        $server = $_SERVER;
        Context::enter(new Context());

        try {
            RequestContext::setWelineWebsiteId(0);
            RequestContext::setWelineWebsiteCode('default');
            RequestContext::setWelineStoreId(11);
            RequestContext::setWelineStoreCode('default');
            RequestContext::setWelineStoreMode('normal');
            RequestContext::setWelineChannelId(21);
            RequestContext::setWelineChannelCode('default');
            RequestContext::installScopeIdentity(ScopeIdentity::channel(
                0,
                'default',
                'default',
                'default',
                ScopeIdentity::MODE_NORMAL,
            ));
            RequestContext::setWelineUserLang('en_US');
            RequestContext::setWelineUserCurrency('USD');
            RequestContext::setWelineTimezone('Asia/Shanghai');
            RequestContext::setStorefrontRoutePath('/item');

            (new WelineEnv())->initFromSnapshot([], [], [], [], [
                'REQUEST_METHOD' => 'GET',
                'REQUEST_URI' => '/item',
                'WELINE_WEBSITE_ID' => '0',
                'WELINE_WEBSITE_CODE' => 'default',
                'WELINE_USER_LANG' => 'en_US',
                'WELINE_USER_CURRENCY' => 'USD',
            ]);

            self::assertSame('/item', RequestContext::getStorefrontRoutePath());
            self::assertSame('Asia/Shanghai', RequestContext::getWelineTimezone());
            self::assertSame(11, RequestContext::getWelineStoreId());
            self::assertSame(21, RequestContext::getWelineChannelId());
            self::assertSame('/item', Context::current()->get('input.uri'));
            self::assertSame('Asia/Shanghai', RequestContext::scopeMetadata()['timezone'] ?? null);

            RequestContext::setStorefrontRoutePath('/item');
            RequestContext::setWelineTimezone('Asia/Shanghai');

            try {
                RequestContext::setStorefrontRoutePath('/other');
                self::fail('A frozen Storefront route path must reject mutation.');
            } catch (\LogicException) {
                self::addToAssertionCount(1);
            }
            self::assertSame('/item', RequestContext::getStorefrontRoutePath());

            try {
                RequestContext::setWelineTimezone('UTC');
                self::fail('A frozen request timezone must reject mutation.');
            } catch (\LogicException) {
                self::addToAssertionCount(1);
            }
            self::assertSame('Asia/Shanghai', RequestContext::getWelineTimezone());
        } finally {
            $_SERVER = $server;
            Context::leave();
        }
    }

    public function testFrozenScopeMetadataAndRoutePathRemainFiberIsolatedWhenPeerResets(): void
    {
        $server = $_SERVER;
        $observed = [];

        $fiberA = new Fiber(function () use (&$observed): void {
            Context::enter(new Context());
            try {
                self::installFrozenScope(0, 'default', 11, 'zero', 21, '/zero', 'Asia/Shanghai');
                Fiber::suspend('a-ready');
                $observed['a_after_b'] = [
                    'meta' => RequestContext::scopeMetadata(),
                    'route_path' => RequestContext::getStorefrontRoutePath(),
                ];
                RequestContext::resetWelineVars();
                Fiber::suspend('a-reset');
            } finally {
                Context::leave();
            }
        });

        $fiberB = new Fiber(function () use (&$observed): void {
            Context::enter(new Context());
            try {
                self::installFrozenScope(7, 'shop_b', 12, 'normal_b', 22, '/shop-b', 'UTC');
                Fiber::suspend('b-ready');
                $observed['b_after_a_reset'] = [
                    'meta' => RequestContext::scopeMetadata(),
                    'route_path' => RequestContext::getStorefrontRoutePath(),
                ];
                Fiber::suspend('b-verified');
            } finally {
                Context::leave();
            }
        });

        try {
            self::assertSame('a-ready', $fiberA->start());
            self::assertSame('b-ready', $fiberB->start());
            self::assertSame('a-reset', $fiberA->resume());
            self::assertSame('b-verified', $fiberB->resume());

            self::assertSame(0, $observed['a_after_b']['meta']['website_id'] ?? null);
            self::assertSame(11, $observed['a_after_b']['meta']['store_id'] ?? null);
            self::assertSame('/zero', $observed['a_after_b']['route_path'] ?? null);
            self::assertSame('Asia/Shanghai', $observed['a_after_b']['meta']['timezone'] ?? null);

            self::assertSame(7, $observed['b_after_a_reset']['meta']['website_id'] ?? null);
            self::assertSame(12, $observed['b_after_a_reset']['meta']['store_id'] ?? null);
            self::assertSame('/shop-b', $observed['b_after_a_reset']['route_path'] ?? null);
            self::assertSame('UTC', $observed['b_after_a_reset']['meta']['timezone'] ?? null);

            $fiberA->resume();
            $fiberB->resume();
            self::assertTrue($fiberA->isTerminated());
            self::assertTrue($fiberB->isTerminated());
        } finally {
            $_SERVER = $server;
        }
    }

    public function testReadOnlyWebsiteContextDistinguishesMissingIdentityFromZeroWebsite(): void
    {
        $context = new StorefrontWebsiteContext(
            0,
            'default',
            '系统默认站点',
            'https://shop.example.test',
            'CNY',
            'zh_Hans_CN',
            'Asia/Shanghai',
        );

        self::assertSame(0, $context->websiteId);
        self::assertSame('default', $context->code);

        $this->expectException(\InvalidArgumentException::class);
        new StorefrontWebsiteContext(
            0,
            'shop',
            '错误站点',
            'https://shop.example.test',
            'CNY',
            'zh_Hans_CN',
            'Asia/Shanghai',
        );
    }

    public function testV1StorageAdapterCanonicalizesDatabaseScalarsAtNamedBoundary(): void
    {
        $envelope = ScopeEnvelope::fromV1StorageArray([
            'scope_kind' => 'channel',
            'website_id' => '0',
            'website_code' => 'default',
            'store_code' => 'default',
            'channel_code' => 'default',
            'store_mode' => 'normal',
            'envelope_version' => 'v1',
        ]);

        self::assertSame('v1|channel|0|default|default|default|normal|v1', $envelope->canonicalKey());
        self::assertSame([
            'scope_kind' => 'channel',
            'website_id' => 0,
            'website_code' => 'default',
            'store_code' => 'default',
            'channel_code' => 'default',
            'store_mode' => 'normal',
            'envelope_version' => 'v1',
        ], $envelope->toV1StorageArray());
    }

    public function testCaptureUsesGlobalOnlyWhenRequestContextIsAbsent(): void
    {
        self::assertTrue(ScopeEnvelope::capture()->scope->isGlobal());

        $invalidRoutes = [
            'website_code_whitespace' => [
                'website_code' => ' default ',
            ],
            'store_code_whitespace' => [
                'website_code' => 'default',
                'store_code' => ' default ',
            ],
            'channel_code_whitespace' => [
                'website_code' => 'default',
                'store_code' => 'default',
                'channel_code' => ' default ',
            ],
            'store_mode_whitespace' => [
                'website_code' => 'default',
                'store_code' => 'default',
                'channel_code' => 'default',
                'store_mode' => ' normal ',
            ],
            'nonzero_website_without_code' => [
                'website_id' => 7,
            ],
            'channel_without_store' => [
                'website_code' => 'default',
                'channel_code' => 'default',
            ],
            'mode_without_store' => [
                'website_code' => 'default',
                'store_mode' => 'normal',
            ],
            'store_without_mode' => [
                'website_code' => 'default',
                'store_code' => 'default',
            ],
        ];

        foreach ($invalidRoutes as $label => $route) {
            $context = new Context();
            $context->set('route.website_id', 0);
            foreach ($route as $field => $value) {
                $context->set('route.' . $field, $value);
            }
            Context::enter($context);

            try {
                ScopeEnvelope::capture();
                self::fail('Invalid scoped request context must fail closed: ' . $label);
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            } finally {
                Context::leave();
            }
        }

        $context = new Context();
        $context->set('route.website_id', 0);
        $context->set('route.website_code', 'default');
        $context->set('route.store_code', 'default');
        $context->set('route.channel_code', 'default');
        $context->set('route.store_mode', 'normal');
        Context::enter($context);
        try {
            self::assertSame(
                'v1|channel|0|default|default|default|normal|v1',
                ScopeEnvelope::capture()->canonicalKey(),
            );
        } finally {
            Context::leave();
        }
    }

    public function testV1StorageAdapterRejectsFutureContextVersionDowngrade(): void
    {
        $envelope = ScopeEnvelope::of(ScopeIdentity::channel(
            0,
            'default',
            'default',
            'default',
            ScopeIdentity::MODE_NORMAL,
            'v2',
        ));

        $this->expectException(\LogicException::class);
        $envelope->toV1StorageArray();
    }

    public function testV1StorageAdapterRejectsFutureEnvelopeVersion(): void
    {
        $data = [
            'scope_kind' => 'global',
            'website_id' => null,
            'website_code' => null,
            'store_code' => null,
            'channel_code' => null,
            'store_mode' => null,
            'envelope_version' => 'v2',
        ];

        $this->expectException(\InvalidArgumentException::class);
        ScopeEnvelope::fromV1StorageArray($data);
    }

    public function testV1StorageAdapterRejectsInjectedContextVersionInsteadOfDowngradingIt(): void
    {
        $data = [
            'scope_kind' => 'global',
            'website_id' => null,
            'website_code' => null,
            'store_code' => null,
            'channel_code' => null,
            'store_mode' => null,
            'envelope_version' => 'v1',
            'context_version' => 'v2',
        ];

        $this->expectException(\InvalidArgumentException::class);
        ScopeEnvelope::fromV1StorageArray($data);
    }

    public function testV1StorageAdapterRejectsWhitespaceNormalization(): void
    {
        $data = [
            'scope_kind' => ' global ',
            'website_id' => null,
            'website_code' => null,
            'store_code' => null,
            'channel_code' => null,
            'store_mode' => null,
            'envelope_version' => 'v1',
        ];

        $this->expectException(\InvalidArgumentException::class);
        ScopeEnvelope::fromV1StorageArray($data);
    }

    public function testV1StorageAdapterRejectsWebsiteCodeBeyondItsFixedColumn(): void
    {
        $envelope = ScopeEnvelope::of(ScopeIdentity::website(1, str_repeat('a', 65)));

        $this->expectException(\LogicException::class);
        $envelope->toV1StorageArray();
    }

    private static function installFrozenScope(
        int $websiteId,
        string $websiteCode,
        int $storeId,
        string $storeCode,
        int $channelId,
        string $routePath,
        string $timezone,
    ): void {
        RequestContext::setWelineWebsiteId($websiteId);
        RequestContext::setWelineWebsiteCode($websiteCode);
        RequestContext::setWelineStoreId($storeId);
        RequestContext::setWelineStoreCode($storeCode);
        RequestContext::setWelineStoreMode('normal');
        RequestContext::setWelineChannelId($channelId);
        RequestContext::setWelineChannelCode('default');
        RequestContext::installScopeIdentity(ScopeIdentity::channel(
            $websiteId,
            $websiteCode,
            $storeCode,
            'default',
            ScopeIdentity::MODE_NORMAL,
        ));
        RequestContext::setWelineUserLang($websiteId === 0 ? 'zh_Hans_CN' : 'en_US');
        RequestContext::setWelineUserCurrency($websiteId === 0 ? 'CNY' : 'USD');
        RequestContext::setWelineTimezone($timezone);
        RequestContext::setStorefrontRoutePath($routePath);
    }
}

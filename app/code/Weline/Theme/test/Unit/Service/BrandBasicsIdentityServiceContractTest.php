<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Runtime\ScopeIdentity;
use Weline\SystemConfig\Api\Scope\ScopeHierarchyInterface;
use Weline\SystemConfig\Api\Scope\ScopeIdentityCatalogInterface;
use Weline\Theme\Api\BrandBasicsIdentityProviderInterface;
use Weline\Theme\Service\BrandBasicsIdentityRegistry;
use Weline\Theme\Service\BrandBasicsIdentityService;

final class BrandBasicsIdentityServiceContractTest extends TestCase
{
    public function testLoadAcceptsPostPrepareThemeContentScopeBag(): void
    {
        $website = ScopeIdentity::website(544, 'changanhanfu');
        $service = $this->service(
            identity: $website,
            provider: $this->providerStub($website, [
                'label' => '网站身份',
                'scope_kind' => ScopeIdentity::KIND_WEBSITE,
                'fields' => [
                    ['key' => 'name', 'type' => 'text', 'label' => '网站名称', 'required' => true, 'max' => 128],
                ],
                'values' => ['name' => '长安汉服'],
            ]),
        );

        // Mirrors ThemeAssetEditorRequestContext::resolve rewrite of scope.
        $payload = $service->loadFromInput([
            'editor_context' => [
                'scope' => [
                    'provider' => 'websites',
                    'scope_key' => 'changanhanfu.__website__.default',
                    'storage_scope' => 'changanhanfu.__website__.default',
                    'store_mode' => 'normal',
                    'display_name' => '长安汉服',
                    'default_locale' => 'zh_Hans_CN',
                    'parent' => null,
                    'fallback_storage_scopes' => ['changanhanfu.__website__.default'],
                    'canonical_key' => '["websites","changanhanfu.__website__.default","normal"]',
                ],
                'area' => 'frontend',
                'resource_type' => 'appearance',
                'theme_id' => 3,
            ],
        ]);

        self::assertTrue($payload['available']);
        self::assertSame(ScopeIdentity::KIND_WEBSITE, $payload['scope_kind']);
        self::assertSame('长安汉服', $payload['values']['name'] ?? null);
        self::assertSame(544, $payload['identity']['website_id'] ?? null);
        self::assertSame('changanhanfu', $payload['identity']['website_code'] ?? null);
    }

    public function testLoadStillAcceptsExplicitScopeIdentityClaims(): void
    {
        $website = ScopeIdentity::website(0, 'default');
        $service = $this->service(
            identity: $website,
            provider: $this->providerStub($website, [
                'label' => '网站身份',
                'scope_kind' => ScopeIdentity::KIND_WEBSITE,
                'fields' => [
                    ['key' => 'name', 'type' => 'text', 'label' => '网站名称', 'required' => true],
                ],
                'values' => ['name' => 'Default'],
            ]),
        );

        $payload = $service->loadFromInput([
            'editor_context' => [
                'scope' => ['identity' => $website->toArray()],
                'area' => 'frontend',
                'resource_type' => 'appearance',
                'theme_id' => 1,
            ],
        ]);

        self::assertTrue($payload['available']);
        self::assertSame('default', $payload['identity']['website_code'] ?? null);
    }

    public function testIncompleteIdentityFallsBackToStorageScope(): void
    {
        $website = ScopeIdentity::website(7, 'shop');
        $service = $this->service(
            identity: $website,
            provider: null,
        );

        $payload = $service->loadFromInput([
            'editor_context' => [
                'scope' => [
                    'identity' => ['store_mode' => 'normal'],
                    'storage_scope' => 'shop.__website__.default',
                ],
            ],
        ]);

        self::assertFalse($payload['available']);
        self::assertSame(ScopeIdentity::KIND_WEBSITE, $payload['scope_kind']);
        self::assertSame(7, $payload['identity']['website_id'] ?? null);
    }

    /**
     * @param array<string,mixed> $loadPayload
     */
    private function providerStub(ScopeIdentity $expected, array $loadPayload): BrandBasicsIdentityProviderInterface
    {
        $provider = $this->createMock(BrandBasicsIdentityProviderInterface::class);
        $provider->method('getCode')->willReturn('stub');
        $provider->method('getModule')->willReturn('Weline_Theme');
        $provider->method('supports')->willReturnCallback(
            static fn(ScopeIdentity $identity): bool => $identity->equals($expected),
        );
        $provider->method('load')->willReturn($loadPayload);

        return $provider;
    }

    private function service(
        ScopeIdentity $identity,
        ?BrandBasicsIdentityProviderInterface $provider,
    ): BrandBasicsIdentityService {
        // BrandBasicsIdentityRegistry is final — seed providers via reflection.
        $registry = (new \ReflectionClass(BrandBasicsIdentityRegistry::class))
            ->newInstanceWithoutConstructor();
        $providers = new \ReflectionProperty(BrandBasicsIdentityRegistry::class, 'providers');
        $providers->setValue($registry, $provider instanceof BrandBasicsIdentityProviderInterface ? [$provider] : []);

        $catalog = $this->createMock(ScopeIdentityCatalogInterface::class);
        $catalog->method('authoritativeIdentity')->willReturnCallback(
            static fn(ScopeIdentity $candidate): ScopeIdentity => $candidate,
        );

        $hierarchy = $this->createMock(ScopeHierarchyInterface::class);
        $hierarchy->method('fromStorageScope')->willReturnCallback(
            static function (string $storageScope) use ($identity): ?ScopeIdentity {
                return $storageScope !== '' ? $identity : null;
            },
        );

        return new BrandBasicsIdentityService($registry, $catalog, $hierarchy);
    }
}

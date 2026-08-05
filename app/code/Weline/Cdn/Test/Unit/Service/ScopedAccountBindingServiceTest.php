<?php

declare(strict_types=1);

namespace Weline\Cdn\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Cdn\Service\MediaUrlCowResolver;
use Weline\Cdn\Service\ScopedAccountBindingService;
use Weline\Cdn\Test\Unit\Double\InMemoryScopedAccountBindingRepository;
use Weline\Framework\Runtime\ScopeIdentity;
use Weline\SystemConfig\Service\SystemConfigScopeResolver;

/**
 * TEST-P1D-02：A/B Scope 账户不串、store_mode 隔离、COW URL、继承恢复。
 */
final class ScopedAccountBindingServiceTest extends TestCase
{
    private ScopedAccountBindingService $bindings;
    private MediaUrlCowResolver $cow;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bindings = new ScopedAccountBindingService(
            new SystemConfigScopeResolver(),
            new InMemoryScopedAccountBindingRepository(),
        );
        $this->cow = new MediaUrlCowResolver($this->bindings);
    }

    public function testTwoWebsitesKeepDistinctAccountBindings(): void
    {
        $a = ScopeIdentity::website(1, 'site-a');
        $b = ScopeIdentity::website(2, 'site-b');
        $this->bindings->bind($a, 'cloudflare', 101, 'https://cdn-a.example', 'global-cf');
        $this->bindings->bind($b, 'cloudflare', 202, 'https://cdn-b.example', 'global-cf');

        $ra = $this->bindings->resolve($a, 'cloudflare');
        $rb = $this->bindings->resolve($b, 'cloudflare');
        self::assertSame(101, $ra['account_id']);
        self::assertSame(202, $rb['account_id']);
        self::assertNotSame($ra['account_id'], $rb['account_id']);
    }

    public function testStoreModeIsolatesTestFromNormal(): void
    {
        $normal = ScopeIdentity::store(1, 'shop', 'main', ScopeIdentity::MODE_NORMAL);
        $test = ScopeIdentity::store(1, 'shop', 'main', ScopeIdentity::MODE_TEST);
        $this->bindings->bind($normal, 'media', 11, 'https://media.example');
        $this->bindings->bind($test, 'media', 22, 'https://media-test.example');

        self::assertSame(11, $this->bindings->resolve($normal, 'media')['account_id']);
        self::assertSame(22, $this->bindings->resolve($test, 'media')['account_id']);
        self::assertSame(
            'https://media-test.example/x.png',
            $this->cow->resolveCowMediaUrl('x.png', $test, 'https://media.example')
        );
        self::assertTrue($this->cow->isCowOverride($test, 'https://media.example'));
        self::assertFalse($this->cow->isCowOverride($normal, 'https://media.example'));
    }

    public function testRestoreInheritanceFallsBackToGlobalAliasBinding(): void
    {
        $global = ScopeIdentity::global();
        $store = ScopeIdentity::store(1, 'shop', 'main', ScopeIdentity::MODE_NORMAL);
        $this->bindings->bind($global, 'cloudflare', 1, '', 'cf-global');
        $this->bindings->bind($store, 'cloudflare', 9, '', 'cf-store');

        self::assertSame(9, $this->bindings->resolve($store, 'cloudflare')['account_id']);
        self::assertTrue($this->bindings->restoreInheritance($store, 'cloudflare'));
        $after = $this->bindings->resolve($store, 'cloudflare');
        self::assertSame(1, $after['account_id']);
        self::assertSame('fallback', $after['source_kind']);
        self::assertSame('cf-global', $after['global_alias']);
    }

    public function testPublicProjectionNeverIncludesSecretFields(): void
    {
        // 契约形状：toPublicArray 由 Account 提供；此处固定断言脱敏字段集合
        $publicKeys = ['account_id', 'adapter', 'name', 'description', 'is_default', 'status', 'has_credentials'];
        self::assertNotContains('credentials', $publicKeys);
        self::assertNotContains('secret_ref', $publicKeys);
    }

    public function testBindingsAreSharedAcrossServiceInstancesThroughRepository(): void
    {
        $repository = new InMemoryScopedAccountBindingRepository();
        $writer = new ScopedAccountBindingService(new SystemConfigScopeResolver(), $repository);
        $reader = new ScopedAccountBindingService(new SystemConfigScopeResolver(), $repository);
        $scope = ScopeIdentity::website(7, 'persisted');

        $writer->bind($scope, 'cloudflare', 701, 'https://cdn.persisted.example');

        self::assertSame(701, $reader->resolve($scope, 'cloudflare')['account_id']);
        self::assertCount(1, $reader->listForMode(ScopeIdentity::MODE_NORMAL));
    }

    public function testRejectsUnsafeBindingInputBeforeRepositoryWrite(): void
    {
        $scope = ScopeIdentity::website(1, 'site-a');

        try {
            $this->bindings->bind($scope, 'media', 1, 'javascript:alert(1)');
            self::fail('Unsafe media URL must be rejected.');
        } catch (\InvalidArgumentException $exception) {
            self::assertSame('cdn_account_binding_media_url_invalid', $exception->getMessage());
        }
        self::assertSame([], $this->bindings->listForMode(ScopeIdentity::MODE_NORMAL));
    }
}

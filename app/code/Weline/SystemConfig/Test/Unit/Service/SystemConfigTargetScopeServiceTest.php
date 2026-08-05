<?php

declare(strict_types=1);

namespace Weline\SystemConfig\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Runtime\ScopeIdentity;
use Weline\SystemConfig\Model\SystemConfig;
use Weline\SystemConfig\Service\SystemConfigScopeResolver;
use Weline\SystemConfig\Service\SystemConfigTargetScopeService;

/**
 * TASK-P1C-004：TargetScope 解析 / Origin / Session 仅 UI。
 */
final class SystemConfigTargetScopeServiceTest extends TestCase
{
    private SystemConfigTargetScopeService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new SystemConfigTargetScopeService(
            new SystemConfigScopeResolver(),
            static fn(string $code): int => $code === 'shop' ? 17 : 0,
        );
    }

    public function testEmptyPartsResolveToGlobal(): void
    {
        $target = $this->service->fromParts('');
        self::assertSame(ScopeIdentity::KIND_GLOBAL, $target['kind']);
        self::assertSame(SystemConfig::SCOPE_GLOBAL, $target['storage_scope']);
        self::assertSame('', $target['website_code']);
    }

    public function testWebsiteStoreChannelParts(): void
    {
        $website = $this->service->fromParts('shop');
        self::assertSame(ScopeIdentity::KIND_WEBSITE, $website['kind']);
        self::assertSame(17, $website['identity']->websiteId);
        self::assertSame('shop.default.default', $website['storage_scope']);

        $store = $this->service->fromParts('shop', 'main');
        self::assertSame(ScopeIdentity::KIND_STORE, $store['kind']);
        self::assertSame('shop.main.default', $store['storage_scope']);

        $channel = $this->service->fromParts('shop', 'main', 'app');
        self::assertSame(ScopeIdentity::KIND_CHANNEL, $channel['kind']);
        self::assertSame('shop.main.app', $channel['storage_scope']);
    }

    public function testWritePathRejectsSessionFallback(): void
    {
        $_SESSION[SystemConfigTargetScopeService::SESSION_KEY] = [
            'storage_scope' => 'shop.main.default',
            'kind' => 'store',
            'website_code' => 'shop',
            'store_code' => 'main',
            'channel_code' => '',
        ];
        $target = $this->service->resolveFromInput([], allowSessionFallback: false);
        self::assertSame(SystemConfig::SCOPE_GLOBAL, $target['storage_scope']);
    }

    public function testSessionRestoreOnlyWhenAllowed(): void
    {
        $_SESSION[SystemConfigTargetScopeService::SESSION_KEY] = [
            'storage_scope' => 'shop.main.default',
            'kind' => 'store',
            'website_code' => 'shop',
            'store_code' => 'main',
            'channel_code' => '',
        ];
        $target = $this->service->resolveFromInput([], allowSessionFallback: true);
        self::assertSame('shop.main.default', $target['storage_scope']);
    }

    public function testShortScopeWriteRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service->resolveFromInput(['scope' => 'shop.main'], false);
    }

    public function testSameOriginAcceptsMatchingHost(): void
    {
        $this->service->assertSameOrigin('https://example.test', 'example.test:9502', null);
        self::assertTrue(true);
    }

    public function testSameOriginRejectsMismatch(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->service->assertSameOrigin('https://evil.test', 'example.test', null);
    }

    public function testSameOriginRequiresHeaderWhenEmpty(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->service->assertSameOrigin('', 'example.test', '');
    }
}

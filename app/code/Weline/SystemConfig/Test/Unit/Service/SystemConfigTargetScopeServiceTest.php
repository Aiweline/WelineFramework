<?php

declare(strict_types=1);

namespace Weline\SystemConfig\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Runtime\ScopeIdentity;
use Weline\SystemConfig\Api\Scope\ScopeIdentityCatalogInterface;
use Weline\SystemConfig\Api\Scope\ScopeUiStateInterface;
use Weline\SystemConfig\Model\SystemConfig;
use Weline\SystemConfig\Service\SystemConfigScopeResolver;
use Weline\SystemConfig\Service\SystemConfigTargetScopeService;

/**
 * TASK-P1C-004：TargetScope 解析 / Origin / Session 仅 UI。
 */
final class SystemConfigTargetScopeServiceTest extends TestCase
{
    private SystemConfigTargetScopeService $service;
    private InMemoryScopeUiState $uiState;

    protected function setUp(): void
    {
        parent::setUp();
        $this->uiState = new InMemoryScopeUiState();
        $this->service = new SystemConfigTargetScopeService(
            new SystemConfigScopeResolver(),
            new TestScopeIdentityCatalog(),
            $this->uiState,
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
        $this->uiState->write(SystemConfigTargetScopeService::SESSION_KEY, [
            'storage_scope' => 'shop.main.default',
            'kind' => 'store',
            'website_code' => 'shop',
            'store_code' => 'main',
            'channel_code' => '',
            'store_mode' => ScopeIdentity::MODE_TEST,
        ]);
        $target = $this->service->resolveFromInput([], allowSessionFallback: false);
        self::assertSame(SystemConfig::SCOPE_GLOBAL, $target['storage_scope']);
    }

    public function testSessionRestoreOnlyWhenAllowed(): void
    {
        $this->uiState->write(SystemConfigTargetScopeService::SESSION_KEY, [
            'storage_scope' => 'shop.main.default',
            'kind' => 'store',
            'website_code' => 'shop',
            'store_code' => 'main',
            'channel_code' => '',
            'store_mode' => ScopeIdentity::MODE_TEST,
        ]);
        $target = $this->service->resolveFromInput([], allowSessionFallback: true);
        self::assertSame('shop.main.default', $target['storage_scope']);
        self::assertSame(ScopeIdentity::MODE_TEST, $target['store_mode']);
    }

    public function testShortScopeWriteRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service->resolveFromInput(['scope' => 'shop.main'], false);
    }

    public function testExplicitTypedModeIsPreservedAndCatalogValidated(): void
    {
        $target = $this->service->resolveFromInput([
            'scope' => 'shop.main.default',
            'store_mode' => ScopeIdentity::MODE_TEST,
        ]);

        self::assertSame(ScopeIdentity::MODE_TEST, $target['identity']->storeMode);
        self::assertSame(ScopeIdentity::MODE_TEST, $target['store_mode']);
    }

    public function testForgedStoreCodeIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service->fromParts('shop', 'forged', '', ScopeIdentity::KIND_STORE);
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

final class InMemoryScopeUiState implements ScopeUiStateInterface
{
    /** @var array<string,array<string,mixed>> */
    private array $values = [];

    public function read(string $key): ?array
    {
        return $this->values[$key] ?? null;
    }

    public function write(string $key, array $value): void
    {
        $this->values[$key] = $value;
    }
}

final class TestScopeIdentityCatalog implements ScopeIdentityCatalogInterface
{
    public function websiteIdForCode(string $websiteCode): int
    {
        if (strtolower(trim($websiteCode)) !== 'shop') {
            throw new \InvalidArgumentException('system_config_website_scope_not_found');
        }

        return 17;
    }

    public function authoritativeIdentity(ScopeIdentity $candidate): ScopeIdentity
    {
        if ($candidate->isGlobal()) {
            return ScopeIdentity::global($candidate->contextVersion);
        }
        if ($candidate->websiteId !== 17 || $candidate->websiteCode !== 'shop') {
            throw new \InvalidArgumentException('system_config_scope_claim_identity_mismatch');
        }
        if ($candidate->scopeKind === ScopeIdentity::KIND_WEBSITE) {
            return $candidate;
        }
        if ($candidate->storeCode !== 'main') {
            throw new \InvalidArgumentException('system_config_store_scope_not_found');
        }
        if ($candidate->scopeKind === ScopeIdentity::KIND_STORE) {
            return $candidate;
        }
        if ($candidate->channelCode !== 'app') {
            throw new \InvalidArgumentException('system_config_channel_scope_not_found');
        }

        return $candidate;
    }

    public function options(): array
    {
        return [[
            'code' => 'shop',
            'name' => 'Shop',
            'website_id' => 17,
            'stores' => [[
                'id' => 31,
                'code' => 'main',
                'name' => 'Main',
                'store_mode' => ScopeIdentity::MODE_TEST,
                'channels' => [['id' => 41, 'code' => 'app', 'name' => 'App']],
            ]],
        ]];
    }
}

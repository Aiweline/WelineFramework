<?php

declare(strict_types=1);

namespace Weline\Meta\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Runtime\ScopeIdentity;
use Weline\Meta\Api\Data\MetaConfigIdentity;
use Weline\Meta\Api\Data\MetaConfigRecord;
use Weline\Meta\Api\Data\MetaConfigScopeSearch;
use Weline\Meta\Api\Data\MetaConfigSearch;
use Weline\Meta\Api\Data\MetaConfigWrite;
use Weline\Meta\Api\MetaConfigRepositoryInterface;
use Weline\Meta\Api\Scope\MetaConfigScopeSource;
use Weline\Meta\Service\MetaConfigTypedScopeService;
use Weline\SystemConfig\Service\SystemConfigScopeResolver;

/**
 * TEST-P1C-02：Meta typed Scope 回落 / 精确 Repository 不串。
 */
final class MetaConfigTypedScopeServiceTest extends TestCase
{
    public function testStoreFallsBackToWebsiteThenGlobal(): void
    {
        $repo = new InMemoryMetaConfigRepository([
            $this->record('shop.default.default', 'website-v'),
            $this->record('default.default.default', 'global-v'),
        ]);
        $service = new MetaConfigTypedScopeService($repo, new SystemConfigScopeResolver());
        $result = $service->resolveTyped(
            'ns',
            'k',
            ScopeIdentity::store(0, 'shop', 'main', ScopeIdentity::MODE_NORMAL),
            null,
        );

        self::assertTrue($result->found());
        self::assertSame('website-v', $result->value());
        self::assertSame(MetaConfigScopeSource::KIND_FALLBACK, $result->source->sourceKind);
        self::assertSame(ScopeIdentity::KIND_WEBSITE, $result->source->scopeKind);
    }

    public function testExactStoreOverridePreferred(): void
    {
        $repo = new InMemoryMetaConfigRepository([
            $this->record('shop.main.default', 'store-v'),
            $this->record('shop.default.default', 'website-v'),
            $this->record('default.default.default', 'global-v'),
        ]);
        $service = new MetaConfigTypedScopeService($repo, new SystemConfigScopeResolver());
        $result = $service->resolveTyped(
            'ns',
            'k',
            ScopeIdentity::store(0, 'shop', 'main', ScopeIdentity::MODE_NORMAL),
            null,
        );

        self::assertSame('store-v', $result->value());
        self::assertSame(MetaConfigScopeSource::KIND_EXACT, $result->source->sourceKind);
    }

    public function testLegacyShortDefaultCompatibleOnGlobalChain(): void
    {
        $repo = new InMemoryMetaConfigRepository([
            $this->record('default', 'legacy-global'),
        ]);
        $service = new MetaConfigTypedScopeService($repo, new SystemConfigScopeResolver());
        $result = $service->resolveTyped('ns', 'k', ScopeIdentity::global(), null);

        self::assertSame('legacy-global', $result->value());
        self::assertSame(MetaConfigScopeSource::KIND_EXACT, $result->source->sourceKind);
    }

    public function testRepositoryExactResolveStillNoFallback(): void
    {
        $repo = new InMemoryMetaConfigRepository([
            $this->record('shop.default.default', 'website-v'),
        ]);
        $miss = $repo->resolve(new MetaConfigIdentity(
            namespace: 'ns',
            configKey: 'k',
            scope: 'shop.main.default',
            locale: null,
            identifyId: '0',
        ));
        self::assertNull($miss);
    }

    private function record(string $scope, string $value): MetaConfigRecord
    {
        return new MetaConfigRecord(
            id: 1,
            namespace: 'ns',
            configKey: 'k',
            value: $value,
            scope: $scope,
            locale: null,
            identifyId: '0',
            metaId: null,
            metaIdentify: null,
        );
    }
}

/**
 * @internal
 */
final class InMemoryMetaConfigRepository implements MetaConfigRepositoryInterface
{
    /** @param list<MetaConfigRecord> $records */
    public function __construct(private array $records)
    {
    }

    public function search(MetaConfigSearch $search): array
    {
        return [];
    }

    public function resolve(MetaConfigIdentity $identity): ?MetaConfigRecord
    {
        foreach ($this->records as $record) {
            if ($record->namespace === $identity->namespace
                && $record->configKey === $identity->configKey
                && $record->scope === $identity->scope
                && $record->locale === $identity->locale
                && (string)($record->identifyId ?? '') === (string)($identity->identifyId ?? '')
            ) {
                return $record;
            }
        }

        return null;
    }

    public function resolveBatch(array $identities): array
    {
        return \array_map(fn(MetaConfigIdentity $i) => $this->resolve($i), $identities);
    }

    public function listScopes(MetaConfigScopeSearch $search): array
    {
        return [];
    }

    public function upsert(MetaConfigWrite $config): MetaConfigRecord
    {
        throw new \LogicException('not used');
    }

    public function delete(MetaConfigIdentity $identity): bool
    {
        return false;
    }
}

<?php

declare(strict_types=1);

namespace Weline\Tax\Service;

use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\ScopeIdentity;
use Weline\SystemConfig\Api\ConfigReader;
use Weline\Tax\Model\TaxRule;
use Weline\Websites\Api\Catalog\StoreCatalogInterface;
use Weline\Websites\Api\Catalog\WebsiteCatalogInterface;

/**
 * Tax-owned adapter over the public typed SystemConfig and Websites APIs.
 */
final class TaxScopeConfig
{
    public const MODULE = 'Weline_Tax';
    public const AREA = ConfigReader::area_BACKEND;
    public const KEY_ENABLED = 'tax/general/enabled';
    public const KEY_DEFAULT_JURISDICTION = 'tax/general/default_jurisdiction';
    public const KEY_SCHEMA_VERSION = 'tax/general/rule_schema_version';
    public const KEY_ROUNDING = 'tax/general/rounding';

    private readonly ?\Closure $resolver;

    /**
     * @param (callable(int,int):array<string,mixed>)|null $resolver Explicit test/frozen-snapshot adapter.
     */
    public function __construct(
        private readonly ?ConfigReader $reader = null,
        private readonly ?WebsiteCatalogInterface $websites = null,
        private readonly ?StoreCatalogInterface $stores = null,
        ?callable $resolver = null,
    ) {
        $this->resolver = $resolver === null ? null : \Closure::fromCallable($resolver);
    }

    /**
     * @param array<string,mixed> $overrides
     */
    public static function forTesting(array $overrides = []): self
    {
        return new self(resolver: static function (int $websiteId, int $storeId) use ($overrides): array {
            $defaults = [
                'website_id' => $websiteId,
                'store_id' => $storeId,
                'scope_key' => 'test|' . $websiteId . '|' . $storeId,
                'enabled' => false,
                'default_jurisdiction' => 'CN|',
                'schema_version' => TaxEngine::SCHEMA_VERSION,
                'rounding' => TaxRule::ROUNDING_HALF_UP,
                'sources' => [],
            ];

            return array_merge($defaults, $overrides, [
                'website_id' => $websiteId,
                'store_id' => $storeId,
            ]);
        });
    }

    /**
     * @param array<string,mixed> $resolved
     */
    public static function fromResolved(array $resolved): self
    {
        return new self(resolver: static function (int $websiteId, int $storeId) use ($resolved): array {
            if ((int) ($resolved['website_id'] ?? -1) !== $websiteId
                || (int) ($resolved['store_id'] ?? -1) !== $storeId
            ) {
                throw new TaxConflictException(
                    \Weline\Tax\Api\TaxEngineInterface::ERROR_INVALID_REQUEST,
                    __('冻结税务 Scope 与请求不一致'),
                );
            }

            return $resolved;
        });
    }

    /**
     * @return array{
     *   website_id:int,
     *   store_id:int,
     *   scope_key:string,
     *   enabled:bool,
     *   default_jurisdiction:string,
     *   schema_version:string,
     *   rounding:string,
     *   sources:array<string,mixed>
     * }
     */
    public function resolve(int $websiteId, int $storeId): array
    {
        if ($websiteId < 0 || $storeId < 0) {
            throw new TaxConflictException(
                \Weline\Tax\Api\TaxEngineInterface::ERROR_INVALID_REQUEST,
                __('Tax Scope ID 不能为负数'),
            );
        }
        if ($this->resolver !== null) {
            return $this->validateResolved(($this->resolver)($websiteId, $storeId), $websiteId, $storeId);
        }

        $website = null;
        foreach ($this->websiteCatalog()->all() as $candidate) {
            if ($candidate->id === $websiteId) {
                $website = $candidate;
                break;
            }
        }
        $store = $this->storeCatalog()->byId($storeId);
        if ($website === null || $store === null || $store->websiteId !== $websiteId) {
            throw new TaxConflictException(
                \Weline\Tax\Api\TaxEngineInterface::ERROR_INVALID_REQUEST,
                __('Tax Scope Website/Store 不存在或不匹配'),
                ['website_id' => $websiteId, 'store_id' => $storeId],
            );
        }
        if (!$store->enabled || $store->lifecycleStatus !== 'active' || $store->tombstonedAt !== null) {
            throw new TaxConflictException(
                \Weline\Tax\Api\TaxEngineInterface::ERROR_INVALID_REQUEST,
                __('Tax Scope Store 已停用或不在 active 生命周期'),
                ['website_id' => $websiteId, 'store_id' => $storeId],
            );
        }

        $identity = ScopeIdentity::store(
            $websiteId,
            $website->code,
            $store->code,
            $store->storeMode,
        );
        $reader = $this->configReader();
        $enabled = $reader->resolveTypedConfig(
            self::KEY_ENABLED,
            self::MODULE,
            self::AREA,
            $identity,
            default: false,
        );
        $jurisdiction = $reader->resolveTypedConfig(
            self::KEY_DEFAULT_JURISDICTION,
            self::MODULE,
            self::AREA,
            $identity,
            default: 'CN|',
        );
        $schema = $reader->resolveTypedConfig(
            self::KEY_SCHEMA_VERSION,
            self::MODULE,
            self::AREA,
            $identity,
            default: TaxEngine::SCHEMA_VERSION,
        );
        $rounding = $reader->resolveTypedConfig(
            self::KEY_ROUNDING,
            self::MODULE,
            self::AREA,
            $identity,
            default: TaxRule::ROUNDING_HALF_UP,
        );

        return $this->validateResolved([
            'website_id' => $websiteId,
            'store_id' => $storeId,
            'scope_key' => $identity->canonicalKey(),
            'enabled' => $this->boolValue($enabled->value),
            'default_jurisdiction' => $jurisdiction->value,
            'schema_version' => $schema->value,
            'rounding' => $rounding->value,
            'sources' => [
                self::KEY_ENABLED => $enabled->source->toArray(),
                self::KEY_DEFAULT_JURISDICTION => $jurisdiction->source->toArray(),
                self::KEY_SCHEMA_VERSION => $schema->source->toArray(),
                self::KEY_ROUNDING => $rounding->source->toArray(),
            ],
        ], $websiteId, $storeId);
    }

    /**
     * @param array<string,mixed> $resolved
     * @return array<string,mixed>
     */
    private function validateResolved(array $resolved, int $websiteId, int $storeId): array
    {
        $scopeKey = trim((string) ($resolved['scope_key'] ?? ''));
        $jurisdiction = strtoupper(trim((string) ($resolved['default_jurisdiction'] ?? '')));
        $schema = trim((string) ($resolved['schema_version'] ?? ''));
        $rounding = trim((string) ($resolved['rounding'] ?? ''));
        if ((int) ($resolved['website_id'] ?? -1) !== $websiteId
            || (int) ($resolved['store_id'] ?? -1) !== $storeId
            || $scopeKey === ''
            || preg_match(TaxRule::JURISDICTION_PATTERN, $jurisdiction) !== 1
            || preg_match('/^tax-schema-v[1-9][0-9]*$/D', $schema) !== 1
            || !in_array($rounding, TaxRule::ROUNDING_MODES, true)
        ) {
            throw new TaxConflictException(
                \Weline\Tax\Api\TaxEngineInterface::ERROR_INVALID_REQUEST,
                __('Tax Scope 配置无效'),
                ['website_id' => $websiteId, 'store_id' => $storeId],
            );
        }

        return [
            'website_id' => $websiteId,
            'store_id' => $storeId,
            'scope_key' => $scopeKey,
            'enabled' => $this->boolValue($resolved['enabled'] ?? false),
            'default_jurisdiction' => $jurisdiction,
            'schema_version' => $schema,
            'rounding' => $rounding,
            'sources' => is_array($resolved['sources'] ?? null) ? $resolved['sources'] : [],
        ];
    }

    private function boolValue(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value)) {
            return $value === 1;
        }
        return in_array(strtolower(trim((string) $value)), ['1', 'true', 'yes', 'on'], true);
    }

    private function configReader(): ConfigReader
    {
        return $this->reader ?? ObjectManager::getInstance(ConfigReader::class);
    }

    private function websiteCatalog(): WebsiteCatalogInterface
    {
        $catalog = $this->websites ?? ObjectManager::getInstance(WebsiteCatalogInterface::class);
        if (!$catalog instanceof WebsiteCatalogInterface) {
            throw new \LogicException('WebsiteCatalogInterface binding is unavailable');
        }
        return $catalog;
    }

    private function storeCatalog(): StoreCatalogInterface
    {
        $catalog = $this->stores ?? ObjectManager::getInstance(StoreCatalogInterface::class);
        if (!$catalog instanceof StoreCatalogInterface) {
            throw new \LogicException('StoreCatalogInterface binding is unavailable');
        }
        return $catalog;
    }
}

<?php

declare(strict_types=1);

namespace Weline\Tax\Service;

use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\ScopeIdentity;
use Weline\SystemConfig\Api\ConfigReader;
use Weline\SystemConfig\Service\SystemConfigScopeResolver;
use Weline\Tax\Model\TaxRule;
use Weline\Websites\Api\Catalog\SalesChannelCatalogInterface;
use Weline\Websites\Api\Catalog\StoreCatalogInterface;
use Weline\Websites\Api\Catalog\WebsiteCatalogInterface;

/**
 * Tax-owned adapter over the public typed SystemConfig and Websites APIs.
 *
 * Resolve order uses SystemConfig inheritance: channel → store → website → global.
 */
final class TaxScopeConfig
{
    public const MODULE = 'Weline_Tax';
    public const AREA = ConfigReader::area_BACKEND;
    public const KEY_ENABLED = 'tax/general/enabled';
    public const KEY_DEFAULT_JURISDICTION = 'tax/general/default_jurisdiction';
    public const KEY_SCHEMA_VERSION = 'tax/general/rule_schema_version';
    public const KEY_ROUNDING = 'tax/general/rounding';
    /** Catalog / cart line amounts already include home-jurisdiction VAT (价内税). */
    public const KEY_PRICES_INCLUDE_TAX = 'tax/general/prices_include_tax';
    /** Optional ISO2 allowlist to collect destination sales/GST at checkout (IOSS/LVG/nexus). */
    public const KEY_COLLECT_SALES_TAX_COUNTRIES = TaxDestinationCheckoutPolicy::KEY_COLLECT_SALES_TAX_COUNTRIES;

    private readonly ?\Closure $resolver;

    /**
     * @param (callable(int,int,int):array<string,mixed>)|null $resolver Explicit test/frozen-snapshot adapter.
     */
    public function __construct(
        private readonly ?ConfigReader $reader = null,
        private readonly ?WebsiteCatalogInterface $websites = null,
        private readonly ?StoreCatalogInterface $stores = null,
        private readonly ?SalesChannelCatalogInterface $channels = null,
        private readonly ?SystemConfigScopeResolver $scopeResolver = null,
        ?callable $resolver = null,
    ) {
        $this->resolver = $resolver === null ? null : \Closure::fromCallable($resolver);
    }

    /**
     * @param array<string,mixed> $overrides
     */
    public static function forTesting(array $overrides = []): self
    {
        return new self(resolver: static function (int $websiteId, int $storeId, int $channelId = 0) use ($overrides): array {
            $defaults = [
                'website_id' => $websiteId,
                'store_id' => $storeId,
                'channel_id' => $channelId,
                'scope_key' => 'test|' . $websiteId . '|' . $storeId . '|' . $channelId,
                'enabled' => false,
                'default_jurisdiction' => 'CN|',
                'schema_version' => TaxEngine::SCHEMA_VERSION,
                'rounding' => TaxRule::ROUNDING_HALF_UP,
                'prices_include_tax' => true,
                'collect_sales_tax_countries' => '',
                'sources' => [],
            ];

            return array_merge($defaults, $overrides, [
                'website_id' => $websiteId,
                'store_id' => $storeId,
                'channel_id' => $channelId,
            ]);
        });
    }

    /**
     * @param array<string,mixed> $resolved
     */
    public static function fromResolved(array $resolved): self
    {
        return new self(resolver: static function (int $websiteId, int $storeId, int $channelId = 0) use ($resolved): array {
            $resolvedChannel = (int) ($resolved['channel_id'] ?? 0);
            if ((int) ($resolved['website_id'] ?? -1) !== $websiteId
                || (int) ($resolved['store_id'] ?? -1) !== $storeId
                || ($resolvedChannel > 0 && $resolvedChannel !== $channelId)
            ) {
                throw new TaxConflictException(
                    \Weline\Tax\Api\TaxEngineInterface::ERROR_INVALID_REQUEST,
                    __('冻结税务 Scope 与请求不一致'),
                );
            }

            return $resolved + ['channel_id' => $channelId];
        });
    }

    /**
     * @return array{
     *   website_id:int,
     *   store_id:int,
     *   channel_id:int,
     *   scope_key:string,
     *   enabled:bool,
     *   default_jurisdiction:string,
     *   schema_version:string,
     *   rounding:string,
     *   prices_include_tax:bool,
     *   collect_sales_tax_countries:string,
     *   sources:array<string,mixed>
     * }
     */
    public function resolve(int $websiteId, int $storeId, int $channelId = 0): array
    {
        if ($websiteId < 0 || $storeId < 0 || $channelId < 0) {
            throw new TaxConflictException(
                \Weline\Tax\Api\TaxEngineInterface::ERROR_INVALID_REQUEST,
                __('Tax Scope ID 不能为负数'),
            );
        }
        if ($this->resolver !== null) {
            return $this->validateResolved(
                ($this->resolver)($websiteId, $storeId, $channelId),
                $websiteId,
                $storeId,
                $channelId,
            );
        }

        $website = null;
        foreach ($this->websiteCatalog()->all() as $candidate) {
            if ($candidate->id === $websiteId) {
                $website = $candidate;
                break;
            }
        }
        if ($website === null) {
            throw new TaxConflictException(
                \Weline\Tax\Api\TaxEngineInterface::ERROR_INVALID_REQUEST,
                __('Tax Scope Website 不存在'),
                ['website_id' => $websiteId],
            );
        }

        $identity = null;
        if ($storeId === 0) {
            $identity = ScopeIdentity::website($websiteId, $website->code);
        } else {
            $store = $this->storeCatalog()->byId($storeId);
            if ($store === null || $store->websiteId !== $websiteId) {
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

            if ($channelId >= 1) {
                $channel = $this->channelCatalog()->byId($channelId);
                if ($channel === null
                    || $channel->websiteId !== $websiteId
                    || $channel->storeId !== $storeId
                ) {
                    throw new TaxConflictException(
                        \Weline\Tax\Api\TaxEngineInterface::ERROR_INVALID_REQUEST,
                        __('Tax Scope Channel 不存在或不匹配'),
                        [
                            'website_id' => $websiteId,
                            'store_id' => $storeId,
                            'channel_id' => $channelId,
                        ],
                    );
                }
                if (!$channel->effectiveEnabled) {
                    throw new TaxConflictException(
                        \Weline\Tax\Api\TaxEngineInterface::ERROR_INVALID_REQUEST,
                        __('Tax Scope Channel 已停用'),
                        [
                            'website_id' => $websiteId,
                            'store_id' => $storeId,
                            'channel_id' => $channelId,
                        ],
                    );
                }
                $identity = ScopeIdentity::channel(
                    $websiteId,
                    $website->code,
                    $store->code,
                    $channel->code,
                    $store->storeMode,
                );
            } else {
                $identity = ScopeIdentity::store(
                    $websiteId,
                    $website->code,
                    $store->code,
                    $store->storeMode,
                );
            }
        }

        $reader = $this->configReader();
        // Pass locale + default positionally (6 args). Named-only `default:` is easy to
        // mis-forward through ConfigReader wrappers; also pin LOCALE_DEFAULT so storefront
        // RequestContext lang cannot skew typed resolution.
        $locale = ConfigReader::LOCALE_DEFAULT;
        $enabled = $reader->resolveTypedConfig(
            self::KEY_ENABLED,
            self::MODULE,
            self::AREA,
            $identity,
            $locale,
            false,
        );
        $jurisdiction = $reader->resolveTypedConfig(
            self::KEY_DEFAULT_JURISDICTION,
            self::MODULE,
            self::AREA,
            $identity,
            $locale,
            'CN|',
        );
        $schema = $reader->resolveTypedConfig(
            self::KEY_SCHEMA_VERSION,
            self::MODULE,
            self::AREA,
            $identity,
            $locale,
            TaxEngine::SCHEMA_VERSION,
        );
        $rounding = $reader->resolveTypedConfig(
            self::KEY_ROUNDING,
            self::MODULE,
            self::AREA,
            $identity,
            $locale,
            TaxRule::ROUNDING_HALF_UP,
        );
        $pricesIncludeTax = $reader->resolveTypedConfig(
            self::KEY_PRICES_INCLUDE_TAX,
            self::MODULE,
            self::AREA,
            $identity,
            $locale,
            true,
        );
        $collectSalesTaxCountries = $reader->resolveTypedConfig(
            self::KEY_COLLECT_SALES_TAX_COUNTRIES,
            self::MODULE,
            self::AREA,
            $identity,
            $locale,
            '',
        );
        $collectValue = trim((string)$collectSalesTaxCountries->value);
        $collectSource = $collectSalesTaxCountries->source->toArray();
        // WLS workers keep system_config in wls_memory. Stale typed envelopes can keep a
        // previous non-empty collect allowlist after the exact scope row is cleared to "".
        // Reconcile against the same storage scope as the identity (not always Global).
        try {
            $storageScope = $this->scopeResolver()->toStorageScope($identity);
            $row = ObjectManager::getInstance(\Weline\SystemConfig\Model\SystemConfig::class)
                ->getScopedConfigRow(
                    self::KEY_COLLECT_SALES_TAX_COUNTRIES,
                    self::MODULE,
                    self::AREA,
                    $storageScope,
                    ConfigReader::LOCALE_DEFAULT,
                );
            if (is_array($row) && array_key_exists('v', $row)) {
                $collectValue = trim((string)$row['v']);
            }
        } catch (\Throwable) {
            // Keep typed value when the exact-row reader is unavailable.
        }

        return $this->validateResolved([
            'website_id' => $websiteId,
            'store_id' => $storeId,
            'channel_id' => $channelId,
            'scope_key' => $identity->canonicalKey(),
            'enabled' => $this->boolValue($enabled->value),
            'default_jurisdiction' => $jurisdiction->value,
            'schema_version' => $schema->value,
            'rounding' => $rounding->value,
            'prices_include_tax' => $this->boolValue($pricesIncludeTax->value),
            'collect_sales_tax_countries' => $collectValue,
            'sources' => [
                self::KEY_ENABLED => $enabled->source->toArray(),
                self::KEY_DEFAULT_JURISDICTION => $jurisdiction->source->toArray(),
                self::KEY_SCHEMA_VERSION => $schema->source->toArray(),
                self::KEY_ROUNDING => $rounding->source->toArray(),
                self::KEY_PRICES_INCLUDE_TAX => $pricesIncludeTax->source->toArray(),
                self::KEY_COLLECT_SALES_TAX_COUNTRIES => $collectSource,
            ],
        ], $websiteId, $storeId, $channelId);
    }

    /**
     * @param array<string,mixed> $resolved
     * @return array<string,mixed>
     */
    private function validateResolved(
        array $resolved,
        int $websiteId,
        int $storeId,
        int $channelId = 0,
    ): array {
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
                [
                    'website_id' => $websiteId,
                    'store_id' => $storeId,
                    'channel_id' => $channelId,
                ],
            );
        }

        return [
            'website_id' => $websiteId,
            'store_id' => $storeId,
            'channel_id' => $channelId,
            'scope_key' => $scopeKey,
            'enabled' => $this->boolValue($resolved['enabled'] ?? false),
            'default_jurisdiction' => $jurisdiction,
            'schema_version' => $schema,
            'rounding' => $rounding,
            // Default true: Chinese B2C catalog amounts are VAT-inclusive (价内税).
            'prices_include_tax' => $this->boolValue($resolved['prices_include_tax'] ?? true),
            'collect_sales_tax_countries' => trim((string)($resolved['collect_sales_tax_countries'] ?? '')),
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

    private function scopeResolver(): SystemConfigScopeResolver
    {
        return $this->scopeResolver ?? ObjectManager::getInstance(SystemConfigScopeResolver::class);
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

    private function channelCatalog(): SalesChannelCatalogInterface
    {
        $catalog = $this->channels ?? ObjectManager::getInstance(SalesChannelCatalogInterface::class);
        if (!$catalog instanceof SalesChannelCatalogInterface) {
            throw new \LogicException('SalesChannelCatalogInterface binding is unavailable');
        }

        return $catalog;
    }
}

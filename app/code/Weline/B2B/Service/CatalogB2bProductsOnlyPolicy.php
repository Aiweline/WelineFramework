<?php

declare(strict_types=1);

namespace Weline\B2B\Service;

use Weline\Framework\Runtime\RequestContext;
use Weline\Framework\Runtime\ScopeIdentity;
use Weline\SystemConfig\Api\ConfigReader;
use Weline\SystemConfig\Api\ConfigStore;

/**
 * Scope-inherited switch for b2b display_type scopes.
 *
 * Default ON: a b2b storefront shows only wholesale-eligible products unless
 * an operator explicitly turns this off for that Website/Store/Channel.
 */
final class CatalogB2bProductsOnlyPolicy
{
    public const CONFIG_KEY = 'catalog_b2b_products_only';

    private const CONFIG_MODULE = 'Weline_B2B';
    private const CONFIG_AREA = ConfigReader::area_FRONTEND;
    private const DEFAULT_ENABLED = true;

    public function __construct(private ?ConfigStore $config = null)
    {
    }

    public function isEnabled(
        int $websiteId = -1,
        int $storeId = -1,
        int $channelId = -1,
    ): bool {
        if ($websiteId < 0) {
            $websiteId = RequestContext::getWelineWebsiteId();
        }
        if ($storeId < 0) {
            $storeId = RequestContext::getWelineStoreId();
        }
        if ($channelId < 0) {
            $channelId = RequestContext::getWelineChannelId();
        }
        if ($websiteId < 0) {
            return false;
        }

        try {
            $identity = RequestContext::scopeIdentity();
            if (!$identity instanceof ScopeIdentity
                || $identity->websiteId !== $websiteId
                || (int)RequestContext::getWelineStoreId() !== max(0, $storeId)
                || (int)RequestContext::getWelineChannelId() !== max(0, $channelId)
            ) {
                $websiteCode = trim((string)RequestContext::getWelineWebsiteCode());
                $storeCode = trim((string)RequestContext::getWelineStoreCode()) ?: 'default';
                $channelCode = trim((string)RequestContext::getWelineChannelCode()) ?: 'default';
                $storeMode = trim((string)RequestContext::getWelineStoreMode()) ?: ScopeIdentity::MODE_NORMAL;
                if ($websiteCode === '') {
                    $websiteCode = 'website' . $websiteId;
                }
                $identity = ScopeIdentity::channel(
                    $websiteId,
                    $websiteCode,
                    $storeCode,
                    $channelCode,
                    $storeMode,
                );
            }
            $resolved = $this->configStore()->resolveTypedConfig(
                self::CONFIG_KEY,
                self::CONFIG_MODULE,
                self::CONFIG_AREA,
                $identity,
                ConfigReader::LOCALE_DEFAULT,
                self::DEFAULT_ENABLED,
            );

            return $this->toBool($resolved->value ?? self::DEFAULT_ENABLED, self::DEFAULT_ENABLED);
        } catch (\Throwable) {
            // Fail closed for b2b catalog: keep wholesale-only when config is unreadable.
            return self::DEFAULT_ENABLED;
        }
    }

    private function configStore(): ConfigStore
    {
        return $this->config ??= new ConfigStore();
    }

    private function toBool(mixed $value, bool $default): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value) || is_float($value)) {
            return (int)$value !== 0;
        }
        if (is_string($value)) {
            $normalized = strtolower(trim($value));
            if ($normalized === '') {
                return $default;
            }
            if (in_array($normalized, ['1', 'true', 'yes', 'on'], true)) {
                return true;
            }
            if (in_array($normalized, ['0', 'false', 'no', 'off'], true)) {
                return false;
            }
        }

        return $default;
    }
}

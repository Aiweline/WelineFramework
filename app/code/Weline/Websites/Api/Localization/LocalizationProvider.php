<?php

declare(strict_types=1);

namespace Weline\Websites\Api\Localization;

use Weline\Framework\App\Localization\LocalizationProviderInterface;
use Weline\Framework\Env\WelineEnv;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\RequestContext;
use Weline\Websites\Data\WebsiteData;
use Weline\Websites\Model\WebsiteCurrency;
use Weline\Websites\Model\WebsiteLanguage;

final class LocalizationProvider implements LocalizationProviderInterface
{
    private const FALLBACK_CACHE_PREFIX = 'websites.localization_provider.fallback.v1.';

    public function priority(): int
    {
        return 100;
    }

    public function languageCodes(): array
    {
        $codes = WebsiteData::getLanguageCodes();
        if ($codes !== []) {
            return $codes;
        }
        $websiteId = $this->websiteId();
        // website_id=0 是系统默认站点，必须参与查询，不能用 >0 过滤掉。
        if ($websiteId === null) {
            return [];
        }

        return $this->fallbackCodes(
            'language',
            $websiteId,
            static fn(): array => ObjectManager::getInstance(WebsiteLanguage::class)
                ->getWebsiteLanguageCodes($websiteId),
        );
    }

    public function currencyCodes(): array
    {
        $codes = WebsiteData::getCurrencyCodes();
        if ($codes !== []) {
            return $codes;
        }
        $websiteId = $this->websiteId();
        if ($websiteId === null) {
            return [];
        }

        return $this->fallbackCodes(
            'currency',
            $websiteId,
            static fn(): array => ObjectManager::getInstance(WebsiteCurrency::class)
                ->getWebsiteCurrencyCodes($websiteId),
        );
    }

    public function supportsLanguage(string $code): ?bool
    {
        return null;
    }

    public function supportsCurrency(string $code): ?bool
    {
        return null;
    }

    /**
     * Backend and bootstrap requests may know the website id before a full
     * WebsiteData snapshot has been installed. Cache that fallback lookup in
     * the current request, including an intentionally empty result.
     *
     * @param callable(): array $loader
     * @return array<int, string>
     */
    private function fallbackCodes(string $dimension, int $websiteId, callable $loader): array
    {
        if (RequestContext::getId() === null) {
            return $loader();
        }

        $key = self::FALLBACK_CACHE_PREFIX . $dimension . '.' . $websiteId;
        if (RequestContext::has($key)) {
            $codes = RequestContext::get($key, []);
            return \is_array($codes) ? $codes : [];
        }

        $codes = $loader();
        RequestContext::set($key, $codes);

        return $codes;
    }

    private function websiteId(): ?int
    {
        try {
            $id = WebsiteData::getWebsiteId();
            if ($id !== null) {
                return (int)$id;
            }
        } catch (\Throwable) {
        }

        $raw = w_env('website_id', null);
        if ($raw !== null && $raw !== '') {
            return (int)$raw;
        }

        $serverId = WelineEnv::server('WELINE_WEBSITE_ID', null);
        if ($serverId !== null && $serverId !== '') {
            return (int)$serverId;
        }

        return null;
    }
}

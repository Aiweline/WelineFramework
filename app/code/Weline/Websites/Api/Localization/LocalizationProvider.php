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
    private const PROCESS_FALLBACK_MAX = 256;

    /**
     * 进程级：website_id + dimension → codes（同 Worker 多请求共享）。
     *
     * @var array<string, array<int, string>>
     */
    private static array $processFallbackByKey = [];

    public function priority(): int
    {
        return 100;
    }

    public function languageCodes(): array
    {
        $codes = WebsiteData::getLanguageCodes();
        if (WebsiteData::hasLanguageSnapshot()) {
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
        if (WebsiteData::hasCurrencySnapshot()) {
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

    public static function clearProcessCache(): void
    {
        self::$processFallbackByKey = [];
    }

    /**
     * Backend and bootstrap requests may know the website id before a full
     * WebsiteData snapshot has been installed. Cache that fallback lookup in
     * the process (keyed by website) and mirror into the current request.
     *
     * @param callable(): array $loader
     * @return array<int, string>
     */
    private function fallbackCodes(string $dimension, int $websiteId, callable $loader): array
    {
        $processKey = $dimension . '.' . $websiteId;
        if (\array_key_exists($processKey, self::$processFallbackByKey)) {
            $codes = self::$processFallbackByKey[$processKey];
            $this->rememberRequestFallback($dimension, $websiteId, $codes);

            return $codes;
        }

        if (RequestContext::getId() !== null) {
            $key = self::FALLBACK_CACHE_PREFIX . $dimension . '.' . $websiteId;
            if (RequestContext::has($key)) {
                $codes = RequestContext::get($key, []);
                $codes = \is_array($codes) ? $codes : [];
                self::rememberProcessFallback($processKey, $codes);

                return $codes;
            }
        }

        $codes = $loader();
        self::rememberProcessFallback($processKey, $codes);
        $this->rememberRequestFallback($dimension, $websiteId, $codes);

        return $codes;
    }

    /**
     * @param array<int, string> $codes
     */
    private function rememberRequestFallback(string $dimension, int $websiteId, array $codes): void
    {
        if (RequestContext::getId() === null) {
            return;
        }
        RequestContext::set(self::FALLBACK_CACHE_PREFIX . $dimension . '.' . $websiteId, $codes);
    }

    /**
     * @param array<int, string> $codes
     */
    private static function rememberProcessFallback(string $processKey, array $codes): void
    {
        if (\count(self::$processFallbackByKey) >= self::PROCESS_FALLBACK_MAX
            && !\array_key_exists($processKey, self::$processFallbackByKey)
        ) {
            self::$processFallbackByKey = \array_slice(
                self::$processFallbackByKey,
                -((int)(self::PROCESS_FALLBACK_MAX / 2)),
                null,
                true,
            );
        }
        self::$processFallbackByKey[$processKey] = $codes;
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

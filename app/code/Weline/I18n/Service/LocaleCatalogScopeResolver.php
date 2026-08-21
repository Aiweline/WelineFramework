<?php

declare(strict_types=1);

namespace Weline\I18n\Service;

use Weline\Framework\App\State;
use Weline\Framework\Manager\ObjectManager;
use Weline\SystemConfig\Api\ConfigReader;

/**
 * Single resolver for switcher language boundaries (website / backend / injected).
 */
final class LocaleCatalogScopeResolver
{
    private const FALLBACK_CODE = 'zh_Hans_CN';

    /**
     * @param list<string> $injectedCodes
     */
    public function resolve(
        bool $isBackendArea,
        int $websiteId,
        array $injectedCodes = [],
        ?string $currentOverride = null,
        ?bool $showRequestOverride = null,
        ?int $websiteIdAttr = null,
    ): LocaleCatalogScope {
        $stateLocale = '';
        if (\function_exists('w_env')) {
            try {
                $stateLocale = $this->normalizeCode((string)State::getLangLocal());
                if ($stateLocale === '') {
                    $stateLocale = $this->normalizeCode((string)State::getLang());
                }
            } catch (\Throwable) {
                $stateLocale = '';
            }
        }
        if ($stateLocale === '') {
            $stateLocale = self::FALLBACK_CODE;
        }

        $currentHint = $this->normalizeCode((string)($currentOverride ?? ''));
        if ($currentHint === '') {
            $currentHint = $stateLocale;
        }

        $injected = $this->normalizeCodeList($injectedCodes);
        if ($injected !== []) {
            $default = $injected[0];
            $current = \in_array($currentHint, $injected, true) ? $currentHint : $default;
            return new LocaleCatalogScope(
                codes: $injected,
                defaultCode: $default,
                currentCode: $current,
                displayLocale: $current,
                allowRequest: false,
                mode: LocaleCatalogScope::MODE_INJECTED,
                websiteId: $websiteIdAttr ?? $websiteId,
            );
        }

        $forcedWebsiteId = $websiteIdAttr;
        if ($forcedWebsiteId !== null) {
            return $this->resolveWebsiteScope(
                \max(0, $forcedWebsiteId),
                $currentHint,
                $stateLocale,
                $showRequestOverride,
            );
        }

        // Frontend and backend chrome share one WebsiteLanguage boundary for the
        // current/default website. `<w:i18n:switcher />` must not show a different
        // list in admin just because Locale install-state is sparse.
        $resolvedWebsiteId = \max(0, $websiteId);
        if (!$isBackendArea) {
            return $this->resolveWebsiteScope(
                $resolvedWebsiteId,
                $currentHint,
                $stateLocale,
                $showRequestOverride,
            );
        }

        $websiteCodes = $this->normalizeCodeList($this->fetchWebsiteLanguageCodes($resolvedWebsiteId));
        if ($websiteCodes !== []) {
            return $this->resolveWebsiteScope(
                $resolvedWebsiteId,
                $currentHint,
                $stateLocale,
                $showRequestOverride,
            );
        }

        // Fallback only when the website has no language rows at all.
        return $this->resolveBackendPlatformScope($currentHint, $stateLocale, $showRequestOverride);
    }

    private function resolveWebsiteScope(
        int $websiteId,
        string $currentHint,
        string $stateLocale,
        ?bool $showRequestOverride,
    ): LocaleCatalogScope {
        $codes = $this->normalizeCodeList($this->fetchWebsiteLanguageCodes($websiteId));
        $default = $this->normalizeCode($this->fetchWebsiteDefaultLanguage($websiteId));
        if ($default === '' || ($codes !== [] && !\in_array($default, $codes, true))) {
            $default = $codes[0] ?? ($stateLocale !== '' ? $stateLocale : self::FALLBACK_CODE);
        }
        if ($codes === []) {
            // Hard boundary: never leak the global/installed catalog on an empty website set.
            $codes = [$default !== '' ? $default : self::FALLBACK_CODE];
            $default = $codes[0];
            try {
                \w_log_warning(
                    '[i18n.locale_catalog_scope] empty website languages website_id='
                    . $websiteId . ' fallback=' . $default
                );
            } catch (\Throwable) {
            }
        }

        $current = \in_array($currentHint, $codes, true) ? $currentHint : $default;
        $allowRequest = $showRequestOverride ?? $this->isLanguageRequestEnabled();

        return new LocaleCatalogScope(
            codes: $codes,
            defaultCode: $default,
            currentCode: $current,
            displayLocale: $current,
            allowRequest: $allowRequest,
            mode: LocaleCatalogScope::MODE_WEBSITE,
            websiteId: $websiteId,
        );
    }

    private function resolveBackendPlatformScope(
        string $currentHint,
        string $stateLocale,
        ?bool $showRequestOverride,
    ): LocaleCatalogScope {
        $codes = [];
        try {
            /** @var ActiveLocaleCodeProvider $provider */
            $provider = ObjectManager::getInstance(ActiveLocaleCodeProvider::class);
            $codes = $this->normalizeCodeList($provider->getInstalledActiveCodes());
        } catch (\Throwable) {
            $codes = [];
        }
        if ($codes === []) {
            $codes = [$stateLocale !== '' ? $stateLocale : self::FALLBACK_CODE];
        }
        $default = \in_array($stateLocale, $codes, true) ? $stateLocale : $codes[0];
        $current = \in_array($currentHint, $codes, true) ? $currentHint : $default;

        return new LocaleCatalogScope(
            codes: $codes,
            defaultCode: $default,
            currentCode: $current,
            displayLocale: $current,
            allowRequest: $showRequestOverride ?? false,
            mode: LocaleCatalogScope::MODE_BACKEND_PLATFORM,
            websiteId: 0,
        );
    }

    /**
     * @return list<string>
     */
    private function fetchWebsiteLanguageCodes(int $websiteId): array
    {
        try {
            $result = \w_query('websites', 'getWebsiteLanguageCodes', ['website_id' => $websiteId]);
            return \is_array($result) ? $result : [];
        } catch (\Throwable) {
            return [];
        }
    }

    private function fetchWebsiteDefaultLanguage(int $websiteId): string
    {
        try {
            $website = \w_query('websites', 'getWebsiteById', ['website_id' => $websiteId]);
            if (\is_array($website)) {
                return (string)($website['default_language'] ?? '');
            }
        } catch (\Throwable) {
        }

        return '';
    }

    private function isLanguageRequestEnabled(): bool
    {
        try {
            $value = ObjectManager::getInstance(ConfigReader::class)->get(
                'i18n/language_request/enabled',
                'Weline_I18n',
                ConfigReader::area_FRONTEND,
                true,
            );
            return \is_bool($value)
                ? $value
                : \in_array(\strtolower(\trim((string)$value)), ['1', 'true', 'yes', 'on'], true);
        } catch (\Throwable) {
            return true;
        }
    }

    private function normalizeCode(string $code): string
    {
        return \trim(\str_replace('-', '_', $code));
    }

    /**
     * @param iterable<mixed> $codes
     * @return list<string>
     */
    private function normalizeCodeList(iterable $codes): array
    {
        $result = [];
        $seen = [];
        foreach ($codes as $code) {
            if (\is_array($code) && isset($code['code'])) {
                $code = $code['code'];
            }
            if (!\is_scalar($code)) {
                continue;
            }
            $normalized = $this->normalizeCode((string)$code);
            if ($normalized === '') {
                continue;
            }
            $key = \strtolower($normalized);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $result[] = $normalized;
        }

        return $result;
    }
}

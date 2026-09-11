<?php

declare(strict_types=1);

namespace Weline\Captcha\Service;

use Weline\SystemConfig\Api\ConfigReader;

final class CaptchaConfig
{
    public const MODULE = 'Weline_Captcha';
    public const AREA = ConfigReader::area_BACKEND;

    public function __construct(private readonly ConfigReader $config)
    {
    }

    public function enabled(): bool
    {
        // Compatibility facade: unified form verification is mandatory.
        return true;
    }

    public function googleEnabled(): bool
    {
        return $this->boolean('captcha/google/enabled', true);
    }

    public function googleProjectId(): string
    {
        return $this->string('captcha/google/project_id');
    }

    public function googleSiteKey(): string
    {
        return $this->string('captcha/google/site_key');
    }

    public function googleApiKey(): string
    {
        return $this->string('captcha/google/api_key');
    }

    /**
     * Google Cloud API keys usually start with "AIza". Site keys usually start with "6L".
     * Merchants often paste Site Key into the API Key field; Google then returns
     * "API key not valid".
     */
    public function googleApiKeyLooksLikeSiteKey(): bool
    {
        $apiKey = $this->googleApiKey();
        if ($apiKey === '') {
            return false;
        }
        if (\str_starts_with($apiKey, 'AIza')) {
            return false;
        }

        return \str_starts_with($apiKey, '6L');
    }

    public function googleAccessToken(): string
    {
        return $this->string('captcha/google/access_token');
    }

    public function googleRefreshToken(): string
    {
        return $this->string('captcha/google/refresh_token');
    }

    public function googleClientId(): string
    {
        return $this->string('captcha/google/oauth_client_id');
    }

    public function googleClientSecret(): string
    {
        return $this->string('captcha/google/oauth_client_secret');
    }

    public function scoreThreshold(): float
    {
        return \max(0.0, \min(1.0, (float)$this->config->get(
            'captcha/google/score_threshold',
            self::MODULE,
            self::AREA,
            0.5,
        )));
    }

    public function tokenMaxAge(): int
    {
        return \max(30, \min(600, (int)$this->config->get(
            'captcha/google/token_max_age',
            self::MODULE,
            self::AREA,
            120,
        )));
    }

    /** @return list<string> */
    public function allowedDomains(): array
    {
        $raw = $this->string('captcha/google/allowed_domains');
        $parts = \preg_split('/[\s,;]+/', \strtolower($raw), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $domains = [];
        foreach ($parts as $part) {
            $domain = \trim($part, ". \t\n\r\0\x0B");
            if ($domain !== '' && \preg_match('/\A(?:\*\.)?[a-z0-9.-]+\z/D', $domain) === 1) {
                $domains[$domain] = true;
            }
        }
        return \array_keys($domains);
    }

    public function isGoogleReady(): bool
    {
        if ($this->googleProjectId() === '' || $this->googleSiteKey() === '') {
            return false;
        }
        // OAuth bearer/refresh can authenticate assessments without an API key.
        if ($this->googleAccessToken() !== '' || $this->googleRefreshToken() !== '') {
            return true;
        }
        $apiKey = $this->googleApiKey();
        if ($apiKey === '') {
            return false;
        }
        // Site-key-shaped values (6L…) are never valid Cloud API keys (AIza…).
        // Always not-ready → Router falls back to local/Tencent (DEV and production).
        // createAssessment still rejects 6L if Google is forced somehow.
        if ($this->googleApiKeyLooksLikeSiteKey()) {
            return false;
        }

        return true;
    }

    public function tencentEnabled(): bool
    {
        return $this->boolean('captcha/tencent/enabled', true);
    }

    public function tencentAppId(): string
    {
        return $this->string('captcha/tencent/app_id');
    }

    public function tencentAppSecretKey(): string
    {
        return $this->string('captcha/tencent/app_secret_key');
    }

    public function isTencentReady(): bool
    {
        return $this->tencentEnabled()
            && $this->tencentAppId() !== ''
            && $this->tencentAppSecretKey() !== '';
    }

    /** @return list<string> */
    public function chinaCodes(): array
    {
        return $this->countryCodeList('captcha/routing/china_codes', ['CN']);
    }

    public function chinaDefaultProvider(): string
    {
        return $this->providerCode('captcha/routing/china_default', CaptchaProviderRouter::TENCENT);
    }

    public function worldDefaultProvider(): string
    {
        return $this->providerCode('captcha/routing/world_default', CaptchaProviderRouter::GOOGLE);
    }

    public function unknownDefaultProvider(): string
    {
        return $this->providerCode('captcha/routing/unknown_default', CaptchaProviderRouter::GOOGLE);
    }

    /**
     * @return array<string, string> ISO2 => provider
     */
    public function countryOverrides(): array
    {
        $raw = $this->string('captcha/routing/country_overrides');
        if ($raw === '') {
            return [];
        }
        $map = [];
        foreach (\preg_split('/\R/', $raw) ?: [] as $line) {
            $line = \trim((string)$line);
            if ($line === '' || \str_starts_with($line, '#')) {
                continue;
            }
            if (!\str_contains($line, '=')) {
                continue;
            }
            [$country, $provider] = \array_map('trim', \explode('=', $line, 2));
            $country = \strtoupper($country);
            $provider = \strtolower($provider);
            if (\preg_match('/\A[A-Z]{2}\z/D', $country) !== 1) {
                continue;
            }
            if (!\in_array($provider, CaptchaProviderRouter::KNOWN_PROVIDERS, true)) {
                continue;
            }
            $map[$country] = $provider;
        }
        return $map;
    }

    /** @return list<string> */
    public function fallbackChain(): array
    {
        $raw = $this->string('captcha/routing/fallback_chain');
        if ($raw === '') {
            return [
                CaptchaProviderRouter::TENCENT,
                CaptchaProviderRouter::GOOGLE,
                CaptchaProviderRouter::LOCAL,
            ];
        }
        // Select UI encodes ordered chains with "+" to avoid options CSV collisions.
        $raw = \str_replace('+', ',', $raw);
        $chain = [];
        foreach (\preg_split('/[\s,;]+/', \strtolower($raw), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $code) {
            if (\in_array($code, CaptchaProviderRouter::KNOWN_PROVIDERS, true)) {
                $chain[] = $code;
            }
        }
        if ($chain === []) {
            return [
                CaptchaProviderRouter::TENCENT,
                CaptchaProviderRouter::GOOGLE,
                CaptchaProviderRouter::LOCAL,
            ];
        }
        return $chain;
    }

    public function allowLocalDegrade(): bool
    {
        return $this->boolean('captcha/routing/allow_local_degrade', true);
    }

    /** @return list<string> */
    public function geoHeaders(): array
    {
        $raw = $this->string('captcha/routing/geo_headers');
        if ($raw === '') {
            return [
                'CF-IPCountry',
                'CloudFront-Viewer-Country',
                'X-Country-Code',
                'X-Geo-Country',
            ];
        }
        $headers = [];
        foreach (\preg_split('/[\s,;]+/', $raw, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $header) {
            $header = \trim($header);
            if ($header !== '' && \preg_match('/\A[A-Za-z0-9_-]+\z/D', $header) === 1) {
                $headers[] = $header;
            }
        }
        return $headers !== [] ? $headers : [
            'CF-IPCountry',
            'CloudFront-Viewer-Country',
            'X-Country-Code',
            'X-Geo-Country',
        ];
    }

    private function providerCode(string $key, string $default): string
    {
        $code = \strtolower($this->string($key));
        if (\in_array($code, CaptchaProviderRouter::KNOWN_PROVIDERS, true)) {
            return $code;
        }
        return $default;
    }

    /**
     * @param list<string> $default
     * @return list<string>
     */
    private function countryCodeList(string $key, array $default): array
    {
        $raw = $this->string($key);
        if ($raw === '') {
            return $default;
        }
        $codes = [];
        foreach (\preg_split('/[\s,;]+/', \strtoupper($raw), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $code) {
            if (\preg_match('/\A[A-Z]{2}\z/D', $code) === 1) {
                $codes[$code] = true;
            }
        }
        return $codes !== [] ? \array_keys($codes) : $default;
    }

    private function string(string $key): string
    {
        return \trim((string)$this->config->get($key, self::MODULE, self::AREA, ''));
    }

    private function boolean(string $key, bool $default): bool
    {
        $value = $this->config->get($key, self::MODULE, self::AREA, $default);
        if (\is_bool($value)) {
            return $value;
        }
        return \in_array(\strtolower(\trim((string)$value)), ['1', 'true', 'yes', 'on'], true);
    }
}

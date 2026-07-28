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
        return $this->boolean('captcha/google/enabled', false);
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
        return $this->googleProjectId() !== ''
            && $this->googleSiteKey() !== ''
            && ($this->googleApiKey() !== '' || $this->googleAccessToken() !== '' || $this->googleRefreshToken() !== '');
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

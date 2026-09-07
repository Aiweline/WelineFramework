<?php

declare(strict_types=1);

namespace Weline\Captcha\Service;

/**
 * Country-aware provider selection: override → china/world/unknown default → ready fallback chain → local_image.
 */
final class CaptchaProviderRouter
{
    public const LOCAL = 'local_image';
    public const GOOGLE = 'google_enterprise';
    public const TENCENT = 'tencent_captcha';

    /** @var list<string> */
    public const KNOWN_PROVIDERS = [self::TENCENT, self::GOOGLE, self::LOCAL];

    public function __construct(
        private readonly CaptchaConfig $config,
        private readonly ClientGeoResolver $geo,
        private readonly CaptchaProviderRegistry $providers,
    ) {
    }

    /**
     * @param array<string, mixed> $server
     * @param array<string, mixed> $options prefer=local_image only honored when degrade allowed
     */
    public function resolve(array $server = [], array $options = []): string
    {
        $prefer = \strtolower(\trim((string)($options['prefer'] ?? '')));
        if (
            $prefer === self::LOCAL
            && $this->config->allowLocalDegrade()
            && $this->isReady(self::LOCAL)
        ) {
            return self::LOCAL;
        }

        $country = $this->geo->resolveFromServer($server);
        $candidate = $this->candidateForCountry($country);
        return $this->firstReady([$candidate, ...$this->config->fallbackChain(), self::LOCAL]);
    }

    public function resolveCountry(array $server = []): string
    {
        return $this->geo->resolveFromServer($server);
    }

    public function isReady(string $code): bool
    {
        $code = \strtolower(\trim($code));
        if ($this->providers->get($code) === null) {
            return false;
        }
        return match ($code) {
            self::GOOGLE => $this->config->googleEnabled() && $this->config->isGoogleReady(),
            self::TENCENT => $this->config->tencentEnabled() && $this->config->isTencentReady(),
            self::LOCAL => true,
            default => false,
        };
    }

    public function normalizeProviderCode(string $code): string
    {
        $code = \strtolower(\trim($code));
        return \in_array($code, self::KNOWN_PROVIDERS, true) ? $code : '';
    }

    private function candidateForCountry(string $country): string
    {
        $overrides = $this->config->countryOverrides();
        if (isset($overrides[$country])) {
            return $overrides[$country];
        }
        if ($country === ClientGeoResolver::UNKNOWN) {
            return $this->config->unknownDefaultProvider();
        }
        if (\in_array($country, $this->config->chinaCodes(), true)) {
            return $this->config->chinaDefaultProvider();
        }
        return $this->config->worldDefaultProvider();
    }

    /**
     * @param list<string> $codes
     */
    private function firstReady(array $codes): string
    {
        $seen = [];
        foreach ($codes as $code) {
            $code = $this->normalizeProviderCode((string)$code);
            if ($code === '' || isset($seen[$code])) {
                continue;
            }
            $seen[$code] = true;
            if ($this->isReady($code)) {
                return $code;
            }
        }
        return self::LOCAL;
    }
}

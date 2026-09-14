<?php

declare(strict_types=1);

namespace Weline\Customer\Service\SocialLogin;

use Weline\Framework\App\State;
use Weline\Framework\Http\Url;
use Weline\Framework\Session\SessionFactory;

/**
 * OAuth start/callback shell for storefront social login providers.
 *
 * Provider-specific authorize / token / profile logic lives on SocialLoginProviderInterface.
 *
 * OAuth state and pending profiles are persisted in {@see SocialLoginTransientStore}
 * (primary) so Google callback still works when the storefront Session cookie is
 * missing after the cross-site return. Session mirroring remains best-effort.
 */
class SocialLoginOAuthService
{
    private const STATE_KIND = 'state';
    private const PENDING_KIND = 'pending';
    private const STATE_PREFIX = 'customer.social_login.state.';
    private const PENDING_PREFIX = 'customer.social_login.pending.';
    private const STATE_TTL = 900;
    private const PENDING_TTL = 900;

    public const INTENT_LOGIN = 'login';
    public const INTENT_BIND = 'bind';

    public function __construct(
        private readonly SocialLoginConfig $config,
        private readonly SocialLoginProviderCatalog $catalog,
        private readonly SessionFactory $sessionFactory,
        private readonly Url $url,
        private readonly SocialLoginTransientStore $transientStore,
    ) {
    }

    public function callbackUrl(): string
    {
        // Google/Meta match redirect_uri exactly. Locale/currency path prefixes
        // must not appear here; they are restored after callback via OAuth state.
        return Url::withoutStorefrontLocalizationPrefix(
            $this->url->getOriginUrl('customer/account/social-login/callback')
        );
    }

    public function startUrl(string $provider, string $returnUrl = '', string $intent = self::INTENT_LOGIN): string
    {
        $provider = strtolower(trim($provider));
        $params = ['provider' => $provider];
        if ($returnUrl !== '') {
            $params['return_url'] = $returnUrl;
        }
        $intent = strtolower(trim($intent));
        if ($intent !== '' && $intent !== self::INTENT_LOGIN) {
            $params['intent'] = $intent;
        }

        // Google OAuth requires exact redirect_uri match. Keep the storefront *start*
        // entry fixed (no /{locale}/ or /{CURRENCY}/) so authorize always uses the same
        // callbackUrl(); language/currency ride in return_url + OAuth state.
        if ($provider === 'google') {
            return Url::withoutStorefrontLocalizationPrefix(
                $this->url->getOriginUrl('customer/account/social-login/start', $params)
            );
        }

        return $this->url->getUrl('customer/account/social-login/start', $params);
    }

    public function quickGoogleUrl(): string
    {
        return $this->url->getUrl('customer/account/social-login/quick-google');
    }

    public function quickFacebookUrl(): string
    {
        return $this->url->getUrl('customer/account/social-login/quick-facebook');
    }

    /**
     * @param array{intent?:string,customer_id?:int} $options
     * @return array{authorization_url:string,state:string}
     */
    public function start(string $provider, string $returnUrl = '', array $options = []): array
    {
        $provider = strtolower(trim($provider));
        $providerInstance = $this->catalog->get($provider);
        if ($providerInstance === null) {
            throw new \InvalidArgumentException((string) __('不支持的社媒登录提供方'));
        }
        if (!$this->config->isConfigured($provider)) {
            throw new \RuntimeException((string) __('请先在统一配置中心填写 %{1} OAuth 凭据', [ucfirst($provider)]));
        }
        if (!$this->config->isEnabled($provider)) {
            throw new \RuntimeException((string) __('请先在统一配置中心激活 %{1} 前台登录入口', [ucfirst($provider)]));
        }

        $intent = strtolower(trim((string) ($options['intent'] ?? self::INTENT_LOGIN)));
        if (!in_array($intent, [self::INTENT_LOGIN, self::INTENT_BIND], true)) {
            $intent = self::INTENT_LOGIN;
        }
        $customerId = (int) ($options['customer_id'] ?? 0);

        $state = bin2hex(random_bytes(24));
        $redirectUri = $this->callbackUrl();
        $localeHint = (string) ($options['locale_hint_url'] ?? '');
        // Prefer return_url / Referer over the start request path: QueryBin may have
        // stamped a wrong locale onto oauth.start URLs (worker path has no /{locale}/).
        $localePrefix = SocialLoginStorefrontLocale::firstNonEmpty(
            $returnUrl,
            $localeHint,
            $this->currentStorefrontLocalePrefix(),
        );
        $payload = [
            'provider' => $provider,
            'return_url' => $returnUrl,
            'redirect_uri' => $redirectUri,
            'intent' => $intent,
            'customer_id' => $customerId,
            'locale_prefix' => $localePrefix,
            'lang' => State::getLang(),
            'currency' => State::getCurrency(),
            'created_at' => time(),
        ];
        $this->transientStore->put(self::STATE_KIND, $state, $payload, self::STATE_TTL);
        $this->mirrorSessionSet(self::STATE_PREFIX . $state, $payload);

        return [
            'state' => $state,
            'authorization_url' => $providerInstance->buildAuthorizationUrl(
                $this->config->clientId($provider),
                $redirectUri,
                $state
            ),
        ];
    }

    /**
     * @param array<string, mixed> $params
     * @return array{provider:string,subject:string,email:string,display_name:string,avatar_url:string,return_url:string,intent:string,customer_id:int,locale_prefix:string,lang:string,currency:string}
     */
    public function complete(array $params): array
    {
        $state = trim((string) ($params['state'] ?? ''));
        $payload = $this->consumeState($state);
        if ($payload === null) {
            throw new SocialLoginOAuthFailedException((string) __('社媒登录状态无效或已过期，请重试'));
        }

        $localePrefix = SocialLoginStorefrontLocale::firstNonEmpty(
            (string) ($payload['locale_prefix'] ?? ''),
            (string) ($payload['return_url'] ?? ''),
        );
        $lang = SocialLoginStorefrontLocale::languageFromPrefix($localePrefix);
        if ($lang === '') {
            $lang = trim((string) ($payload['lang'] ?? ''));
        }
        if ($lang !== '') {
            State::setRequestLanguageOverride($lang);
        }

        $error = trim((string) ($params['error'] ?? ''));
        if ($error !== '') {
            $description = trim((string) ($params['error_description'] ?? $error));
            throw new SocialLoginOAuthFailedException(
                (string) __('社媒授权失败：%{1}', [$description]),
                $localePrefix
            );
        }

        $code = trim((string) ($params['code'] ?? ''));
        if ($code === '') {
            throw new SocialLoginOAuthFailedException((string) __('社媒授权回调缺少授权码'), $localePrefix);
        }

        $provider = (string) ($payload['provider'] ?? '');
        $providerInstance = $this->catalog->get($provider);
        if ($providerInstance === null) {
            throw new SocialLoginOAuthFailedException((string) __('不支持的社媒登录提供方'), $localePrefix);
        }

        $redirectUri = (string) ($payload['redirect_uri'] ?? $this->callbackUrl());
        try {
            $profile = $providerInstance->exchangeAndFetchProfile(
                $this->config->clientId($provider),
                $this->config->clientSecret($provider),
                $code,
                $redirectUri
            );
        } catch (SocialLoginOAuthFailedException $e) {
            throw new SocialLoginOAuthFailedException(
                (string) __($e->getMessage()),
                $localePrefix !== '' ? $localePrefix : $e->getLocalePrefix(),
                $e
            );
        } catch (\Throwable $e) {
            throw new SocialLoginOAuthFailedException(
                (string) __($e->getMessage()),
                $localePrefix,
                $e
            );
        }

        return [
            'provider' => $provider,
            'subject' => $profile['subject'],
            'email' => $profile['email'],
            'display_name' => $profile['display_name'],
            'avatar_url' => $profile['avatar_url'],
            'return_url' => (string) ($payload['return_url'] ?? ''),
            'intent' => (string) ($payload['intent'] ?? self::INTENT_LOGIN),
            'customer_id' => (int) ($payload['customer_id'] ?? 0),
            'locale_prefix' => $localePrefix,
            'lang' => (string) ($payload['lang'] ?? ''),
            'currency' => (string) ($payload['currency'] ?? ''),
        ];
    }

    public function currentStorefrontLocalePrefix(): string
    {
        return SocialLoginStorefrontLocale::currentPrefix();
    }

    public function normalizeLocalePrefix(string $prefix): string
    {
        return SocialLoginStorefrontLocale::normalize($prefix);
    }

    /**
     * @param array{provider:string,subject:string,email:string,display_name:string,avatar_url:string,return_url?:string,intent?:string} $profile
     */
    public function storePending(array $profile): string
    {
        $token = bin2hex(random_bytes(24));
        $payload = [
            'profile' => $profile,
            'created_at' => time(),
        ];
        $this->transientStore->put(self::PENDING_KIND, $token, $payload, self::PENDING_TTL);
        $this->mirrorSessionSet(self::PENDING_PREFIX . $token, $payload);

        return $token;
    }

    /** @return array<string, mixed>|null */
    public function peekPending(string $token): ?array
    {
        return $this->readPending($token, false);
    }

    /** @return array<string, mixed>|null */
    public function consumePending(string $token): ?array
    {
        return $this->readPending($token, true);
    }

    /** @return array<string, mixed>|null */
    private function readPending(string $token, bool $consume): ?array
    {
        $token = trim($token);
        if ($token === '') {
            return null;
        }

        $payload = $consume
            ? $this->transientStore->take(self::PENDING_KIND, $token)
            : $this->transientStore->get(self::PENDING_KIND, $token);

        if (!is_array($payload)) {
            $payload = $this->readSessionPayload(self::PENDING_PREFIX . $token, $consume);
            if (is_array($payload) && !$consume) {
                // Promote legacy session-only pending into the durable store.
                try {
                    $this->transientStore->put(self::PENDING_KIND, $token, $payload, self::PENDING_TTL);
                } catch (\Throwable) {
                    // best-effort
                }
            }
        } else {
            $this->mirrorSessionDelete(self::PENDING_PREFIX . $token);
        }

        if (!is_array($payload) || !is_array($payload['profile'] ?? null)) {
            return null;
        }
        if ((int) ($payload['created_at'] ?? 0) + self::PENDING_TTL < time()) {
            $this->transientStore->delete(self::PENDING_KIND, $token);
            $this->mirrorSessionDelete(self::PENDING_PREFIX . $token);

            return null;
        }

        return $payload['profile'];
    }

    /** @return array<string, mixed>|null */
    private function consumeState(string $state): ?array
    {
        $state = trim($state);
        if ($state === '') {
            return null;
        }

        $payload = $this->transientStore->take(self::STATE_KIND, $state);
        if (!is_array($payload)) {
            $payload = $this->readSessionPayload(self::STATE_PREFIX . $state, true);
        } else {
            $this->mirrorSessionDelete(self::STATE_PREFIX . $state);
        }

        if (!is_array($payload)) {
            return null;
        }
        if ((int) ($payload['created_at'] ?? 0) + self::STATE_TTL < time()) {
            return null;
        }

        return $payload;
    }

    /** @param array<string, mixed> $payload */
    private function mirrorSessionSet(string $key, array $payload): void
    {
        try {
            $this->sessionFactory->createFrontendSession()->set($key, $payload);
        } catch (\Throwable) {
            // Session cookie may be unavailable; transient store is authoritative.
        }
    }

    private function mirrorSessionDelete(string $key): void
    {
        try {
            $this->sessionFactory->createFrontendSession()->delete($key);
        } catch (\Throwable) {
            // ignore
        }
    }

    /** @return array<string, mixed>|null */
    private function readSessionPayload(string $key, bool $consume): ?array
    {
        try {
            $session = $this->sessionFactory->createFrontendSession();
            $payload = $session->get($key);
            if ($consume) {
                $session->delete($key);
            }

            return is_array($payload) ? $payload : null;
        } catch (\Throwable) {
            return null;
        }
    }
}

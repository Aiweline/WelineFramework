<?php

declare(strict_types=1);

namespace WeShop\GoogleAuth\Service;

use GuzzleHttp\Client;
use Weline\Framework\App\Env;
use Weline\Framework\Http\Url;
use Weline\Framework\Session\SessionFactory;
use Weline\Framework\Session\SessionInterface;

class GoogleOAuthService
{
    private const STATE_PREFIX = 'weshop_googleauth_state_';
    private const STATE_TTL = 600;
    private static ?bool $isConfiguredCache = null;

    public function __construct(
        private readonly Url $url
    ) {
    }

    public function isConfigured(): bool
    {
        return self::isConfiguredFast();
    }

    public static function isConfiguredFast(): bool
    {
        if (self::$isConfiguredCache !== null) {
            return self::$isConfiguredCache;
        }

        $clientId = trim((string) (Env::getInstance()->getConfig('google_auth.client_id', '') ?: getenv('WESHOP_GOOGLE_CLIENT_ID') ?: ''));
        $clientSecret = trim((string) (Env::getInstance()->getConfig('google_auth.client_secret', '') ?: getenv('WESHOP_GOOGLE_CLIENT_SECRET') ?: ''));
        self::$isConfiguredCache = $clientId !== '' && $clientSecret !== '';

        return self::$isConfiguredCache;
    }

    public function getClientId(): string
    {
        return trim((string) (Env::getInstance()->getConfig('google_auth.client_id', '') ?: getenv('WESHOP_GOOGLE_CLIENT_ID') ?: ''));
    }

    public function getClientSecret(): string
    {
        return trim((string) (Env::getInstance()->getConfig('google_auth.client_secret', '') ?: getenv('WESHOP_GOOGLE_CLIENT_SECRET') ?: ''));
    }

    public function getCallbackUrl(): string
    {
        return $this->url->getOriginUrl('weshop_googleauth/frontend/auth/callback');
    }

    public function beginAuthorization(
        string $area,
        string $mode = 'login',
        int $localUserId = 0,
        string $redirectUrl = ''
    ): string {
        $area = $this->normalizeArea($area);
        $mode = $this->normalizeMode($mode);
        $this->assertConfigured();

        $state = bin2hex(random_bytes(24));
        $session = $this->getSession();
        $session->set(self::STATE_PREFIX . $state, [
            'area' => $area,
            'mode' => $mode,
            'local_user_id' => $localUserId,
            'redirect_url' => trim($redirectUrl),
            'created_at' => time(),
        ]);

        $query = [
            'client_id' => $this->getClientId(),
            'redirect_uri' => $this->getCallbackUrl(),
            'response_type' => 'code',
            'scope' => 'openid email profile',
            'state' => $state,
            'access_type' => 'offline',
            'prompt' => $mode === 'bind' ? 'consent' : 'select_account',
        ];

        return 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query($query);
    }

    public function sanitizeRedirectUrl(string $area, string $redirectUrl, bool $allowEmpty = true): string
    {
        $area = $this->normalizeArea($area);
        $redirectUrl = trim($redirectUrl);

        if ($redirectUrl === '') {
            return $allowEmpty ? '' : $this->getDefaultRedirectUrl($area);
        }

        if ($this->url->isLink($redirectUrl)) {
            if (Url::is_same_site($redirectUrl)) {
                return $redirectUrl;
            }

            return $allowEmpty ? '' : $this->getDefaultRedirectUrl($area);
        }

        // Reject scheme-like payloads such as "javascript:" and "data:".
        if ((bool) preg_match('/^[a-z][a-z0-9+.-]*:/i', $redirectUrl)) {
            return $allowEmpty ? '' : $this->getDefaultRedirectUrl($area);
        }

        $path = trim((string) (parse_url($redirectUrl, PHP_URL_PATH) ?? ''), '/');
        if ($path === '') {
            return $allowEmpty ? '' : $this->getDefaultRedirectUrl($area);
        }

        $query = [];
        $queryString = (string) (parse_url($redirectUrl, PHP_URL_QUERY) ?? '');
        if ($queryString !== '') {
            parse_str($queryString, $query);
        }

        if ($area === 'backend') {
            return $this->url->getBackendUrl($path, $query, false);
        }

        return $this->url->getFrontendUrl($path, $query, false);
    }

    public function consumeState(string $state): ?array
    {
        $state = trim($state);
        if ($state === '') {
            return null;
        }

        $session = $this->getSession();
        $payload = $session->get(self::STATE_PREFIX . $state);
        $session->delete(self::STATE_PREFIX . $state);

        if (!is_array($payload)) {
            return null;
        }

        $createdAt = (int) ($payload['created_at'] ?? 0);
        if ($createdAt <= 0 || ($createdAt + self::STATE_TTL) < time()) {
            return null;
        }

        return $payload;
    }

    public function fetchGoogleUser(string $code): array
    {
        $code = trim($code);
        if ($code === '') {
            throw new \InvalidArgumentException((string) __('Google authorization code is required.'));
        }

        $this->assertConfigured();
        $client = new Client([
            'timeout' => 20,
            'http_errors' => false,
        ]);

        $tokenResponse = $client->post('https://oauth2.googleapis.com/token', [
            'form_params' => [
                'code' => $code,
                'client_id' => $this->getClientId(),
                'client_secret' => $this->getClientSecret(),
                'redirect_uri' => $this->getCallbackUrl(),
                'grant_type' => 'authorization_code',
            ],
        ]);
        $tokenData = $this->decodeJsonResponse((string) $tokenResponse->getBody());
        if ($tokenResponse->getStatusCode() >= 400 || empty($tokenData['access_token'])) {
            throw new \RuntimeException((string) __('Google token exchange failed.'));
        }

        $accessToken = (string) ($tokenData['access_token'] ?? '');
        $idToken = (string) ($tokenData['id_token'] ?? '');
        $tokenInfo = $this->fetchTokenInfo($client, $idToken, $accessToken);
        $userInfo = $this->fetchUserInfo($client, $accessToken);

        $email = strtolower(trim((string) ($userInfo['email'] ?? $tokenInfo['email'] ?? '')));
        $subject = trim((string) ($userInfo['sub'] ?? $tokenInfo['sub'] ?? ''));
        $emailVerified = $this->toBool($userInfo['email_verified'] ?? $tokenInfo['email_verified'] ?? false);

        if ($subject === '' || $email === '') {
            throw new \RuntimeException((string) __('Google did not return a usable user profile.'));
        }

        if (!$emailVerified) {
            throw new \RuntimeException((string) __('Google email verification is required.'));
        }

        return [
            'sub' => $subject,
            'email' => $email,
            'email_verified' => true,
            'name' => (string) ($userInfo['name'] ?? $tokenInfo['name'] ?? ''),
            'given_name' => (string) ($userInfo['given_name'] ?? ''),
            'family_name' => (string) ($userInfo['family_name'] ?? ''),
            'picture' => (string) ($userInfo['picture'] ?? ''),
            'locale' => (string) ($userInfo['locale'] ?? ''),
            'access_token' => $accessToken,
            'id_token' => $idToken,
        ];
    }

    private function fetchTokenInfo(Client $client, string $idToken, string $accessToken): array
    {
        $query = $idToken !== '' ? ['id_token' => $idToken] : ['access_token' => $accessToken];
        $response = $client->get('https://oauth2.googleapis.com/tokeninfo', [
            'query' => $query,
        ]);
        $data = $this->decodeJsonResponse((string) $response->getBody());
        if ($response->getStatusCode() >= 400) {
            throw new \RuntimeException((string) __('Google token validation failed.'));
        }

        return $data;
    }

    private function fetchUserInfo(Client $client, string $accessToken): array
    {
        $response = $client->get('https://openidconnect.googleapis.com/v1/userinfo', [
            'headers' => [
                'Authorization' => 'Bearer ' . $accessToken,
            ],
        ]);
        $data = $this->decodeJsonResponse((string) $response->getBody());
        if ($response->getStatusCode() >= 400) {
            throw new \RuntimeException((string) __('Google userinfo request failed.'));
        }

        return $data;
    }

    private function decodeJsonResponse(string $json): array
    {
        $decoded = json_decode($json, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function assertConfigured(): void
    {
        if (!$this->isConfigured()) {
            throw new \RuntimeException((string) __('Google auth is not configured.'));
        }
    }

    private function getSession(): SessionInterface
    {
        $session = SessionFactory::session();
        $session->start(null);
        return $session;
    }

    private function normalizeArea(string $area): string
    {
        $area = strtolower(trim($area));
        if (!in_array($area, ['frontend', 'backend'], true)) {
            throw new \InvalidArgumentException((string) __('Unsupported Google auth area: %{1}', [$area]));
        }

        return $area;
    }

    private function normalizeMode(string $mode): string
    {
        $mode = strtolower(trim($mode));
        if (!in_array($mode, ['login', 'bind'], true)) {
            throw new \InvalidArgumentException((string) __('Unsupported Google auth mode: %{1}', [$mode]));
        }

        return $mode;
    }

    private function toBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return (int) $value === 1;
        }

        return in_array(strtolower(trim((string) $value)), ['1', 'true', 'yes'], true);
    }

    private function getDefaultRedirectUrl(string $area): string
    {
        if ($area === 'backend') {
            return $this->url->getBackendUrl('admin');
        }

        return $this->url->getFrontendUrl('weshop/customer/account/index');
    }
}

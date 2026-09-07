<?php

declare(strict_types=1);

namespace Weline\Customer\Service\SocialLogin;

use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Weline\Customer\Api\Auth\CustomerAccountFacadeInterface;
use Weline\Customer\Service\CustomerAuthReturnUrlService;
use Weline\Framework\Http\Url;
use Weline\Framework\Session\SessionFactory;

/**
 * Browser quick-auth (Google One Tap id_token / Facebook JS SDK access token).
 *
 * Completes into the same bind/create flow as redirect OAuth.
 */
final class SocialLoginQuickAuthService
{
    private const GOOGLE_JWKS_URL = 'https://www.googleapis.com/oauth2/v3/certs';
    private const GOOGLE_ISSUERS = [
        'https://accounts.google.com',
        'accounts.google.com',
    ];
    private const FB_USERINFO_URL = 'https://graph.facebook.com/v21.0/me';

    public function __construct(
        private readonly SocialLoginConfig $config,
        private readonly SocialLoginAccountLinker $linker,
        private readonly SocialLoginOAuthService $oauth,
        private readonly CustomerAccountFacadeInterface $accounts,
        private readonly CustomerAuthReturnUrlService $authReturn,
        private readonly SessionFactory $sessionFactory,
        private readonly Url $url,
    ) {
    }

    /**
     * @return array{redirect:string,status:string}
     */
    public function completeGoogleIdToken(string $idToken, string $returnUrl = ''): array
    {
        if (!$this->config->isQuickPromptEnabled('google')) {
            throw new \RuntimeException((string) __('未启用 Google 快捷登录'));
        }
        $claims = $this->verifyGoogleIdToken($idToken);
        $profile = [
            'provider' => 'google',
            'subject' => (string) ($claims['sub'] ?? ''),
            'email' => strtolower(trim((string) ($claims['email'] ?? ''))),
            'display_name' => trim((string) ($claims['name'] ?? $claims['given_name'] ?? '')),
            'avatar_url' => trim((string) ($claims['picture'] ?? '')),
            'return_url' => $returnUrl,
            'intent' => SocialLoginOAuthService::INTENT_LOGIN,
            'customer_id' => 0,
        ];
        if ($profile['subject'] === '' || $profile['email'] === '') {
            throw new \RuntimeException((string) __('Google 未返回可用的账号标识或邮箱'));
        }

        return $this->finishProfile($profile);
    }

    /**
     * @return array{redirect:string,status:string}
     */
    public function completeFacebookAccessToken(string $accessToken, string $returnUrl = ''): array
    {
        if (!$this->config->isQuickPromptEnabled('facebook')) {
            throw new \RuntimeException((string) __('未启用 Facebook 快捷登录'));
        }
        $accessToken = trim($accessToken);
        if ($accessToken === '') {
            throw new \InvalidArgumentException((string) __('缺少 Facebook access token'));
        }
        $data = $this->httpGetJson(self::FB_USERINFO_URL, [
            'fields' => 'id,name,email,picture.type(large)',
            'access_token' => $accessToken,
        ]);
        $subject = trim((string) ($data['id'] ?? ''));
        $email = strtolower(trim((string) ($data['email'] ?? '')));
        if ($subject === '') {
            throw new \RuntimeException((string) __('Facebook 未返回用户标识'));
        }
        if ($email === '') {
            $email = 'facebook_' . $subject . '@social-login.local';
        }
        $picture = '';
        if (is_array($data['picture']['data'] ?? null)) {
            $picture = trim((string) ($data['picture']['data']['url'] ?? ''));
        }
        $profile = [
            'provider' => 'facebook',
            'subject' => $subject,
            'email' => $email,
            'display_name' => trim((string) ($data['name'] ?? '')),
            'avatar_url' => $picture,
            'return_url' => $returnUrl,
            'intent' => SocialLoginOAuthService::INTENT_LOGIN,
            'customer_id' => 0,
        ];

        return $this->finishProfile($profile);
    }

    /**
     * @param array{provider:string,subject:string,email:string,display_name:string,avatar_url:string,return_url:string,intent:string,customer_id:int} $profile
     * @return array{redirect:string,status:string}
     */
    private function finishProfile(array $profile): array
    {
        $bound = $this->linker->findBoundIdentity($profile);
        if ($bound !== null) {
            $this->accounts->login($bound);
            $session = $this->sessionFactory->createFrontendSession();
            $captured = $this->authReturn->capture(
                $session,
                (string) ($profile['return_url'] ?? ''),
                ''
            );
            $target = $this->authReturn->consume($session, $captured);
            $redirect = $this->authReturn->formatAuthSuccessRedirect($target);

            return ['redirect' => $redirect, 'status' => 'logged_in'];
        }

        $token = $this->oauth->storePending($profile);
        $redirect = $this->url->getUrl('customer/account/social-login/choose', ['token' => $token]);

        return ['redirect' => $redirect, 'status' => 'choose'];
    }

    /** @return array<string, mixed> */
    private function verifyGoogleIdToken(string $idToken): array
    {
        $idToken = trim($idToken);
        if ($idToken === '' || substr_count($idToken, '.') !== 2) {
            throw new \InvalidArgumentException((string) __('Google id_token 无效'));
        }
        $clientId = $this->config->clientId('google');
        if ($clientId === '') {
            throw new \RuntimeException((string) __('未配置 Google Client ID'));
        }

        $jwks = $this->httpGetJson(self::GOOGLE_JWKS_URL);
        $keys = JWK::parseKeySet($jwks, 'RS256');
        try {
            $decoded = JWT::decode($idToken, $keys);
        } catch (\Throwable $e) {
            throw new \RuntimeException((string) __('Google id_token 校验失败：%{1}', [$e->getMessage()]), 0, $e);
        }
        $claims = (array) $decoded;
        $aud = $claims['aud'] ?? null;
        $audOk = is_string($aud)
            ? hash_equals($clientId, $aud)
            : (is_array($aud) && in_array($clientId, array_map('strval', $aud), true));
        if (!$audOk) {
            throw new \RuntimeException((string) __('Google id_token audience 不匹配'));
        }
        $iss = (string) ($claims['iss'] ?? '');
        if (!in_array($iss, self::GOOGLE_ISSUERS, true)) {
            throw new \RuntimeException((string) __('Google id_token issuer 无效'));
        }
        $verified = $claims['email_verified'] ?? true;
        if ($verified === false || $verified === 'false' || $verified === 0 || $verified === '0') {
            throw new \RuntimeException((string) __('Google 邮箱尚未验证'));
        }

        return $claims;
    }

    /**
     * @param array<string, scalar> $query
     * @return array<string, mixed>
     */
    private function httpGetJson(string $url, array $query = []): array
    {
        if ($query !== []) {
            $url .= (str_contains($url, '?') ? '&' : '?') . http_build_query($query);
        }
        $ch = curl_init($url);
        if ($ch === false) {
            throw new \RuntimeException((string) __('无法初始化社媒快捷登录请求'));
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
        ]);
        $proxy = SocialLoginOutboundProxy::resolve();
        if ($proxy['proxy'] !== '') {
            curl_setopt($ch, CURLOPT_PROXY, $proxy['proxy']);
            curl_setopt($ch, CURLOPT_HTTPPROXYTUNNEL, true);
            if (($proxy['type'] ?? 'http') === 'socks5') {
                curl_setopt($ch, CURLOPT_PROXYTYPE, CURLPROXY_SOCKS5_HOSTNAME);
            }
            if ($proxy['userpwd'] !== '') {
                curl_setopt($ch, CURLOPT_PROXYUSERPWD, $proxy['userpwd']);
            }
        }
        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        curl_close($ch);
        if ($errno !== 0 || !is_string($raw)) {
            throw new \RuntimeException((string) __('社媒快捷登录请求失败：%{1}', [$error !== '' ? $error : 'network']));
        }
        $json = json_decode($raw, true);
        if (!is_array($json) || $status >= 400) {
            $message = is_array($json['error'] ?? null)
                ? (string) ($json['error']['message'] ?? 'HTTP ' . $status)
                : (string) ($json['error'] ?? ('HTTP ' . $status));
            throw new \RuntimeException((string) __('社媒快捷登录请求失败：%{1}', [$message]));
        }

        return $json;
    }
}

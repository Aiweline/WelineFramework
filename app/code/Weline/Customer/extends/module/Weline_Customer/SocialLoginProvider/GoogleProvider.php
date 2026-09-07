<?php

declare(strict_types=1);

namespace Weline\Customer\Extends\Module\Weline_Customer\SocialLoginProvider;

use Weline\Customer\Service\SocialLogin\AbstractSocialLoginProvider;

final class GoogleProvider extends AbstractSocialLoginProvider
{
    private const AUTHORIZE_URL = 'https://accounts.google.com/o/oauth2/v2/auth';
    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';
    private const USERINFO_URL = 'https://openidconnect.googleapis.com/v1/userinfo';
    private const SCOPES = 'openid email profile';

    public function getCode(): string
    {
        return 'google';
    }

    public function getLabel(): string
    {
        return 'Google';
    }

    public function getIcon(): string
    {
        return 'chrome';
    }

    public function getSortOrder(): int
    {
        return 10;
    }

    public function getIconSvgMarkup(): string
    {
        return '<svg class="account-social-login__mark" viewBox="0 0 24 24" width="22" height="22" focusable="false" aria-hidden="true">'
            . '<path fill="#4285F4" d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z"/>'
            . '<path fill="#34A853" d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z"/>'
            . '<path fill="#FBBC05" d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.07H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.93l2.85-2.22.81-.62z"/>'
            . '<path fill="#EA4335" d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.07l3.66 2.84c.87-2.6 3.3-4.53 6.16-4.53z"/>'
            . '</svg>';
    }

    public function buildAuthorizationUrl(string $clientId, string $redirectUri, string $state): string
    {
        $query = [
            'client_id' => $clientId,
            'redirect_uri' => $redirectUri,
            'response_type' => 'code',
            'state' => $state,
            'scope' => self::SCOPES,
            'access_type' => 'online',
            'include_granted_scopes' => 'true',
            'prompt' => 'select_account',
        ];

        return self::AUTHORIZE_URL . '?' . http_build_query($query);
    }

    public function exchangeAndFetchProfile(
        string $clientId,
        string $clientSecret,
        string $code,
        string $redirectUri
    ): array {
        $token = $this->httpFormPost(self::TOKEN_URL, [
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'code' => $code,
            'redirect_uri' => $redirectUri,
            'grant_type' => 'authorization_code',
        ]);
        $accessToken = trim((string) ($token['access_token'] ?? ''));
        if ($accessToken === '') {
            throw new \RuntimeException((string) __('交换社媒 access token 失败'));
        }

        $data = $this->httpGet(self::USERINFO_URL, [], [
            'Authorization: Bearer ' . $accessToken,
        ]);
        $subject = trim((string) ($data['sub'] ?? ''));
        $email = strtolower(trim((string) ($data['email'] ?? '')));
        if ($subject === '' || $email === '') {
            throw new \RuntimeException((string) __('Google 未返回可用的账号标识或邮箱'));
        }

        return [
            'subject' => $subject,
            'email' => $email,
            'display_name' => trim((string) ($data['name'] ?? $data['given_name'] ?? '')),
            'avatar_url' => trim((string) ($data['picture'] ?? '')),
        ];
    }
}

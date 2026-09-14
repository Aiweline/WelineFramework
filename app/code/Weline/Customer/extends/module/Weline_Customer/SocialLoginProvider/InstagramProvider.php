<?php

declare(strict_types=1);

namespace Weline\Customer\Extends\Module\Weline_Customer\SocialLoginProvider;

use Weline\Customer\Service\SocialLogin\AbstractSocialLoginProvider;

final class InstagramProvider extends AbstractSocialLoginProvider
{
    /** Business Login for Instagram (Instagram API with Instagram Login). Basic Display retired 2024-12-04. */
    private const AUTHORIZE_URL = 'https://www.instagram.com/oauth/authorize';
    private const TOKEN_URL = 'https://api.instagram.com/oauth/access_token';
    private const USERINFO_URL = 'https://graph.instagram.com/me';
    private const SCOPES = 'instagram_business_basic';

    public function getCode(): string
    {
        return 'instagram';
    }

    public function getLabel(): string
    {
        return 'Instagram';
    }

    public function getIcon(): string
    {
        return 'instagram';
    }

    public function getSortOrder(): int
    {
        return 30;
    }

    /**
     * @return array<string, list<string>>
     */
    public function cspDirectives(): array
    {
        // Instagram Business Login authorize + token + Graph.
        return [
            'frame-src' => [
                'https://www.instagram.com',
            ],
            'connect-src' => [
                'https://www.instagram.com',
                'https://api.instagram.com',
                'https://graph.instagram.com',
            ],
        ];
    }

    public function getIconSvgMarkup(): string
    {
        return '<svg class="account-social-login__mark" viewBox="0 0 24 24" width="22" height="22" focusable="false" aria-hidden="true">'
            . '<defs><linearGradient id="ig-social-login" x1="0%" y1="100%" x2="100%" y2="0%">'
            . '<stop offset="0%" stop-color="#f58529"/><stop offset="50%" stop-color="#dd2a7b"/><stop offset="100%" stop-color="#515bd4"/>'
            . '</linearGradient></defs>'
            . '<path fill="url(#ig-social-login)" d="M12 2.163c3.204 0 3.584.012 4.85.07 3.252.148 4.771 1.691 4.919 4.919.058 1.265.069 1.645.069 4.849 0 3.205-.012 3.584-.069 4.849-.149 3.225-1.664 4.771-4.919 4.919-1.266.058-1.644.07-4.85.07-3.204 0-3.584-.012-4.849-.07-3.26-.149-4.771-1.699-4.919-4.92-.058-1.265-.07-1.644-.07-4.849 0-3.204.013-3.583.07-4.849.149-3.227 1.664-4.771 4.919-4.919 1.266-.057 1.645-.069 4.849-.069zM12 0C8.741 0 8.333.014 7.053.072 2.695.272.273 2.69.073 7.052.014 8.333 0 8.741 0 12c0 3.259.014 3.668.072 4.948.2 4.358 2.618 6.78 6.98 6.98C8.333 23.986 8.741 24 12 24c3.259 0 3.668-.014 4.948-.072 4.354-.2 6.782-2.618 6.979-6.98.059-1.28.073-1.689.073-4.948 0-3.259-.014-3.667-.072-4.947-.196-4.354-2.617-6.78-6.979-6.98C15.668.014 15.259 0 12 0zm0 5.838a6.162 6.162 0 100 12.324 6.162 6.162 0 000-12.324zM12 16a4 4 0 110-8 4 4 0 010 8zm6.406-11.845a1.44 1.44 0 100 2.881 1.44 1.44 0 000-2.881z"/>'
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
        ];

        return self::AUTHORIZE_URL . '?' . http_build_query($query);
    }

    public function exchangeAndFetchProfile(
        string $clientId,
        string $clientSecret,
        string $code,
        string $redirectUri
    ): array {
        // Meta may append "#_" to the code on redirect; strip before exchange.
        if (str_ends_with($code, '#_')) {
            $code = substr($code, 0, -2);
        }
        $token = $this->httpFormPost(self::TOKEN_URL, [
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'code' => $code,
            'redirect_uri' => $redirectUri,
            'grant_type' => 'authorization_code',
        ]);
        $tokenRow = $token;
        if (isset($token['data']) && is_array($token['data']) && $token['data'] !== []) {
            $first = $token['data'][0] ?? null;
            $tokenRow = is_array($first) ? $first : $token;
        }
        $accessToken = trim((string) ($tokenRow['access_token'] ?? ''));
        $userId = trim((string) ($tokenRow['user_id'] ?? ''));
        if ($accessToken === '') {
            throw new \RuntimeException((string) __('交换 Instagram access token 失败'));
        }

        $data = $this->httpGet(self::USERINFO_URL, [
            'fields' => 'user_id,username,name,account_type,profile_picture_url',
            'access_token' => $accessToken,
        ]);
        $subject = $userId !== ''
            ? $userId
            : trim((string) ($data['user_id'] ?? $data['id'] ?? ''));
        if ($subject === '') {
            throw new \RuntimeException((string) __('Instagram 未返回用户标识'));
        }
        $username = trim((string) ($data['username'] ?? ''));
        $name = trim((string) ($data['name'] ?? ''));
        $display = $username !== '' ? $username : ($name !== '' ? $name : ('instagram:' . $subject));
        $avatar = trim((string) ($data['profile_picture_url'] ?? ''));

        return [
            'subject' => $subject,
            'email' => 'instagram_' . $subject . '@social-login.local',
            'display_name' => $display,
            'avatar_url' => $avatar,
        ];
    }
}

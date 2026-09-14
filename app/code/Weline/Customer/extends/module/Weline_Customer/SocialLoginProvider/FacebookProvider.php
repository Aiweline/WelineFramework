<?php

declare(strict_types=1);

namespace Weline\Customer\Extends\Module\Weline_Customer\SocialLoginProvider;

use Weline\Customer\Service\SocialLogin\AbstractSocialLoginProvider;

final class FacebookProvider extends AbstractSocialLoginProvider
{
    private const AUTHORIZE_URL = 'https://www.facebook.com/v21.0/dialog/oauth';
    private const TOKEN_URL = 'https://graph.facebook.com/v21.0/oauth/access_token';
    private const USERINFO_URL = 'https://graph.facebook.com/v21.0/me';
    private const SCOPES = 'email,public_profile';

    public function getCode(): string
    {
        return 'facebook';
    }

    public function getLabel(): string
    {
        return 'Facebook';
    }

    public function getIcon(): string
    {
        return 'facebook';
    }

    public function getSortOrder(): int
    {
        return 20;
    }

    /**
     * @return array<string, list<string>>
     */
    public function cspDirectives(): array
    {
        // Facebook Login dialog + Graph API (+ optional JS SDK host).
        return [
            'script-src' => [
                'https://connect.facebook.net',
            ],
            'frame-src' => [
                'https://www.facebook.com',
            ],
            'connect-src' => [
                'https://www.facebook.com',
                'https://graph.facebook.com',
                'https://connect.facebook.net',
            ],
        ];
    }

    public function getIconSvgMarkup(): string
    {
        return '<svg class="account-social-login__mark" viewBox="0 0 24 24" width="22" height="22" focusable="false" aria-hidden="true">'
            . '<path fill="#1877F2" d="M24 12.073C24 5.405 18.627 0 12 0S0 5.405 0 12.073C0 18.1 4.388 23.094 10.125 24v-8.437H7.078v-3.49h3.047V9.43c0-3.007 1.792-4.669 4.533-4.669 1.312 0 2.686.235 2.686.235v2.953H15.83c-1.491 0-1.956.925-1.956 1.874v2.25h3.328l-.532 3.49h-2.796V24C19.612 23.094 24 18.1 24 12.073z"/>'
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
        $token = $this->httpGet(self::TOKEN_URL, [
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

        $data = $this->httpGet(self::USERINFO_URL, [
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

        return [
            'subject' => $subject,
            'email' => $email,
            'display_name' => trim((string) ($data['name'] ?? '')),
            'avatar_url' => $picture,
        ];
    }
}

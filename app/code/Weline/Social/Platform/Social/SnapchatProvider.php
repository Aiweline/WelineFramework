<?php

declare(strict_types=1);

namespace Weline\Social\Platform\Social;

use Weline\Social\Platform\DocumentedSocialPlatformProvider;

class SnapchatProvider extends DocumentedSocialPlatformProvider
{
    protected const DEFINITION = [
        'code' => 'snapchat',
        'title' => 'Snapchat',
        'family' => 'social',
        'sort_order' => 10,
        'region' => 'global',
        'brand_color' => '#fffc00',
        'auth_modes' => ['oauth2'],
        'supports_one_click_auth' => true,
        'capabilities' => ['publish_image', 'publish_video', 'publish_text'],
        'content_types' => ['image', 'video', 'text'],
        'required_config' => ['access_token'],
        'config_fields' => [
            ['key' => 'access_token', 'label' => 'Access Token', 'type' => 'password', 'required' => true],
            ['key' => 'refresh_token', 'label' => 'Refresh Token', 'type' => 'password', 'required' => false],
            ['key' => 'ad_account_id', 'label' => 'Ad Account ID', 'type' => 'text', 'required' => false],
        ],
        'docs' => [
            'marketing_api' => 'https://developers.snap.com/api/marketing-api/',
            'login_kit' => 'https://developers.snap.com/api/snap-kit/login-kit/',
        ],
        'status' => 'live_publish_enabled',
    ];

    public function buildAuthorizationUrl(array $accountContext, string $redirectUri, string $state): ?string
    {
        $clientId = $this->appConfig()->get('snapchat', 'client_id');
        if ($clientId === '' || $redirectUri === '') {
            return null;
        }

        return 'https://accounts.snapchat.com/login/oauth2/authorize?' . \http_build_query([
            'client_id' => $clientId,
            'redirect_uri' => $redirectUri,
            'response_type' => 'code',
            'scope' => 'snapchat-marketing-api',
            'state' => $state,
        ]);
    }

    public function handleAuthorizationCallback(array $callbackData, array $context = []): array
    {
        $code = \trim((string)($callbackData['code'] ?? ''));
        $redirectUri = \trim((string)($context['redirect_uri'] ?? ''));
        $app = \is_array($context['app_config'] ?? null) ? $context['app_config'] : $this->appConfig()->getPlatformApp('snapchat');
        $clientId = \trim((string)($app['client_id'] ?? ''));
        $clientSecret = \trim((string)($app['client_secret'] ?? ''));
        if ($code === '' || $clientId === '' || $clientSecret === '') {
            return ['success' => false, 'message' => (string)__('Snapchat 授权回调缺少 code 或应用凭据。'), 'credentials' => []];
        }

        $tokenRes = $this->http()->postForm('https://accounts.snapchat.com/login/oauth2/access_token', [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $redirectUri,
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
        ]);
        $accessToken = \trim((string)($tokenRes['json']['access_token'] ?? ''));
        if (!$tokenRes['ok'] || $accessToken === '') {
            return ['success' => false, 'message' => $this->apiErrorMessage($tokenRes, (string)__('交换 Snapchat access token 失败。')), 'credentials' => []];
        }

        return [
            'success' => true,
            'message' => (string)__('Snapchat 授权完成。'),
            'account_name' => 'Snapchat',
            'credentials' => [
                'access_token' => $accessToken,
                'refresh_token' => (string)($tokenRes['json']['refresh_token'] ?? ''),
            ],
        ];
    }

    public function testConfig(array $config, array $context = []): array
    {
        $token = \trim((string)($config['access_token'] ?? ''));
        if ($token === '') {
            return ['success' => false, 'message' => (string)__('缺少 Snapchat Access Token。')];
        }
        $res = $this->http()->get('https://adsapi.snapchat.com/v1/me', [], [
            'Authorization' => 'Bearer ' . $token,
        ]);
        if (!$res['ok']) {
            return ['success' => false, 'message' => $this->apiErrorMessage($res, (string)__('Snapchat 连通性检测失败。'))];
        }

        return ['success' => true, 'message' => (string)__('Snapchat Marketing API 连通性检测通过。')];
    }

    public function publish(array $draft, array $account, array $context = []): array
    {
        $payload = $this->normalizeDraftPayload($draft);

        return [
            'success' => false,
            'status' => 'blocked_by_content_type',
            'message' => (string)__('Snapchat 第一期仅支持授权与连通性检测；创意/媒体发布将在二期实现。当前草稿：%{1}', [$payload['title'] !== '' ? $payload['title'] : __('（无标题）')]),
            'remote_id' => '',
            'remote_url' => '',
        ];
    }
}

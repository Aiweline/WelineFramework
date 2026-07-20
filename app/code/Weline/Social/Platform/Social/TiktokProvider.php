<?php

declare(strict_types=1);

namespace Weline\Social\Platform\Social;

use Weline\Social\Platform\DocumentedSocialPlatformProvider;

class TiktokProvider extends DocumentedSocialPlatformProvider
{
    protected const DEFINITION = [
        'code' => 'tiktok',
        'title' => 'TikTok',
        'family' => 'social',
        'sort_order' => 4,
        'region' => 'global',
        'brand_color' => '#010101',
        'auth_modes' => ['oauth2'],
        'supports_one_click_auth' => true,
        'capabilities' => ['publish_video', 'publish_text'],
        'content_types' => ['video', 'text'],
        'required_config' => ['access_token'],
        'config_fields' => [
            ['key' => 'access_token', 'label' => 'Access Token', 'type' => 'password', 'required' => true],
            ['key' => 'open_id', 'label' => 'Open ID', 'type' => 'text', 'required' => false],
            ['key' => 'refresh_token', 'label' => 'Refresh Token', 'type' => 'password', 'required' => false],
        ],
        'docs' => [
            'content_posting' => 'https://developers.tiktok.com/doc/content-posting-api-get-started/',
            'login_kit' => 'https://developers.tiktok.com/doc/login-kit-web/',
        ],
        'status' => 'live_publish_enabled',
    ];

    public function buildAuthorizationUrl(array $accountContext, string $redirectUri, string $state): ?string
    {
        $clientKey = $this->appConfig()->get('tiktok', 'client_key');
        if ($clientKey === '' || $redirectUri === '') {
            return null;
        }

        return 'https://www.tiktok.com/v2/auth/authorize/?' . \http_build_query([
            'client_key' => $clientKey,
            'scope' => 'user.info.basic,video.upload,video.publish',
            'response_type' => 'code',
            'redirect_uri' => $redirectUri,
            'state' => $state,
        ]);
    }

    public function handleAuthorizationCallback(array $callbackData, array $context = []): array
    {
        $code = \trim((string)($callbackData['code'] ?? ''));
        $redirectUri = \trim((string)($context['redirect_uri'] ?? ''));
        $app = \is_array($context['app_config'] ?? null) ? $context['app_config'] : $this->appConfig()->getPlatformApp('tiktok');
        $clientKey = \trim((string)($app['client_key'] ?? ''));
        $clientSecret = \trim((string)($app['client_secret'] ?? ''));
        if ($code === '' || $clientKey === '' || $clientSecret === '') {
            return ['success' => false, 'message' => (string)__('TikTok 授权回调缺少 code 或应用凭据。'), 'credentials' => []];
        }

        $tokenRes = $this->http()->postForm('https://open.tiktokapis.com/v2/oauth/token/', [
            'client_key' => $clientKey,
            'client_secret' => $clientSecret,
            'code' => $code,
            'grant_type' => 'authorization_code',
            'redirect_uri' => $redirectUri,
        ]);
        $accessToken = \trim((string)($tokenRes['json']['access_token'] ?? ''));
        $openId = \trim((string)($tokenRes['json']['open_id'] ?? ''));
        if (!$tokenRes['ok'] || $accessToken === '') {
            return ['success' => false, 'message' => $this->apiErrorMessage($tokenRes, (string)__('交换 TikTok access token 失败。')), 'credentials' => []];
        }

        return [
            'success' => true,
            'message' => (string)__('TikTok 授权完成。'),
            'account_name' => $openId !== '' ? ('TikTok ' . $openId) : 'TikTok',
            'remote_account_id' => $openId,
            'remote_account_name' => $openId,
            'credentials' => [
                'access_token' => $accessToken,
                'refresh_token' => (string)($tokenRes['json']['refresh_token'] ?? ''),
                'open_id' => $openId,
            ],
        ];
    }

    public function testConfig(array $config, array $context = []): array
    {
        $token = \trim((string)($config['access_token'] ?? ''));
        if ($token === '') {
            return ['success' => false, 'message' => (string)__('缺少 TikTok Access Token。')];
        }
        $res = $this->http()->postJson(
            'https://open.tiktokapis.com/v2/user/info/?fields=open_id,display_name',
            [],
            ['Authorization' => 'Bearer ' . $token]
        );
        if (!$res['ok']) {
            return ['success' => false, 'message' => $this->apiErrorMessage($res, (string)__('TikTok 连通性检测失败。'))];
        }
        $name = (string)($res['json']['data']['user']['display_name'] ?? $res['json']['data']['user']['open_id'] ?? 'OK');

        return ['success' => true, 'message' => (string)__('TikTok 连通性检测通过：%{1}', [$name])];
    }

    public function publish(array $draft, array $account, array $context = []): array
    {
        $payload = $this->normalizeDraftPayload($draft);

        return [
            'success' => false,
            'status' => 'blocked_by_content_type',
            'message' => (string)__('TikTok 第一期仅支持授权与连通性检测；视频上传将在二期实现。当前草稿：%{1}', [$payload['title'] !== '' ? $payload['title'] : __('（无标题）')]),
            'remote_id' => '',
            'remote_url' => '',
        ];
    }
}

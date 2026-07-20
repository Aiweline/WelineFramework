<?php

declare(strict_types=1);

namespace Weline\Social\Platform\Video;

use Weline\Social\Platform\DocumentedSocialPlatformProvider;

class YoutubeProvider extends DocumentedSocialPlatformProvider
{
    protected const DEFINITION = [
        'code' => 'youtube',
        'title' => 'YouTube',
        'family' => 'video',
        'sort_order' => 2,
        'region' => 'global',
        'brand_color' => '#ff0000',
        'auth_modes' => ['oauth2'],
        'supports_one_click_auth' => true,
        'capabilities' => ['publish_text', 'publish_link'],
        'content_types' => ['text', 'link'],
        'required_config' => ['access_token'],
        'config_fields' => [
            ['key' => 'access_token', 'label' => 'Access Token', 'type' => 'password', 'required' => true],
            ['key' => 'refresh_token', 'label' => 'Refresh Token', 'type' => 'password', 'required' => false],
            ['key' => 'channel_id', 'label' => 'Channel ID', 'type' => 'text', 'required' => false],
        ],
        'docs' => [
            'data_api' => 'https://developers.google.com/youtube/v3',
            'upload' => 'https://developers.google.com/youtube/v3/guides/uploading_a_video',
        ],
        'status' => 'live_publish_enabled',
    ];

    public function buildAuthorizationUrl(array $accountContext, string $redirectUri, string $state): ?string
    {
        $clientId = $this->appConfig()->get('youtube', 'client_id');
        if ($clientId === '' || $redirectUri === '') {
            return null;
        }

        return 'https://accounts.google.com/o/oauth2/v2/auth?' . \http_build_query([
            'client_id' => $clientId,
            'redirect_uri' => $redirectUri,
            'response_type' => 'code',
            'scope' => 'https://www.googleapis.com/auth/youtube.readonly https://www.googleapis.com/auth/youtube.upload https://www.googleapis.com/auth/youtube.force-ssl',
            'access_type' => 'offline',
            'include_granted_scopes' => 'true',
            'prompt' => 'consent',
            'state' => $state,
        ]);
    }

    public function handleAuthorizationCallback(array $callbackData, array $context = []): array
    {
        $code = \trim((string)($callbackData['code'] ?? ''));
        $redirectUri = \trim((string)($context['redirect_uri'] ?? ''));
        $app = \is_array($context['app_config'] ?? null) ? $context['app_config'] : $this->appConfig()->getPlatformApp('youtube');
        $clientId = \trim((string)($app['client_id'] ?? ''));
        $clientSecret = \trim((string)($app['client_secret'] ?? ''));
        if ($code === '' || $clientId === '' || $clientSecret === '') {
            return ['success' => false, 'message' => (string)__('YouTube 授权回调缺少 code 或应用凭据。'), 'credentials' => []];
        }

        $tokenRes = $this->http()->postForm('https://oauth2.googleapis.com/token', [
            'code' => $code,
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'redirect_uri' => $redirectUri,
            'grant_type' => 'authorization_code',
        ]);
        $accessToken = \trim((string)($tokenRes['json']['access_token'] ?? ''));
        if (!$tokenRes['ok'] || $accessToken === '') {
            return ['success' => false, 'message' => $this->apiErrorMessage($tokenRes, (string)__('交换 YouTube access token 失败。')), 'credentials' => []];
        }

        $channelRes = $this->http()->get('https://www.googleapis.com/youtube/v3/channels', [
            'part' => 'snippet',
            'mine' => 'true',
        ], ['Authorization' => 'Bearer ' . $accessToken]);
        $channel = \is_array($channelRes['json']['items'][0] ?? null) ? $channelRes['json']['items'][0] : [];
        $channelId = (string)($channel['id'] ?? '');
        $title = (string)($channel['snippet']['title'] ?? 'YouTube Channel');

        return [
            'success' => true,
            'message' => (string)__('YouTube 授权完成。'),
            'account_name' => $title,
            'profile_url' => $channelId !== '' ? ('https://www.youtube.com/channel/' . $channelId) : '',
            'remote_account_id' => $channelId,
            'remote_account_name' => $title,
            'credentials' => [
                'access_token' => $accessToken,
                'refresh_token' => (string)($tokenRes['json']['refresh_token'] ?? ''),
                'channel_id' => $channelId,
            ],
            'token_expires_at' => !empty($tokenRes['json']['expires_in'])
                ? \date('Y-m-d H:i:s', \time() + (int)$tokenRes['json']['expires_in'])
                : '',
        ];
    }

    public function testConfig(array $config, array $context = []): array
    {
        $token = \trim((string)($config['access_token'] ?? ''));
        if ($token === '') {
            return ['success' => false, 'message' => (string)__('缺少 YouTube Access Token。')];
        }
        $res = $this->http()->get('https://www.googleapis.com/youtube/v3/channels', [
            'part' => 'snippet',
            'mine' => 'true',
        ], ['Authorization' => 'Bearer ' . $token]);
        if (!$res['ok']) {
            return ['success' => false, 'message' => $this->apiErrorMessage($res, (string)__('YouTube 连通性检测失败。'))];
        }
        $title = (string)($res['json']['items'][0]['snippet']['title'] ?? 'OK');

        return ['success' => true, 'message' => (string)__('YouTube 连通性检测通过：%{1}', [$title])];
    }

    public function publish(array $draft, array $account, array $context = []): array
    {
        $payload = $this->normalizeDraftPayload($draft);
        // Phase-1: no binary video upload. Keep connectivity live but fail publish clearly.
        return [
            'success' => false,
            'status' => 'blocked_by_content_type',
            'message' => (string)__('YouTube 第一期仅支持授权与连通性检测；视频文件上传将在二期实现。当前草稿标题：%{1}', [$payload['title'] !== '' ? $payload['title'] : __('（无标题）')]),
            'remote_id' => '',
            'remote_url' => '',
        ];
    }
}

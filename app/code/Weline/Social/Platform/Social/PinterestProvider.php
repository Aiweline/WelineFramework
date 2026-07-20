<?php

declare(strict_types=1);

namespace Weline\Social\Platform\Social;

use Weline\Social\Platform\DocumentedSocialPlatformProvider;

class PinterestProvider extends DocumentedSocialPlatformProvider
{
    protected const DEFINITION = [
        'code' => 'pinterest',
        'title' => 'Pinterest',
        'family' => 'social',
        'sort_order' => 9,
        'region' => 'global',
        'brand_color' => '#e60023',
        'auth_modes' => ['oauth2'],
        'supports_one_click_auth' => true,
        'capabilities' => ['publish_image', 'publish_link'],
        'content_types' => ['image', 'link'],
        'required_config' => ['access_token', 'board_id'],
        'config_fields' => [
            ['key' => 'access_token', 'label' => 'Access Token', 'type' => 'password', 'required' => true],
            ['key' => 'board_id', 'label' => 'Board ID', 'type' => 'text', 'required' => true],
        ],
        'docs' => [
            'getting_started' => 'https://developers.pinterest.com/docs/getting-started/',
            'create_pin' => 'https://developers.pinterest.com/docs/api/v5/#operation/pins/create',
        ],
        'status' => 'live_publish_enabled',
    ];

    public function buildAuthorizationUrl(array $accountContext, string $redirectUri, string $state): ?string
    {
        $appId = $this->appConfig()->get('pinterest', 'app_id');
        if ($appId === '' || $redirectUri === '') {
            return null;
        }

        return 'https://www.pinterest.com/oauth/?' . \http_build_query([
            'client_id' => $appId,
            'redirect_uri' => $redirectUri,
            'response_type' => 'code',
            'scope' => 'boards:read,pins:read,pins:write',
            'state' => $state,
        ]);
    }

    public function handleAuthorizationCallback(array $callbackData, array $context = []): array
    {
        $code = \trim((string)($callbackData['code'] ?? ''));
        $redirectUri = \trim((string)($context['redirect_uri'] ?? ''));
        $app = \is_array($context['app_config'] ?? null) ? $context['app_config'] : $this->appConfig()->getPlatformApp('pinterest');
        $appId = \trim((string)($app['app_id'] ?? ''));
        $appSecret = \trim((string)($app['app_secret'] ?? ''));
        if ($code === '' || $appId === '' || $appSecret === '') {
            return ['success' => false, 'message' => (string)__('Pinterest 授权回调缺少 code 或应用凭据。'), 'credentials' => []];
        }

        $tokenRes = $this->http()->postForm('https://api.pinterest.com/v5/oauth/token', [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $redirectUri,
        ], [
            'Authorization' => 'Basic ' . \base64_encode($appId . ':' . $appSecret),
        ]);
        $accessToken = \trim((string)($tokenRes['json']['access_token'] ?? ''));
        if (!$tokenRes['ok'] || $accessToken === '') {
            return ['success' => false, 'message' => $this->apiErrorMessage($tokenRes, (string)__('交换 Pinterest access token 失败。')), 'credentials' => []];
        }

        $boardsRes = $this->http()->get('https://api.pinterest.com/v5/boards', [
            'page_size' => 1,
        ], ['Authorization' => 'Bearer ' . $accessToken]);
        $board = \is_array($boardsRes['json']['items'][0] ?? null) ? $boardsRes['json']['items'][0] : [];
        $boardId = (string)($board['id'] ?? '');

        return [
            'success' => true,
            'message' => (string)__('Pinterest 授权完成。'),
            'account_name' => (string)($board['name'] ?? 'Pinterest'),
            'remote_account_id' => $boardId,
            'remote_account_name' => (string)($board['name'] ?? ''),
            'credentials' => [
                'access_token' => $accessToken,
                'board_id' => $boardId,
                'refresh_token' => (string)($tokenRes['json']['refresh_token'] ?? ''),
            ],
        ];
    }

    public function testConfig(array $config, array $context = []): array
    {
        $token = \trim((string)($config['access_token'] ?? ''));
        if ($token === '') {
            return ['success' => false, 'message' => (string)__('缺少 Pinterest Access Token。')];
        }
        $res = $this->http()->get('https://api.pinterest.com/v5/user_account', [], [
            'Authorization' => 'Bearer ' . $token,
        ]);
        if (!$res['ok']) {
            return ['success' => false, 'message' => $this->apiErrorMessage($res, (string)__('Pinterest 连通性检测失败。'))];
        }

        return ['success' => true, 'message' => (string)__('Pinterest 连通性检测通过：%{1}', [(string)($res['json']['username'] ?? 'OK')])];
    }

    public function publish(array $draft, array $account, array $context = []): array
    {
        $credentials = $this->accountCredentials($account);
        $token = \trim((string)($credentials['access_token'] ?? ''));
        $boardId = \trim((string)($credentials['board_id'] ?? ''));
        $payload = $this->normalizeDraftPayload($draft);
        if ($token === '' || $boardId === '') {
            return ['success' => false, 'status' => 'failed', 'message' => (string)__('缺少 Pinterest Access Token 或 Board ID。'), 'remote_id' => '', 'remote_url' => ''];
        }
        if ($payload['image_url'] === '') {
            return [
                'success' => false,
                'status' => 'blocked_by_content_type',
                'message' => (string)__('Pinterest 发布需要图片 URL。'),
                'remote_id' => '',
                'remote_url' => '',
            ];
        }

        $body = [
            'board_id' => $boardId,
            'title' => \mb_substr($payload['title'] !== '' ? $payload['title'] : $payload['message'], 0, 100),
            'description' => \mb_substr($payload['message'], 0, 800),
            'media_source' => [
                'source_type' => 'image_url',
                'url' => $payload['image_url'],
            ],
        ];
        if ($payload['link'] !== '') {
            $body['link'] = $payload['link'];
        }
        $res = $this->http()->postJson('https://api.pinterest.com/v5/pins', $body, [
            'Authorization' => 'Bearer ' . $token,
        ]);
        $remoteId = \trim((string)($res['json']['id'] ?? ''));
        if (!$res['ok'] || $remoteId === '') {
            return ['success' => false, 'status' => 'failed', 'message' => $this->apiErrorMessage($res, (string)__('Pinterest 发布失败。')), 'remote_id' => '', 'remote_url' => ''];
        }

        return [
            'success' => true,
            'status' => 'published',
            'message' => (string)__('Pinterest Pin 已创建。'),
            'remote_id' => $remoteId,
            'remote_url' => (string)($res['json']['link'] ?? ''),
        ];
    }
}

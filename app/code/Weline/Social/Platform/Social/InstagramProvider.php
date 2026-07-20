<?php

declare(strict_types=1);

namespace Weline\Social\Platform\Social;

use Weline\Social\Platform\DocumentedSocialPlatformProvider;

class InstagramProvider extends DocumentedSocialPlatformProvider
{
    protected const DEFINITION = [
        'code' => 'instagram',
        'title' => 'Instagram',
        'family' => 'social',
        'sort_order' => 3,
        'region' => 'global',
        'brand_color' => '#e4405f',
        'auth_modes' => ['oauth2'],
        'supports_one_click_auth' => true,
        'capabilities' => ['publish_image', 'publish_text'],
        'content_types' => ['image', 'text'],
        'required_config' => ['access_token', 'ig_user_id'],
        'config_fields' => [
            ['key' => 'access_token', 'label' => 'Access Token', 'type' => 'password', 'required' => true],
            ['key' => 'ig_user_id', 'label' => 'Instagram Business User ID', 'type' => 'text', 'required' => true],
        ],
        'docs' => [
            'graph_api' => 'https://developers.facebook.com/docs/instagram-api/',
            'content_publishing' => 'https://developers.facebook.com/docs/instagram-api/guides/content-publishing/',
        ],
        'status' => 'live_publish_enabled',
    ];

    public function buildAuthorizationUrl(array $accountContext, string $redirectUri, string $state): ?string
    {
        $app = $this->appConfig()->getPlatformApp('instagram');
        $appId = \trim((string)($app['app_id'] ?? ''));
        if ($appId === '' || $redirectUri === '') {
            return null;
        }
        $version = \trim((string)($app['graph_version'] ?? 'v21.0'));

        return 'https://www.facebook.com/' . \rawurlencode($version) . '/dialog/oauth?' . \http_build_query([
            'client_id' => $appId,
            'redirect_uri' => $redirectUri,
            'state' => $state,
            'response_type' => 'code',
            'scope' => 'instagram_basic,instagram_content_publish,pages_show_list,pages_read_engagement',
        ]);
    }

    public function handleAuthorizationCallback(array $callbackData, array $context = []): array
    {
        $code = \trim((string)($callbackData['code'] ?? ''));
        $redirectUri = \trim((string)($context['redirect_uri'] ?? ''));
        $app = \is_array($context['app_config'] ?? null) ? $context['app_config'] : $this->appConfig()->getPlatformApp('instagram');
        $appId = \trim((string)($app['app_id'] ?? ''));
        $appSecret = \trim((string)($app['app_secret'] ?? ''));
        $version = \trim((string)($app['graph_version'] ?? 'v21.0'));
        if ($code === '' || $appId === '' || $appSecret === '') {
            return ['success' => false, 'message' => (string)__('Instagram 授权回调缺少 code 或应用凭据。'), 'credentials' => []];
        }

        $tokenRes = $this->http()->get('https://graph.facebook.com/' . $version . '/oauth/access_token', [
            'client_id' => $appId,
            'client_secret' => $appSecret,
            'redirect_uri' => $redirectUri,
            'code' => $code,
        ]);
        $userToken = \trim((string)($tokenRes['json']['access_token'] ?? ''));
        if (!$tokenRes['ok'] || $userToken === '') {
            return ['success' => false, 'message' => $this->apiErrorMessage($tokenRes, (string)__('交换 Instagram access token 失败。')), 'credentials' => []];
        }

        $pagesRes = $this->http()->get('https://graph.facebook.com/' . $version . '/me/accounts', [
            'access_token' => $userToken,
            'fields' => 'id,name,access_token,instagram_business_account{id,username}',
        ]);
        $igUserId = '';
        $username = '';
        $pageToken = $userToken;
        foreach (\is_array($pagesRes['json']['data'] ?? null) ? $pagesRes['json']['data'] : [] as $page) {
            if (!\is_array($page)) {
                continue;
            }
            $ig = \is_array($page['instagram_business_account'] ?? null) ? $page['instagram_business_account'] : [];
            if (!empty($ig['id'])) {
                $igUserId = (string)$ig['id'];
                $username = (string)($ig['username'] ?? $page['name'] ?? 'Instagram');
                $pageToken = (string)($page['access_token'] ?? $userToken);
                break;
            }
        }
        if ($igUserId === '') {
            return ['success' => false, 'message' => (string)__('未找到 Instagram 商业账户，请确认主页已绑定 IG 账号。'), 'credentials' => []];
        }

        return [
            'success' => true,
            'message' => (string)__('Instagram 授权完成。'),
            'account_name' => $username,
            'profile_url' => $username !== '' ? ('https://www.instagram.com/' . \rawurlencode($username) . '/') : '',
            'remote_account_id' => $igUserId,
            'remote_account_name' => $username,
            'credentials' => [
                'access_token' => $pageToken,
                'ig_user_id' => $igUserId,
            ],
        ];
    }

    public function testConfig(array $config, array $context = []): array
    {
        $token = \trim((string)($config['access_token'] ?? ''));
        $igUserId = \trim((string)($config['ig_user_id'] ?? ''));
        if ($token === '' || $igUserId === '') {
            return ['success' => false, 'message' => (string)__('缺少 Instagram Access Token 或 User ID。')];
        }
        $version = $this->appConfig()->get('instagram', 'graph_version', 'v21.0');
        $res = $this->http()->get('https://graph.facebook.com/' . $version . '/' . \rawurlencode($igUserId), [
            'fields' => 'id,username',
            'access_token' => $token,
        ]);
        if (!$res['ok']) {
            return ['success' => false, 'message' => $this->apiErrorMessage($res, (string)__('Instagram 连通性检测失败。'))];
        }

        return ['success' => true, 'message' => (string)__('Instagram 连通性检测通过：%{1}', [(string)($res['json']['username'] ?? $igUserId)])];
    }

    public function publish(array $draft, array $account, array $context = []): array
    {
        $credentials = $this->accountCredentials($account);
        $token = \trim((string)($credentials['access_token'] ?? ''));
        $igUserId = \trim((string)($credentials['ig_user_id'] ?? ''));
        $payload = $this->normalizeDraftPayload($draft);
        if ($token === '' || $igUserId === '') {
            return ['success' => false, 'status' => 'failed', 'message' => (string)__('缺少 Instagram 发布凭据。'), 'remote_id' => '', 'remote_url' => ''];
        }
        if ($payload['image_url'] === '') {
            return [
                'success' => false,
                'status' => 'blocked_by_content_type',
                'message' => (string)__('Instagram 发布需要图片 URL（第一期不支持纯文本帖）。'),
                'remote_id' => '',
                'remote_url' => '',
            ];
        }

        $version = $this->appConfig()->get('instagram', 'graph_version', 'v21.0');
        $containerRes = $this->http()->postForm('https://graph.facebook.com/' . $version . '/' . \rawurlencode($igUserId) . '/media', [
            'image_url' => $payload['image_url'],
            'caption' => $payload['message'],
            'access_token' => $token,
        ]);
        $creationId = \trim((string)($containerRes['json']['id'] ?? ''));
        if (!$containerRes['ok'] || $creationId === '') {
            return ['success' => false, 'status' => 'failed', 'message' => $this->apiErrorMessage($containerRes, (string)__('创建 Instagram 媒体容器失败。')), 'remote_id' => '', 'remote_url' => ''];
        }

        $publishRes = $this->http()->postForm('https://graph.facebook.com/' . $version . '/' . \rawurlencode($igUserId) . '/media_publish', [
            'creation_id' => $creationId,
            'access_token' => $token,
        ]);
        $remoteId = \trim((string)($publishRes['json']['id'] ?? ''));
        if (!$publishRes['ok'] || $remoteId === '') {
            return ['success' => false, 'status' => 'failed', 'message' => $this->apiErrorMessage($publishRes, (string)__('Instagram 发布失败。')), 'remote_id' => '', 'remote_url' => ''];
        }

        return [
            'success' => true,
            'status' => 'published',
            'message' => (string)__('Instagram 发布成功。'),
            'remote_id' => $remoteId,
            'remote_url' => 'https://www.instagram.com/p/' . \rawurlencode($remoteId) . '/',
        ];
    }
}

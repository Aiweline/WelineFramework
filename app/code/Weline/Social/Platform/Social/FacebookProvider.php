<?php

declare(strict_types=1);

namespace Weline\Social\Platform\Social;

use Weline\Social\Platform\DocumentedSocialPlatformProvider;

class FacebookProvider extends DocumentedSocialPlatformProvider
{
    protected const DEFINITION = [
        'code' => 'facebook',
        'title' => 'Facebook',
        'family' => 'social',
        'sort_order' => 1,
        'region' => 'global',
        'brand_color' => '#1877f2',
        'auth_modes' => ['oauth2'],
        'supports_one_click_auth' => true,
        'capabilities' => ['publish_text', 'publish_image', 'publish_link'],
        'content_types' => ['text', 'image', 'link'],
        'required_config' => ['access_token', 'page_id'],
        'config_fields' => [
            ['key' => 'access_token', 'label' => 'Page Access Token', 'type' => 'password', 'required' => true],
            ['key' => 'page_id', 'label' => 'Page ID', 'type' => 'text', 'required' => true],
        ],
        'docs' => [
            'graph_api' => 'https://developers.facebook.com/docs/graph-api/',
            'pages' => 'https://developers.facebook.com/docs/pages-api/',
        ],
        'status' => 'live_publish_enabled',
    ];

    public function buildAuthorizationUrl(array $accountContext, string $redirectUri, string $state): ?string
    {
        $app = $this->appConfig()->getPlatformApp('facebook');
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
            'scope' => 'pages_show_list,pages_read_engagement,pages_manage_posts',
        ]);
    }

    public function handleAuthorizationCallback(array $callbackData, array $context = []): array
    {
        $code = \trim((string)($callbackData['code'] ?? ''));
        $redirectUri = \trim((string)($context['redirect_uri'] ?? ''));
        $app = \is_array($context['app_config'] ?? null) ? $context['app_config'] : $this->appConfig()->getPlatformApp('facebook');
        $appId = \trim((string)($app['app_id'] ?? ''));
        $appSecret = \trim((string)($app['app_secret'] ?? ''));
        $version = \trim((string)($app['graph_version'] ?? 'v21.0'));
        if ($code === '' || $appId === '' || $appSecret === '') {
            return ['success' => false, 'message' => (string)__('Facebook 授权回调缺少 code 或应用凭据。'), 'credentials' => []];
        }

        $tokenRes = $this->http()->get('https://graph.facebook.com/' . $version . '/oauth/access_token', [
            'client_id' => $appId,
            'client_secret' => $appSecret,
            'redirect_uri' => $redirectUri,
            'code' => $code,
        ]);
        $userToken = \trim((string)($tokenRes['json']['access_token'] ?? ''));
        if (!$tokenRes['ok'] || $userToken === '') {
            return ['success' => false, 'message' => $this->apiErrorMessage($tokenRes, (string)__('交换 Facebook access token 失败。')), 'credentials' => []];
        }

        $pagesRes = $this->http()->get('https://graph.facebook.com/' . $version . '/me/accounts', [
            'access_token' => $userToken,
            'fields' => 'id,name,access_token,link',
        ]);
        $pages = \is_array($pagesRes['json']['data'] ?? null) ? $pagesRes['json']['data'] : [];
        $page = \is_array($pages[0] ?? null) ? $pages[0] : null;
        if ($page === null) {
            return ['success' => false, 'message' => (string)__('未找到可管理的 Facebook 主页，请确认授权权限。'), 'credentials' => []];
        }

        return [
            'success' => true,
            'message' => (string)__('Facebook 授权完成。'),
            'account_name' => (string)($page['name'] ?? 'Facebook Page'),
            'profile_url' => (string)($page['link'] ?? ('https://www.facebook.com/' . ($page['id'] ?? ''))),
            'remote_account_id' => (string)($page['id'] ?? ''),
            'remote_account_name' => (string)($page['name'] ?? ''),
            'credentials' => [
                'access_token' => (string)($page['access_token'] ?? $userToken),
                'page_id' => (string)($page['id'] ?? ''),
                'user_access_token' => $userToken,
            ],
            'scopes' => ['pages_manage_posts'],
        ];
    }

    public function testConfig(array $config, array $context = []): array
    {
        $token = \trim((string)($config['access_token'] ?? ''));
        $pageId = \trim((string)($config['page_id'] ?? ''));
        if ($token === '' || $pageId === '') {
            return ['success' => false, 'message' => (string)__('缺少 Page Access Token 或 Page ID。')];
        }
        $version = $this->appConfig()->get('facebook', 'graph_version', 'v21.0');
        $res = $this->http()->get('https://graph.facebook.com/' . $version . '/' . \rawurlencode($pageId), [
            'fields' => 'id,name,link',
            'access_token' => $token,
        ]);
        if (!$res['ok']) {
            return ['success' => false, 'message' => $this->apiErrorMessage($res, (string)__('Facebook 连通性检测失败。'))];
        }

        return [
            'success' => true,
            'message' => (string)__('Facebook 主页连通性检测通过：%{1}', [(string)($res['json']['name'] ?? $pageId)]),
            'details' => ['page_id' => (string)($res['json']['id'] ?? $pageId)],
        ];
    }

    public function publish(array $draft, array $account, array $context = []): array
    {
        $credentials = $this->accountCredentials($account);
        $token = \trim((string)($credentials['access_token'] ?? ''));
        $pageId = \trim((string)($credentials['page_id'] ?? ''));
        $payload = $this->normalizeDraftPayload($draft);
        if ($token === '' || $pageId === '') {
            return ['success' => false, 'status' => 'failed', 'message' => (string)__('缺少 Facebook 发布凭据。'), 'remote_id' => '', 'remote_url' => ''];
        }
        if ($payload['message'] === '' && $payload['image_url'] === '') {
            return ['success' => false, 'status' => 'failed', 'message' => (string)__('发布内容为空。'), 'remote_id' => '', 'remote_url' => ''];
        }

        $version = $this->appConfig()->get('facebook', 'graph_version', 'v21.0');
        if ($payload['image_url'] !== '') {
            $res = $this->http()->postForm('https://graph.facebook.com/' . $version . '/' . \rawurlencode($pageId) . '/photos', [
                'url' => $payload['image_url'],
                'caption' => $payload['message'],
                'access_token' => $token,
            ]);
        } else {
            $body = [
                'message' => $payload['message'],
                'access_token' => $token,
            ];
            if ($payload['link'] !== '') {
                $body['link'] = $payload['link'];
            }
            $res = $this->http()->postForm('https://graph.facebook.com/' . $version . '/' . \rawurlencode($pageId) . '/feed', $body);
        }

        $remoteId = \trim((string)($res['json']['id'] ?? $res['json']['post_id'] ?? ''));
        if (!$res['ok'] || $remoteId === '') {
            return [
                'success' => false,
                'status' => 'failed',
                'message' => $this->apiErrorMessage($res, (string)__('Facebook 发布失败。')),
                'remote_id' => '',
                'remote_url' => '',
                'provider_payload' => $this->redact($res['json']),
            ];
        }

        return [
            'success' => true,
            'status' => 'published',
            'message' => (string)__('Facebook 发布成功。'),
            'remote_id' => $remoteId,
            'remote_url' => 'https://www.facebook.com/' . \rawurlencode($remoteId),
        ];
    }
}

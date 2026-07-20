<?php

declare(strict_types=1);

namespace Weline\Social\Platform\Social;

use Weline\Social\Platform\DocumentedSocialPlatformProvider;

class LinkedinProvider extends DocumentedSocialPlatformProvider
{
    protected const DEFINITION = [
        'code' => 'linkedin',
        'title' => 'LinkedIn',
        'family' => 'social',
        'sort_order' => 8,
        'region' => 'global',
        'brand_color' => '#0a66c2',
        'auth_modes' => ['oauth2'],
        'supports_one_click_auth' => true,
        'capabilities' => ['publish_text', 'publish_link'],
        'content_types' => ['text', 'link'],
        'required_config' => ['access_token', 'author_urn'],
        'config_fields' => [
            ['key' => 'access_token', 'label' => 'Access Token', 'type' => 'password', 'required' => true],
            ['key' => 'author_urn', 'label' => 'Author URN (urn:li:person:xxx)', 'type' => 'text', 'required' => true],
        ],
        'docs' => [
            'share_on_linkedin' => 'https://learn.microsoft.com/en-us/linkedin/marketing/community-management/shares/posts-api',
            'oauth' => 'https://learn.microsoft.com/en-us/linkedin/shared/authentication/authentication',
        ],
        'status' => 'live_publish_enabled',
    ];

    public function buildAuthorizationUrl(array $accountContext, string $redirectUri, string $state): ?string
    {
        $clientId = $this->appConfig()->get('linkedin', 'client_id');
        if ($clientId === '' || $redirectUri === '') {
            return null;
        }

        return 'https://www.linkedin.com/oauth/v2/authorization?' . \http_build_query([
            'response_type' => 'code',
            'client_id' => $clientId,
            'redirect_uri' => $redirectUri,
            'state' => $state,
            'scope' => 'openid profile w_member_social',
        ]);
    }

    public function handleAuthorizationCallback(array $callbackData, array $context = []): array
    {
        $code = \trim((string)($callbackData['code'] ?? ''));
        $redirectUri = \trim((string)($context['redirect_uri'] ?? ''));
        $app = \is_array($context['app_config'] ?? null) ? $context['app_config'] : $this->appConfig()->getPlatformApp('linkedin');
        $clientId = \trim((string)($app['client_id'] ?? ''));
        $clientSecret = \trim((string)($app['client_secret'] ?? ''));
        if ($code === '' || $clientId === '' || $clientSecret === '') {
            return ['success' => false, 'message' => (string)__('LinkedIn 授权回调缺少 code 或应用凭据。'), 'credentials' => []];
        }

        $tokenRes = $this->http()->postForm('https://www.linkedin.com/oauth/v2/accessToken', [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $redirectUri,
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
        ]);
        $accessToken = \trim((string)($tokenRes['json']['access_token'] ?? ''));
        if (!$tokenRes['ok'] || $accessToken === '') {
            return ['success' => false, 'message' => $this->apiErrorMessage($tokenRes, (string)__('交换 LinkedIn access token 失败。')), 'credentials' => []];
        }

        $meRes = $this->http()->get('https://api.linkedin.com/v2/userinfo', [], [
            'Authorization' => 'Bearer ' . $accessToken,
        ]);
        $sub = (string)($meRes['json']['sub'] ?? '');
        $name = (string)($meRes['json']['name'] ?? 'LinkedIn');
        $authorUrn = $sub !== '' ? ('urn:li:person:' . $sub) : '';

        return [
            'success' => true,
            'message' => (string)__('LinkedIn 授权完成。'),
            'account_name' => $name,
            'profile_url' => '',
            'remote_account_id' => $sub,
            'remote_account_name' => $name,
            'credentials' => [
                'access_token' => $accessToken,
                'author_urn' => $authorUrn,
            ],
        ];
    }

    public function testConfig(array $config, array $context = []): array
    {
        $token = \trim((string)($config['access_token'] ?? ''));
        if ($token === '') {
            return ['success' => false, 'message' => (string)__('缺少 LinkedIn Access Token。')];
        }
        $res = $this->http()->get('https://api.linkedin.com/v2/userinfo', [], [
            'Authorization' => 'Bearer ' . $token,
        ]);
        if (!$res['ok']) {
            return ['success' => false, 'message' => $this->apiErrorMessage($res, (string)__('LinkedIn 连通性检测失败。'))];
        }

        return ['success' => true, 'message' => (string)__('LinkedIn 连通性检测通过：%{1}', [(string)($res['json']['name'] ?? 'OK')])];
    }

    public function publish(array $draft, array $account, array $context = []): array
    {
        $credentials = $this->accountCredentials($account);
        $token = \trim((string)($credentials['access_token'] ?? ''));
        $authorUrn = \trim((string)($credentials['author_urn'] ?? ''));
        $payload = $this->normalizeDraftPayload($draft);
        if ($token === '' || $authorUrn === '') {
            return ['success' => false, 'status' => 'failed', 'message' => (string)__('缺少 LinkedIn Access Token 或 Author URN。'), 'remote_id' => '', 'remote_url' => ''];
        }
        if ($payload['message'] === '') {
            return ['success' => false, 'status' => 'failed', 'message' => (string)__('发布内容为空。'), 'remote_id' => '', 'remote_url' => ''];
        }

        $body = [
            'author' => $authorUrn,
            'lifecycleState' => 'PUBLISHED',
            'specificContent' => [
                'com.linkedin.ugc.ShareContent' => [
                    'shareCommentary' => ['text' => $payload['message']],
                    'shareMediaCategory' => 'NONE',
                ],
            ],
            'visibility' => [
                'com.linkedin.ugc.MemberNetworkVisibility' => 'PUBLIC',
            ],
        ];
        $res = $this->http()->postJson('https://api.linkedin.com/v2/ugcPosts', $body, [
            'Authorization' => 'Bearer ' . $token,
            'X-Restli-Protocol-Version' => '2.0.0',
        ]);
        $remoteId = \trim((string)($res['json']['id'] ?? ''));
        if (!$res['ok'] || $remoteId === '') {
            return ['success' => false, 'status' => 'failed', 'message' => $this->apiErrorMessage($res, (string)__('LinkedIn 发布失败。')), 'remote_id' => '', 'remote_url' => ''];
        }

        return [
            'success' => true,
            'status' => 'published',
            'message' => (string)__('LinkedIn 发布成功。'),
            'remote_id' => $remoteId,
            'remote_url' => '',
        ];
    }
}

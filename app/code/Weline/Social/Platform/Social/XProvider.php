<?php

declare(strict_types=1);

namespace Weline\Social\Platform\Social;

use Weline\Social\Platform\DocumentedSocialPlatformProvider;

class XProvider extends DocumentedSocialPlatformProvider
{
    protected const DEFINITION = [
        'code' => 'x',
        'title' => 'X / Twitter',
        'family' => 'social',
        'sort_order' => 7,
        'region' => 'global',
        'brand_color' => '#000000',
        'auth_modes' => ['oauth2'],
        'supports_one_click_auth' => true,
        'capabilities' => ['publish_text', 'publish_link'],
        'content_types' => ['text', 'link'],
        'required_config' => ['access_token'],
        'config_fields' => [
            ['key' => 'access_token', 'label' => 'Access Token', 'type' => 'password', 'required' => true],
            ['key' => 'refresh_token', 'label' => 'Refresh Token', 'type' => 'password', 'required' => false],
        ],
        'docs' => [
            'twitter_api' => 'https://developer.x.com/en/docs/twitter-api',
            'manage_tweets' => 'https://developer.x.com/en/docs/twitter-api/tweets/manage-tweets/introduction',
        ],
        'status' => 'live_publish_enabled',
    ];

    public function buildAuthorizationUrl(array $accountContext, string $redirectUri, string $state): ?string
    {
        $clientId = $this->appConfig()->get('x', 'client_id');
        if ($clientId === '' || $redirectUri === '') {
            return null;
        }
        $codeVerifier = \trim((string)($accountContext['code_verifier'] ?? ''));
        if ($codeVerifier === '') {
            return null;
        }
        $challenge = \rtrim(\strtr(\base64_encode(\hash('sha256', $codeVerifier, true)), '+/', '-_'), '=');

        return 'https://twitter.com/i/oauth2/authorize?' . \http_build_query([
            'response_type' => 'code',
            'client_id' => $clientId,
            'redirect_uri' => $redirectUri,
            'scope' => 'tweet.read tweet.write users.read offline.access',
            'state' => $state,
            'code_challenge' => $challenge,
            'code_challenge_method' => 'S256',
        ]);
    }

    public function handleAuthorizationCallback(array $callbackData, array $context = []): array
    {
        $code = \trim((string)($callbackData['code'] ?? ''));
        $redirectUri = \trim((string)($context['redirect_uri'] ?? ''));
        $accountContext = \is_array($context['account_context'] ?? null) ? $context['account_context'] : [];
        $codeVerifier = \trim((string)($accountContext['code_verifier'] ?? $callbackData['code_verifier'] ?? ''));
        $app = \is_array($context['app_config'] ?? null) ? $context['app_config'] : $this->appConfig()->getPlatformApp('x');
        $clientId = \trim((string)($app['client_id'] ?? ''));
        $clientSecret = \trim((string)($app['client_secret'] ?? ''));
        if ($code === '' || $clientId === '' || $codeVerifier === '') {
            return ['success' => false, 'message' => (string)__('X 授权回调缺少 code / PKCE verifier 或应用凭据。'), 'credentials' => []];
        }

        $headers = ['Content-Type' => 'application/x-www-form-urlencoded'];
        if ($clientSecret !== '') {
            $headers['Authorization'] = 'Basic ' . \base64_encode($clientId . ':' . $clientSecret);
        }
        $tokenRes = $this->http()->request('POST', 'https://api.twitter.com/2/oauth2/token', $headers, [
            'code' => $code,
            'grant_type' => 'authorization_code',
            'client_id' => $clientId,
            'redirect_uri' => $redirectUri,
            'code_verifier' => $codeVerifier,
        ]);
        $accessToken = \trim((string)($tokenRes['json']['access_token'] ?? ''));
        if (!$tokenRes['ok'] || $accessToken === '') {
            return ['success' => false, 'message' => $this->apiErrorMessage($tokenRes, (string)__('交换 X access token 失败。')), 'credentials' => []];
        }

        $meRes = $this->http()->get('https://api.twitter.com/2/users/me', [], [
            'Authorization' => 'Bearer ' . $accessToken,
        ]);
        $username = (string)($meRes['json']['data']['username'] ?? 'X');
        $userId = (string)($meRes['json']['data']['id'] ?? '');

        return [
            'success' => true,
            'message' => (string)__('X 授权完成。'),
            'account_name' => '@' . $username,
            'profile_url' => 'https://x.com/' . \rawurlencode($username),
            'remote_account_id' => $userId,
            'remote_account_name' => $username,
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
            return ['success' => false, 'message' => (string)__('缺少 X Access Token。')];
        }
        $res = $this->http()->get('https://api.twitter.com/2/users/me', [], [
            'Authorization' => 'Bearer ' . $token,
        ]);
        if (!$res['ok']) {
            return ['success' => false, 'message' => $this->apiErrorMessage($res, (string)__('X 连通性检测失败。'))];
        }

        return ['success' => true, 'message' => (string)__('X 连通性检测通过：@%{1}', [(string)($res['json']['data']['username'] ?? 'user')])];
    }

    public function publish(array $draft, array $account, array $context = []): array
    {
        $credentials = $this->accountCredentials($account);
        $token = \trim((string)($credentials['access_token'] ?? ''));
        $payload = $this->normalizeDraftPayload($draft);
        if ($token === '') {
            return ['success' => false, 'status' => 'failed', 'message' => (string)__('缺少 X Access Token。'), 'remote_id' => '', 'remote_url' => ''];
        }
        if ($payload['message'] === '') {
            return ['success' => false, 'status' => 'failed', 'message' => (string)__('发布内容为空。'), 'remote_id' => '', 'remote_url' => ''];
        }

        $text = \mb_substr($payload['message'], 0, 280);
        $res = $this->http()->postJson('https://api.twitter.com/2/tweets', [
            'text' => $text,
        ], ['Authorization' => 'Bearer ' . $token]);
        $remoteId = \trim((string)($res['json']['data']['id'] ?? ''));
        if (!$res['ok'] || $remoteId === '') {
            return ['success' => false, 'status' => 'failed', 'message' => $this->apiErrorMessage($res, (string)__('X 发帖失败。')), 'remote_id' => '', 'remote_url' => ''];
        }

        return [
            'success' => true,
            'status' => 'published',
            'message' => (string)__('X 发帖成功。'),
            'remote_id' => $remoteId,
            'remote_url' => 'https://x.com/i/web/status/' . \rawurlencode($remoteId),
        ];
    }
}

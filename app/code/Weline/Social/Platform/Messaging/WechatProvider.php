<?php

declare(strict_types=1);

namespace Weline\Social\Platform\Messaging;

use Weline\Social\Platform\DocumentedSocialPlatformProvider;

class WechatProvider extends DocumentedSocialPlatformProvider
{
    protected const DEFINITION = [
        'code' => 'wechat',
        'title' => '微信公众号',
        'family' => 'messaging',
        'sort_order' => 6,
        'region' => 'cn',
        'brand_color' => '#07c160',
        'auth_modes' => ['api_key'],
        'supports_one_click_auth' => false,
        'capabilities' => ['publish_text', 'publish_image', 'publish_link'],
        'content_types' => ['text', 'image', 'link'],
        'required_config' => ['app_id', 'app_secret', 'openid'],
        'config_fields' => [
            ['key' => 'app_id', 'label' => 'AppID', 'type' => 'text', 'required' => true],
            ['key' => 'app_secret', 'label' => 'AppSecret', 'type' => 'password', 'required' => true],
            ['key' => 'openid', 'label' => '测试/默认 OpenID（客服消息）', 'type' => 'text', 'required' => true],
            ['key' => 'access_token', 'label' => 'Access Token（可选，留空自动获取）', 'type' => 'password', 'required' => false],
        ],
        'docs' => [
            'offiaccount' => 'https://developers.weixin.qq.com/doc/offiaccount/Getting_Started/Overview.html',
            'custom_message' => 'https://developers.weixin.qq.com/doc/offiaccount/Message_Management/Service_Center_messages.html',
        ],
        'status' => 'live_publish_enabled',
    ];

    public function testConfig(array $config, array $context = []): array
    {
        try {
            $token = $this->resolveAccessToken($config);
        } catch (\Throwable $throwable) {
            return ['success' => false, 'message' => $throwable->getMessage()];
        }
        $res = $this->http()->get('https://api.weixin.qq.com/cgi-bin/get_api_domain_ip', [
            'access_token' => $token,
        ]);
        if (!$res['ok'] || (int)($res['json']['errcode'] ?? 0) !== 0) {
            return ['success' => false, 'message' => $this->apiErrorMessage($res, (string)__('微信公众号连通性检测失败。'))];
        }

        return ['success' => true, 'message' => (string)__('微信公众号 access_token 检测通过。')];
    }

    public function publish(array $draft, array $account, array $context = []): array
    {
        $credentials = $this->accountCredentials($account);
        $openid = \trim((string)($credentials['openid'] ?? ''));
        $payload = $this->normalizeDraftPayload($draft);
        if ($openid === '') {
            return ['success' => false, 'status' => 'failed', 'message' => (string)__('缺少微信 OpenID。'), 'remote_id' => '', 'remote_url' => ''];
        }
        if ($payload['message'] === '') {
            return ['success' => false, 'status' => 'failed', 'message' => (string)__('发布内容为空。'), 'remote_id' => '', 'remote_url' => ''];
        }

        try {
            $token = $this->resolveAccessToken($credentials);
        } catch (\Throwable $throwable) {
            return ['success' => false, 'status' => 'failed', 'message' => $throwable->getMessage(), 'remote_id' => '', 'remote_url' => ''];
        }

        $res = $this->http()->postJson(
            'https://api.weixin.qq.com/cgi-bin/message/custom/send?access_token=' . \rawurlencode($token),
            [
                'touser' => $openid,
                'msgtype' => 'text',
                'text' => ['content' => $payload['message']],
            ]
        );
        if (!$res['ok'] || (int)($res['json']['errcode'] ?? 0) !== 0) {
            return ['success' => false, 'status' => 'failed', 'message' => $this->apiErrorMessage($res, (string)__('微信客服消息发送失败。')), 'remote_id' => '', 'remote_url' => ''];
        }

        return [
            'success' => true,
            'status' => 'published',
            'message' => (string)__('微信客服消息已发送。'),
            'remote_id' => 'wechat-custom-' . \substr(\sha1($openid . '|' . $payload['message']), 0, 16),
            'remote_url' => '',
        ];
    }

    /**
     * @param array<string, mixed> $config
     */
    private function resolveAccessToken(array $config): string
    {
        $token = \trim((string)($config['access_token'] ?? ''));
        if ($token !== '') {
            return $token;
        }
        $appId = \trim((string)($config['app_id'] ?? $this->appConfig()->get('wechat', 'app_id')));
        $appSecret = \trim((string)($config['app_secret'] ?? $this->appConfig()->get('wechat', 'app_secret')));
        if ($appId === '' || $appSecret === '') {
            throw new \InvalidArgumentException((string)__('缺少微信 AppID/AppSecret。'));
        }
        $res = $this->http()->get('https://api.weixin.qq.com/cgi-bin/token', [
            'grant_type' => 'client_credential',
            'appid' => $appId,
            'secret' => $appSecret,
        ]);
        $accessToken = \trim((string)($res['json']['access_token'] ?? ''));
        if (!$res['ok'] || $accessToken === '') {
            throw new \RuntimeException($this->apiErrorMessage($res, (string)__('获取微信 access_token 失败。')));
        }

        return $accessToken;
    }
}

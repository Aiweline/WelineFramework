<?php

declare(strict_types=1);

namespace Weline\Social\Platform\Messaging;

use Weline\Social\Platform\DocumentedSocialPlatformProvider;

class WhatsappProvider extends DocumentedSocialPlatformProvider
{
    protected const DEFINITION = [
        'code' => 'whatsapp',
        'title' => 'WhatsApp Business',
        'family' => 'messaging',
        'sort_order' => 5,
        'region' => 'global',
        'brand_color' => '#25d366',
        'auth_modes' => ['api_key'],
        'supports_one_click_auth' => false,
        'capabilities' => ['publish_text', 'publish_image', 'publish_link'],
        'content_types' => ['text', 'image', 'link'],
        'required_config' => ['phone_number_id', 'access_token', 'recipient'],
        'config_fields' => [
            ['key' => 'phone_number_id', 'label' => 'Phone Number ID', 'type' => 'text', 'required' => true],
            ['key' => 'access_token', 'label' => 'Cloud API Access Token', 'type' => 'password', 'required' => true],
            ['key' => 'recipient', 'label' => 'Default Recipient (E.164)', 'type' => 'text', 'required' => true],
            ['key' => 'waba_id', 'label' => 'WhatsApp Business Account ID', 'type' => 'text', 'required' => false],
        ],
        'docs' => [
            'cloud_api' => 'https://developers.facebook.com/docs/whatsapp/cloud-api/',
            'messages' => 'https://developers.facebook.com/docs/whatsapp/cloud-api/guides/send-messages/',
        ],
        'status' => 'live_publish_enabled',
    ];

    public function testConfig(array $config, array $context = []): array
    {
        $phoneNumberId = \trim((string)($config['phone_number_id'] ?? ''));
        $token = \trim((string)($config['access_token'] ?? ''));
        if ($phoneNumberId === '' || $token === '') {
            return ['success' => false, 'message' => (string)__('缺少 WhatsApp Phone Number ID 或 Access Token。')];
        }
        $version = $this->appConfig()->get('facebook', 'graph_version', 'v21.0');
        $res = $this->http()->get('https://graph.facebook.com/' . $version . '/' . \rawurlencode($phoneNumberId), [
            'access_token' => $token,
        ]);
        if (!$res['ok']) {
            return ['success' => false, 'message' => $this->apiErrorMessage($res, (string)__('WhatsApp 连通性检测失败。'))];
        }

        return ['success' => true, 'message' => (string)__('WhatsApp Cloud API 连通性检测通过。')];
    }

    public function publish(array $draft, array $account, array $context = []): array
    {
        $credentials = $this->accountCredentials($account);
        $phoneNumberId = \trim((string)($credentials['phone_number_id'] ?? ''));
        $token = \trim((string)($credentials['access_token'] ?? ''));
        $recipient = \trim((string)($credentials['recipient'] ?? ''));
        $payload = $this->normalizeDraftPayload($draft);
        if ($phoneNumberId === '' || $token === '' || $recipient === '') {
            return ['success' => false, 'status' => 'failed', 'message' => (string)__('缺少 WhatsApp 发布凭据或收件人。'), 'remote_id' => '', 'remote_url' => ''];
        }
        if ($payload['message'] === '') {
            return ['success' => false, 'status' => 'failed', 'message' => (string)__('发布内容为空。'), 'remote_id' => '', 'remote_url' => ''];
        }

        $version = $this->appConfig()->get('facebook', 'graph_version', 'v21.0');
        $body = [
            'messaging_product' => 'whatsapp',
            'to' => $recipient,
            'type' => 'text',
            'text' => ['body' => $payload['message']],
        ];
        $res = $this->http()->postJson(
            'https://graph.facebook.com/' . $version . '/' . \rawurlencode($phoneNumberId) . '/messages',
            $body,
            ['Authorization' => 'Bearer ' . $token]
        );
        $remoteId = \trim((string)($res['json']['messages'][0]['id'] ?? ''));
        if (!$res['ok'] || $remoteId === '') {
            return ['success' => false, 'status' => 'failed', 'message' => $this->apiErrorMessage($res, (string)__('WhatsApp 发送失败。')), 'remote_id' => '', 'remote_url' => ''];
        }

        return [
            'success' => true,
            'status' => 'published',
            'message' => (string)__('WhatsApp 消息已发送。'),
            'remote_id' => $remoteId,
            'remote_url' => '',
        ];
    }
}

<?php

declare(strict_types=1);

namespace Weline\Social\Platform\Fake;

use Weline\Social\Platform\AbstractSocialPlatformProvider;

class FakeBrowserProvider extends AbstractSocialPlatformProvider
{
    protected const DEFINITION = [
        'code' => 'fake_browser',
        'title' => 'Fake Browser',
        'family' => 'testing',
        'region' => 'local',
        'auth_modes' => ['fake'],
        'capabilities' => ['publish_text', 'publish_link', 'publish_image', 'browser_smoke'],
        'content_types' => ['text', 'link', 'image'],
        'required_config' => ['fake_token'],
        'config_fields' => [
            ['key' => 'fake_token', 'label' => 'Fake Token', 'type' => 'password', 'required' => true],
        ],
        'docs' => [
            'local' => 'Weline_Social fake provider is local-only and never calls an external social platform.',
        ],
        'status' => 'fake_enabled',
        'supports_fake_publish' => true,
    ];

    public function testConfig(array $config, array $context = []): array
    {
        $token = \trim((string)($config['fake_token'] ?? ''));
        if ($token === '') {
            return [
                'success' => false,
                'message' => (string)__('Fake Token 不能为空。'),
            ];
        }

        return [
            'success' => true,
            'message' => (string)__('Fake 模式凭据已通过本地检测。'),
            'details' => ['mode' => 'browser_smoke'],
        ];
    }

    public function publish(array $draft, array $account, array $context = []): array
    {
        $idempotencyKey = (string)($context['idempotency_key'] ?? \sha1(\json_encode([$draft, $account], JSON_UNESCAPED_UNICODE)));
        $remoteId = 'fake-' . \substr($idempotencyKey, 0, 16);

        return [
            'success' => true,
            'status' => 'published',
            'message' => (string)__('Fake 发布已完成，可用于浏览器冒烟验证。'),
            'remote_id' => $remoteId,
            'remote_url' => '/weline_social/frontend/social/smoke?remote_id=' . \rawurlencode($remoteId),
            'provider_payload' => [
                'title' => (string)($draft['title'] ?? ''),
                'content' => (string)($draft['content'] ?? ''),
                'variant' => $draft['variant'] ?? [],
            ],
        ];
    }
}

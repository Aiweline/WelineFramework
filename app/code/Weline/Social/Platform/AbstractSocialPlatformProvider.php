<?php

declare(strict_types=1);

namespace Weline\Social\Platform;

use Weline\Social\Interface\SocialPlatformConfigTesterInterface;
use Weline\Social\Interface\SocialPlatformProviderInterface;

abstract class AbstractSocialPlatformProvider implements SocialPlatformProviderInterface, SocialPlatformConfigTesterInterface
{
    /**
     * @var array<string, mixed>
     */
    protected const DEFINITION = [];

    public function getPlatformCode(): string
    {
        return (string)($this->getDefinition()['code'] ?? '');
    }

    public function getDefinition(): array
    {
        $definition = static::DEFINITION;
        $definition['code'] = (string)($definition['code'] ?? '');
        $definition['title'] = (string)($definition['title'] ?? $definition['code']);
        $definition['family'] = (string)($definition['family'] ?? 'social');
        $definition['auth_modes'] = \array_values((array)($definition['auth_modes'] ?? []));
        $definition['capabilities'] = \array_values((array)($definition['capabilities'] ?? []));
        $definition['content_types'] = \array_values((array)($definition['content_types'] ?? ['text']));
        $definition['config_fields'] = \array_values((array)($definition['config_fields'] ?? []));
        $definition['docs'] = (array)($definition['docs'] ?? []);
        $definition['icon'] = (string)($definition['icon'] ?? $definition['code']);
        $definition['status'] = (string)($definition['status'] ?? 'documented');
        $definition['supports_fake_publish'] = (bool)($definition['supports_fake_publish'] ?? false);

        return $definition;
    }

    public function buildAuthorizationUrl(array $accountContext, string $redirectUri, string $state): ?string
    {
        $definition = $this->getDefinition();
        if (!\in_array('oauth2', $definition['auth_modes'], true) && !\in_array('oauth1', $definition['auth_modes'], true)) {
            return null;
        }

        return null;
    }

    public function handleAuthorizationCallback(array $callbackData, array $context = []): array
    {
        return [
            'success' => false,
            'message' => (string)__('该平台尚未实现授权回调处理。'),
            'credentials' => [],
        ];
    }

    public function publish(array $draft, array $account, array $context = []): array
    {
        return [
            'success' => false,
            'status' => 'blocked_by_platform_adapter',
            'message' => (string)__('该平台已有官方文档记录，但当前 Provider 尚未实现实发 API。'),
            'remote_id' => '',
            'remote_url' => '',
        ];
    }

    public function queryPublishStatus(string $remoteId, array $account, array $context = []): array
    {
        return [
            'success' => $remoteId !== '',
            'status' => $remoteId !== '' ? 'submitted' : 'unknown',
            'remote_id' => $remoteId,
        ];
    }

    public function testConfig(array $config, array $context = []): array
    {
        $required = \array_values((array)($this->getDefinition()['required_config'] ?? []));
        $missing = [];
        foreach ($required as $key) {
            $key = (string)$key;
            if ($key === '') {
                continue;
            }
            if (!isset($config[$key]) || \trim((string)$config[$key]) === '') {
                $missing[] = $key;
            }
        }

        if ($missing !== []) {
            return [
                'success' => false,
                'message' => (string)__('缺少必填凭据：%{1}', [\implode(', ', $missing)]),
                'details' => ['missing' => $missing],
            ];
        }

        return [
            'success' => true,
            'message' => (string)__('凭据字段完整，尚未执行远端连通性检测。'),
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    protected function redact(array $payload): array
    {
        $sensitive = ['token', 'access_token', 'refresh_token', 'secret', 'client_secret', 'password', 'authorization', 'api_key'];
        foreach ($payload as $key => $value) {
            if (\in_array(\strtolower((string)$key), $sensitive, true)) {
                $payload[$key] = '***';
                continue;
            }
            if (\is_array($value)) {
                $payload[$key] = $this->redact($value);
            }
        }

        return $payload;
    }
}

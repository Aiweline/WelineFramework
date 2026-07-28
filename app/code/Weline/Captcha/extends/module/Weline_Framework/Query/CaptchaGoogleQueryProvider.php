<?php

declare(strict_types=1);

namespace Weline\Captcha\Extends\Module\Weline_Framework\Query;

use Weline\Captcha\Service\GoogleOAuthService;
use Weline\Framework\Service\Query\Provider\QueryProviderInterface;
use Weline\Framework\Session\SessionFactory;

final class CaptchaGoogleQueryProvider implements QueryProviderInterface
{
    public function __construct(
        private readonly GoogleOAuthService $oauth,
        private readonly SessionFactory $sessions,
    ) {
    }

    public function getProviderName(): string
    {
        return 'captcha_google';
    }

    public function execute(string $operation, array $params = []): mixed
    {
        $this->assertBackendSession();
        return match ($operation) {
            'listProjects' => [
                'success' => true,
                'projects' => $this->oauth->listProjects(),
            ],
            'bindProject' => $this->oauth->bindProject(
                (string)($params['project_id'] ?? ''),
                \trim((string)($params['scope'] ?? '')) ?: null,
            ),
            'testConnection' => $this->oauth->testConnection(),
            'revoke' => $this->revoke(),
            default => throw new \InvalidArgumentException(
                (string)__('Google Captcha 查询器不支持的操作：%{1}', [$operation])
            ),
        };
    }

    public function getDescriptor(): array
    {
        $acl = ['kind' => 'source', 'source_id' => 'Weline_Captcha::config'];
        return [
            'provider' => $this->getProviderName(),
            'name' => __('Google reCAPTCHA Enterprise 授权'),
            'description' => __('选择授权账户可访问的 Project，并为当前网站域名创建 Enterprise Key。'),
            'module' => 'Weline_Captcha',
            'operations' => [
                [
                    'name' => 'listProjects',
                    'frontend' => true,
                    'backend' => true,
                    'auth' => 'backend',
                    'backend_acl' => $acl,
                    'mode' => 'read',
                    'graph' => false,
                    'params' => [],
                    'returns' => ['type' => 'map'],
                ],
                [
                    'name' => 'bindProject',
                    'frontend' => true,
                    'backend' => true,
                    'auth' => 'backend',
                    'backend_acl' => $acl,
                    'mode' => 'write',
                    'graph' => false,
                    'params' => [
                        ['name' => 'project_id', 'type' => 'string', 'required' => true, 'max_length' => 64],
                        ['name' => 'scope', 'type' => 'string', 'required' => false, 'max_length' => 255],
                    ],
                    'returns' => ['type' => 'map'],
                ],
                [
                    'name' => 'testConnection',
                    'frontend' => true,
                    'backend' => true,
                    'auth' => 'backend',
                    'backend_acl' => $acl,
                    'mode' => 'read',
                    'graph' => false,
                    'params' => [],
                    'returns' => ['type' => 'map'],
                ],
                [
                    'name' => 'revoke',
                    'frontend' => true,
                    'backend' => true,
                    'auth' => 'backend',
                    'backend_acl' => $acl,
                    'mode' => 'write',
                    'graph' => false,
                    'params' => [],
                    'returns' => ['type' => 'map'],
                ],
            ],
        ];
    }

    private function assertBackendSession(): void
    {
        $session = $this->sessions->createBackendSession();
        $session->start();
        if (!$session->isLoggedIn() || (int)($session->getUserId() ?? 0) <= 0) {
            throw new \RuntimeException((string)__('请先登录后台'));
        }
    }

    private function revoke(): array
    {
        $this->oauth->revoke();
        return [
            'success' => true,
            'message' => (string)__('Google 授权已撤销'),
        ];
    }
}

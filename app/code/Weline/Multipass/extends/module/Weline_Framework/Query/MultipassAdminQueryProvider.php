<?php
declare(strict_types=1);

namespace Weline\Multipass\Extends\Module\Weline_Framework\Query;

use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Service\Query\Provider\QueryProviderInterface;
use Weline\Multipass\Service\IdentityClientService;

class MultipassAdminQueryProvider implements QueryProviderInterface
{
    public function getProviderName(): string
    {
        return 'multipass';
    }

    public function execute(string $operation, array $params = []): mixed
    {
        return match ($operation) {
            'adminRequest' => $this->adminRequest($params),
            'saveIdentityProvider' => $this->saveIdentityProvider($params),
            'deleteIdentityProvider' => $this->deleteIdentityProvider($params),
            default => throw new \InvalidArgumentException('Unsupported operation: ' . $operation),
        };
    }

    public function getDescriptor(): array
    {
        return [
            'provider' => 'multipass',
            'name' => 'Weline_Multipass admin bridge',
            'module' => 'Weline_Multipass',
            'operations' => [
                [
                    'name' => 'adminRequest',
                    'description' => 'Legacy controller bridge',
                    'frontend' => true,
                    'auth' => 'backend',
                    'backend' => true,
                    'backend_acl' => ['kind' => 'self'],
                    'mode' => 'write',
                    'params' => [
                        ['name' => 'url', 'type' => 'string', 'required' => true],
                        ['name' => 'method', 'type' => 'string', 'required' => false],
                        ['name' => 'headers', 'type' => 'array', 'required' => false],
                        ['name' => 'body', 'type' => 'string', 'required' => false],
                    ],
                ],
                [
                    'name' => 'saveIdentityProvider',
                    'description' => 'Save official login identity provider (modal)',
                    'frontend' => true,
                    'auth' => 'backend',
                    'backend' => true,
                    'backend_acl' => [
                        'kind' => 'source',
                        'source_id' => 'Weline_Multipass::identity_provider_save',
                    ],
                    'mode' => 'write',
                    'params' => [
                        ['name' => 'provider_id', 'type' => 'int', 'required' => false],
                        ['name' => 'name', 'type' => 'string', 'required' => true],
                        ['name' => 'issuer_base_url', 'type' => 'string', 'required' => true],
                        ['name' => 'rest_base_url', 'type' => 'string', 'required' => false],
                        ['name' => 'client_id', 'type' => 'string', 'required' => true],
                        ['name' => 'client_secret', 'type' => 'string', 'required' => false],
                        ['name' => 'redirect_uri', 'type' => 'string', 'required' => false],
                        ['name' => 'scopes', 'type' => 'array', 'required' => false],
                        ['name' => 'status', 'type' => 'string', 'required' => false],
                        ['name' => 'sort_order', 'type' => 'int', 'required' => false],
                    ],
                ],
                [
                    'name' => 'deleteIdentityProvider',
                    'description' => 'Delete official login identity provider',
                    'frontend' => true,
                    'auth' => 'backend',
                    'backend' => true,
                    'backend_acl' => [
                        'kind' => 'source',
                        'source_id' => 'Weline_Multipass::identity_provider_delete',
                    ],
                    'mode' => 'write',
                    'params' => [
                        ['name' => 'provider_id', 'type' => 'int', 'required' => true],
                    ],
                ],
            ],
        ];
    }

    /**
     * @param array<string, mixed> $params
     * @return array{success:bool,message:mixed,provider_id?:int}
     */
    private function saveIdentityProvider(array $params): array
    {
        try {
            /** @var IdentityClientService $service */
            $service = ObjectManager::getInstance(IdentityClientService::class);
            $provider = $service->saveProvider([
                'provider_id' => (int)($params['provider_id'] ?? 0),
                'name' => (string)($params['name'] ?? ''),
                'issuer_base_url' => (string)($params['issuer_base_url'] ?? ''),
                'rest_base_url' => (string)($params['rest_base_url'] ?? ''),
                'client_id' => (string)($params['client_id'] ?? ''),
                'client_secret' => (string)($params['client_secret'] ?? ''),
                'redirect_uri' => (string)($params['redirect_uri'] ?? ''),
                'scopes' => is_array($params['scopes'] ?? null) ? $params['scopes'] : [],
                'status' => (string)($params['status'] ?? 'active'),
                'sort_order' => (int)($params['sort_order'] ?? 100),
            ]);

            return [
                'success' => true,
                'message' => __('官网登录提供方已保存'),
                'provider_id' => (int)$provider->getId(),
            ];
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'message' => __('保存失败：%{1}', [$e->getMessage()]),
            ];
        }
    }

    /**
     * @param array<string, mixed> $params
     * @return array{success:bool,message:mixed}
     */
    private function deleteIdentityProvider(array $params): array
    {
        $providerId = (int)($params['provider_id'] ?? 0);
        /** @var IdentityClientService $service */
        $service = ObjectManager::getInstance(IdentityClientService::class);
        if ($service->deleteProvider($providerId)) {
            return ['success' => true, 'message' => __('官网登录提供方已删除')];
        }

        return ['success' => false, 'message' => __('官网登录提供方不存在')];
    }

    /** @param array<string,mixed> $params */
    private function adminRequest(array $params): mixed
    {
        $url = trim((string)($params['url'] ?? ''));
        $method = strtoupper(trim((string)($params['method'] ?? 'POST'))) ?: 'POST';
        $headers = is_array($params['headers'] ?? null) ? $params['headers'] : [];
        $body = array_key_exists('body', $params) && $params['body'] !== null ? (string)$params['body'] : '';
        if ($url === '') {
            return ['success' => false, 'message' => 'Missing URL'];
        }
        $parts = parse_url($url);
        $path = (string)($parts['path'] ?? '');
        $pathLower = strtolower($path);
        $markers = ['/multipass/'];
        $normalized = $path;
        foreach ($markers as $marker) {
            $pos = strpos($pathLower, $marker);
            if ($pos !== false) {
                $normalized = substr($path, $pos);
                break;
            }
        }
        $area = 'Backend';
        $controllerSeg = 'Index';
        $actionSeg = 'index';
        if (preg_match('#^/[a-z0-9_-]+/(backend|admin|frontend)/([a-z0-9_-]+)(?:/([a-z0-9_-]+))?$#i', $normalized, $mm)) {
            $area = ucfirst(strtolower($mm[1]));
            $controllerSeg = $mm[2];
            $actionSeg = $mm[3] ?? 'index';
        } elseif (preg_match('#^/[a-z0-9_-]+/([a-z0-9_-]+)(?:/([a-z0-9_-]+))?$#i', $normalized, $mm)) {
            $controllerSeg = $mm[1];
            $actionSeg = $mm[2] ?? 'index';
        } else {
            return ['success' => false, 'message' => 'Unsupported admin path: ' . $normalized];
        }
        $controllerSeg = str_replace(['-', '_'], '', ucwords(str_replace(['-', '_'], ' ', $controllerSeg)));
        $actionSeg = str_replace('-', '', $actionSeg);
        $ns = 'Weline\Multipass\Controller';
        $class = $ns . '\\' . $area . '\\' . $controllerSeg;
        if (!class_exists($class)) {
            $classAlt = $ns . '\\' . $controllerSeg;
            if (class_exists($classAlt)) {
                $class = $classAlt;
            } else {
                return ['success' => false, 'message' => 'Controller missing: ' . $class];
            }
        }
        $queryParams = [];
        if (!empty($parts['query'])) {
            parse_str((string)$parts['query'], $queryParams);
        }
        $bodyParams = [];
        if ($body !== '') {
            $ct = '';
            foreach ($headers as $name => $value) {
                if (strtolower((string)$name) === 'content-type') { $ct = strtolower((string)$value); break; }
            }
            if (str_contains($ct, 'application/json') || str_starts_with(ltrim($body), '{')) {
                $decoded = json_decode($body, true);
                $bodyParams = is_array($decoded) ? $decoded : [];
            } else {
                parse_str($body, $bodyParams);
                if (!is_array($bodyParams)) { $bodyParams = []; }
            }
        }
        $candidates = [$actionSeg, 'get' . ucfirst($actionSeg), 'post' . ucfirst($actionSeg)];
        if ($method === 'GET') {
            array_unshift($candidates, 'get' . ucfirst($actionSeg));
        } else {
            array_unshift($candidates, 'post' . ucfirst($actionSeg));
        }
        return \Weline\Framework\Service\Query\AdminControllerBridge::invoke(
            $class,
            $candidates,
            $queryParams,
            $bodyParams,
            $method,
            $body
        );
    }
}

<?php
declare(strict_types=1);

namespace Weline\Meta\Extends\Module\Weline_Framework\Query;

use Weline\Framework\Http\Cookie;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\RequestContext;
use Weline\Framework\Service\Query\Provider\QueryProviderInterface;
use Weline\Meta\Service\MetaConfigTypedScopeService;

class MetaAdminQueryProvider implements QueryProviderInterface
{
    public function getProviderName(): string
    {
        return 'meta';
    }

    public function execute(string $operation, array $params = []): mixed
    {
        return match ($operation) {
            'adminRequest' => $this->adminRequest($params),
            'resolvePublicCurrentScope' => $this->resolvePublicCurrentScope($params),
            default => throw new \InvalidArgumentException('Unsupported operation: ' . $operation),
        };
    }

    public function getDescriptor(): array
    {
        return [
            'provider' => 'meta',
            'name' => 'Weline_Meta admin bridge',
            'module' => 'Weline_Meta',
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
                    'name' => 'resolvePublicCurrentScope',
                    'description' => __('按可信请求 Scope 解析 public.* 命名空间配置。'),
                    'frontend' => true,
                    'auth' => 'any',
                    'mode' => 'read',
                    'graph' => true,
                    'cost' => 1,
                    'params' => [
                        ['name' => 'namespace', 'type' => 'string', 'required' => true, 'max_length' => 100],
                        ['name' => 'config_key', 'type' => 'string', 'required' => true, 'max_length' => 255],
                        ['name' => 'locale', 'type' => 'string', 'required' => false, 'max_length' => 20],
                    ],
                    'returns' => ['type' => 'array'],
                ],
            ],
        ];
    }

    /**
     * Frontend read boundary for explicitly public Meta configuration.
     *
     * The request's frozen ScopeIdentity is authoritative. Owner identity is
     * fixed to "0", so callers cannot enumerate entity-owned Meta records.
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function resolvePublicCurrentScope(array $params): array
    {
        $namespace = trim((string)($params['namespace'] ?? ''));
        $configKey = trim((string)($params['config_key'] ?? ''));
        if (!str_starts_with($namespace, 'public.')) {
            return [
                'success' => false,
                'error_code' => 'meta_public_namespace_required',
                'message' => (string)__('前台 Meta 读取仅允许 public.* 命名空间'),
            ];
        }
        if ($configKey === '') {
            throw new \InvalidArgumentException((string)__('Meta config_key 不能为空'));
        }
        $identity = RequestContext::scopeIdentity();
        if ($identity === null || $identity->isGlobal()) {
            return [
                'success' => false,
                'error_code' => 'meta_request_scope_unavailable',
                'message' => (string)__('当前请求没有可用的商城 Scope'),
            ];
        }
        $locale = trim((string)($params['locale'] ?? Cookie::getLangLocal() ?? ''));
        $locale = $locale !== '' ? $locale : null;

        /** @var MetaConfigTypedScopeService $resolver */
        $resolver = ObjectManager::getInstance(MetaConfigTypedScopeService::class);
        $result = $resolver->resolveTyped(
            namespace: $namespace,
            configKey: $configKey,
            identity: $identity,
            locale: $locale,
            identifyId: '0',
        );
        $payload = $result->toArray();

        return [
            'success' => true,
            'data' => $payload,
        ] + $payload;
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
        $markers = ['/meta/'];
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
        $ns = 'Weline\Meta\Controller';
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

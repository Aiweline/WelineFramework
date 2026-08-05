<?php
declare(strict_types=1);

namespace Weline\CacheManager\Extends\Module\Weline_Framework\Query;

use Weline\CacheManager\Service\CacheAdminService;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Service\Query\Provider\QueryProviderInterface;

class CacheManagerAdminQueryProvider implements QueryProviderInterface
{
    public function getProviderName(): string
    {
        return 'cache_manager';
    }

    public function execute(string $operation, array $params = []): mixed
    {
        return match ($operation) {
            'adminRequest' => $this->adminRequest($params),
            'clearPool' => $this->clearPool($params),
            'clearAll' => $this->clearAll($params),
            default => throw new \InvalidArgumentException('Unsupported operation: ' . $operation),
        };
    }

    public function getDescriptor(): array
    {
        return [
            'provider' => 'cache_manager',
            'name' => 'Weline_CacheManager admin bridge',
            'module' => 'Weline_CacheManager',
            'operations' => [
                [
                    'name' => 'adminRequest',
                    'description' => 'Legacy controller bridge for cache admin pages',
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
                    'name' => 'clearPool',
                    'description' => 'Clear one cache pool by identity',
                    'frontend' => true,
                    'auth' => 'backend',
                    'backend' => true,
                    'backend_acl' => [
                        'kind' => 'source',
                        'source_id' => 'Weline_CacheManager::system_cache_clear',
                    ],
                    'mode' => 'write',
                    'params' => [
                        ['name' => 'identity', 'type' => 'string', 'required' => true],
                        ['name' => 'force', 'type' => 'bool', 'required' => false],
                    ],
                ],
                [
                    'name' => 'clearAll',
                    'description' => 'Clear all non-permanent cache pools (force may include permanent)',
                    'frontend' => true,
                    'auth' => 'backend',
                    'backend' => true,
                    'backend_acl' => [
                        'kind' => 'source',
                        'source_id' => 'Weline_CacheManager::system_cache_clear_all',
                    ],
                    'mode' => 'write',
                    'params' => [
                        ['name' => 'force', 'type' => 'bool', 'required' => false],
                    ],
                ],
            ],
        ];
    }

    /** @param array<string,mixed> $params */
    private function clearPool(array $params): array
    {
        $identity = \trim((string)($params['identity'] ?? ''));
        if ($identity === '') {
            return [
                'success' => false,
                'code' => 403,
                'message' => (string)__('参数 identity 不能为空'),
                'data' => null,
            ];
        }
        $force = $this->truthy($params['force'] ?? false);
        try {
            /** @var CacheAdminService $service */
            $service = ObjectManager::getInstance(CacheAdminService::class);
            $result = $service->clearPool($identity, $force);
            $message = (string)__('缓存池 %{1} 已清理', $identity);
            if (!empty($result['shared_namespace_cleared'])) {
                $message .= ' ' . (string)__('WLS 共享命名空间已同步清理');
            }

            return [
                'success' => true,
                'code' => 200,
                'message' => $message,
                'msg' => $message,
                'data' => $result,
            ];
        } catch (\Throwable $throwable) {
            return [
                'success' => false,
                'code' => 500,
                'message' => $throwable->getMessage(),
                'msg' => $throwable->getMessage(),
                'data' => null,
            ];
        }
    }

    /** @param array<string,mixed> $params */
    private function clearAll(array $params): array
    {
        $force = $this->truthy($params['force'] ?? false);
        try {
            /** @var CacheAdminService $service */
            $service = ObjectManager::getInstance(CacheAdminService::class);
            $result = $service->clearAll($force);
            if ($force) {
                $message = (string)__('已强制清理所有缓存池（包括持久缓存）');
            } else {
                $message = (string)__('已清理所有非持久缓存池');
            }
            if (($result['extra_shared_namespaces_cleared_count'] ?? 0) > 0) {
                $message .= ' ' . (string)__(
                    '并额外清理 %{1} 个 WLS 共享缓存命名空间',
                    $result['extra_shared_namespaces_cleared_count']
                );
            }

            return [
                'success' => true,
                'code' => 200,
                'message' => $message,
                'msg' => $message,
                'data' => $result,
            ];
        } catch (\Throwable $throwable) {
            return [
                'success' => false,
                'code' => 500,
                'message' => $throwable->getMessage(),
                'msg' => $throwable->getMessage(),
                'data' => null,
            ];
        }
    }

    /** @param array<string,mixed> $params */
    private function adminRequest(array $params): mixed
    {
        $url = \trim((string)($params['url'] ?? ''));
        $method = \strtoupper(\trim((string)($params['method'] ?? 'POST'))) ?: 'POST';
        $headers = \is_array($params['headers'] ?? null) ? $params['headers'] : [];
        $body = \array_key_exists('body', $params) && $params['body'] !== null ? (string)$params['body'] : '';
        if ($url === '') {
            return ['success' => false, 'message' => 'Missing URL'];
        }
        $parts = \parse_url($url);
        $path = (string)($parts['path'] ?? '');
        $pathLower = \strtolower($path);
        // Backend routes are /{areaKey}/admin/system/cache[/action]. Prefer the
        // nested admin system path before generic module markers.
        $markers = [
            '/admin/system/cache-policy',
            '/admin/system/cache',
            '/cachemanager/',
            '/cache-manager/',
            '/cache_manager/',
            '/admin/',
        ];
        $normalized = $path;
        foreach ($markers as $marker) {
            $pos = \strpos($pathLower, $marker);
            if ($pos !== false) {
                $normalized = \substr($path, $pos);
                break;
            }
        }

        $resolved = $this->resolveControllerAction($normalized);
        if ($resolved === null) {
            return ['success' => false, 'message' => 'Unsupported admin path: ' . $normalized];
        }
        [$class, $actionSeg] = $resolved;
        if (!\class_exists($class)) {
            return ['success' => false, 'message' => 'Controller missing: ' . $class];
        }

        $queryParams = [];
        if (!empty($parts['query'])) {
            \parse_str((string)$parts['query'], $queryParams);
        }
        $bodyParams = [];
        if ($body !== '') {
            $ct = '';
            foreach ($headers as $name => $value) {
                if (\strtolower((string)$name) === 'content-type') {
                    $ct = \strtolower((string)$value);
                    break;
                }
            }
            if (\str_contains($ct, 'application/json') || \str_starts_with(\ltrim($body), '{')) {
                $decoded = \json_decode($body, true);
                $bodyParams = \is_array($decoded) ? $decoded : [];
            } else {
                \parse_str($body, $bodyParams);
                if (!\is_array($bodyParams)) {
                    $bodyParams = [];
                }
            }
        }
        $candidates = [$actionSeg, 'get' . \ucfirst($actionSeg), 'post' . \ucfirst($actionSeg)];
        if ($method === 'GET') {
            \array_unshift($candidates, 'get' . \ucfirst($actionSeg));
        } else {
            \array_unshift($candidates, 'post' . \ucfirst($actionSeg));
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

    /**
     * @return null|array{0:string,1:string}
     */
    private function resolveControllerAction(string $normalized): ?array
    {
        $ns = 'Weline\\CacheManager\\Controller';

        // /admin/system/cache[/action] and /admin/system/cache-policy[/action]
        if (\preg_match(
            '#^/admin/(system)/(cache(?:-policy)?)(?:/([a-z0-9_-]+))?$#i',
            $normalized,
            $mm
        )) {
            $folder = $this->studly((string)$mm[1]);
            $controller = $this->studly((string)$mm[2]);
            $action = isset($mm[3]) && $mm[3] !== '' ? \str_replace('-', '', (string)$mm[3]) : 'index';

            return [$ns . '\\' . $folder . '\\' . $controller, $action];
        }

        // /cachemanager/... legacy module-front paths
        if (\preg_match(
            '#^/[a-z0-9_-]+/(backend|admin|frontend)/([a-z0-9_/-]+)(?:/([a-z0-9_-]+))?$#i',
            $normalized,
            $mm
        )) {
            $segments = \array_values(\array_filter(\explode('/', (string)$mm[2])));
            $action = isset($mm[3]) && $mm[3] !== ''
                ? \str_replace('-', '', (string)$mm[3])
                : 'index';
            if ($segments === []) {
                return null;
            }
            if (\count($segments) === 1) {
                return [$ns . '\\' . $this->studly($segments[0]), $action];
            }
            $controller = $this->studly((string)\array_pop($segments));
            $folders = \array_map([$this, 'studly'], $segments);

            return [$ns . '\\' . \implode('\\', $folders) . '\\' . $controller, $action];
        }

        if (\preg_match('#^/[a-z0-9_-]+/([a-z0-9_/-]+)(?:/([a-z0-9_-]+))?$#i', $normalized, $mm)) {
            $segments = \array_values(\array_filter(\explode('/', (string)$mm[1])));
            $action = isset($mm[2]) && $mm[2] !== ''
                ? \str_replace('-', '', (string)$mm[2])
                : 'index';
            if ($segments === []) {
                return null;
            }
            if (\count($segments) === 1) {
                return [$ns . '\\' . $this->studly($segments[0]), $action];
            }
            $controller = $this->studly((string)\array_pop($segments));
            $folders = \array_map([$this, 'studly'], $segments);

            return [$ns . '\\' . \implode('\\', $folders) . '\\' . $controller, $action];
        }

        return null;
    }

    private function studly(string $value): string
    {
        return \str_replace(
            [' ', '-', '_'],
            '',
            \ucwords(\str_replace(['-', '_'], ' ', $value))
        );
    }

    private function truthy(mixed $value): bool
    {
        if (\is_bool($value)) {
            return $value;
        }
        if (\is_int($value) || \is_float($value)) {
            return (int)$value === 1;
        }

        return \in_array(\strtolower(\trim((string)$value)), ['1', 'true', 'yes', 'on'], true);
    }
}

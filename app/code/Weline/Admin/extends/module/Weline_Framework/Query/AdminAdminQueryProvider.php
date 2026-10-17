<?php
declare(strict_types=1);

namespace Weline\Admin\Extends\Module\Weline_Framework\Query;

use Weline\Admin\Service\BackendMenuSignalAggregator;
use Weline\Backend\Api\Auth\BackendUserContextProviderInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Service\Query\Provider\QueryProviderInterface;

class AdminAdminQueryProvider implements QueryProviderInterface
{
    public function getProviderName(): string
    {
        return 'admin';
    }

    public function execute(string $operation, array $params = []): mixed
    {
        return match ($operation) {
            'adminRequest' => $this->adminRequest($params),
            'menuSignals' => $this->menuSignals(),
            default => throw new \InvalidArgumentException('Unsupported operation: ' . $operation),
        };
    }

    public function getDescriptor(): array
    {
        return [
            'provider' => 'admin',
            'name' => 'Weline_Admin admin bridge',
            'module' => 'Weline_Admin',
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
                    'name' => 'menuSignals',
                    'description' => 'Backend sidebar menu unread signal counts (JS paint only; never SSR).',
                    // Exposed to frontend worker so backend pages can call via Weline.Api.resource('admin')
                    // (same pattern as adminRequest). Auth remains backend.
                    'frontend' => true,
                    'auth' => 'backend',
                    'backend' => true,
                    'backend_acl' => ['kind' => 'self'],
                    'mode' => 'read',
                    'params' => [],
                ],
            ],
        ];
    }

    /**
     * @return array{total:int, by_code: object|array<string, int>}
     */
    private function menuSignals(): array
    {
        /** @var BackendUserContextProviderInterface $users */
        $users = ObjectManager::getInstance(BackendUserContextProviderInterface::class);
        $actor = $users->current();
        $userId = $actor !== null ? (int)$actor->getId() : 0;
        if ($userId <= 0) {
            return [
                'total' => 0,
                'by_code' => (object)[],
            ];
        }

        /** @var BackendMenuSignalAggregator $aggregator */
        $aggregator = ObjectManager::getInstance(BackendMenuSignalAggregator::class);
        $signals = $aggregator->aggregate($userId);

        return [
            'total' => (int)($signals['total'] ?? 0),
            'by_code' => (object)($signals['by_code'] ?? []),
        ];
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
        $markers = ['/admin/'];
        $normalized = $path;
        foreach ($markers as $marker) {
            $pos = strpos($pathLower, $marker);
            if ($pos !== false) {
                $normalized = substr($path, $pos);
                break;
            }
        }
        $resolved = $this->resolveControllerAction($normalized);
        if ($resolved === null) {
            return ['success' => false, 'message' => 'Unsupported admin path: ' . $normalized];
        }
        [$class, $actionSeg] = $resolved;

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

    /**
     * Resolve nested Admin controllers such as System\\Menus (URL: /admin/system/menus/detail).
     *
     * Mirrors MetaAdminQueryProvider nested segment resolution so multi-level
     * backend paths are not rejected as "Unsupported admin path".
     *
     * @return null|array{0:string,1:string}
     */
    private function resolveControllerAction(string $normalized): ?array
    {
        $ns = 'Weline\\Admin\\Controller';
        $area = null;
        $rest = '';
        if (preg_match('#^/[a-z0-9_-]+/(backend|admin|frontend)/(.+)$#i', $normalized, $mm)) {
            $areaName = strtolower((string)$mm[1]);
            $area = $areaName === 'admin' ? 'Backend' : ucfirst($areaName);
            $rest = (string)$mm[2];
        } elseif (preg_match('#^/[a-z0-9_-]+/(.+)$#i', $normalized, $mm)) {
            $rest = (string)$mm[1];
        } else {
            return null;
        }

        $segments = array_values(array_filter(
            explode('/', $rest),
            static fn(string $segment): bool => $segment !== ''
        ));
        if ($segments === []) {
            return null;
        }

        // Prefer last segment as action when the preceding path maps to an existing controller.
        if (count($segments) >= 2) {
            $actionCandidate = str_replace('-', '', (string)$segments[count($segments) - 1]);
            $controllerSegments = array_slice($segments, 0, -1);
            $class = $this->buildControllerClass($ns, $area, $controllerSegments);
            if ($class !== null && class_exists($class)) {
                return [$class, $actionCandidate !== '' ? $actionCandidate : 'index'];
            }
            if ($area !== null) {
                $classAlt = $this->buildControllerClass($ns, null, $controllerSegments);
                if ($classAlt !== null && class_exists($classAlt)) {
                    return [$classAlt, $actionCandidate !== '' ? $actionCandidate : 'index'];
                }
            }
            // Legacy 2–3 segment bridge: /admin/{controller}/{action} → Controller\\Backend\\{Controller}
            if ($area === null && count($segments) <= 2) {
                $legacyClass = $this->buildControllerClass($ns, 'Backend', $controllerSegments);
                if ($legacyClass !== null && class_exists($legacyClass)) {
                    return [$legacyClass, $actionCandidate !== '' ? $actionCandidate : 'index'];
                }
            }
        }

        $class = $this->buildControllerClass($ns, $area, $segments);
        if ($class !== null && class_exists($class)) {
            return [$class, 'index'];
        }
        if ($area !== null) {
            $classAlt = $this->buildControllerClass($ns, null, $segments);
            if ($classAlt !== null && class_exists($classAlt)) {
                return [$classAlt, 'index'];
            }
        }
        if ($area === null) {
            $legacyClass = $this->buildControllerClass($ns, 'Backend', $segments);
            if ($legacyClass !== null && class_exists($legacyClass)) {
                return [$legacyClass, 'index'];
            }
        }

        return null;
    }

    /**
     * @param list<string> $segments
     */
    private function buildControllerClass(string $ns, ?string $area, array $segments): ?string
    {
        if ($segments === []) {
            return null;
        }
        $parts = [];
        foreach ($segments as $segment) {
            $parts[] = $this->studly((string)$segment);
        }
        $suffix = implode('\\', $parts);

        return $area !== null && $area !== ''
            ? $ns . '\\' . $area . '\\' . $suffix
            : $ns . '\\' . $suffix;
    }

    private function studly(string $value): string
    {
        return str_replace(
            [' ', '-', '_'],
            '',
            ucwords(str_replace(['-', '_'], ' ', $value))
        );
    }
}

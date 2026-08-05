<?php
declare(strict_types=1);

namespace Weline\Admin\Extends\Module\Weline_Framework\Query;

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
            default => throw new \InvalidArgumentException('Unsupported operation: ' . $operation),
        };
    }

    public function getDescriptor(): array
    {
        return [
            'provider' => 'admin',
            'name' => 'Weline_Admin admin bridge',
            'module' => 'Weline_Admin',
            'operations' => [[
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
            ]],
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
        $ns = 'Weline\Admin\Controller';
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

<?php

declare(strict_types=1);

namespace Weline\DeveloperWorkspace\Api\Rest\V1;

use Weline\DeveloperWorkspace\Api\DevToolRestController;
use Weline\DeveloperWorkspace\Service\DevToolPayloadStore;
use Weline\Framework\Cache\RuntimeCachePolicy;
use Weline\DeveloperWorkspace\Service\PanelAccessService;
use Weline\Framework\App\Env;
use Weline\Framework\Manager\ObjectManager;

class Routes extends DevToolRestController
{
    private const ROUTES_TTL_SECONDS = 300;

    private ?DevToolPayloadStore $payloadStore = null;

    public function getIndex()
    {
        try {
            if (!$this->isAllowed()) {
                return $this->error('dev tool routes is not allowed', [], 403);
            }
            $type = (string)$this->request->getGet('type', 'frontend');

            return $this->success('success', $this->getGroupedRoutes($type));
        } catch (\Throwable $e) {
            return $this->error($e->getMessage(), [], 500);
        }
    }

    public function getSearch()
    {
        try {
            if (!$this->isAllowed()) {
                return $this->error('dev tool routes is not allowed', [], 403);
            }
            $keyword = \trim((string)$this->request->getGet('keyword', ''));
            $type = (string)$this->request->getGet('type', 'frontend');
            if ($keyword === '') {
                return $this->error('搜索关键词不能为空', [], 400);
            }

            $results = [];
            foreach ($this->getGroupedRoutes($type) as $moduleGroup) {
                $module = (string)($moduleGroup['name'] ?? '');
                foreach ((array)($moduleGroup['routes'] ?? []) as $route) {
                    $path = (string)($route['path'] ?? '');
                    if (\stripos($module, $keyword) === false && \stripos($path, $keyword) === false) {
                        continue;
                    }
                    $results[] = [
                        'module' => $module,
                        'path' => $path,
                        'url' => (string)($route['url'] ?? '/' . $path),
                        'controller' => (string)($route['controller'] ?? ''),
                        'method' => (string)($route['method'] ?? 'index'),
                    ];
                }
            }

            return $this->success('success', $results);
        } catch (\Throwable $e) {
            return $this->error($e->getMessage(), [], 500);
        }
    }

    /**
     * @return list<array{name: string, routes: list<array<string, string>>}>
     */
    private function getGroupedRoutes(string $type): array
    {
        $type = $type === 'backend' ? 'backend' : 'frontend';
        $routerFile = $type === 'backend'
            ? Env::path_BACKEND_PC_ROUTER_FILE
            : Env::path_FRONTEND_PC_ROUTER_FILE;

        if (!\is_file($routerFile)) {
            throw new \RuntimeException('router file not found: ' . $routerFile);
        }

        $key = 'routes:' . $type . ':' . (string)@\filemtime($routerFile) . ':' . (string)@\filesize($routerFile);

        return (array)$this->payloadStore()->remember('routes', $key, $this->routesTtl(), function () use ($routerFile): array {
            $routers = include $routerFile;
            if (!\is_array($routers)) {
                return [];
            }

            $modulesRouters = [];
            foreach ($routers as $path => $router) {
                if (!\is_array($router)) {
                    continue;
                }
                $module = (string)($router['module'] ?? '');
                if ($module === '') {
                    continue;
                }
                $path = (string)$path;
                if (!\str_contains($path, '::GET') && \str_contains($path, '::')) {
                    continue;
                }

                $cleanPath = \str_replace('::GET', '', $path);
                if (!isset($modulesRouters[$module])) {
                    $modulesRouters[$module] = [
                        'name' => $module,
                        'routes' => [],
                    ];
                }

                $modulesRouters[$module]['routes'][] = [
                    'path' => $cleanPath,
                    'url' => '/' . $cleanPath,
                    'controller' => (string)($router['class']['name'] ?? ''),
                    'method' => (string)($router['class']['method'] ?? 'index'),
                ];
            }
            \ksort($modulesRouters);

            return \array_values($modulesRouters);
        });
    }

    private function payloadStore(): DevToolPayloadStore
    {
        if ($this->payloadStore === null) {
            $this->payloadStore = ObjectManager::getInstance(DevToolPayloadStore::class);
        }

        return $this->payloadStore;
    }

    private function routesTtl(): int
    {
        return ObjectManager::getInstance(RuntimeCachePolicy::class)->ttl('dev.routes_ttl', self::ROUTES_TTL_SECONDS);
    }

    private function isAllowed(): bool
    {
        return (new PanelAccessService())->canAccessApi($this->request);
    }
}

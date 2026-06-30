<?php
declare(strict_types=1);

namespace Weline\Server\Service\DynamicWarmup;

use Weline\Framework\App\Env;
use Weline\Framework\DataObject\DataObject;
use Weline\Framework\Event\Event;
use Weline\Framework\Event\EventsManager;
use Weline\Framework\Manager\ObjectManager;

final class HotPathDiscoveryService
{
    public const EVENT_NAME = 'Weline_Server::dispatcher::warmup_paths';

    /**
     * @param list<string> $seedPaths
     * @return list<string>
     */
    public function discover(?int $maxPaths = null, array $seedPaths = []): array
    {
        $maxPaths = $this->normalizeMaxPaths($maxPaths);
        $criticalPaths = $this->criticalPaths();
        $paths = \array_merge(
            $criticalPaths,
            $seedPaths !== [] ? $seedPaths : ['/'],
            $this->configuredPaths()
        );

        $mode = \strtolower(\trim((string)Env::get('wls.worker.dynamic_hot_path_discovery', 'auto')));
        if ($mode === '' || $mode === 'auto' || $mode === '1' || $mode === 'true') {
            $paths = $this->applyWarmupPathObservers($paths);
        }

        return $this->normalizeRankAndLimit($paths, $maxPaths, $criticalPaths);
    }

    /**
     * @param list<string> $paths
     * @param list<string> $priorityPaths
     * @return list<string>
     */
    public function normalizeRankAndLimit(array $paths, int $maxPaths = 32, array $priorityPaths = []): array
    {
        $maxPaths = $this->normalizeMaxPaths($maxPaths);
        $priority = [];
        foreach ($priorityPaths as $priorityIndex => $priorityPath) {
            $normalizedPriorityPath = $this->normalizeFrontendPagePath($priorityPath);
            if ($normalizedPriorityPath !== null && !isset($priority[$normalizedPriorityPath])) {
                $priority[$normalizedPriorityPath] = $priorityIndex;
            }
        }

        $ranked = [];
        $index = 0;
        foreach ($paths as $path) {
            $normalized = $this->normalizeFrontendPagePath($path);
            if ($normalized === null) {
                $index++;
                continue;
            }

            if (!isset($ranked[$normalized])) {
                $ranked[$normalized] = [
                    'path' => $normalized,
                    'priority' => $priority[$normalized] ?? \PHP_INT_MAX,
                    'score' => $this->scorePath($normalized),
                    'index' => $index,
                ];
            }
            $index++;
        }

        \uasort(
            $ranked,
            static fn (array $a, array $b): int => ($a['priority'] <=> $b['priority'])
                ?: ($a['score'] <=> $b['score'])
                ?: ($a['index'] <=> $b['index'])
        );

        return \array_slice(
            \array_map(static fn (array $item): string => (string)$item['path'], \array_values($ranked)),
            0,
            $maxPaths
        );
    }

    public function normalizeFrontendPagePath(mixed $path): ?string
    {
        if (!\is_scalar($path)) {
            return null;
        }

        $path = \str_replace(["\r", "\n", "\t"], '', \trim((string)$path));
        if ($path === '' || \strlen($path) > 2048) {
            return null;
        }
        if (\preg_match('#^(?:POST|PUT|PATCH|DELETE|OPTIONS)\s+#i', $path)) {
            return null;
        }
        $path = \preg_replace('#^(?:GET|HEAD)\s+#i', '', $path) ?? $path;
        $path = \trim($path);
        if ($path === '') {
            return null;
        }

        if (\str_contains($path, '://')) {
            $parsed = $this->parseCandidateUrl($path);
            if ($parsed === null) {
                return null;
            }
            $parsedPath = $parsed['path'] ?? null;
            $parsedQuery = $parsed['query'] ?? null;
            $path = \is_string($parsedPath) && $parsedPath !== '' ? $parsedPath : '/';
            if (\is_string($parsedQuery) && $this->queryIsPreviewOrEditor($parsedQuery)) {
                return null;
            }
        } else {
            $queryPos = \strpos($path, '?');
            if ($queryPos !== false) {
                $query = \substr($path, $queryPos + 1);
                if ($this->queryIsPreviewOrEditor($query)) {
                    return null;
                }
                $path = \substr($path, 0, $queryPos);
            }
        }

        $path = \str_replace('\\', '/', $path);
        if ($path === '' || $path[0] !== '/') {
            $path = '/' . $path;
        }
        $path = \preg_replace('#/+#', '/', $path) ?? $path;
        if ($path !== '/') {
            $path = \rtrim($path, '/');
        }

        return $this->isExcludedPath($path) ? null : $path;
    }

    private function normalizeMaxPaths(?int $maxPaths): int
    {
        $maxPaths ??= (int)(Env::get('wls.worker.dynamic_hot_path_max', 32) ?: 32);
        return \max(1, \min($maxPaths, 128));
    }

    /**
     * @return list<string>
     */
    private function configuredPaths(): array
    {
        return $this->pathListFromConfig(Env::get('wls.worker.dynamic_hot_paths', []));
    }

    /**
     * @return list<string>
     */
    private function criticalPaths(): array
    {
        $configured = Env::get('wls.worker.dynamic_critical_paths', null);
        if ($configured !== null && (!\is_string($configured) || \trim($configured) !== '')) {
            return $this->pathListFromConfig($configured);
        }

        return [
            '/',
            '/catalog/category/clothing',
            '/en_US/catalog/category/clothing',
            '/USD/en_US/catalog/category/clothing',
            '/zh_Hans_CN/catalog/category/clothing',
            '/CNY/zh_Hans_CN/catalog/category/clothing',
            '/product/demo-category-81-sports',
            '/en_US/product/demo-category-81-sports',
            '/product/demo-category-45-clothing',
            '/en_US/product/demo-category-45-clothing',
        ];
    }

    /**
     * @return list<string>
     */
    private function pathListFromConfig(mixed $configured): array
    {
        if (\is_string($configured)) {
            $decoded = \json_decode($configured, true);
            $configured = \is_array($decoded)
                ? $decoded
                : (\preg_split('/[,\s]+/', $configured, -1, \PREG_SPLIT_NO_EMPTY) ?: []);
        }
        if (!\is_array($configured)) {
            return [];
        }

        $paths = [];
        foreach ($configured as $path) {
            if (\is_scalar($path)) {
                $paths[] = (string)$path;
            }
        }

        return $paths;
    }

    /**
     * @param list<string> $paths
     * @return list<string>
     */
    private function applyWarmupPathObservers(array $paths): array
    {
        try {
            $eventsManager = ObjectManager::getInstance(EventsManager::class);
            if (!$eventsManager instanceof EventsManager) {
                return $paths;
            }

            $data = new DataObject([
                'paths' => $paths,
                'instance_name' => (string)($_SERVER['WLS_INSTANCE'] ?? $_ENV['WLS_INSTANCE'] ?? \getenv('WLS_INSTANCE') ?: ''),
                'port' => (int)($_SERVER['WLS_PORT'] ?? $_ENV['WLS_PORT'] ?? \getenv('WLS_PORT') ?: 0),
                'hosts' => $this->resolveObserverHosts(),
                'source' => 'worker-dynamic-deferred-warmup',
            ]);

            $events = $eventsManager->scanEvents();
            $observers = \is_array($events[self::EVENT_NAME] ?? null) ? $events[self::EVENT_NAME] : [];
            if ($observers === []) {
                $eventsManager->dispatch(self::EVENT_NAME, $data);
            } else {
                $event = new Event([
                    'data' => &$data,
                    'observers' => $observers,
                ]);
                $event->setName(self::EVENT_NAME)->dispatch();
            }

            $eventPaths = $data->getData('paths');
            return \is_array($eventPaths) ? $eventPaths : $paths;
        } catch (\Throwable $e) {
            if (\function_exists('w_log_warning')) {
                \w_log_warning('[DynamicWarmup] hot path observer discovery failed: ' . $e->getMessage());
            }
            return $paths;
        }
    }

    /**
     * @return list<string>
     */
    private function resolveObserverHosts(): array
    {
        $hosts = Env::get('wls.worker.dynamic_hot_path_hosts', ['127.0.0.1']);
        if (\is_string($hosts)) {
            $decoded = \json_decode($hosts, true);
            $hosts = \is_array($decoded)
                ? $decoded
                : (\preg_split('/[,\s]+/', $hosts, -1, \PREG_SPLIT_NO_EMPTY) ?: []);
        }
        if (!\is_array($hosts)) {
            return ['127.0.0.1'];
        }

        $normalized = [];
        foreach ($hosts as $host) {
            if (!\is_scalar($host)) {
                continue;
            }
            $host = \trim((string)$host);
            if ($host !== '') {
                $normalized[$host] = $host;
            }
            if (\count($normalized) >= 3) {
                break;
            }
        }

        return \array_values($normalized) ?: ['127.0.0.1'];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function parseCandidateUrl(string $url): ?array
    {
        try {
            $parsed = \parse_url($url);
        } catch (\ValueError) {
            return null;
        }
        if (!\is_array($parsed)) {
            return null;
        }

        $authority = (string)($parsed['host'] ?? '');
        if ($authority !== '' && (\str_contains($authority, '[') || \str_contains($authority, ']'))) {
            if (!\preg_match('/^\[[0-9A-Fa-f:.]+\]$/', $authority)
                && !\filter_var(\trim($authority, '[]'), \FILTER_VALIDATE_IP, \FILTER_FLAG_IPV6)) {
                return null;
            }
        }

        return $parsed;
    }

    private function queryIsPreviewOrEditor(string $query): bool
    {
        $query = \strtolower($query);
        foreach (['preview', 'editor', 'pagebuilder', 'visual_editor', 'weline_editor', 'wls_internal'] as $needle) {
            if (\str_contains($query, $needle)) {
                return true;
            }
        }

        return false;
    }

    private function isExcludedPath(string $path): bool
    {
        $lower = \strtolower($path);
        if ($lower === '/favicon.ico' || $lower === '/robots.txt' || $lower === '/sitemap.xml') {
            return true;
        }
        if ($this->categoryPathExceedsMaxDepth($lower)) {
            return true;
        }
        foreach ([
            '/admin',
            '/backend',
            '/api',
            '/rest',
            '/graphql',
            '/_wls',
            '/sse',
            '/static',
            '/media',
            '/pub/static',
            '/pagebuilder/backend',
            '/editor',
            '/preview',
        ] as $prefix) {
            if ($lower === $prefix || \str_starts_with($lower, $prefix . '/')) {
                return true;
            }
        }

        return (bool)\preg_match('#\.(?:css|js|mjs|map|png|jpe?g|gif|webp|svg|ico|woff2?|ttf|eot|pdf|zip)$#i', $path);
    }

    private function categoryPathExceedsMaxDepth(string $lowerPath): bool
    {
        $maxDepth = (int)(Env::get('wls.worker.dynamic_hot_path_category_max_depth', 2) ?: 2);
        if ($maxDepth <= 0) {
            return false;
        }

        $needle = '/catalog/category/';
        $offset = \strpos($lowerPath, $needle);
        if ($offset === false) {
            return false;
        }

        $tail = \trim(\substr($lowerPath, $offset + \strlen($needle)), '/');
        if ($tail === '') {
            return false;
        }

        $segments = \array_values(\array_filter(\explode('/', $tail), static fn (string $segment): bool => $segment !== ''));
        return \count($segments) > $maxDepth;
    }

    private function scorePath(string $path): int
    {
        $lower = \strtolower($path);
        if ($lower === '/') {
            return 0;
        }
        if (\str_contains($lower, '/catalog/category/')) {
            return 10;
        }
        if (\str_contains($lower, '/product/')) {
            return 20;
        }
        if (\str_contains($lower, '/category/')) {
            return 30;
        }

        return 50;
    }
}

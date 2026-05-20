<?php

declare(strict_types=1);

namespace WeShop\Catalog\Observer;

use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Framework\Manager\ObjectManager;
use WeShop\Catalog\Service\CategoryService;

class DispatcherWarmupPaths implements ObserverInterface
{
    private const CURRENCY = 'CNY';
    private const LOCALES = ['en_US', 'zh_Hans_CN'];
    private const PREFERRED_CATEGORY_HANDLE = 'clothing';
    private const MAX_CATEGORY_HANDLES = 2;
    private const PUBLIC_ENTRY_PATHS = [
        '/',
        '/about',
        '/contact',
        '/search',
    ];

    public function execute(Event &$event): void
    {
        $paths = $this->normalizePaths($event->getData('paths'));
        foreach (self::PUBLIC_ENTRY_PATHS as $entryPath) {
            $paths[] = $entryPath;
            foreach (self::LOCALES as $locale) {
                $paths[] = '/' . self::CURRENCY . '/' . $locale . $entryPath;
            }
        }
        foreach ($this->resolveCategoryHandles() as $handle) {
            $paths[] = '/catalog/category/' . $this->encodeHandlePath($handle);
            foreach (self::LOCALES as $locale) {
                $paths[] = '/' . self::CURRENCY . '/' . $locale . '/catalog/category/' . $this->encodeHandlePath($handle);
            }
        }

        $event->setData('paths', $this->normalizePaths($paths));
    }

    /**
     * @return list<string>
     */
    private function resolveCategoryHandles(): array
    {
        try {
            /** @var CategoryService $categoryService */
            $categoryService = ObjectManager::getInstance(CategoryService::class);
            $tree = $categoryService->getCategoryTree(0);
        } catch (\Throwable) {
            return [];
        }

        $handles = [];
        $preferred = $this->findHandle($tree, self::PREFERRED_CATEGORY_HANDLE);
        if ($preferred !== '') {
            $handles[$preferred] = $preferred;
        }

        $this->collectHandles($tree, $handles);

        return \array_slice(\array_values($handles), 0, self::MAX_CATEGORY_HANDLES);
    }

    /**
     * @param array<int, mixed> $categories
     */
    private function findHandle(array $categories, string $targetHandle, string $prefix = ''): string
    {
        foreach ($categories as $category) {
            if (!\is_array($category)) {
                continue;
            }
            $handle = \trim((string)($category['handle'] ?? ''), '/');
            $fullHandle = $handle !== '' && $prefix !== '' ? $prefix . '/' . $handle : $handle;
            if ($handle === $targetHandle || $fullHandle === $targetHandle) {
                return $fullHandle;
            }

            $children = $category['children'] ?? [];
            if (\is_array($children)) {
                $found = $this->findHandle($children, $targetHandle, $fullHandle);
                if ($found !== '') {
                    return $found;
                }
            }
        }

        return '';
    }

    /**
     * @param array<int, mixed> $categories
     * @param array<string, string> $handles
     */
    private function collectHandles(array $categories, array &$handles, string $prefix = ''): void
    {
        foreach ($categories as $category) {
            if (!\is_array($category)) {
                continue;
            }
            if ((int)($category['is_active'] ?? 0) !== 1) {
                continue;
            }

            $handle = \trim((string)($category['handle'] ?? ''), '/');
            $fullHandle = $handle !== '' && $prefix !== '' ? $prefix . '/' . $handle : $handle;
            if ($fullHandle !== '') {
                $handles[$fullHandle] = $fullHandle;
            }

            if (\count($handles) >= self::MAX_CATEGORY_HANDLES) {
                return;
            }

            $children = $category['children'] ?? [];
            if (\is_array($children)) {
                $this->collectHandles($children, $handles, $fullHandle);
            }
        }
    }

    /**
     * @return list<string>
     */
    private function normalizePaths(mixed $paths): array
    {
        if (\is_string($paths)) {
            $decoded = \json_decode($paths, true);
            $paths = \is_array($decoded) ? $decoded : (\preg_split('/[,\s]+/', $paths) ?: []);
        }
        if (!\is_array($paths)) {
            return [];
        }

        $normalized = [];
        foreach ($paths as $path) {
            if (!\is_scalar($path)) {
                continue;
            }
            $path = \str_replace(["\r", "\n", "\t"], '', \trim((string)$path));
            if ($path === '') {
                continue;
            }
            if ($path[0] !== '/') {
                $path = '/' . $path;
            }
            $normalized[$path] = $path;
        }

        return \array_values($normalized);
    }

    private function encodeHandlePath(string $handlePath): string
    {
        $segments = [];
        foreach (\explode('/', \trim($handlePath, '/')) as $segment) {
            $segment = \trim($segment);
            if ($segment !== '') {
                $segments[] = \rawurlencode($segment);
            }
        }

        return \implode('/', $segments);
    }
}

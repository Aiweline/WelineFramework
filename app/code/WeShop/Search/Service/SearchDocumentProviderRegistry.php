<?php

declare(strict_types=1);

namespace WeShop\Search\Service;

use WeShop\Search\Api\SearchDocumentProviderInterface;
use Weline\Framework\Extends\ExtendsData;
use Weline\Framework\Manager\ObjectManager;

class SearchDocumentProviderRegistry
{
    /**
     * @var array<string, SearchDocumentProviderInterface>
     */
    private array $providers = [];

    private bool $loaded = false;

    private ?int $cachedExtendsMtime = null;

    /**
     * @return array<string, SearchDocumentProviderInterface>
     */
    public function getAllProviders(bool $forceReload = false): array
    {
        $this->load($forceReload);

        return $this->providers;
    }

    public function getProvider(string $providerCode, bool $forceReload = false): ?SearchDocumentProviderInterface
    {
        $this->load($forceReload);

        return $this->providers[strtolower(trim($providerCode))] ?? null;
    }

    /**
     * @return array<string, mixed>
     */
    public function getMergedIndexConfiguration(bool $forceReload = false): array
    {
        $configuration = [
            'searchable_fields' => [],
            'filterable_fields' => [],
            'sortable_fields' => [],
        ];

        foreach ($this->getAllProviders($forceReload) as $provider) {
            $definition = $provider->getIndexConfiguration();
            foreach (['searchable_fields', 'filterable_fields', 'sortable_fields'] as $key) {
                $items = $definition[$key] ?? [];
                if (!is_array($items)) {
                    continue;
                }

                foreach ($items as $item) {
                    $item = trim((string) $item);
                    if ($item === '' || in_array($item, $configuration[$key], true)) {
                        continue;
                    }

                    $configuration[$key][] = $item;
                }
            }
        }

        return $configuration;
    }

    private function load(bool $forceReload = false): void
    {
        $currentMtime = ExtendsData::getRegistryFileMtime();
        if (
            !$forceReload
            && $this->loaded
            && $this->cachedExtendsMtime !== null
            && $this->cachedExtendsMtime === $currentMtime
        ) {
            return;
        }

        $providers = [];
        $extendedBy = ExtendsData::getExtendedBy('WeShop_Search', $forceReload);

        foreach ($extendedBy as $extensions) {
            foreach ($extensions as $extension) {
                $relativePath = strtolower(str_replace('\\', '/', (string) ($extension['relative_path'] ?? '')));
                if (!str_starts_with($relativePath, 'extends/module/weshop_search/document/')) {
                    continue;
                }

                $className = $this->resolveClassName($extension);
                if ($className === null) {
                    continue;
                }

                $sourceFile = (string) ($extension['source_file'] ?? '');
                if (!class_exists($className, false) && $sourceFile !== '' && is_file($sourceFile)) {
                    require_once $sourceFile;
                }

                if (!class_exists($className)) {
                    continue;
                }

                try {
                    $instance = ObjectManager::getInstance($className);
                    if (!$instance instanceof SearchDocumentProviderInterface) {
                        continue;
                    }

                    $providerCode = strtolower(trim($instance->getProviderCode()));
                    if ($providerCode === '') {
                        continue;
                    }

                    $providers[$providerCode] = $instance;
                } catch (\Throwable $throwable) {
                    w_log_error('加载搜索文档提供者失败：' . $throwable->getMessage());
                }
            }
        }

        $this->providers = $providers;
        $this->loaded = true;
        $this->cachedExtendsMtime = $currentMtime;
    }

    private function resolveClassName(array $extension): ?string
    {
        $className = trim((string) ($extension['class_name'] ?? ''));
        if ($className !== '') {
            return $className;
        }

        $sourceFile = (string) ($extension['source_file'] ?? '');
        if ($sourceFile === '' || !is_file($sourceFile)) {
            return null;
        }

        $content = file_get_contents($sourceFile);
        if ($content === false) {
            return null;
        }

        $namespace = null;
        $class = null;

        if (preg_match('/namespace\s+([^;]+);/', $content, $matches)) {
            $namespace = trim($matches[1]);
        }

        if (preg_match('/class\s+(\w+)/', $content, $matches)) {
            $class = trim($matches[1]);
        }

        if ($namespace === null || $class === null) {
            return null;
        }

        return $namespace . '\\' . $class;
    }
}

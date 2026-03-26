<?php

declare(strict_types=1);

namespace WeShop\Search\Service;

use WeShop\Search\Api\SearchDocumentExtenderInterface;
use Weline\Framework\Extends\ExtendsData;
use Weline\Framework\Manager\ObjectManager;

class SearchDocumentExtenderRegistry
{
    /**
     * @var array<string, array<int, SearchDocumentExtenderInterface>>
     */
    private array $extenders = [];

    private bool $loaded = false;

    private ?int $cachedExtendsMtime = null;

    /**
     * @return array<string, array<int, SearchDocumentExtenderInterface>>
     */
    public function getAllExtenders(bool $forceReload = false): array
    {
        $this->load($forceReload);

        return $this->extenders;
    }

    /**
     * @return array<int, SearchDocumentExtenderInterface>
     */
    public function getExtendersForProvider(string $providerCode, bool $forceReload = false): array
    {
        $this->load($forceReload);

        return $this->extenders[strtolower(trim($providerCode))] ?? [];
    }

    /**
     * @return array<string, mixed>
     */
    public function getMergedIndexConfiguration(?string $providerCode = null, bool $forceReload = false): array
    {
        $configuration = [
            'searchable_fields' => [],
            'filterable_fields' => [],
            'sortable_fields' => [],
        ];

        $extenders = $providerCode === null
            ? array_merge(...array_values($this->getAllExtenders($forceReload) ?: [[]]))
            : $this->getExtendersForProvider($providerCode, $forceReload);

        foreach ($extenders as $extender) {
            $definition = $extender->getIndexConfiguration();
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

        $extenders = [];
        $extendedBy = ExtendsData::getExtendedBy('WeShop_Search', $forceReload);

        foreach ($extendedBy as $extensions) {
            foreach ($extensions as $extension) {
                $relativePath = strtolower(str_replace('\\', '/', (string) ($extension['relative_path'] ?? '')));
                if (!str_starts_with($relativePath, 'extends/module/weshop_search/documentextender/')) {
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
                    if (!$instance instanceof SearchDocumentExtenderInterface) {
                        continue;
                    }

                    $providerCode = strtolower(trim($instance->getTargetProviderCode()));
                    if ($providerCode === '') {
                        continue;
                    }

                    $extenders[$providerCode][] = $instance;
                } catch (\Throwable $throwable) {
                    w_log_error('鍔犺浇鎼滅储鏂囨。澧炲己鍣ㄥけ璐ワ細' . $throwable->getMessage());
                }
            }
        }

        $this->extenders = $extenders;
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

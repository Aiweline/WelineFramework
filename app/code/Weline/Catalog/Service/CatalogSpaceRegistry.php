<?php

declare(strict_types=1);

namespace Weline\Catalog\Service;

use Weline\Catalog\Api\CatalogSpaceProviderInterface;
use Weline\Framework\Extends\ExtendsData;
use Weline\Framework\Manager\ObjectManager;

class CatalogSpaceRegistry
{
    /** @var array<string, CatalogSpaceProviderInterface>|null */
    private ?array $providers = null;

    public function __construct(
        private readonly ObjectManager $objectManager,
    ) {
    }

    /**
     * @return array<string, CatalogSpaceProviderInterface>
     */
    public function all(bool $forceReload = false): array
    {
        if (!$forceReload && $this->providers !== null) {
            return $this->providers;
        }

        $map = [];
        try {
            foreach (ExtendsData::getExtendedBy('Weline_Catalog', $forceReload) as $extensions) {
                foreach ($extensions as $extension) {
                    if ($this->extensionName($extension) !== 'CatalogSpace') {
                        continue;
                    }
                    $class = $this->extensionClass($extension);
                    if ($class === '' || !class_exists($class)) {
                        continue;
                    }
                    $instance = $this->objectManager->getInstance($class);
                    if (!$instance instanceof CatalogSpaceProviderInterface) {
                        continue;
                    }
                    $code = trim($instance->code());
                    if ($code === '') {
                        continue;
                    }
                    $map[$code] = $instance;
                }
            }
        } catch (\Throwable) {
            $map = [];
        }

        uasort(
            $map,
            static fn (CatalogSpaceProviderInterface $a, CatalogSpaceProviderInterface $b): int => $a->sortOrder() <=> $b->sortOrder(),
        );
        $this->providers = $map;

        return $this->providers;
    }

    public function get(string $code): ?CatalogSpaceProviderInterface
    {
        $code = trim($code);
        if ($code === '') {
            return null;
        }

        return $this->all()[$code] ?? null;
    }

    /**
     * @return list<array{code:string,label:string,icon:string,sort_order:int}>
     */
    public function listSpaces(): array
    {
        $out = [];
        foreach ($this->all() as $provider) {
            $out[] = [
                'code' => $provider->code(),
                'label' => $provider->label(),
                'icon' => $provider->icon(),
                'sort_order' => $provider->sortOrder(),
            ];
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $extension
     */
    private function extensionName(array $extension): string
    {
        $extendName = trim((string)($extension['extend_name'] ?? ''));
        if ($extendName !== '') {
            return $extendName;
        }
        $filePath = str_replace('\\', '/', (string)($extension['file_path'] ?? ''));
        $segments = explode('/', $filePath);

        return trim((string)($segments[0] ?? ''));
    }

    /**
     * @param array<string, mixed> $extension
     */
    private function extensionClass(array $extension): string
    {
        foreach (['class', 'class_name'] as $key) {
            $class = trim((string)($extension[$key] ?? ''));
            if ($class !== '') {
                return $class;
            }
        }

        return $this->classFromFile((string)($extension['source_file'] ?? ''));
    }

    private function classFromFile(string $sourceFile): string
    {
        if ($sourceFile === '' || !is_file($sourceFile) || !is_readable($sourceFile)) {
            return '';
        }

        $content = file_get_contents($sourceFile, false, null, 0, 4096);
        if ($content === false) {
            return '';
        }

        $namespace = '';
        $className = '';
        if (preg_match('/^\s*namespace\s+([^;]+)\s*;/m', $content, $matches) === 1) {
            $namespace = trim($matches[1]);
        }
        if (preg_match('/^\s*(?:abstract\s+)?(?:final\s+)?class\s+(\w+)/m', $content, $matches) === 1) {
            $className = trim($matches[1]);
        }

        return $namespace !== '' && $className !== '' ? $namespace . '\\' . $className : '';
    }
}

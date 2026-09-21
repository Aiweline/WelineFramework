<?php

declare(strict_types=1);

namespace Weline\Framework\Controller\Extra;

use Weline\Framework\Extends\ExtendsData;
use Weline\Framework\Manager\ObjectManager;

final class ExtraTypeRegistry
{
    /** @var array<string, ExtraTypeProviderInterface>|null */
    private ?array $byType = null;

    /** @return array<string, ExtraTypeProviderInterface> */
    public function all(): array
    {
        $this->load();
        return $this->byType ?? [];
    }

    public function get(string $type): ?ExtraTypeProviderInterface
    {
        $this->load();
        return $this->byType[strtolower(trim($type))] ?? null;
    }

    /** @return list<string> */
    public function registeredTypes(): array
    {
        return array_keys($this->all());
    }

    private function load(): void
    {
        if ($this->byType !== null) {
            return;
        }
        $this->byType = [];
        try {
            $extendedBy = ExtendsData::getExtendedBy('Weline_Framework');
        } catch (\Throwable) {
            return;
        }
        $prefix = ExtraTypeProviderInterface::EXTENDS_RELATIVE_PREFIX;
        foreach ($extendedBy as $extensions) {
            if (!is_array($extensions)) {
                continue;
            }
            foreach ($extensions as $extension) {
                if (!is_array($extension) || ($extension['source_module_status'] ?? true) === false) {
                    continue;
                }
                $relativePath = str_replace('\\', '/', (string)($extension['relative_path'] ?? ''));
                if (!str_starts_with(strtolower($relativePath), $prefix)) {
                    continue;
                }
                $className = $this->resolveClassName($extension);
                $sourceFile = (string)($extension['source_file'] ?? '');
                if ($className === null) {
                    continue;
                }
                if (!class_exists($className, false) && $sourceFile !== '' && is_file($sourceFile)) {
                    require_once $sourceFile;
                }
                if (!class_exists($className) || !is_subclass_of($className, ExtraTypeProviderInterface::class)) {
                    continue;
                }
                try {
                    /** @var ExtraTypeProviderInterface $provider */
                    $provider = ObjectManager::getInstance($className);
                    $type = strtolower(trim($provider->type()));
                    if ($type !== '') {
                        $this->byType[$type] = $provider;
                    }
                } catch (\Throwable) {
                    continue;
                }
            }
        }
    }

    /** @param array<string, mixed> $extension */
    private function resolveClassName(array $extension): ?string
    {
        $fromScan = trim((string)($extension['class_name'] ?? ''));
        if ($fromScan !== '') {
            return $fromScan;
        }
        $sourceFile = (string)($extension['source_file'] ?? '');
        if ($sourceFile === '' || !is_file($sourceFile)) {
            return null;
        }
        $content = file_get_contents($sourceFile);
        if ($content === false) {
            return null;
        }
        $namespace = null;
        if (preg_match('/namespace\s+([^;]+);/', $content, $matches) === 1) {
            $namespace = trim((string)$matches[1]);
        }
        $class = null;
        if (preg_match('/class\s+(\w+)/', $content, $matches) === 1) {
            $class = (string)$matches[1];
        }
        if ($namespace !== null && $class !== null) {
            return $namespace . '\\' . $class;
        }
        return null;
    }
}

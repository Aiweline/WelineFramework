<?php

declare(strict_types=1);

namespace Weline\Framework\Event\Changed;

use Weline\Framework\Extends\ExtendsData;
use Weline\Framework\Manager\ObjectManager;

final class ChangedCapabilityRegistry
{
    /** @var array<string, ChangedCapabilityInterface>|null */
    private ?array $byCode = null;

    /** @return array<string, ChangedCapabilityInterface> */
    public function all(): array
    {
        $this->load();
        return $this->byCode ?? [];
    }

    public function get(string $code): ?ChangedCapabilityInterface
    {
        $this->load();
        return $this->byCode[$code] ?? null;
    }

    public function forEffect(string $effectCode): ?ChangedCapabilityInterface
    {
        foreach ($this->all() as $capability) {
            if (in_array($effectCode, $capability->supportedEffects(), true)) {
                return $capability;
            }
        }
        return null;
    }

    private function load(): void
    {
        if ($this->byCode !== null) {
            return;
        }
        $this->byCode = [];
        foreach ($this->loadExtends(ChangedCapabilityInterface::EXTENDS_RELATIVE_PREFIX, ChangedCapabilityInterface::class) as $instance) {
            /** @var ChangedCapabilityInterface $instance */
            $code = $instance->code();
            if ($code === '') {
                continue;
            }
            $this->byCode[$code] = $instance;
        }
    }

    /**
     * @param class-string $interface
     * @return list<object>
     */
    private function loadExtends(string $prefix, string $interface): array
    {
        $rows = [];
        try {
            $extendedBy = ExtendsData::getExtendedBy('Weline_Framework');
        } catch (\Throwable) {
            return [];
        }
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
                if (!class_exists($className) || !is_subclass_of($className, $interface)) {
                    continue;
                }
                try {
                    $rows[] = ObjectManager::getInstance($className);
                } catch (\Throwable) {
                    continue;
                }
            }
        }
        return $rows;
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

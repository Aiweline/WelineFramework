<?php

declare(strict_types=1);

namespace Weline\Framework\Event\Changed;

use Weline\Framework\Extends\ExtendsData;
use Weline\Framework\Manager\ObjectManager;

final class ChangedTypeRegistry
{
    /** @var array<string, ChangedTypeInterface>|null */
    private ?array $byCode = null;

    /** @return array<string, ChangedTypeInterface> */
    public function all(): array
    {
        $this->load();
        return $this->byCode ?? [];
    }

    public function get(string $code): ?ChangedTypeInterface
    {
        $this->load();
        return $this->byCode[strtolower(trim($code))] ?? null;
    }

    private function load(): void
    {
        if ($this->byCode !== null) {
            return;
        }
        $this->byCode = [];
        foreach ($this->loadExtends() as $instance) {
            $code = strtolower(trim($instance->code()));
            if ($code === '') {
                continue;
            }
            $this->byCode[$code] = $instance;
        }
    }

    /** @return list<ChangedTypeInterface> */
    private function loadExtends(): array
    {
        $rows = [];
        try {
            $extendedBy = ExtendsData::getExtendedBy('Weline_Framework');
        } catch (\Throwable) {
            return [];
        }
        $prefix = ChangedTypeInterface::EXTENDS_RELATIVE_PREFIX;
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
                if (!class_exists($className) || !is_subclass_of($className, ChangedTypeInterface::class)) {
                    continue;
                }
                try {
                    /** @var ChangedTypeInterface $instance */
                    $instance = ObjectManager::getInstance($className);
                    $rows[] = $instance;
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

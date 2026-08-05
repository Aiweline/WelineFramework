<?php

declare(strict_types=1);

namespace Weline\Seo\Service;

use Weline\Framework\Extends\ExtendsData;
use Weline\Framework\Manager\ObjectManager;
use Weline\Seo\Interface\OptimizationTargetAdapterInterface;

final class OptimizationTargetRegistry
{
    /** @var array<string,OptimizationTargetAdapterInterface>|null */
    private ?array $adapters = null;

    public function __construct(private readonly ObjectManager $objectManager)
    {
    }

    public function get(string $code): ?OptimizationTargetAdapterInterface
    {
        return $this->all()[$code] ?? null;
    }

    /** @return array<string,OptimizationTargetAdapterInterface> */
    public function all(bool $reload = false): array
    {
        if (!$reload && $this->adapters !== null) {
            return $this->adapters;
        }
        $this->adapters = [];
        try {
            foreach (ExtendsData::getExtendedBy('Weline_Seo', $reload) as $extensions) {
                foreach ($extensions as $extension) {
                    if ($this->extensionName($extension) !== 'OptimizationTargetAdapter') {
                        continue;
                    }
                    $class = $this->extensionClass($extension);
                    if ($class === '' || !\class_exists($class)) {
                        continue;
                    }
                    $adapter = $this->objectManager->getInstance($class);
                    if ($adapter instanceof OptimizationTargetAdapterInterface && $adapter->getCode() !== '') {
                        $this->adapters[$adapter->getCode()] = $adapter;
                    }
                }
            }
        } catch (\Throwable $throwable) {
            \w_log_error('[Weline_Seo] Optimization adapter discovery failed: ' . $throwable->getMessage());
        }
        return $this->adapters;
    }

    /** @param array<string,mixed> $extension */
    private function extensionName(array $extension): string
    {
        $name = \trim((string)($extension['extend_name'] ?? ''));
        if ($name !== '') {
            return $name;
        }
        $parts = \explode('/', \str_replace('\\', '/', (string)($extension['file_path'] ?? '')));
        return \trim((string)($parts[0] ?? ''));
    }

    /** @param array<string,mixed> $extension */
    private function extensionClass(array $extension): string
    {
        foreach (['class', 'class_name'] as $key) {
            $class = \trim((string)($extension[$key] ?? ''));
            if ($class !== '') {
                return $class;
            }
        }
        $file = (string)($extension['source_file'] ?? '');
        if ($file === '' || !\is_readable($file)) {
            return '';
        }
        $content = \file_get_contents($file, false, null, 0, 4096);
        if (!\is_string($content)
            || \preg_match('/^\s*namespace\s+([^;]+);/m', $content, $namespace) !== 1
            || \preg_match('/^\s*(?:final\s+)?class\s+(\w+)/m', $content, $class) !== 1
        ) {
            return '';
        }
        return \trim($namespace[1]) . '\\' . \trim($class[1]);
    }
}

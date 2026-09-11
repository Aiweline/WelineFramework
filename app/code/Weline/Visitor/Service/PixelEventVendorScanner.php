<?php

declare(strict_types=1);

namespace Weline\Visitor\Service;

use Weline\Framework\App\Env;
use Weline\Framework\Extends\ExtendsData;
use Weline\Framework\Manager\ObjectManager;
use Weline\Visitor\Interface\PixelEventVendorInterface;

class PixelEventVendorScanner
{
    /** @var list<array<string, mixed>>|null */
    private ?array $cachedDefinitions = null;

    private ?int $cachedExtendsMtime = null;

    public function __construct(
        private readonly ObjectManager $objectManager
    ) {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function scanProviderDefinitions(bool $forceReload = false): array
    {
        $currentMtime = ExtendsData::getRegistryFileMtime();
        if (!$forceReload && $this->cachedDefinitions !== null && $currentMtime === $this->cachedExtendsMtime) {
            return $this->cachedDefinitions;
        }

        $definitions = [];
        $extendedBy = ExtendsData::getExtendedBy('Weline_Visitor', $forceReload);
        $modules = Env::getInstance()->getModuleList();

        foreach ($extendedBy as $sourceModule => $extensions) {
            $sourceModuleInfo = $modules[$sourceModule] ?? null;
            if (empty($sourceModuleInfo) || !($sourceModuleInfo['status'] ?? false)) {
                continue;
            }

            foreach ((array)$extensions as $extension) {
                if (($extension['is_sticker_extension'] ?? false) === true) {
                    continue;
                }

                $relativePath = (string)($extension['relative_path'] ?? '');
                if (!\str_starts_with($relativePath, 'extends/module/Weline_Visitor/PixelEventVendor/')) {
                    continue;
                }

                $sourceFile = (string)($extension['source_file'] ?? '');
                if ($sourceFile === '' || !\is_file($sourceFile)) {
                    continue;
                }

                $className = $this->getClassNameFromFile($sourceFile);
                if ($className === '') {
                    continue;
                }

                require_once $sourceFile;
                if (!\class_exists($className)) {
                    continue;
                }

                try {
                    $reflection = new \ReflectionClass($className);
                    if (!$reflection->implementsInterface(PixelEventVendorInterface::class)) {
                        continue;
                    }
                } catch (\Throwable $e) {
                    w_log_error('PixelEventVendor interface check failed: ' . $className . ' ' . $e->getMessage());
                    continue;
                }

                $definitions[] = [
                    'class_name' => $className,
                    'source_module' => (string)$sourceModule,
                    'source_file' => $sourceFile,
                    'relative_path' => $relativePath,
                ];
            }
        }

        $this->cachedDefinitions = $definitions;
        $this->cachedExtendsMtime = $currentMtime;

        return $definitions;
    }

    /**
     * @return list<PixelEventVendorInterface>
     */
    public function getProviderInstances(bool $forceReload = false): array
    {
        $providers = [];
        foreach ($this->scanProviderDefinitions($forceReload) as $definition) {
            $className = (string)($definition['class_name'] ?? '');
            if ($className === '') {
                continue;
            }
            try {
                /** @var PixelEventVendorInterface $instance */
                $instance = $this->objectManager->getInstance($className);
                if ($instance instanceof PixelEventVendorInterface) {
                    $providers[] = $instance;
                }
            } catch (\Throwable $e) {
                w_log_error('PixelEventVendor instantiate failed: ' . $className . ' ' . $e->getMessage());
            }
        }

        return $providers;
    }

    private function getClassNameFromFile(string $file): string
    {
        $content = (string)@\file_get_contents($file);
        if ($content === '') {
            return '';
        }
        $namespace = '';
        if (\preg_match('/namespace\s+([^;]+);/', $content, $m)) {
            $namespace = \trim($m[1]);
        }
        if (!\preg_match('/\b(?:final\s+)?class\s+(\w+)/', $content, $m)) {
            return '';
        }
        $class = $m[1];

        return $namespace !== '' ? $namespace . '\\' . $class : $class;
    }
}

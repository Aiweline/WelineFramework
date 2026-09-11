<?php

declare(strict_types=1);

namespace Weline\Dropship\Service;

use Weline\Dropship\Interface\DropshipProviderInterface;
use Weline\Framework\App\Env;
use Weline\Framework\Extends\ExtendsData;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\System\File\Scan;

class DropshipProviderScanner
{
    /** @var array<int, array<string, mixed>>|null */
    private ?array $cachedDefinitions = null;
    private ?int $cachedExtendsMtime = null;

    public function __construct(
        private readonly Scan $fileScanner,
        private readonly ObjectManager $objectManager
    ) {
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function scanProviderDefinitions(bool $forceReload = false): array
    {
        $currentMtime = ExtendsData::getRegistryFileMtime();
        if (!$forceReload && $this->cachedDefinitions !== null && $currentMtime === $this->cachedExtendsMtime) {
            return $this->cachedDefinitions;
        }

        $definitions = [];
        $extendedBy = ExtendsData::getExtendedBy('Weline_Dropship', $forceReload);
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
                if (!str_starts_with($relativePath, 'extends/module/Weline_Dropship/DropshipProvider/')) {
                    continue;
                }

                $sourceFile = (string)($extension['source_file'] ?? '');
                if ($sourceFile === '' || !is_file($sourceFile)) {
                    continue;
                }

                $className = $this->getClassNameFromFile($sourceFile);
                if ($className === '') {
                    continue;
                }

                require_once $sourceFile;
                if (!class_exists($className)) {
                    continue;
                }

                try {
                    $reflection = new \ReflectionClass($className);
                    if (!$reflection->implementsInterface(DropshipProviderInterface::class)) {
                        continue;
                    }
                } catch (\Throwable $throwable) {
                    w_log_error('Dropship provider check failed: ' . $className . ' ' . $throwable->getMessage());
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
     * @return list<DropshipProviderInterface>
     */
    public function instantiateProviders(bool $forceReload = false): array
    {
        $providers = [];
        foreach ($this->scanProviderDefinitions($forceReload) as $definition) {
            try {
                /** @var DropshipProviderInterface $provider */
                $provider = $this->objectManager->getInstance((string)$definition['class_name']);
                $code = trim($provider->getCode());
                if ($code === '') {
                    continue;
                }
                $providers[$code] = $provider;
            } catch (\Throwable $e) {
                w_log_error('Dropship provider instantiate failed: ' . ($definition['class_name'] ?? '') . ' ' . $e->getMessage());
            }
        }

        return array_values($providers);
    }

    private function getClassNameFromFile(string $file): string
    {
        $content = @file_get_contents($file);
        if ($content === false) {
            return '';
        }
        $ns = '';
        if (preg_match('/namespace\s+([^;]+);/', $content, $m)) {
            $ns = trim($m[1]);
        }
        if (!preg_match('/class\s+(\w+)/', $content, $m)) {
            return '';
        }
        $class = $m[1];

        return $ns !== '' ? $ns . '\\' . $class : $class;
    }
}

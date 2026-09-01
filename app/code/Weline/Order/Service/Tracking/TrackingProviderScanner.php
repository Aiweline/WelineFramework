<?php

declare(strict_types=1);

namespace Weline\Order\Service\Tracking;

use Weline\Framework\App\Env;
use Weline\Framework\Extends\ExtendsData;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\System\File\Scan;
use Weline\Order\Interface\TrackingProviderInterface;

final class TrackingProviderScanner
{
    /** @var array<int, array<string, mixed>>|null */
    private ?array $cachedDefinitions = null;
    private ?int $cachedExtendsMtime = null;

    public function __construct(
        private readonly Scan $fileScanner,
        private readonly ObjectManager $objectManager,
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
        $extendedBy = ExtendsData::getExtendedBy('Weline_Order', $forceReload);
        $modules = Env::getInstance()->getModuleList();

        foreach ($extendedBy as $sourceModule => $extensions) {
            $sourceModuleInfo = $modules[$sourceModule] ?? null;
            if (empty($sourceModuleInfo) || !($sourceModuleInfo['status'] ?? false)) {
                continue;
            }

            foreach ((array) $extensions as $extension) {
                if (($extension['is_sticker_extension'] ?? false) === true) {
                    continue;
                }

                $relativePath = (string) ($extension['relative_path'] ?? '');
                if (!str_starts_with($relativePath, 'extends/module/Weline_Order/TrackingProvider/')) {
                    continue;
                }

                $sourceFile = (string) ($extension['source_file'] ?? '');
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
                    if (!$reflection->implementsInterface(TrackingProviderInterface::class)) {
                        continue;
                    }
                } catch (\Throwable $throwable) {
                    w_log_error('检查订单跟踪 Provider 接口失败: ' . $className . ', 错误: ' . $throwable->getMessage());
                    continue;
                }

                $definitions[] = [
                    'class_name' => $className,
                    'source_module' => (string) $sourceModule,
                    'source_file' => $sourceFile,
                    'relative_path' => $relativePath,
                    'file_path' => (string) ($extension['file_path'] ?? $sourceFile),
                ];
            }
        }

        $this->cachedDefinitions = $definitions;
        $this->cachedExtendsMtime = $currentMtime;

        return $definitions;
    }

    /**
     * @return TrackingProviderInterface[]
     */
    public function getProviderInstances(bool $forceReload = false): array
    {
        $providers = [];
        foreach ($this->scanProviderDefinitions($forceReload) as $definition) {
            $className = (string) ($definition['class_name'] ?? '');
            if ($className === '') {
                continue;
            }
            try {
                $instance = $this->objectManager->getInstance($className);
                if ($instance instanceof TrackingProviderInterface) {
                    $providers[] = $instance;
                }
            } catch (\Throwable $throwable) {
                w_log_error('实例化订单跟踪 Provider 失败: ' . $className . ', 错误: ' . $throwable->getMessage());
            }
        }

        return $providers;
    }

    private function getClassNameFromFile(string $filePath): string
    {
        $contents = @file_get_contents($filePath);
        if (!is_string($contents) || $contents === '') {
            return '';
        }

        $namespace = '';
        if (preg_match('/^\s*namespace\s+([^;]+);/m', $contents, $matches) === 1) {
            $namespace = trim($matches[1]);
        }

        if (preg_match('/^\s*(?:final\s+|abstract\s+)?class\s+([A-Za-z_][A-Za-z0-9_]*)/m', $contents, $matches) !== 1) {
            return '';
        }

        $class = trim($matches[1]);
        return $namespace !== '' ? $namespace . '\\' . $class : $class;
    }
}

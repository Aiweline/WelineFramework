<?php
declare(strict_types=1);

/**
 * 域名商适配器解析服务
 *
 * 扫描并解析所有域名商适配器（内置 + extends 扩展），
 * 参照 Weline_Cdn 的 AdapterResolver 实现。
 *
 * @author Aiweline
 * @email aiweline@qq.com
 */

namespace Weline\Websites\Service;

use Weline\Framework\App\Env;
use Weline\Framework\Extends\ExtendsData;
use Weline\Framework\Manager\ObjectManager;
use Weline\Websites\Api\DomainRegistrarInterface;

class DomainRegistrarResolverService
{
    private ObjectManager $objectManager;

    /**
     * 已注册的适配器缓存
     *
     * @var array<string, DomainRegistrarInterface>
     */
    private array $adapters = [];

    /**
     * ExtendsData 缓存的 mtime
     */
    private ?int $cachedExtendsMtime = null;

    public function __construct(ObjectManager $objectManager)
    {
        $this->objectManager = $objectManager;
    }

    /**
     * 获取所有适配器
     *
     * @param bool $forceReload 是否强制重新加载
     * @return array<string, DomainRegistrarInterface> 适配器代码 => 适配器实例
     */
    public function getAllAdapters(bool $forceReload = false): array
    {
        // 检查 extends 注册表是否更新
        if (!$forceReload && !empty($this->adapters)) {
            try {
                $currentMtime = ExtendsData::getRegistryFileMtime();
                if ($this->cachedExtendsMtime !== null && $currentMtime === $this->cachedExtendsMtime) {
                    return $this->adapters;
                }
            } catch (\Exception $e) {
                if (!empty($this->adapters)) {
                    return $this->adapters;
                }
            }
        }

        if (empty($this->adapters) || $forceReload) {
            $this->adapters = [];
            $this->scanAdapters();
        }

        return $this->adapters;
    }

    /**
     * 获取适配器实例
     *
     * @param string $adapterCode 适配器代码
     * @return DomainRegistrarInterface|null
     */
    public function getAdapter(string $adapterCode): ?DomainRegistrarInterface
    {
        $adapters = $this->getAllAdapters();
        return $adapters[$adapterCode] ?? null;
    }

    /**
     * 获取适配器信息列表（供前端选择使用）
     *
     * @return array<array{code: string, name: string, description: string, version: string, config_fields: array}>
     */
    public function getAdapterOptions(): array
    {
        $options = [];
        foreach ($this->getAllAdapters() as $code => $adapter) {
            $options[] = [
                'code' => $adapter->getRegistrarCode(),
                'name' => $adapter->getRegistrarName(),
                'description' => $adapter->getDescription(),
                'version' => $adapter->getVersion(),
                'config_fields' => $adapter->getConfigFields(),
            ];
        }
        return $options;
    }

    /**
     * 扫描所有适配器
     */
    private function scanAdapters(): void
    {
        // 1. 扫描 Weline_Websites 模块本身的适配器
        $adapterDir = BP . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'code'
            . DIRECTORY_SEPARATOR . 'Weline' . DIRECTORY_SEPARATOR . 'Websites'
            . DIRECTORY_SEPARATOR . 'Adapter';

        if (\is_dir($adapterDir)) {
            $adapterFiles = \glob($adapterDir . DIRECTORY_SEPARATOR . '*.php');
            foreach ($adapterFiles as $adapterFile) {
                $this->loadAdapterFromFile($adapterFile);
            }
        }

        // 2. 通过 ExtendsData 获取其他模块的适配器
        $this->scanExtendsAdapters();

        // 更新缓存的 mtime
        try {
            $this->cachedExtendsMtime = ExtendsData::getRegistryFileMtime();
        } catch (\Exception $e) {
            // 忽略
        }
    }

    /**
     * 通过 ExtendsData 扫描其他模块的适配器
     */
    private function scanExtendsAdapters(): void
    {
        try {
            $extendedBy = ExtendsData::getExtendedBy('Weline_Websites');

            if (empty($extendedBy)) {
                return;
            }

            $moduleList = Env::getInstance()->getModuleList();

            foreach ($extendedBy as $sourceModule => $extensions) {
                if (!isset($moduleList[$sourceModule])) {
                    continue;
                }

                $moduleBasePath = $moduleList[$sourceModule]['base_path'] ?? '';
                if (empty($moduleBasePath)) {
                    continue;
                }

                if (!($moduleList[$sourceModule]['status'] ?? false)) {
                    continue;
                }

                $adapterDir = \rtrim($moduleBasePath, '/\\') . DIRECTORY_SEPARATOR
                    . 'extends' . DIRECTORY_SEPARATOR
                    . 'module' . DIRECTORY_SEPARATOR
                    . 'Weline_Websites' . DIRECTORY_SEPARATOR
                    . 'Registrar';

                if (!\is_dir($adapterDir)) {
                    continue;
                }

                $adapterFiles = \glob($adapterDir . DIRECTORY_SEPARATOR . '*.php');
                foreach ($adapterFiles as $adapterFile) {
                    $this->loadAdapterFromFile($adapterFile, $sourceModule);
                }
            }
        } catch (\Exception $e) {
            w_log_error(__('从 ExtendsData 扫描域名商适配器失败: ') . $e->getMessage());
        }
    }

    /**
     * 从文件加载适配器
     *
     * @param string $filePath 文件路径
     * @param string|null $moduleName 模块名称
     */
    private function loadAdapterFromFile(string $filePath, ?string $moduleName = null): void
    {
        if (!\file_exists($filePath)) {
            return;
        }

        require_once $filePath;

        $className = $this->getClassNameFromFile($filePath, $moduleName);

        if (!$className || !\class_exists($className, false)) {
            return;
        }

        try {
            $instance = $this->objectManager->getInstance($className);

            if (!$instance instanceof DomainRegistrarInterface) {
                return;
            }

            $adapterCode = $instance->getRegistrarCode();
            $this->adapters[$adapterCode] = $instance;
        } catch (\Exception $e) {
            w_log_error(__('加载域名商适配器失败: %{file}, 错误: %{error}', [
                'file' => $filePath,
                'error' => $e->getMessage(),
            ]));
        }
    }

    /**
     * 从文件路径获取类名
     */
    private function getClassNameFromFile(string $filePath, ?string $moduleName = null): ?string
    {
        // 如果是 extends 目录下的文件，从文件内容中解析命名空间
        if (\str_contains($filePath, DIRECTORY_SEPARATOR . 'extends' . DIRECTORY_SEPARATOR)
            || \str_contains($filePath, '/extends/')) {
            $content = \file_get_contents($filePath);
            if ($content === false) {
                return null;
            }

            if (\preg_match('/namespace\s+([^;]+);/', $content, $namespaceMatches)) {
                $namespace = \trim($namespaceMatches[1]);

                if (\preg_match('/class\s+(\w+)/', $content, $classMatches)) {
                    $className = $classMatches[1];
                    return "\\{$namespace}\\{$className}";
                }
            }

            return null;
        }

        $fileName = \basename($filePath, '.php');

        if (!$moduleName || $moduleName === 'Weline_Websites') {
            return 'Weline\\Websites\\Adapter\\' . $fileName;
        }

        // 尝试从文件内容解析
        $content = \file_get_contents($filePath);
        if ($content !== false && \preg_match('/namespace\s+([^;]+);/', $content, $namespaceMatches)) {
            $namespace = \trim($namespaceMatches[1]);
            if (\preg_match('/class\s+(\w+)/', $content, $classMatches)) {
                return "\\{$namespace}\\{$classMatches[1]}";
            }
        }

        return null;
    }
}

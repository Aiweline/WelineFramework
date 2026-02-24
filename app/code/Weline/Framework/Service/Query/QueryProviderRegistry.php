<?php
declare(strict_types=1);

namespace Weline\Framework\Service\Query;

use Weline\Framework\Extends\ExtendsData;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Service\Query\Provider\QueryProviderInterface;
use Weline\Framework\Service\Query\Provider\DefaultCrudProvider;

/**
 * 查询器注册表
 *
 * 从 ExtendsData 扫描 Weline_Framework 的 Query 扩展点，
 * 按 getProviderName() 注册所有实现 QueryProviderInterface 的查询器。
 * 内置 crud 直接注册 DefaultCrudProvider。
 */
class QueryProviderRegistry
{
    /** @var array<string, QueryProviderInterface> provider name => instance */
    private array $providers = [];

    private bool $loaded = false;

    /**
     * 加载所有已注册的查询器
     */
    private function load(): void
    {
        if ($this->loaded) {
            return;
        }
        $this->loaded = true;

        /** @var DefaultCrudProvider $crud */
        $crud = ObjectManager::getInstance(DefaultCrudProvider::class);
        $this->providers[$crud->getProviderName()] = $crud;

        $extendedBy = ExtendsData::getExtendedBy('Weline_Framework');
        foreach ($extendedBy as $sourceModule => $extensions) {
            foreach ($extensions as $extension) {
                $relativePath = $extension['relative_path'] ?? '';
                if (!str_starts_with($relativePath, 'extends/module/Weline_Framework/Query/')) {
                    continue;
                }
                $className = $this->resolveClassName($extension);
                if ($className === null || !class_exists($className)) {
                    continue;
                }
                try {
                    $instance = ObjectManager::getInstance($className);
                    if ($instance instanceof QueryProviderInterface) {
                        $name = $instance->getProviderName();
                        if ($name !== '') {
                            $this->providers[$name] = $instance;
                        }
                    }
                } catch (\Throwable) {
                }
            }
        }
    }

    /**
     * 从 extends 条目中解析类名（读取源文件的 namespace + class）
     */
    private function resolveClassName(array $extension): ?string
    {
        $sourceFile = $extension['source_file'] ?? '';
        if ($sourceFile === '' || !file_exists($sourceFile)) {
            return null;
        }
        $content = file_get_contents($sourceFile);
        if ($content === false) {
            return null;
        }
        $namespace = null;
        if (preg_match('/namespace\s+([^;]+);/', $content, $m)) {
            $namespace = trim($m[1]);
        }
        $class = null;
        if (preg_match('/class\s+(\w+)/', $content, $m)) {
            $class = $m[1];
        }
        if ($namespace !== null && $class !== null) {
            return $namespace . '\\' . $class;
        }
        return null;
    }

    /**
     * 获取指定 provider 的查询器
     */
    public function getProvider(string $providerName): ?QueryProviderInterface
    {
        $this->load();
        return $this->providers[$providerName] ?? null;
    }

    /**
     * 获取所有已注册的查询器
     *
     * @return array<string, QueryProviderInterface>
     */
    public function getAllProviders(): array
    {
        $this->load();
        return $this->providers;
    }

    /**
     * 收集所有查询器的描述信息
     *
     * @return array 每项为一个查询器的 getDescriptor() 结果
     */
    public function getAllDescriptors(): array
    {
        $this->load();
        $descriptors = [];
        foreach ($this->providers as $provider) {
            $descriptors[] = $provider->getDescriptor();
        }
        return $descriptors;
    }
}

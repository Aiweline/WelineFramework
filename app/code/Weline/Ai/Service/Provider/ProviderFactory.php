<?php
declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 作者：Admin
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 * 日期：2025/10/09
 */

namespace Weline\Ai\Service\Provider;

use Weline\Ai\Model\AiModel;
use Weline\Framework\App\Exception;
use Weline\Framework\Manager\ObjectManager;

/**
 * AI提供者工厂
 * 
 * 功能：
 * - 根据模型选择合适的提供者
 * - 管理提供者实例
 * - 提供者注册和发现
 */
class ProviderFactory
{
    /**
     * 提供者实例缓存
     * 
     * @var array
     */
    /**
     * Explicit supplier-to-provider bindings for OpenAI-compatible vendors.
     *
     * @var array<string,class-string<ProviderInterface>>
     */
    private array $providerAliases = [
        'anthropic' => AnthropicProvider::class,
        'google' => GeminiProvider::class,
        'gemini' => GeminiProvider::class,
        'vectorengine' => VectorEngineProvider::class,
        'openai' => OpenAiProvider::class,
        'deepseek' => OpenAiProvider::class,
    ];

    private array $providers = [];

    /**
     * 提供者类映射
     * 
     * @var array
     */
    private array $providerClasses = [
        AnthropicProvider::class,
        GeminiProvider::class,
        VectorEngineProvider::class,
        OpenAiProvider::class,
    ];

    /**
     * 获取模型的提供者
     * 
     * @param AiModel $model
     * @return ProviderInterface
     * @throws Exception
     */
    public function getProvider(AiModel $model): ProviderInterface
    {
        $modelCode = $model->getModelCode();
        $vendor = strtolower(trim($model->getVendor()));

        // 尝试从缓存获取
        $cacheKey = $vendor . '_' . $modelCode;
        if (isset($this->providers[$cacheKey])) {
            return $this->providers[$cacheKey];
        }

        // 查找支持该模型的提供者
        if ($vendor !== '') {
            if (isset($this->providerAliases[$vendor])) {
                /** @var ProviderInterface $provider */
                $provider = ObjectManager::getInstance($this->providerAliases[$vendor]);
                $this->providers[$cacheKey] = $provider;
                return $provider;
            }

            foreach ($this->providerClasses as $providerClass) {
                /** @var ProviderInterface $provider */
                $provider = ObjectManager::getInstance($providerClass);
                if ($provider->getProviderCode() === $vendor) {
                    $this->providers[$cacheKey] = $provider;
                    return $provider;
                }
            }
        }

        foreach ($this->providerClasses as $providerClass) {
            /** @var ProviderInterface $provider */
            $provider = ObjectManager::getInstance($providerClass);
            
            if ($provider->supports($modelCode)) {
                $this->providers[$cacheKey] = $provider;
                return $provider;
            }
        }

        // 如果没有找到提供者，返回默认的模拟提供者
        return $this->getMockProvider();
    }

    /**
     * 获取模拟提供者（用于测试和开发）
     * 
     * @return ProviderInterface
     */
    private function getMockProvider(): ProviderInterface
    {
        if (!isset($this->providers['mock'])) {
            $this->providers['mock'] = new MockProvider();
        }
        return $this->providers['mock'];
    }

    /**
     * 创建工厂实例（ObjectManager 自动调用）
     * 
     * @return ProviderFactory
     */
    public function create(): ProviderFactory
    {
        return $this;
    }

    /**
     * 注册新的提供者类
     * 
     * @param string $providerClass
     * @return void
     */
    public function registerProvider(string $providerClass): void
    {
        if (!in_array($providerClass, $this->providerClasses)) {
            $this->providerClasses[] = $providerClass;
        }
    }

    /**
     * 获取所有已注册的提供者
     * 
     * @return array
     */
    public function getAllProviders(): array
    {
        $providers = [];
        
        foreach ($this->providerClasses as $providerClass) {
            $provider = ObjectManager::getInstance($providerClass);
            $providers[] = [
                'class' => $providerClass,
                'name' => basename(str_replace('\\', '/', $providerClass)),
            ];
        }
        
        return $providers;
    }
}


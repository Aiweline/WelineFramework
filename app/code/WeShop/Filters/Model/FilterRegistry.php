<?php

declare(strict_types=1);

namespace WeShop\Filters\Model;

use WeShop\Filters\Api\FilterProviderInterface;
use WeShop\Filters\Api\FilterCollectionInterface;
use Weline\Framework\Manager\ObjectManager;

/**
 * 筛选器注册表
 * 
 * 管理所有已注册的筛选器
 */
class FilterRegistry
{
    /**
     * @var FilterProviderInterface[]
     */
    private array $providers = [];
    
    /**
     * @var bool
     */
    private bool $initialized = false;
    
    /**
     * @var array 默认筛选器类
     */
    private array $defaultProviders = [
        'price' => \WeShop\Filters\Provider\PriceFilterProvider::class,
        'rating' => \WeShop\Filters\Provider\RatingFilterProvider::class,
        'stock' => \WeShop\Filters\Provider\StockFilterProvider::class,
        'shipping' => \WeShop\Filters\Provider\ShippingFilterProvider::class,
        'brand' => \WeShop\Filters\Provider\BrandFilterProvider::class,
        'new' => \WeShop\Filters\Provider\NewFilterProvider::class,
        'sale' => \WeShop\Filters\Provider\SaleFilterProvider::class,
    ];
    
    /**
     * 注册筛选器
     * 
     * @param FilterProviderInterface $provider
     * @return self
     */
    public function register(FilterProviderInterface $provider): self
    {
        $this->providers[$provider->getCode()] = $provider;
        return $this;
    }
    
    /**
     * 注销筛选器
     * 
     * @param string $code
     * @return self
     */
    public function unregister(string $code): self
    {
        unset($this->providers[$code]);
        return $this;
    }
    
    /**
     * 获取筛选器
     * 
     * @param string $code
     * @return FilterProviderInterface|null
     */
    public function get(string $code): ?FilterProviderInterface
    {
        $this->initialize();
        return $this->providers[$code] ?? null;
    }
    
    /**
     * 获取所有筛选器
     * 
     * @return FilterProviderInterface[]
     */
    public function getAll(): array
    {
        $this->initialize();
        return $this->providers;
    }
    
    /**
     * 获取指定分类可用的筛选器集合
     * 
     * @param int $categoryId
     * @return FilterCollectionInterface
     */
    public function getForCategory(int $categoryId): FilterCollectionInterface
    {
        $this->initialize();
        
        /** @var FilterCollection $collection */
        $collection = ObjectManager::getInstance(FilterCollection::class);
        
        foreach ($this->providers as $provider) {
            if ($provider->isEnabled($categoryId)) {
                $collection->addFilter($provider);
            }
        }
        
        return $collection;
    }
    
    /**
     * 检查筛选器是否存在
     * 
     * @param string $code
     * @return bool
     */
    public function has(string $code): bool
    {
        $this->initialize();
        return isset($this->providers[$code]);
    }
    
    /**
     * 初始化默认筛选器
     */
    private function initialize(): void
    {
        if ($this->initialized) {
            return;
        }
        
        foreach ($this->defaultProviders as $code => $class) {
            if (!isset($this->providers[$code]) && class_exists($class)) {
                try {
                    $provider = ObjectManager::getInstance($class);
                    if ($provider instanceof FilterProviderInterface) {
                        $this->providers[$code] = $provider;
                    }
                } catch (\Throwable $e) {
                    // 忽略无法实例化的提供者
                }
            }
        }
        
        $this->initialized = true;
    }
    
    /**
     * 添加默认筛选器类
     * 
     * @param string $code
     * @param string $class
     * @return self
     */
    public function addDefaultProvider(string $code, string $class): self
    {
        $this->defaultProviders[$code] = $class;
        $this->initialized = false;
        return $this;
    }
}

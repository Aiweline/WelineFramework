<?php
declare(strict_types=1);

/**
 * Weline Server 策略工厂
 * 
 * 根据当前平台自动选择最优的服务器启动策略。
 * 遵循开闭原则：添加新策略只需注册，无需修改工厂代码。
 * 
 * @author Aiweline
 * @email aiweline@qq.com
 */

namespace Weline\Server\Strategy;

/**
 * 服务器启动策略工厂
 */
class ServerStrategyFactory
{
    /**
     * 已注册的策略列表
     * @var ServerStrategyInterface[]
     */
    private array $strategies = [];
    
    /**
     * 策略优先级（数字越小优先级越高）
     * @var array<string, int>
     */
    private array $priorities = [];
    
    /**
     * 构造函数
     * 
     * 自动注册默认策略
     */
    public function __construct()
    {
        // 注册 Linux 直连策略（优先级最高）
        $this->register(new LinuxDirectStrategy(), 10);
        
        // 注册 Windows Dispatcher 策略（后备方案）
        $this->register(new WindowsDispatcherStrategy(), 20);
    }
    
    /**
     * 注册策略
     * 
     * @param ServerStrategyInterface $strategy 策略实例
     * @param int $priority 优先级（数字越小越优先）
     * @return self
     */
    public function register(ServerStrategyInterface $strategy, int $priority = 100): self
    {
        $id = $strategy->getIdentifier();
        $this->strategies[$id] = $strategy;
        $this->priorities[$id] = $priority;
        
        return $this;
    }
    
    /**
     * 获取当前平台支持的最优策略
     * 
     * @return ServerStrategyInterface
     * @throws \RuntimeException 如果没有可用策略
     */
    public function getStrategy(): ServerStrategyInterface
    {
        // 按优先级排序
        $sortedIds = \array_keys($this->strategies);
        \usort($sortedIds, fn($a, $b) => ($this->priorities[$a] ?? 100) <=> ($this->priorities[$b] ?? 100));
        
        // 返回第一个支持当前平台的策略
        foreach ($sortedIds as $id) {
            $strategy = $this->strategies[$id];
            if ($strategy->supports()) {
                return $strategy;
            }
        }
        
        throw new \RuntimeException(__('没有可用的服务器启动策略'));
    }
    
    /**
     * 按标识符获取策略
     * 
     * @param string $identifier 策略标识符
     * @return ServerStrategyInterface|null
     */
    public function getStrategyByIdentifier(string $identifier): ?ServerStrategyInterface
    {
        return $this->strategies[$identifier] ?? null;
    }
    
    /**
     * 获取所有已注册的策略
     * 
     * @return ServerStrategyInterface[]
     */
    public function getAllStrategies(): array
    {
        return $this->strategies;
    }
    
    /**
     * 获取所有支持当前平台的策略
     * 
     * @return ServerStrategyInterface[]
     */
    public function getSupportedStrategies(): array
    {
        return \array_filter($this->strategies, fn($s) => $s->supports());
    }
    
    /**
     * 检查是否有可用策略
     * 
     * @return bool
     */
    public function hasAvailableStrategy(): bool
    {
        foreach ($this->strategies as $strategy) {
            if ($strategy->supports()) {
                return true;
            }
        }
        return false;
    }
    
    /**
     * 获取当前平台的推荐策略名称
     * 
     * @return string
     */
    public function getRecommendedStrategyName(): string
    {
        try {
            return $this->getStrategy()->getName();
        } catch (\RuntimeException $e) {
            return __('无可用策略');
        }
    }
    
    /**
     * 获取策略对比信息
     * 
     * @return array
     */
    public function getStrategyComparison(): array
    {
        $comparison = [];
        
        foreach ($this->strategies as $strategy) {
            $comparison[] = [
                'identifier' => $strategy->getIdentifier(),
                'name' => $strategy->getName(),
                'supported' => $strategy->supports(),
                'priority' => $this->priorities[$strategy->getIdentifier()] ?? 100,
                'architecture' => $strategy->getArchitectureDescription(),
            ];
        }
        
        // 按优先级排序
        \usort($comparison, fn($a, $b) => $a['priority'] <=> $b['priority']);
        
        return $comparison;
    }
}

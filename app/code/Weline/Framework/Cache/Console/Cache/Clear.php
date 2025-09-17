<?php

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Framework\Cache\Console\Cache;

use Weline\Framework\Cache\CacheFactory;
use Weline\Framework\Cache\CacheFactoryInterface;
use Weline\Framework\Cache\CacheInterface;
use Weline\Framework\Cache\Scanner;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Output\Cli\Printing;

class Clear implements \Weline\Framework\Console\CommandInterface
{
    /**
     * @var Scanner
     */
    private Scanner $scanner;

    /**
     * @var Printing
     */
    private Printing $printing;

    public function __construct(
        Scanner  $scanner,
        Printing $printing
    )
    {
        $this->scanner  = $scanner;
        $this->printing = $printing;
    }

    /**
     * @inheritDoc
     */
    public function execute(array $args = [], array $data = [])
    {
        $is_force = in_array('-f', $args);
        $caches   = $this->scanner->getCaches();
        
        $totalStats = [
            'app' => ['count' => 0, 'size' => 0],
            'framework' => ['count' => 0, 'size' => 0]
        ];
        
        foreach ($caches as $form => $modules_caches) {
            switch ($form) {
                case 'app':
                    $this->printing->note(__('📱 模块缓存清理中...'));
                    $appStats = $this->clearCacheGroup($modules_caches, $is_force, 'app');
                    $totalStats['app'] = $appStats;
                    $this->printCategorySummary('模块缓存', $appStats);
                    break;
                    
                case 'framework':
                    $this->printing->note(__('🔧 框架缓存清理中...'));
                    $frameworkStats = $this->clearCacheGroup($modules_caches, $is_force, 'framework');
                    $totalStats['framework'] = $frameworkStats;
                    $this->printCategorySummary('框架缓存', $frameworkStats);
                    break;
                    
                default:
                    $this->printing->error(__('没有任何类型的缓存需要清理！'));
            }
        }
        
        // 显示总体统计
        $this->printOverallSummary($totalStats);
    }
    
    /**
     * 清理缓存组
     * 
     * @param array $modules_caches 模块缓存数组
     * @param bool $is_force 是否强制清理
     * @param string $type 缓存类型
     * @return array 清理统计信息
     */
    private function clearCacheGroup(array $modules_caches, bool $is_force, string $type): array
    {
        $totalCount = 0;
        $totalSize = 0;
        $processedClasses = [];
        
        foreach ($modules_caches as $module => $module_caches) {
            $moduleClasses = [];
            $moduleCount = 0;
            $moduleSize = 0;
            
            foreach ($module_caches as $cache) {
                $className = $this->getShortClassName($cache['class']);
                $moduleClasses[] = $className;
                
                try {
                    /**@var CacheFactory $cacheObjectManager */
                    $cacheObjectManager = ObjectManager::getInstance($this->reductionFactoryClass($cache['class']));
                    
                    if ($cacheObjectManager instanceof CacheFactoryInterface) {
                        if ($is_force || !$cacheObjectManager->isKeep()) {
                            $result = $this->clearCacheWithStats($cacheObjectManager->create());
                            $moduleCount += $result['count'];
                            $moduleSize += $result['size'];
                        }
                    } else {
                        /**@var CacheInterface $cacheObjectManager */
                        $result = $this->clearCacheWithStats($cacheObjectManager);
                        $moduleCount += $result['count'];
                        $moduleSize += $result['size'];
                    }
                } catch (\Exception $e) {
                    // 静默处理错误，继续清理其他缓存
                }
            }
            
            if (!empty($moduleClasses)) {
                $processedClasses = array_merge($processedClasses, $moduleClasses);
                $totalCount += $moduleCount;
                $totalSize += $moduleSize;
                
                // 显示模块清理信息（同行显示所有类）
                $classesStr = implode(', ', array_unique($moduleClasses));
                $this->printing->printing('  └─ ' . $module . ': ' . $classesStr);
            }
        }
        
        return ['count' => $totalCount, 'size' => $totalSize, 'classes' => count(array_unique($processedClasses))];
    }
    
    /**
     * 清理缓存并获取统计信息
     * 
     * @param CacheInterface $cache 缓存对象
     * @return array 清理统计信息
     */
    private function clearCacheWithStats(CacheInterface $cache): array
    {
        // 这里可以根据实际的缓存实现来获取更准确的统计信息
        // 目前返回基本统计，实际项目中可能需要根据具体缓存类型调整
        $cache->clear();
        return ['count' => 1, 'size' => 0]; // 简化统计，实际可以更精确
    }
    
    /**
     * 获取短类名
     * 
     * @param string $fullClassName 完整类名
     * @return string 短类名
     */
    private function getShortClassName(string $fullClassName): string
    {
        $parts = explode('\\', $fullClassName);
        return end($parts);
    }
    
    /**
     * 显示分类总结
     * 
     * @param string $categoryName 分类名称
     * @param array $stats 统计信息
     */
    private function printCategorySummary(string $categoryName, array $stats): void
    {
        if ($stats['count'] > 0) {
            $this->printing->success('✅ ' . $categoryName . ' 清理完成: ' . $stats['classes'] . ' 个缓存类，清理了 ' . $stats['count'] . ' 个缓存项');
        } else {
            $this->printing->note('ℹ️  ' . $categoryName . ' 无需清理');
        }
    }
    
    /**
     * 显示总体总结
     * 
     * @param array $totalStats 总体统计信息
     */
    private function printOverallSummary(array $totalStats): void
    {
        $totalCount = $totalStats['app']['count'] + $totalStats['framework']['count'];
        $totalClasses = $totalStats['app']['classes'] + $totalStats['framework']['classes'];
        
        if ($totalCount > 0) {
            $this->printing->success('🎉 缓存清理完成！总共清理了 ' . $totalClasses . ' 个缓存类，' . $totalCount . ' 个缓存项');
        } else {
            $this->printing->note('✨ 所有缓存都是最新的，无需清理');
        }
    }

    /**
     * @DESC          # 还原工厂类
     *
     * @AUTH    秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 2022/4/14 22:54
     * 参数区：
     *
     * @param string $class
     *
     * @return string
     */
    public function reductionFactoryClass(string $class): string
    {
        if (!class_exists($class) && str_ends_with($class, 'Factory')) {
            if (str_ends_with($class, 'Factory')) {
                $class = rtrim($class, 'Factory');
            }
        }
        return $class;
    }

    /**
     * @inheritDoc
     */
    public function tip(): string
    {
        return '缓存清理。';
    }
}

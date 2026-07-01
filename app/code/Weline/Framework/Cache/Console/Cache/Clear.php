<?php

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Framework\Cache\Console\Cache;

use Weline\Framework\Console\CommandInterface;

use Weline\Framework\Cache\CacheFactory;
use Weline\Framework\Cache\CacheFactoryInterface;
use Weline\Framework\Cache\Contract\CachePoolInterface;
use Weline\Framework\Cache\Scanner;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Output\Cli\Printing;
use Weline\Framework\View\Taglib;
use Weline\Framework\View\TemplateCacheManager;
use Weline\Server\Service\Control\BroadcastControlDispatchService;

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

    /**
     * @var BroadcastControlDispatchService|null
     */
    private ?BroadcastControlDispatchService $broadcastService = null;

    public function __construct(
        Scanner  $scanner,
        Printing $printing
    )
    {
        $this->scanner  = $scanner;
        $this->printing = $printing;
    }

    /**
     * 获取广播控制服务（延迟初始化，避免 CLI 场景不必要开销）
     */
    private function getBroadcastService(): BroadcastControlDispatchService
    {
        if ($this->broadcastService === null) {
            $this->broadcastService = ObjectManager::getInstance(BroadcastControlDispatchService::class);
        }
        return $this->broadcastService;
    }

    /**
     * @inheritDoc
     */
    public function execute(array $args = [], array $data = [])
    {
        $is_force = in_array('-f', $args);
        $caches   = $this->scanner->getCaches();
        
        $totalStats = [
            'app' => ['count' => 0, 'size' => 0, 'classes' => 0, 'files' => 0],
            'framework' => ['count' => 0, 'size' => 0, 'classes' => 0, 'files' => 0]
        ];
        
        foreach ($caches as $form => $modules_caches) {
            switch ($form) {
                case 'app':
                    $appStats = $this->clearCacheGroup($modules_caches, $is_force, 'app', __('模块缓存清理中...'));
                    $totalStats['app'] = $appStats;
                    $this->printCategorySummary(__('模块缓存'), $appStats);
                    break;
                    
                case 'framework':
                    $frameworkStats = $this->clearCacheGroup($modules_caches, $is_force, 'framework', __('框架缓存清理中...'));
                    $totalStats['framework'] = $frameworkStats;
                    $this->printCategorySummary(__('框架缓存'), $frameworkStats);
                    break;
                    
                default:
                    $this->printing->error(__('没有任何类型的缓存需要清理！'));
            }
        }
        
        $this->clearViewCompileCaches();
        
        // 显示总体统计
        $this->printOverallSummary($totalStats);

        // 向 WLS 发送缓存清理命令（进程内缓存失效，不重启 Worker）
        $this->sendWlsCacheClearCommand();
    }

    /**
     * 清理模板编译相关缓存，避免源码已更新但继续读取旧编译产物。
     */
    private function clearViewCompileCaches(): void
    {
        try {
            $taglib = ObjectManager::getInstance(Taglib::class);
            if ($taglib instanceof Taglib) {
                $taglib->clearCache();
            }
        } catch (\Throwable) {
            // View may be unavailable in stripped CLI contexts.
        }

        try {
            TemplateCacheManager::getInstance()->clearAll();
        } catch (\Throwable) {
            // Enhanced template cache clear is best-effort during global cache clear.
        }
    }

    /**
     * 向 WLS 发送缓存清理 IPC 命令
     */
    private function sendWlsCacheClearCommand(): void
    {
        try {
            $service = $this->getBroadcastService();
            $result = $service->cacheClear();

            if (!empty($result['success'])) {
                $this->printing->successIcon(__('WLS 缓存清理命令已发送'));
                if ($result['message']) {
                    $this->printing->note($result['message']);
                }
            } else {
                $this->printing->warning(__('WLS 缓存清理命令发送失败：%{1}', [$result['message'] ?? __('未知错误')]));
            }
        } catch (\Throwable $e) {
            // WLS 未运行时静默忽略，不影响本地缓存清理的展示
        }
    }
    
    /**
     * 清理缓存组
     * 
     * @param array $modules_caches 模块缓存数组
     * @param bool $is_force 是否强制清理
     * @param string $type 缓存类型
     * @param string $title 标题
     * @return array 清理统计信息
     */
    private function clearCacheGroup(array $modules_caches, bool $is_force, string $type, string $title): array
    {
        $totalCount = 0;
        $totalSize = 0;
        $totalFiles = 0;
        $processedClasses = [];
        $currentIndex = 0;
        $totalItems = 0;
        
        // 计算总项目数
        foreach ($modules_caches as $module => $module_caches) {
            $totalItems += count($module_caches);
        }
        
        // 显示固定的标题进度条
        $this->printing->progressBar(0, $totalItems, $title, 30);
        
        foreach ($modules_caches as $module => $module_caches) {
            foreach ($module_caches as $cache) {
                $currentIndex++;
                $className = $this->getShortClassName($cache['class']);
                $processedClasses[] = $className;
                
                // 更新进度条，保持标题不变
                $this->printing->progressBar($currentIndex, $totalItems, $title, 30);
                
                try {
                    /**@var CacheFactory $cacheObjectManager */
                    $cacheObjectManager = ObjectManager::getInstance($this->reductionFactoryClass($cache['class']));
                    
                    if ($cacheObjectManager instanceof CacheFactoryInterface) {
                        if ($is_force || !$cacheObjectManager->isKeep()) {
                            $result = $this->clearCacheWithStats($cacheObjectManager->create());
                            $totalCount += $result['count'];
                            $totalSize += $result['size'];
                            $totalFiles += $result['files'];
                        }
                    } elseif ($cacheObjectManager instanceof CachePoolInterface) {
                        /** @var CachePoolInterface $cacheObjectManager */
                        $result = $this->clearCacheWithStats($cacheObjectManager);
                        $totalCount += $result['count'];
                        $totalSize += $result['size'];
                        $totalFiles += $result['files'];
                    }
                } catch (\Exception $e) {
                    // 静默处理错误，继续清理其他缓存
                }
            }
        }
        
        // 进度条会自动在完成时换行，无需手动清理
        
        return [
            'count' => $totalCount, 
            'size' => $totalSize, 
            'files' => $totalFiles,
            'classes' => count(array_unique($processedClasses))
        ];
    }
    
    /**
     * 清理缓存并获取统计信息
     * 
     * @param CachePoolInterface $cache 缓存对象
     * @return array 清理统计信息
     */
    private function clearCacheWithStats(CachePoolInterface $cache): array
    {
        $stats = $this->getCacheStats($cache);
        $cache->clear();
        
        return [
            'count' => $stats['items'] ?? 1,
            'size' => $stats['size'] ?? 0,
            'files' => $stats['files'] ?? 1
        ];
    }
    
    /**
     * 获取缓存统计信息
     * 
     * @param CachePoolInterface $cache 缓存对象
     * @return array 缓存统计信息
     */
    private function getCacheStats(CachePoolInterface $cache): array
    {
        try {
            return $cache->getStats();
        } catch (\Exception $e) {
            return [
                'items' => 1,
                'size' => 0,
                'files' => 1
            ];
        }
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
            $sizeFormatted = $this->formatBytes($stats['size']);
            $this->printing->successIcon(__('%{1} 清理完成', [$categoryName]));
            $this->printing->coloredText(__('   📊 缓存类: %{1} 个', [$stats['classes']]), $this->printing::NOTE);
            $this->printing->coloredText(__('   📁 缓存文件: %{1} 个', [$stats['files']]), $this->printing::NOTE);
            $this->printing->coloredText(__('   💾 缓存项: %{1} 个', [$stats['count']]), $this->printing::NOTE);
            $this->printing->coloredText(__('   🗂️  释放空间: %{1}', [$sizeFormatted]), $this->printing::NOTE);
        } else {
            $this->printing->infoIcon(__('%{1} 无需清理', [$categoryName]));
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
        $totalFiles = $totalStats['app']['files'] + $totalStats['framework']['files'];
        $totalSize = $totalStats['app']['size'] + $totalStats['framework']['size'];
        
        if ($totalCount > 0) {
            $sizeFormatted = $this->formatBytes($totalSize);
            $this->printing->doneIcon(__('缓存清理完成！'));
            $this->printing->coloredText(__('   📊 总计缓存类: %{1} 个', [$totalClasses]), $this->printing::NOTE);
            $this->printing->coloredText(__('   📁 总计缓存文件: %{1} 个', [$totalFiles]), $this->printing::NOTE);
            $this->printing->coloredText(__('   💾 总计缓存项: %{1} 个', [$totalCount]), $this->printing::NOTE);
            $this->printing->coloredText(__('   🗂️  总计释放空间: %{1}', [$sizeFormatted]), $this->printing::NOTE);
        } else {
            $this->printing->infoIcon(__('所有缓存都是最新的，无需清理'));
        }
    }
    
    /**
     * 格式化字节大小
     * 
     * @param int $bytes 字节数
     * @return string 格式化后的大小
     */
    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        
        $bytes /= pow(1024, $pow);
        
        return round($bytes, 2) . ' ' . $units[$pow];
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
        // 如果类已存在，直接返回
        if (class_exists($class)) {
            return $class;
        }
        
        // 如果类不存在，尝试转换
        if (str_ends_with($class, 'Factory')) {
            // 如果以 Factory 结尾，尝试去掉 Factory 后缀
            $baseClass = rtrim($class, 'Factory');
            if (class_exists($baseClass)) {
                return $baseClass;
            }
        } else {
            // 如果不以 Factory 结尾，尝试加上 Factory 后缀
            $factoryClass = $class . 'Factory';
            if (class_exists($factoryClass)) {
                return $factoryClass;
            }
        }
        
        // 如果都找不到，返回原类名（让后续代码处理错误）
        return $class;
    }

    /**
     * @inheritDoc
     */
    public function tip(): string
    {
        return '缓存清理。';
    }

    public function help(): array|string
    {
        // 基于tip的默认help实现
        return \Weline\Framework\Console\CommandHelper::formatHelp(
            '',
            $this->tip(),
            [
                '-h, --help' => '显示帮助信息',
            ],
            [],
            []
        );
    }
}


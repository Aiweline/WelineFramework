<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Framework\Cache\Console\Cache;

use Weline\Framework\App\Env;
use Weline\Framework\Cache\CacheInterface;
use Weline\Framework\Cache\Scanner;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Output\Cli\Printing;

class Status implements \Weline\Framework\Console\CommandInterface
{
    /**
     * @var Scanner
     */
    private Scanner $scanner;
    private Printing $printing;

    public function __construct(
        Scanner  $scanner,
        Printing $printing
    )
    {
        $this->scanner = $scanner;
        $this->printing = $printing;
    }

    /**
     * @inheritDoc
     */
    public function execute(array $args = [], array $data = [])
    {
        # 检查是否有操作符（enable/disable）
        $op = null;
        if (isset($args[1]) && in_array($args[1], ['enable', 'disable'])) {
            $op = $args[1];
        }
        
        if ($op) {
            $caches = $this->scanner->getCaches();
            $cachesObjs = [];
            foreach ($caches as $position => $position_cache_class_files) {
                foreach ($position_cache_class_files as $module => $module_cache_class_files) {
                    foreach ($module_cache_class_files as $moduleCacheClassFile) {
                        $cachesObjs[] = ObjectManager::getInstance((rtrim($moduleCacheClassFile['class'], 'Factory') . 'Factory'));
                    }
                }
            }
            /**@var CacheInterface $cacheObj */
            $status = $op == 'enable' ? 1 : 0;
            $cache_config = Env::getInstance()->getData('cache');
            $identify_s = array_slice($args, 2, count($args));
            $no_has_data = [];
            $set_data = $cache_config['status'] ?? [];
            if ($identify_s) {
                foreach ($identify_s as $identify) {
                    $no_has = true;
                    foreach ($cachesObjs as $cacheObj) {
                        if ($identify === $cacheObj->getIdentify()) {
                            $set_data[$identify] = $status;
                            $no_has = false;
                        }
                    }
                    if ($no_has) {
                        $no_has_data[] = $identify;
                    }
                }
                # 配置缓存
                $cache_config['status'] = $set_data;
                Env::getInstance()->setConfig('cache', $cache_config);
                $this->printAll();
                if ($no_has_data) {
                    $this->printing->errorIcon(__('不存在的缓存标识：'));
                    $this->printing->list($no_has_data);
                }
            } else {
                foreach ($caches as $cacheObj) {
                    $identify = $cacheObj->getIdentify();
                    $set_data[$identify] = $status;
                }
                $cache_config['status'] = $set_data;
                Env::getInstance()->setConfig('cache', $cache_config);
                $this->printAll();
            }
        } else {
            # 处理缓存状态默认查看 所有缓存状态
            $identify_s = array_slice($args, 0, count($args));
            // 过滤掉命令本身
            $identify_s = array_filter($identify_s, function($arg) {
                return $arg !== 'cache:status' && !empty($arg);
            });
            
            if ($identify_s) {
                $this->printSpecific(array_values($identify_s));
            } else {
                $this->printAll();
            }
        }
    }

    /**
     * 打印所有缓存状态
     */
    public function printAll()
    {
        $this->printing->title(__('缓存状态总览'), '=', $this->printing::SUCCESS);
        
        $caches = $this->scanner->getCaches();
        $totalStats = ['enabled' => 0, 'disabled' => 0, 'total' => 0, 'size' => 0];
        
        // 模块缓存
        $this->printing->coloredText(__('📱 模块缓存'), $this->printing::WARNING, 'bold');
        $appStats = $this->printCacheGroup($caches['app'] ?? [], 'app');
        $totalStats = $this->mergeStats($totalStats, $appStats);
        
        $this->printing->separator('─', 60, $this->printing::NOTE);
        
        // 框架缓存
        $this->printing->coloredText(__('🔧 框架缓存'), $this->printing::WARNING, 'bold');
        $frameworkStats = $this->printCacheGroup($caches['framework'] ?? [], 'framework');
        $totalStats = $this->mergeStats($totalStats, $frameworkStats);
        
        // 总体统计
        $this->printing->separator('=', 60, $this->printing::SUCCESS);
        $this->printing->coloredText(__('📊 总体统计'), $this->printing::SUCCESS, 'bold');
        $this->printing->coloredText(__('📁 缓存目录位置: %{1}', ['var/cache/']), $this->printing::NOTE);
        $this->printing->keyValue([
            __('总缓存数') => $totalStats['total'],
            __('已启用') => $totalStats['enabled'],
            __('已禁用') => $totalStats['disabled'],
            __('总占用空间') => $this->formatBytes($totalStats['size'])
        ], ':', 15);
    }
    
    /**
     * 打印指定缓存状态
     * 
     * @param array $identifies 缓存标识数组
     */
    public function printSpecific(array $identifies)
    {
        $this->printing->title(__('指定缓存状态'), '=', $this->printing::SUCCESS);
        
        $caches = $this->scanner->getCaches();
        $foundCaches = [];
        $notFound = [];
        
        // 收集所有缓存
        $allCaches = [];
        foreach ($caches as $position => $position_cache_class_files) {
            foreach ($position_cache_class_files as $module => $module_cache_class_files) {
                foreach ($module_cache_class_files as $cache_class_file) {
                    $cache = ObjectManager::make(rtrim($cache_class_file['class'], 'Factory') . 'Factory');
                    $allCaches[$cache->getIdentify()] = $cache;
                }
            }
        }
        
        // 查找指定的缓存
        foreach ($identifies as $identify) {
            if (isset($allCaches[$identify])) {
                $foundCaches[] = $allCaches[$identify];
            } else {
                $notFound[] = $identify;
            }
        }
        
        if ($foundCaches) {
            $this->printCacheDetails($foundCaches);
        }
        
        if ($notFound) {
            $this->printing->separator('─', 50, $this->printing::WARNING);
            $this->printing->warningIcon(__('未找到的缓存标识:'));
            $this->printing->list($notFound, '•', $this->printing::ERROR);
        }
    }
    
    /**
     * 打印缓存组
     * 
     * @param array $cacheGroup 缓存组
     * @param string $type 类型
     * @return array 统计信息
     */
    private function printCacheGroup(array $cacheGroup, string $type): array
    {
        $stats = ['enabled' => 0, 'disabled' => 0, 'total' => 0, 'size' => 0];
        $cacheData = [];
        
        foreach ($cacheGroup as $module => $cache_class_files) {
            foreach ($cache_class_files as $cache_class_file) {
                $cache = ObjectManager::make(rtrim($cache_class_file['class'], 'Factory') . 'Factory');
                $identify = $cache->getIdentify();
                $status = $cache->getStatus();
                $cacheInfo = $this->getCacheInfo($cache);
                
                $cacheData[] = [
                    'identify' => $identify,
                    'status' => $status,
                    'info' => $cacheInfo,
                    'module' => $module
                ];
                
                $stats['total']++;
                $stats['size'] += $cacheInfo['size'];
                if ($status) {
                    $stats['enabled']++;
                } else {
                    $stats['disabled']++;
                }
            }
        }
        
        // 显示表格
        if ($cacheData) {
            $headers = [__('标识'), __('状态'), __('占用空间'), __('可清理'), __('描述')];
            $rows = [];
            
            foreach ($cacheData as $data) {
                $statusText = $data['status'] ? 
                    $this->printing->colorize(__('启用'), $this->printing::SUCCESS) : 
                    $this->printing->colorize(__('禁用'), $this->printing::ERROR);
                
                $rows[] = [
                    $data['identify'],
                    $statusText,
                    $this->formatBytes($data['info']['size']),
                    $this->formatBytes($data['info']['cleanable']),
                    $data['info']['description']
                ];
            }
            
            $this->printing->table($headers, $rows, ['padding' => 1, 'border' => false]);
        }
        
        return $stats;
    }
    
    /**
     * 打印缓存详细信息
     * 
     * @param array $caches 缓存对象数组
     */
    private function printCacheDetails(array $caches)
    {
        foreach ($caches as $cache) {
            $identify = $cache->getIdentify();
            $status = $cache->getStatus();
            $cacheInfo = $this->getCacheInfo($cache);
            
            $this->printing->coloredText(__('缓存标识: %{1}', [$identify]), $this->printing::WARNING, 'bold');
            
            $statusText = $status ? 
                $this->printing->colorize(__('启用'), $this->printing::SUCCESS) : 
                $this->printing->colorize(__('禁用'), $this->printing::ERROR);
            
            $this->printing->keyValue([
                __('状态') => $statusText,
                __('占用空间') => $this->formatBytes($cacheInfo['size']),
                __('可清理空间') => $this->formatBytes($cacheInfo['cleanable']),
                __('文件数量') => $cacheInfo['files'],
                __('描述') => $cacheInfo['description']
            ], ':', 12);
            
            $this->printing->separator('─', 40, $this->printing::NOTE);
        }
    }
    
    /**
     * 获取缓存信息
     * 
     * @param CacheInterface $cache 缓存对象
     * @return array 缓存信息
     */
    private function getCacheInfo(CacheInterface $cache): array
    {
        $identify = $cache->getIdentify();
        $cacheDir = BP . 'var' . DS . 'cache' . DS . $identify;
        
        $info = [
            'path' => $this->getRelativePath($cacheDir),
            'size' => 0,
            'cleanable' => 0,
            'files' => 0,
            'description' => $cache->tip()
        ];
        
        if (is_dir($cacheDir)) {
            $info['size'] = $this->getDirectorySize($cacheDir);
            $info['files'] = $this->getFileCount($cacheDir);
            $info['cleanable'] = $this->getCleanableSize($cacheDir);
        }
        
        return $info;
    }
    
    /**
     * 获取相对路径
     * 
     * @param string $path 绝对路径
     * @return string 相对路径
     */
    private function getRelativePath(string $path): string
    {
        $basePath = BP;
        if (strpos($path, $basePath) === 0) {
            return 'var/cache/' . basename($path);
        }
        return $path;
    }
    
    /**
     * 获取目录大小
     * 
     * @param string $directory 目录路径
     * @return int 字节数
     */
    private function getDirectorySize(string $directory): int
    {
        $size = 0;
        if (is_dir($directory)) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($directory, \RecursiveDirectoryIterator::SKIP_DOTS)
            );
            
            foreach ($iterator as $file) {
                if ($file->isFile()) {
                    $size += $file->getSize();
                }
            }
        }
        return $size;
    }
    
    /**
     * 获取文件数量
     * 
     * @param string $directory 目录路径
     * @return int 文件数量
     */
    private function getFileCount(string $directory): int
    {
        $count = 0;
        if (is_dir($directory)) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($directory, \RecursiveDirectoryIterator::SKIP_DOTS)
            );
            
            foreach ($iterator as $file) {
                if ($file->isFile()) {
                    $count++;
                }
            }
        }
        return $count;
    }
    
    /**
     * 获取可清理的大小（超过24小时的文件）
     * 
     * @param string $directory 目录路径
     * @return int 字节数
     */
    private function getCleanableSize(string $directory): int
    {
        $size = 0;
        if (is_dir($directory)) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($directory, \RecursiveDirectoryIterator::SKIP_DOTS)
            );
            
            foreach ($iterator as $file) {
                if ($file->isFile() && (time() - $file->getMTime() > 86400)) {
                    $size += $file->getSize();
                }
            }
        }
        return $size;
    }
    
    /**
     * 合并统计信息
     * 
     * @param array $stats1 统计信息1
     * @param array $stats2 统计信息2
     * @return array 合并后的统计信息
     */
    private function mergeStats(array $stats1, array $stats2): array
    {
        return [
            'enabled' => $stats1['enabled'] + $stats2['enabled'],
            'disabled' => $stats1['disabled'] + $stats2['disabled'],
            'total' => $stats1['total'] + $stats2['total'],
            'size' => $stats1['size'] + $stats2['size']
        ];
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
     * @inheritDoc
     */
    public function tip(): string
    {
        return __('缓存状态。[enable/disable]:开启/关闭 [identify...]:缓存识别名');
    }
}

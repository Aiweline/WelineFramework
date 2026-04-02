<?php

declare(strict_types=1);

namespace Weline\Framework\Setup\Console\Setup;

use Weline\Framework\App\Env;
use Weline\Framework\Console\CommandAbstract;
use Weline\Framework\Console\CommandHelper;

/**
 * 后台优化缓存生成命令
 * 
 * 由 setup:upgrade 在后台调用，执行耗时的优化操作：
 * - 类映射缓存生成
 * - PSR-4 映射缓存生成
 * - 反射元数据编译
 * 
 * 这些操作被移到后台执行，不占用升级主流程时间。
 */
class BackgroundOptimize extends CommandAbstract
{
    public function execute(array $args = [], array $data = [])
    {
        $startTime = microtime(true);
        $logFile = BP . 'var' . DIRECTORY_SEPARATOR . 'log' . DIRECTORY_SEPARATOR . 'setup' . DIRECTORY_SEPARATOR . 'background_optimize.log';

        $this->log($logFile, '====== 开始后台优化任务 ======');
        $this->log($logFile, '开始时间: ' . date('Y-m-d H:i:s'));
        
        try {
            // 1. 生成类映射缓存
            $this->log($logFile, '[1/3] 生成类映射缓存...');
            $classCount = $this->generateClassmapCache();
            $this->log($logFile, "      完成，共 {$classCount} 个类");
            
            // 2. 生成 PSR-4 映射缓存
            $this->log($logFile, '[2/3] 生成 PSR-4 映射缓存...');
            $psr4Count = $this->generatePsr4Cache();
            $this->log($logFile, "      完成，共 {$psr4Count} 个命名空间");
            
            // 3. 编译反射元数据与编译型工厂
            $skipReflectionCompile = isset($args['skip-reflection-compile']) || isset($args['skip-reflect']);
            if ($skipReflectionCompile) {
                $this->log($logFile, '[3/3] 跳过反射/工厂编译（--skip-reflection-compile）');
            } else {
                $this->log($logFile, '[3/3] 编译反射元数据与编译型工厂...');
                $this->compileReflectionAndFactories($logFile);
                $this->log($logFile, '      完成');
            }
            
            $endTime = microtime(true);
            $duration = round($endTime - $startTime, 2);
            
            $this->log($logFile, '====== 后台优化任务完成 ======');
            $this->log($logFile, "总耗时: {$duration} 秒");
            $this->log($logFile, '');
            
            // 控制台输出（如果是直接调用）
            $this->printer->success(__('后台优化任务完成，耗时 %{1} 秒', [$duration]));
            $this->printer->note(__('  - 类映射缓存: %{1} 个类', [$classCount]));
            $this->printer->note(__('  - PSR-4 映射缓存: %{1} 个命名空间', [$psr4Count]));
            
        } catch (\Throwable $e) {
            $this->log($logFile, '====== 后台优化任务失败 ======');
            $this->log($logFile, '错误: ' . $e->getMessage());
            $this->log($logFile, '');
            
            // 记录到错误日志
            w_log_error('后台优化任务失败: ' . $e->getMessage(), [], 'setup/background_optimize.log');
            
            $this->printer->error(__('后台优化任务失败: %{1}', [$e->getMessage()]));
        }
    }
    
    /**
     * 写入日志
     */
    private function log(string $logFile, string $message): void
    {
        $logDir = dirname($logFile);
        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }
        
        $line = '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL;
        file_put_contents($logFile, $line, FILE_APPEND | LOCK_EX);
    }
    
    /**
     * 生成类映射缓存
     */
    private function generateClassmapCache(): int
    {
        $classMap = [];
        $directories = [
            APP_CODE_PATH,
            BP . 'generated' . DS . 'code' . DS,
        ];
        
        foreach ($directories as $baseDir) {
            if (!is_dir($baseDir)) {
                continue;
            }
            
            $this->scanDirectoryForClasses($baseDir, $baseDir, $classMap);
        }
        
        // 保存类映射缓存
        $classMapFile = BP . 'generated' . DS . 'classmap.php';
        $content = '<?php' . PHP_EOL;
        $content .= '// 类映射缓存 - 由 setup:background-optimize 自动生成' . PHP_EOL;
        $content .= '// 生成时间: ' . date('Y-m-d H:i:s') . PHP_EOL;
        $content .= 'return ' . var_export($classMap, true) . ';' . PHP_EOL;
        
        file_put_contents($classMapFile, $content, LOCK_EX);
        
        return count($classMap);
    }
    
    /**
     * 递归扫描目录查找 PHP 类文件
     */
    private function scanDirectoryForClasses(string $dir, string $baseDir, array &$classMap): void
    {
        if (!is_dir($dir)) {
            return;
        }
        
        $files = scandir($dir);
        foreach ($files as $file) {
            if ($file === '.' || $file === '..') {
                continue;
            }
            
            $path = $dir . $file;
            
            if (is_dir($path)) {
                $this->scanDirectoryForClasses($path . DS, $baseDir, $classMap);
            } elseif (str_ends_with($file, '.php')) {
                // 从文件内容解析实际的 namespace 和 class 名称
                // 这样可以正确处理大小写（如 extends vs Extends）
                $className = $this->extractFullyQualifiedClassName($path);
                
                if ($className !== null) {
                    $classMap[$className] = $path;
                }
            }
        }
    }
    
    /**
     * 从 PHP 文件中提取完整类名（namespace + class）
     * 
     * 通过解析文件内容获取实际声明的 namespace 和 class 名称，
     * 而不是从文件路径推断，确保大小写正确（解决 Linux 区分大小写问题）
     * 
     * @param string $filePath PHP 文件路径
     * @return string|null 完整类名，解析失败返回 null
     */
    private function extractFullyQualifiedClassName(string $filePath): ?string
    {
        $content = @file_get_contents($filePath);
        if ($content === false) {
            return null;
        }
        
        // 只读取文件前 4KB（namespace 和 class 声明通常在文件开头）
        $content = substr($content, 0, 4096);
        
        $namespace = '';
        $className = '';
        
        // 解析 namespace
        if (preg_match('/^\s*namespace\s+([a-zA-Z_\x80-\xff][a-zA-Z0-9_\x80-\xff\\\\]*)\s*;/m', $content, $matches)) {
            $namespace = $matches[1];
        }
        
        // 解析 class/interface/trait/enum 名称
        if (preg_match('/^\s*(?:abstract\s+|final\s+|readonly\s+)*(?:class|interface|trait|enum)\s+([a-zA-Z_\x80-\xff][a-zA-Z0-9_\x80-\xff]*)/m', $content, $matches)) {
            $className = $matches[1];
        }
        
        if ($className === '') {
            return null;
        }
        
        // 组合完整类名
        if ($namespace !== '') {
            return $namespace . '\\' . $className;
        }
        
        return $className;
    }
    
    /**
     * 生成 PSR-4 映射缓存
     */
    private function generatePsr4Cache(): int
    {
        // 加载 Composer 的 autoload
        $autoloader = VENDOR_PATH . 'autoload.php';
        if (!is_file($autoloader)) {
            return 0;
        }
        
        $composerLoader = require $autoloader;
        $psr4Map = $composerLoader->getPrefixesPsr4();
        $modifiedPsr4 = [];
        
        foreach ($psr4Map as $prefix => $paths) {
            $relativePath = str_replace('\\', DS, trim($prefix, '\\'));
            $appCodePath = APP_CODE_PATH . $relativePath . DS;
            
            if (is_dir($appCodePath)) {
                // 移除已存在的 app/code 路径
                $paths = array_filter($paths, function($path) use ($appCodePath) {
                    $normalizedPath = rtrim($path, DS) . DS;
                    return $normalizedPath !== $appCodePath;
                });
                // 将 app/code 路径添加到最前面
                array_unshift($paths, $appCodePath);
                $modifiedPsr4[$prefix] = array_values($paths);
            }
        }
        
        // 保存 PSR-4 映射缓存
        if (!empty($modifiedPsr4)) {
            $psr4CacheFile = BP . 'generated' . DS . 'psr4_map.php';
            $content = '<?php' . PHP_EOL;
            $content .= '// PSR-4 映射缓存 - 由 setup:background-optimize 自动生成' . PHP_EOL;
            $content .= '// 生成时间: ' . date('Y-m-d H:i:s') . PHP_EOL;
            $content .= 'return ' . var_export($modifiedPsr4, true) . ';' . PHP_EOL;
            
            file_put_contents($psr4CacheFile, $content, LOCK_EX);
            
            return count($modifiedPsr4);
        }
        
        return 0;
    }
    
    /**
     * 执行 reflection:compile，生成反射元数据和编译型工厂容器
     */
    private function compileReflectionAndFactories(string $logFile): void
    {
        $phpBin = PHP_BINARY ?: 'php';
        $binW = BP . 'bin' . DIRECTORY_SEPARATOR . 'w';
        $cmd = '"' . $phpBin . '" "' . $binW . '" reflection:compile 2>&1';
        $output = [];
        $exitCode = 0;
        exec($cmd, $output, $exitCode);
        $outputStr = implode("\n", $output);
        
        // 记录编译输出到日志
        if ($outputStr) {
            foreach (explode("\n", $outputStr) as $line) {
                if (trim($line) !== '') {
                    $this->log($logFile, '      ' . $line);
                }
            }
        }
        
        if ($exitCode !== 0) {
            $this->log($logFile, "      警告: reflection:compile 返回码 {$exitCode}（不影响系统功能）");
        }
    }

    public function tip(): string
    {
        return __('后台执行优化缓存生成（类映射、PSR-4、反射编译）');
    }

    public function help(): array|string
    {
        return CommandHelper::formatHelp(
            'setup:background-optimize',
            __('在后台执行优化缓存生成任务，由 setup:upgrade 自动调用。' . 
               '也可手动执行来重新生成优化缓存。'),
            [
                '--skip-reflection-compile, --skip-reflect' => __('跳过反射元数据编译'),
                '-h, --help' => __('显示帮助信息'),
            ],
            [],
            [
                __('手动执行') => 'php bin/w setup:background-optimize',
                __('跳过反射编译') => 'php bin/w setup:background-optimize --skip-reflection-compile',
            ]
        );
    }
}

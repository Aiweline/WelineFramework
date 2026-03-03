<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Component\Service;

use Weline\Component\ComponentInterface;
use Weline\Framework\App\Env;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Register\Register;

/**
 * 组件扫描服务
 * 扫描所有实现了 ComponentInterface 的 Block 类
 */
class ComponentScanner
{
    /**
     * 扫描所有组件
     * 
     * @return array 组件列表，格式：[类名 => [模块名, 类名, 命名空间, 文档]]
     */
    public function scanAllComponents(): array
    {
        $components = [];
        $modules = Env::getInstance()->getModuleList();
        
        foreach ($modules as $moduleName => $moduleInfo) {
            $modulePath = $moduleInfo['base_path'] ?? '';
            if (empty($modulePath) || !is_dir($modulePath) || !($moduleInfo['status'] ?? false)) {
                continue;
            }
            
            // 扫描 Block 目录
            $blockPath = $modulePath . DS . 'Block';
            if (is_dir($blockPath)) {
                $this->scanDirectory($blockPath, $moduleName, $components);
            }
        }
        
        return $components;
    }
    
    /**
     * 递归扫描目录
     * 
     * @param string $dir 目录路径
     * @param string $moduleName 模块名
     * @param array &$components 组件数组（引用传递）
     */
    private function scanDirectory(string $dir, string $moduleName, array &$components): void
    {
        if (!is_dir($dir)) {
            return;
        }
        
        $files = scandir($dir);
        if ($files === false || !is_array($files)) {
            return;
        }
        
        foreach ($files as $file) {
            if ($file === '.' || $file === '..') {
                continue;
            }
            
            $filePath = $dir . DS . $file;
            
            if (is_dir($filePath)) {
                // 递归扫描子目录
                $this->scanDirectory($filePath, $moduleName, $components);
            } elseif (is_file($filePath) && pathinfo($filePath, PATHINFO_EXTENSION) === 'php') {
                // 检查 PHP 文件
                $this->checkComponentClass($filePath, $moduleName, $components);
            }
        }
    }
    
    /**
     * 检查类是否实现了 ComponentInterface
     * 
     * @param string $filePath 文件路径
     * @param string $moduleName 模块名
     * @param array &$components 组件数组（引用传递）
     */
    private function checkComponentClass(string $filePath, string $moduleName, array &$components): void
    {
        try {
            // 读取文件内容
            $content = file_get_contents($filePath);
            if ($content === false) {
                return;
            }
            
            // 提取命名空间和类名
            if (!preg_match('/namespace\s+([^;]+);/', $content, $namespaceMatch)) {
                return;
            }
            
            if (!preg_match('/class\s+(\w+)(?:\s+extends|\s+implements)/', $content, $classMatch)) {
                return;
            }
            
            $namespace = trim($namespaceMatch[1]);
            $className = trim($classMatch[1]);
            $fullClassName = $namespace . '\\' . $className;
            
            // 检查是否实现了 ComponentInterface
            if (!str_contains($content, 'ComponentInterface')) {
                return;
            }
            
            // 检查是否包含 implements 关键字
            if (!str_contains($content, 'implements')) {
                return;
            }
            
            // 尝试加载类并检查接口
            if (!class_exists($fullClassName, false)) {
                // 尝试自动加载
                try {
                    require_once $filePath;
                } catch (\Throwable $e) {
                    return;
                }
            }
            
            if (!class_exists($fullClassName, false)) {
                return;
            }
            
            try {
                $reflection = new \ReflectionClass($fullClassName);
                if (!$reflection->implementsInterface(ComponentInterface::class)) {
                    return;
                }
            } catch (\Throwable $e) {
                // 反射失败，跳过
                return;
            }
            
            // 获取组件文档
            $doc = '';
            try {
                $instance = ObjectManager::getInstance($fullClassName);
                if (method_exists($instance, 'doc')) {
                    $doc = $instance->doc();
                }
            } catch (\Throwable $e) {
                // 忽略实例化错误
            }
            
            // 提取简短名称（去掉命名空间前缀）
            $shortName = $className;
            $category = '其他';
            
            // 尝试从命名空间提取分类
            if (preg_match('/\\\Block\\\(.+?)(?:\\\\.*)?$/', $namespace, $categoryMatch)) {
                $categoryPath = $categoryMatch[1];
                $categoryParts = explode('\\', $categoryPath);
                $category = end($categoryParts);
            }
            
            $components[$fullClassName] = [
                'module' => $moduleName,
                'class' => $className,
                'full_class' => $fullClassName,
                'namespace' => $namespace,
                'category' => $category,
                'doc' => $doc,
                'file_path' => $filePath
            ];
        } catch (\Throwable $e) {
            // 忽略单个文件的错误，继续扫描其他文件
            w_log_error("扫描组件文件失败: {$filePath}, 错误: " . $e->getMessage());
        }
    }
    
    /**
     * 按分类组织组件
     * 
     * @param array $components 组件列表
     * @return array 按分类组织的组件
     */
    public function groupByCategory(array $components): array
    {
        $grouped = [];
        
        foreach ($components ?? [] as $class => $info) {
            $category = $info['category'] ?? '其他';
            if (!isset($grouped[$category])) {
                $grouped[$category] = [];
            }
            $grouped[$category][$class] = $info;
        }
        
        // 按分类名排序
        ksort($grouped);
        
        return $grouped;
    }
}


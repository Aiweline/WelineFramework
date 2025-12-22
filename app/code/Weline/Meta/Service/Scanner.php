<?php
declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 作者：Admin
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Meta\Service;

use Weline\Framework\App\Exception;
use Weline\Framework\Manager\ObjectManager;
use Weline\Meta\Model\Meta;

class Scanner
{
    /**
     * 扫描指定路径的文件并提取元数据
     * 
     * @param string $scanPath 扫描路径，格式：ModuleName::path，如 Weline_Theme::view/theme
     * @param string $namespace 命名空间（如果文件中有@meta.namespace，会覆盖此值）
     * @param bool $strictMode 严格模式：如果文件不符合格式要求，会抛出异常
     * @return array 扫描结果数组，包含成功和失败的文件列表
     * @throws Exception 如果strictMode=true且文件不符合格式要求
     */
    public function scanPath(string $scanPath, string $namespace = '', bool $strictMode = true): array
    {
        // 解析路径格式：ModuleName::path
        if (strpos($scanPath, '::') === false) {
            throw new Exception(__('扫描路径格式错误，应为：ModuleName::path，如：Weline_Theme::view/theme'));
        }
        
        [$moduleName, $relativePath] = explode('::', $scanPath, 2);
        
        // 获取模块路径
        $modulePath = $this->getModulePath($moduleName);
        if (!$modulePath) {
            throw new Exception(__('模块不存在：%1', $moduleName));
        }
        
        $fullPath = rtrim($modulePath, '/') . '/' . ltrim($relativePath, '/');
        if (!is_dir($fullPath)) {
            throw new Exception(__('扫描路径不存在：%1', $fullPath));
        }
        
        $results = [
            'success' => [],
            'failed' => [],
            'skipped' => []
        ];
        
        // 递归扫描目录
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($fullPath, \RecursiveDirectoryIterator::SKIP_DOTS)
        );
        
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'phtml') {
                try {
                    $meta = $this->scanFile($file->getPathname(), $namespace, null, $strictMode);
                    if ($meta) {
                        $results['success'][] = $file->getPathname();
                    } else {
                        $results['skipped'][] = $file->getPathname();
                    }
                } catch (\Exception $e) {
                    $results['failed'][] = [
                        'file' => $file->getPathname(),
                        'error' => $e->getMessage()
                    ];
                    if ($strictMode) {
                        throw $e;
                    }
                }
            }
        }
        
        return $results;
    }

    /**
     * 扫描单个文件并提取元数据
     * 
     * @param string $filePath 文件路径
     * @param string $namespace 命名空间（如果文件中有@meta.namespace，会覆盖此值）
     * @param callable|null $identifyGenerator 标识生成器回调函数
     * @param bool $strictMode 严格模式：如果文件不符合格式要求，会抛出异常
     * @return Meta|null 返回Meta对象，如果文件没有元数据则返回null
     * @throws Exception 如果strictMode=true且文件不符合格式要求
     */
    public function scanFile(string $filePath, string $namespace = '', ?callable $identifyGenerator = null, bool $strictMode = true): ?Meta
    {
        if (!file_exists($filePath)) {
            throw new Exception(__('文件不存在：%1', $filePath));
        }

        $content = file_get_contents($filePath);
        $metaData = $this->extractMetadata($content, $filePath, $strictMode, $namespace);
        
        // 如果没有元数据，返回null（不报错）
        if (empty($metaData)) {
            return null;
        }
        
        // 验证必需字段
        $this->validateMetadata($metaData, $filePath, $strictMode);
        
        // 使用文件中的namespace或传入的namespace
        $finalNamespace = $metaData['namespace'] ?? $namespace;
        if (empty($finalNamespace)) {
            if ($strictMode) {
                throw new Exception(__('文件缺少命名空间定义：%1。请添加 @meta.namespace 或通过参数传入', $filePath));
            }
            return null;
        }

        // 生成标识
        $identify = $identifyGenerator ? $identifyGenerator($filePath, $metaData) : ($metaData['identify'] ?? $this->generateIdentify($filePath));
        
        // 创建或更新元数据
        /** @var Meta $meta */
        $meta = ObjectManager::getInstance(Meta::class);
        $meta->where(Meta::fields_NAMESPACE, $finalNamespace)
             ->where(Meta::fields_META_TYPE, $metaData['type'])
             ->where(Meta::fields_META_IDENTIFY, $identify)
             ->fetch();

        $meta->setData(Meta::fields_NAMESPACE, $finalNamespace);
        $meta->setData(Meta::fields_META_TYPE, $metaData['type']);
        $meta->setData(Meta::fields_META_IDENTIFY, $identify);
        $meta->setData(Meta::fields_FILE_PATH, $this->getRelativePath($filePath));
        $meta->setData(Meta::fields_FILE_FULL_PATH, $filePath);
        $meta->setData(Meta::fields_AREA, $metaData['area'] ?? null);
        $meta->setData(Meta::fields_CATEGORY, $metaData['category'] ?? null);
        $meta->setData(Meta::fields_META_DATA, json_encode($metaData, JSON_UNESCAPED_UNICODE));

        $meta->saveMeta($metaData);
        
        return $meta;
    }

    /**
     * 提取元数据（严格验证格式）
     */
    protected function extractMetadata(string $content, string $filePath, bool $strictMode, string $defaultNamespace = ''): array
    {
        $metaData = [];
        
        // 检查是否有元数据区域标识
        $hasMetaBlock = preg_match('/元数据区域|Metadata Block/', $content);
        
        // 第一步：提取 @meta.namespace（必须先提取，因为后续字段依赖命名空间）
        $namespace = $defaultNamespace;
        if (preg_match('/@meta\.namespace\s+(\S+)/', $content, $matches)) {
            $namespace = trim($matches[1]);
            $metaData['namespace'] = $namespace;
        } elseif (empty($namespace)) {
            // 如果没有命名空间且严格模式，报错
            if ($strictMode && $hasMetaBlock) {
                throw new Exception(__('文件缺少必需字段 @meta.namespace：%1', $filePath));
            }
            return []; // 没有命名空间，无法继续提取
        }
        
        // 第二步：使用命名空间提取其他字段
        // 提取 @meta.{namespace}.type（必需）
        if (preg_match('/@meta\.' . preg_quote($namespace, '/') . '\.type\s+(\S+)/', $content, $matches)) {
            $metaData['type'] = trim($matches[1]);
        } elseif ($strictMode && $hasMetaBlock) {
            throw new Exception(__('文件缺少必需字段 @meta.%1.type：%2', $namespace, $filePath));
        }
        
        // 提取 @meta.{namespace}.area
        if (preg_match('/@meta\.' . preg_quote($namespace, '/') . '\.area\s+(\S+)/', $content, $matches)) {
            $metaData['area'] = trim($matches[1]);
        }
        
        // 提取 @meta.{namespace}.category
        if (preg_match('/@meta\.' . preg_quote($namespace, '/') . '\.category\s+(\S+)/', $content, $matches)) {
            $metaData['category'] = trim($matches[1]);
        }
        
        // 提取 @meta.{namespace}.identify
        if (preg_match('/@meta\.' . preg_quote($namespace, '/') . '\.identify\s+(\S+)/', $content, $matches)) {
            $metaData['identify'] = trim($matches[1]);
        }
        
        // 提取 @meta.{namespace}.info.* 字段（至少需要name和description）
        if (preg_match_all('/@meta\.' . preg_quote($namespace, '/') . '\.info\.(\w+)\s+(.+)/', $content, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $field = trim($match[1]);
                $value = trim($match[2]);
                // 提取值（格式：名称：值 或 描述：值）
                if (preg_match('/^[^：:]+[：:]\s*(.+)$/u', $value, $valueMatch)) {
                    $metaData['info'][$field] = trim($valueMatch[1]);
                } else {
                    $metaData['info'][$field] = $value;
                }
            }
        }
        
        // 严格模式：如果有元数据区域标识，必须至少有一个info字段
        if ($strictMode && $hasMetaBlock && empty($metaData['info'])) {
            throw new Exception(__('文件定义了元数据区域但缺少 @meta.%1.info.* 字段：%2', $namespace, $filePath));
        }
        
        return $metaData;
    }

    /**
     * 验证元数据格式
     */
    protected function validateMetadata(array $metaData, string $filePath, bool $strictMode): void
    {
        if ($strictMode) {
            // 必需字段验证
            if (empty($metaData['type'])) {
                throw new Exception(__('元数据缺少必需字段 type：%1', $filePath));
            }
            
            // info字段至少需要name
            if (empty($metaData['info']['name'])) {
                $namespace = $metaData['namespace'] ?? 'unknown';
                throw new Exception(__('元数据缺少必需字段 @meta.%1.info.name：%2', $namespace, $filePath));
            }
        }
    }

    /**
     * 获取模块路径
     */
    protected function getModulePath(string $moduleName): ?string
    {
        $moduleManager = ObjectManager::getInstance(\Weline\Framework\Module\Model\Module::class);
        $module = $moduleManager->load('name', $moduleName);
        return $module->getId() ? $module->getPath() : null;
    }

    /**
     * 生成标识（从文件路径）
     */
    protected function generateIdentify(string $filePath): string
    {
        $basename = basename($filePath, '.phtml');
        return str_replace(['/', '\\'], '_', $basename);
    }

    /**
     * 获取相对路径
     */
    protected function getRelativePath(string $filePath): string
    {
        $rootPath = BP . '/';
        if (strpos($filePath, $rootPath) === 0) {
            return substr($filePath, strlen($rootPath));
        }
        return $filePath;
    }
}


<?php
declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Theme\Observer;

use Weline\Framework\App\Env;
use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\RuntimeProviderResolver;
use Weline\Meta\Api\Data\MetadataIdentity;
use Weline\Meta\Api\Data\MetadataWrite;
use Weline\Meta\Api\MetadataRepositoryInterface;
use Weline\Theme\Helper\CssVariableParser;

/**
 * Meta扫描变量文件Observer
 * 
 * 监听 Weline_Meta::scan_path 事件
 * 当扫描路径包含 variables/ 目录时，解析所有 _*.css 变量文件
 * 为每个变量创建Meta记录
 */
class MetaScanVariableFiles implements ObserverInterface
{
    /**
     * 执行观察者逻辑
     * 
     * @param Event $event
     * @return void
     */
    public function execute(Event &$event): void
    {
        $scanPath = $event->getData('scan_path');
        $namespace = $event->getData('namespace') ?? 'theme';
        
        // 检查是否包含variables目录
        if (strpos($scanPath, 'variables') === false) {
            return;
        }
        
        // 解析扫描路径
        if (strpos($scanPath, '::') === false) {
            return;
        }
        
        [$moduleName, $relativePath] = explode('::', $scanPath, 2);
        
        // 获取模块路径
        $modules = Env::getInstance()->getModuleList();
        if (!isset($modules[$moduleName])) {
            return;
        }
        
        $modulePath = $modules[$moduleName]['base_path'];
        $fullPath = rtrim($modulePath, DS) . DS . ltrim($relativePath, DS);
        
        // 检查是否是variables目录
        if (strpos($relativePath, 'variables') === false) {
            return;
        }
        
        // 提取area（frontend/backend）
        $area = $this->extractAreaFromPath($relativePath);
        if (empty($area)) {
            $area = 'frontend'; // 默认使用frontend
        }
        
        // 扫描变量文件
        $variableFiles = $this->findVariableFiles($fullPath);
        
        foreach ($variableFiles as $filePath) {
            try {
                $variables = CssVariableParser::parseFile($filePath);
                $this->saveVariablesToMeta($variables, $filePath, $area, $namespace);
            } catch (\Exception $e) {
                // 记录错误但不阻止扫描
                if (defined('DEV') && DEV) {
                    w_log_error('解析变量文件失败: ' . $filePath . ' - ' . $e->getMessage());
                }
            }
        }
    }
    
    /**
     * 从路径中提取area
     * 
     * @param string $relativePath 相对路径
     * @return string area（frontend/backend）
     */
    private function extractAreaFromPath(string $relativePath): string
    {
        if (preg_match('/theme[\/\\\\](frontend|backend)[\/\\\\]variables/', $relativePath, $matches)) {
            return $matches[1];
        }
        
        return '';
    }
    
    /**
     * 查找所有变量文件
     * 
     * @param string $path 扫描路径
     * @return array 文件路径数组
     */
    private function findVariableFiles(string $path): array
    {
        $files = [];
        
        if (!is_dir($path)) {
            return $files;
        }
        
        // 扫描所有以_开头的CSS文件
        $pattern = rtrim($path, DS) . DS . '_*.css';
        $foundFiles = glob($pattern);
        
        if ($foundFiles) {
            $files = array_merge($files, $foundFiles);
        }
        
        return $files;
    }
    
    /**
     * 将变量保存到Meta系统
     * 
     * @param array $variables 变量数组
     * @param string $filePath 文件路径
     * @param string $area 区域
     * @param string $namespace 命名空间
     * @return void
     */
    private function saveVariablesToMeta(array $variables, string $filePath, string $area, string $namespace): void
    {
        $writes = [];
        foreach ($variables as $variable) {
            $variableName = $variable['variable_name']; // 包含--前缀
            $variableNameWithoutPrefix = substr($variableName, 2); // 移除--前缀
            $variableFile = $variable['file'];
            
            // 构建meta_identify：theme.{area}.variables.{file}.{varName}
            $metaIdentify = "{$namespace}.{$area}.variables.{$variableFile}.{$variableNameWithoutPrefix}";
            
            // 准备meta_data
            $metaDataArray = [
                'variable_name' => $variableName,
                'variable_type' => $variable['variable_type'],
                'default_value' => $variable['default_value'],
                'category' => $variable['category'],
                'description' => $variable['description'],
                'is_color' => $variable['is_color'],
                'file' => $variableFile,
                'name' => ucfirst(str_replace(['-', '_'], ' ', $variableNameWithoutPrefix)),
                'info' => [
                    'name' => ucfirst(str_replace(['-', '_'], ' ', $variableNameWithoutPrefix)),
                    'description' => $variable['description']
                ]
            ];
            
            // 准备setting（参数定义）
            $inputType = $this->getInputType($variable['variable_type'], $variable['is_color']);
            $setting = [
                'param' => [
                    'value' => [
                        'name' => '变量值',
                        'description' => $variable['description'] ?: 'CSS变量值',
                        'type' => $variable['variable_type'],
                        'input' => $inputType,
                        'default' => $variable['default_value']
                    ]
                ]
            ];
            
            $writes[] = new MetadataWrite(
                identity: new MetadataIdentity($namespace, 'variables', $metaIdentify),
                metaData: $metaDataArray,
                setting: $setting,
                filePath: $this->getRelativePath($filePath),
                fileFullPath: $filePath,
                area: $area,
                category: $variableFile,
            );
        }

        if ($writes !== []) {
            $this->metadataRepository()->upsertBatch($writes);
        }
    }

    private function metadataRepository(): MetadataRepositoryInterface
    {
        $repository = ObjectManager::getInstance(RuntimeProviderResolver::class)
            ->resolve(MetadataRepositoryInterface::class);
        if (!$repository instanceof MetadataRepositoryInterface) {
            throw new \RuntimeException('Weline_Meta metadata repository provider is unavailable.');
        }

        return $repository;
    }
    
    /**
     * 根据类型获取输入类型
     * 
     * @param string $type 变量类型
     * @param bool $isColor 是否为颜色值
     * @return string 输入类型
     */
    private function getInputType(string $type, bool $isColor): string
    {
        if ($isColor || $type === 'color') {
            return 'color';
        }
        
        if ($type === 'spacing') {
            return 'text'; // 可以是text或number，根据实际情况
        }
        
        return 'text';
    }
    
    /**
     * 获取相对路径
     * 
     * @param string $filePath 完整文件路径
     * @return string 相对路径
     */
    private function getRelativePath(string $filePath): string
    {
        $rootPath = BP . DS;
        if (strpos($filePath, $rootPath) === 0) {
            return substr($filePath, strlen($rootPath));
        }
        return $filePath;
    }
}

<?php
declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Theme\Helper;

use Weline\Framework\App\Env;
use Weline\Framework\Manager\ObjectManager;
use Weline\Meta\Helper\MetaData;
use Weline\Meta\Model\Meta;
use Weline\Theme\Model\WelineTheme;

/**
 * CSS变量扫描器
 * 
 * 扫描variables目录下的CSS文件，提取CSS变量定义并注册到Meta系统
 */
class CssVariableScanner
{
    /**
     * 扫描指定区域的所有CSS变量
     * 
     * @param string $area 区域（frontend/backend）
     * @param WelineTheme|null $theme 主题对象，如果为null则使用当前激活主题
     * @return array 扫描结果数组
     */
    public function scanVariables(string $area, ?WelineTheme $theme = null): array
    {
        $area = strtolower($area);
        $results = [];
        
        // 获取主题
        if ($theme === null) {
            $theme = ObjectManager::getInstance(WelineTheme::class);
            $theme = $theme->getActiveTheme($area);
        }
        
        if (!$theme || !$theme->getId()) {
            return $results;
        }
        
        // 获取variables目录路径
        $variablesDirs = $this->getVariablesDirectories($theme, $area);
        
        foreach ($variablesDirs as $variablesDir) {
            if (!is_dir($variablesDir)) {
                continue;
            }
            
            // 扫描所有以_开头的CSS文件
            $variableFiles = glob($variablesDir . DS . '_*.css');
            
            foreach ($variableFiles as $filePath) {
                $fileName = basename($filePath, '.css');
                $variableFile = substr($fileName, 1); // 移除下划线前缀
                
                if (empty($variableFile)) {
                    continue;
                }
                
                // 提取变量
                $variables = $this->extractVariablesFromCss($filePath, $variableFile);
                
                foreach ($variables as $variable) {
                    // 注册到Meta系统
                    $this->registerVariableToMeta($area, $variableFile, $variable, $theme);
                    
                    $results[] = [
                        'file' => $variableFile,
                        'variable' => $variable['name'],
                        'value' => $variable['value'],
                        'category' => $variable['category'] ?? '其他'
                    ];
                }
            }
        }
        
        return $results;
    }
    
    /**
     * 获取variables目录路径（支持主题继承）
     * 
     * @param WelineTheme $theme 主题对象
     * @param string $area 区域
     * @return array 目录路径数组
     */
    private function getVariablesDirectories(WelineTheme $theme, string $area): array
    {
        $directories = [];
        
        // 1. 当前主题的variables目录
        $themePath = $theme->getPath();
        if (!empty($themePath)) {
            $themeVariablesDir = rtrim($themePath, DS) . DS . 'view' . DS . 'theme' . DS . $area . DS . 'variables';
            if (is_dir($themeVariablesDir)) {
                $directories[] = $themeVariablesDir;
            }
        }
        
        // 2. 父主题的variables目录（递归）
        $parentTheme = $theme->getParentTheme();
        if ($parentTheme) {
            $parentDirs = $this->getVariablesDirectories($parentTheme, $area);
            $directories = array_merge($directories, $parentDirs);
        }
        
        // 3. 默认主题（Weline_Theme模块）的variables目录
        $modules = Env::getInstance()->getModuleList();
        if (isset($modules['Weline_Theme'])) {
            $themeModule = $modules['Weline_Theme'];
            $defaultVariablesDir = rtrim($themeModule['base_path'], DS) . DS . 'view' . DS . 'theme' . DS . $area . DS . 'variables';
            if (is_dir($defaultVariablesDir)) {
                $directories[] = $defaultVariablesDir;
            }
        }
        
        return array_unique($directories);
    }
    
    /**
     * 从CSS文件中提取变量定义
     * 
     * @param string $filePath CSS文件路径
     * @param string $variableFile 变量文件名（不含下划线和扩展名）
     * @return array 变量数组
     */
    public function extractVariablesFromCss(string $filePath, string $variableFile): array
    {
        if (!is_file($filePath)) {
            return [];
        }
        
        $content = file_get_contents($filePath);
        $variables = [];
        $currentCategory = '其他';
        
        // 提取@meta信息
        $metaInfo = $this->extractMetaInfo($content);
        
        // 按行处理
        $lines = explode("\n", $content);
        
        foreach ($lines as $line) {
            $line = trim($line);
            
            // 检查是否是分类注释
            if (preg_match('/\/\*\s*={3,}\s*([^=]+)\s*={3,}/', $line, $matches)) {
                $currentCategory = trim($matches[1]);
                continue;
            }
            
            // 匹配CSS变量定义：--variable-name: value;
            if (preg_match('/--([\w-]+)\s*:\s*([^;]+);/', $line, $matches)) {
                $variableName = $matches[1];
                $variableValue = trim($matches[2]);
                
                // 提取变量描述（从注释中）
                $description = $this->extractVariableDescription($line, $content, $variableName);
                
                // 判断变量类型
                $type = $this->detectVariableType($variableValue, $variableFile);
                
                $variables[] = [
                    'name' => $variableName,
                    'value' => $variableValue,
                    'category' => $currentCategory,
                    'description' => $description,
                    'type' => $type,
                    'file' => $variableFile,
                    'meta' => $metaInfo
                ];
            }
        }
        
        return $variables;
    }
    
    /**
     * 提取@meta信息
     * 
     * @param string $content CSS文件内容
     * @return array meta信息
     */
    private function extractMetaInfo(string $content): array
    {
        $meta = [
            'name' => '',
            'description' => ''
        ];
        
        // 提取 @meta.name
        if (preg_match('/@meta\.name\s*\{[^}]*default=["\']([^"\']+)["\']/', $content, $matches)) {
            $meta['name'] = trim($matches[1]);
        } elseif (preg_match('/@meta\.name\s*\{[^}]*name=["\']([^"\']+)["\']/', $content, $matches)) {
            $meta['name'] = trim($matches[1]);
        }
        
        // 提取 @meta.description
        if (preg_match('/@meta\.description\s*\{[^}]*default=["\']([^"\']+)["\']/', $content, $matches)) {
            $meta['description'] = trim($matches[1]);
        } elseif (preg_match('/@meta\.description\s*\{[^}]*name=["\']([^"\']+)["\']/', $content, $matches)) {
            $meta['description'] = trim($matches[1]);
        }
        
        return $meta;
    }
    
    /**
     * 提取变量描述
     * 
     * @param string $line 当前行
     * @param string $content 完整内容
     * @param string $variableName 变量名
     * @return string 描述
     */
    private function extractVariableDescription(string $line, string $content, string $variableName): string
    {
        // 尝试从行内注释提取
        if (preg_match('/\/\*\s*(.+?)\s*\*\//', $line, $matches)) {
            return trim($matches[1]);
        }
        
        // 尝试从变量上方的注释提取
        $lines = explode("\n", $content);
        $lineIndex = array_search($line, $lines);
        if ($lineIndex !== false && $lineIndex > 0) {
            $prevLine = trim($lines[$lineIndex - 1]);
            if (preg_match('/\/\*\s*(.+?)\s*\*\//', $prevLine, $matches)) {
                return trim($matches[1]);
            }
        }
        
        return '';
    }
    
    /**
     * 检测变量类型
     * 
     * @param string $value 变量值
     * @param string $variableFile 变量文件名
     * @return string 类型（color, spacing, typography, shadow, border等）
     */
    private function detectVariableType(string $value, string $variableFile): string
    {
        // 根据文件名判断
        $fileTypeMap = [
            'colors' => 'color',
            'spacing' => 'spacing',
            'typography' => 'typography',
            'shadows' => 'shadow',
            'borders' => 'border'
        ];
        
        if (isset($fileTypeMap[$variableFile])) {
            return $fileTypeMap[$variableFile];
        }
        
        // 根据值判断
        $value = trim($value);
        
        // 颜色值
        if (preg_match('/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', $value) ||
            preg_match('/^rgba?\(/', $value) ||
            preg_match('/^hsla?\(/', $value)) {
            return 'color';
        }
        
        // 间距值（通常包含rem, em, px等）
        if (preg_match('/^\d+(\.\d+)?(rem|em|px|%)$/', $value)) {
            return 'spacing';
        }
        
        // 字体相关
        if (preg_match('/font|family|size|weight|line-height/i', $variableFile)) {
            return 'typography';
        }
        
        return 'text';
    }
    
    /**
     * 将变量注册到Meta系统
     * 
     * @param string $area 区域
     * @param string $variableFile 变量文件名
     * @param array $variableData 变量数据
     * @param WelineTheme $theme 主题对象
     * @return void
     */
    public function registerVariableToMeta(string $area, string $variableFile, array $variableData, WelineTheme $theme): void
    {
        $variableName = $variableData['name'];
        $variableValue = $variableData['value'];
        $category = $variableData['category'] ?? '其他';
        $description = $variableData['description'] ?? '';
        $type = $variableData['type'] ?? 'text';
        $metaInfo = $variableData['meta'] ?? [];
        
        // 构建meta_identify
        $metaIdentify = "theme.{$area}.variables.{$variableFile}.{$variableName}";
        
        // 准备meta_data
        $metaDataArray = [
            'name' => $metaInfo['name'] ?? ucfirst(str_replace('-', ' ', $variableName)),
            'description' => $description ?: ($metaInfo['description'] ?? ''),
            'category' => $category,
            'type' => $type,
            'default' => $variableValue,
            'variable_file' => $variableFile,
            'variable_name' => $variableName
        ];
        
        // 准备setting（参数定义）
        $inputType = $this->getInputType($type, $variableValue);
        $setting = [
            'param' => [
                'value' => [
                    'name' => '变量值',
                    'description' => $description,
                    'type' => $type,
                    'input' => $inputType,
                    'default' => $variableValue
                ]
            ]
        ];
        
        // 使用 forceCheck 自动处理插入或更新，避免唯一约束冲突
        /** @var Meta $metaModel */
        $metaModel = ObjectManager::make(Meta::class);
        $metaModel->reset();
        $metaModel->setData(Meta::schema_fields_NAMESPACE, 'theme');
        $metaModel->setData(Meta::schema_fields_META_TYPE, 'variables');
        $metaModel->setData(Meta::schema_fields_META_IDENTIFY, $metaIdentify);
        $metaModel->setData(Meta::schema_fields_META_DATA, json_encode($metaDataArray, JSON_UNESCAPED_UNICODE));
        $metaModel->setData(Meta::schema_fields_SETTING, json_encode($setting, JSON_UNESCAPED_UNICODE));
        $metaModel->setData(Meta::schema_fields_AREA, $area);
        $metaModel->setData(Meta::schema_fields_CATEGORY, $variableFile);
        
        // 使用 forceCheck 确保唯一键检查，如果已存在则更新，不存在则插入
        // 这样可以避免并发情况下的唯一约束冲突
        $metaModel->forceCheck(true, [Meta::schema_fields_NAMESPACE, Meta::schema_fields_META_TYPE, Meta::schema_fields_META_IDENTIFY])
                ->save();
    }
    
    /**
     * 根据类型获取输入类型
     * 
     * @param string $type 变量类型
     * @param string $value 变量值
     * @return string 输入类型
     */
    private function getInputType(string $type, string $value): string
    {
        if ($type === 'color') {
            return 'color';
        }
        
        if ($type === 'spacing') {
            return 'text'; // 可以是text或number，根据实际情况
        }
        
        if ($type === 'typography') {
            return 'text';
        }
        
        // 根据值判断
        if (preg_match('/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', $value) ||
            preg_match('/^rgba?\(/', $value)) {
            return 'color';
        }
        
        if (preg_match('/^\d+(\.\d+)?(rem|em|px|%)$/', $value)) {
            return 'text';
        }
        
        return 'text';
    }
}


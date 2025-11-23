<?php
declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\I18n\Helper;

use Weline\Framework\App\Env;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\View\Template;
use Weline\I18n\Service\TranslationCollector;

/**
 * JS翻译词提取器
 * 用于从 weline.modules.js 配置和 JS 文件中提取翻译词
 */
class JsTranslationsExtractor
{
    /**
     * 解析 weline.modules.js 文件，提取模块配置
     * 
     * @param string $area 区域：frontend 或 backend
     * @return array 模块配置数组，格式：['moduleName' => ['paths' => [...], 'globalVar' => '...']]
     */
    public static function parseModulesConfig(string $area = 'frontend'): array
    {
        $modules = [];
        
        try {
            // 读取所有模块的 weline.modules.js 文件
            /**@var \Weline\Theme\Config\Reader\WelineModules $reader */
            $reader = ObjectManager::getInstance(\Weline\Theme\Config\Reader\WelineModules::class);
            $configResources = $reader->getResourceFiles();
            
            if (!isset($configResources[$area])) {
                return $modules;
            }
            
            $content = $configResources[$area];
            
            // 使用正则表达式提取模块配置
            // 匹配格式：moduleName: { paths: [...], globalVar: "..." }
            if (preg_match_all('/Object\.assign\(window\.WelineModulesConfig\.modules,\s*\{([^}]+)\}\)/s', $content, $assignMatches)) {
                foreach ($assignMatches[1] as $assignContent) {
                    // 提取每个模块的定义（支持多行）
                    if (preg_match_all('/(\w+):\s*\{([^}]+)\}/s', $assignContent, $moduleMatches, PREG_SET_ORDER)) {
                        foreach ($moduleMatches as $moduleMatch) {
                            $moduleName = $moduleMatch[1];
                            $moduleConfig = $moduleMatch[2];
                            
                            $moduleData = [
                                'paths' => [],
                                'globalVar' => null,
                            ];
                            
                            // 提取 paths 数组（支持多行）
                            if (preg_match('/paths:\s*\[([^\]]+)\]/s', $moduleConfig, $pathsMatch)) {
                                $pathsStr = $pathsMatch[1];
                                // 提取所有路径（支持单引号、双引号）
                                if (preg_match_all('/["\']([^"\']+)["\']/', $pathsStr, $pathMatches)) {
                                    $moduleData['paths'] = $pathMatches[1];
                                }
                            }
                            
                            // 提取 globalVar
                            if (preg_match('/globalVar:\s*["\']([^"\']+)["\']/', $moduleConfig, $globalVarMatch)) {
                                $moduleData['globalVar'] = $globalVarMatch[1];
                            }
                            
                            if (!empty($moduleData['paths'])) {
                                $modules[$moduleName] = $moduleData;
                            }
                        }
                    }
                }
            }
        } catch (\Exception $e) {
            error_log('解析 weline.modules.js 失败: ' . $e->getMessage());
        }
        
        return $modules;
    }
    
    /**
     * 将模块路径转换为实际文件路径
     * 
     * @param string $modulePath 模块路径，如 "Weline_Frontend::libs/vue/vue2.6.11.js" 或 "/static/..."
     * @return string|null 实际文件路径，如果无法解析则返回 null
     */
    public static function resolveModulePath(string $modulePath): ?string
    {
        // 如果是完整URL（CDN资源），跳过
        if (strpos($modulePath, 'http://') === 0 || strpos($modulePath, 'https://') === 0) {
            return null;
        }
        
        // 只解析 Vendor_Module::path/to/file.js 格式
        if (strpos($modulePath, '::') !== false) {
            return null;
        }
        
        return null;
    }
    
    /**
     * 从JS文件中提取翻译词
     * 使用统一的 TranslationCollector 收集服务
     * 
     * @param string $filePath JS文件路径
     * @return array 翻译词数组 [原文 => 原文]
     */
    public static function extractWordsFromJsFile(string $filePath): array
    {
        if (!is_file($filePath)) {
            return [];
        }
        
        // 使用统一的收集服务
        $collector = ObjectManager::getInstance(TranslationCollector::class);
        
        // 读取文件内容
        $content = file_get_contents($filePath);
        $words = [];
        
        // 使用与收集器相同的提取逻辑
        // 1. 匹配 <lang>...</lang> 标签（JS中可能通过模板字符串使用）
        if (preg_match_all('/<lang>(.*?)<\/lang>/s', $content, $matches)) {
            foreach ($matches[1] as $match) {
                $match = trim($match);
                if (!empty($match) && $collector->isValidTranslationString($match)) {
                    $words[$match] = $match;
                }
            }
        }
        
        // 2. 匹配 @lang(...) 格式
        if (preg_match_all('/@lang\s*\(\s*(["\'])((?:[^\\\\\1\n\r]|\\\\.)*?)\1\s*\)/s', $content, $matches, PREG_OFFSET_CAPTURE)) {
            foreach ($matches[2] as $index => $matchData) {
                $match = $matchData[0];
                $match = str_replace(['\\"', "\\'", '\\\\'], ['"', "'", '\\'], $match);
                $match = trim($match);
                if (!empty($match) && $collector->isValidTranslationString($match)) {
                    $words[$match] = $match;
                }
            }
        }
        
        // 3. 匹配 @lang{...} 格式
        if (preg_match_all('/@lang\{(.*?)}/s', $content, $matches)) {
            foreach ($matches[1] as $match) {
                $match = trim($match);
                if (!empty($match) && $collector->isValidTranslationString($match)) {
                    $words[$match] = $match;
                }
            }
        }
        
        // 4. 匹配 __('...') 或 __("...") 格式（JS中常用的翻译函数）
        if (preg_match_all('/__\s*\(\s*(["\'])((?:[^\\\\\1\n\r]|\\\\.)*?)\1\s*([,\\)])/', $content, $matches, PREG_OFFSET_CAPTURE)) {
            foreach ($matches[2] as $index => $matchData) {
                $match = $matchData[0];
                $quoteChar = $matches[1][$index][0];
                
                // 验证引号闭合
                $escapedQuote = '\\' . $quoteChar;
                $quoteCount = substr_count($match, $quoteChar);
                $escapedQuoteCount = substr_count($match, $escapedQuote);
                $realQuoteCount = $quoteCount - $escapedQuoteCount;
                
                if ($realQuoteCount > 0) {
                    continue;
                }
                
                $match = str_replace(['\\"', "\\'", '\\\\'], ['"', "'", '\\'], $match);
                $match = trim($match);
                if (!empty($match) && $collector->isValidTranslationString($match)) {
                    $words[$match] = $match;
                }
            }
        }
        
        // 5. 匹配多行 __() 格式
        if (preg_match_all('/__\s*\(\s*(["\'])((?:(?<!\\\\)(?:\\\\\\\\)*\\\\\1|(?!\1).)*?)\1\s*([,\\)])/s', $content, $matches, PREG_OFFSET_CAPTURE)) {
            foreach ($matches[2] as $index => $matchData) {
                $match = $matchData[0];
                $quoteChar = $matches[1][$index][0];
                
                $escapedQuote = '\\' . $quoteChar;
                $quoteCount = substr_count($match, $quoteChar);
                $escapedQuoteCount = substr_count($match, $escapedQuote);
                $realQuoteCount = $quoteCount - $escapedQuoteCount;
                
                if ($realQuoteCount > 0) {
                    continue;
                }
                
                $match = str_replace(['\\"', "\\'", '\\\\'], ['"', "'", '\\'], $match);
                $match = trim($match);
                
                if (strpos($match, "\n") !== false) {
                    if (preg_match('/\$[a-zA-Z_]|->|::|\[.*\$|\(.*\$/', $match)) {
                        continue;
                    }
                }
                
                if (!empty($match) && strlen($match) <= 200 && $collector->isValidTranslationString($match)) {
                    $words[$match] = $match;
                }
            }
        }
        
        // 6. 匹配 phrase() 调用（JS中可能使用的翻译函数）
        if (preg_match_all('/phrase\s*\(\s*(["\'])((?:[^\\\\\1\n\r]|\\\\.)*?)\1\s*([,\\)])/', $content, $matches, PREG_OFFSET_CAPTURE)) {
            foreach ($matches[2] as $index => $matchData) {
                $match = $matchData[0];
                $match = str_replace(['\\"', "\\'", '\\\\'], ['"', "'", '\\'], $match);
                $match = trim($match);
                if (!empty($match) && $collector->isValidTranslationString($match)) {
                    $words[$match] = $match;
                }
            }
        }
        
        return $words;
    }
    
    /**
     * 从文件路径中提取模块名
     * 
     * @param string $filePath 文件路径
     * @return string 模块名
     */
    private static function extractModuleNameFromPath(string $filePath): string
    {
        // 尝试从路径中提取模块名
        // 例如：app/code/Weline/Frontend/view/statics/js/file.js -> Weline_Frontend
        if (preg_match('#app/code/([^/]+)/([^/]+)/#', $filePath, $matches)) {
            return $matches[1] . '_' . $matches[2];
        }
        
        // 如果无法提取，返回默认值
        return 'Unknown';
    }
    
    /**
     * 根据模块名列表，提取这些模块JS文件中的翻译词
     * 
     * @param array $moduleNames 模块名数组，如 ['jquery', 'vue']
     * @param string $area 区域：frontend 或 backend
     * @return array 翻译词数组
     */
    public static function extractWordsFromModules(array $moduleNames, string $area = 'frontend'): array
    {
        $allWords = [];
        
        if (empty($moduleNames)) {
            return $allWords;
        }
        
        // 1. 解析模块配置
        $modules = self::parseModulesConfig($area);
        
        // 2. 遍历指定的模块，解析每个模块的JS文件
        foreach ($moduleNames as $moduleName) {
            if (!isset($modules[$moduleName])) {
                continue;
            }
            
            $moduleConfig = $modules[$moduleName];
            if (!isset($moduleConfig['paths'])) {
                continue;
            }
            
            foreach ($moduleConfig['paths'] as $modulePath) {
                $realPath = self::resolveModulePath($modulePath);
                if ($realPath) {
                    $words = self::extractWordsFromJsFile($realPath);
                    $allWords = array_merge($allWords, $words);
                }
            }
        }
        
        return $allWords;
    }
}


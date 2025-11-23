<?php
declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\I18n\Observer;

use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\I18n\Helper\JsTranslationsExtractor;
use Weline\I18n\Model\I18n;

/**
 * 资源编译观察者
 * 在编译 weline.modules.js 时收集JS文件中的翻译词，并生成翻译对象和__()函数
 */
class ResourceCompiler implements ObserverInterface
{
    public function execute(Event &$event): void
    {
        $eventData = $event->getEvenData();
        $type = $eventData->getData('type');
        $resources = $eventData->getData('resources');
        // 只处理 weline.modules.js 类型的资源
        if ($type !== 'weline.modules.js' || empty($resources) || !is_array($resources)) {
            return;
        }
        
        try {
            // compiler_after 事件的 resources 是按 area 分组的数组
            // 格式：['frontend' => '内容', 'backend' => '内容']
            $allJsWords = [];
            $modulesMap = []; // 用于存储模块映射数据，格式：['frontend' => ['模块名' => ['paths' => [...], 'i18n' => [...]]], ...]
            
            // 遍历所有区域的资源
            foreach ($resources as $area => $areaResources) {
                if (empty($areaResources)) {
                    continue;
                }
                
                // 确保 resources 是字符串
                if (!is_string($areaResources)) {
                    continue;
                }
                
                // 从资源内容中提取模块配置
                $modules = $this->extractModulesFromResources($areaResources);
                if (empty($modules)) {
                    continue;
                }
                
                // 收集模块映射数据
                $areaModulesMap = [];
                $basePathNormalized = str_replace('\\', '/', rtrim(BP, '/\\'));
                
                // 从模块的JS文件中提取翻译词
                foreach ($modules as $moduleName => $moduleConfig) {
                    if (isset($moduleConfig['paths']) && is_array($moduleConfig['paths'])) {
                        $modulePaths = [];
                        $moduleWords = []; // 收集该模块的翻译词
                        
                        foreach ($moduleConfig['paths'] as $modulePath) {
                            // 跳过CDN资源
                            if (strpos($modulePath, 'http://') === 0 || strpos($modulePath, 'https://') === 0) {
                                continue;
                            }
                            
                            // 解析模块路径为相对根目录的路径（用于模块映射）
                            $originPath = $this->resolveModulePathToOriginPath($modulePath, $basePathNormalized);
                            if ($originPath) {
                                $modulePaths[] = $originPath;
                            }
                            
                            // 解析模块路径为实际文件路径（用于提取翻译词）
                            $filePath = $this->resolvePathToFile($modulePath);
                            if ($filePath && is_file($filePath)) {
                                // 从JS文件中提取翻译词
                                $words = JsTranslationsExtractor::extractWordsFromJsFile($filePath);
                                $allJsWords = array_merge($allJsWords, $words);
                                // 收集该模块的翻译词
                                $moduleWords = array_merge($moduleWords, $words);
                            }
                        }
                        
                        // 如果模块有路径，添加到映射中
                        if (!empty($modulePaths)) {
                            $areaModulesMap[$moduleName] = [
                                'paths' => $modulePaths,
                                'i18n' => $moduleWords // 先保存原始翻译词，后面会替换为翻译后的
                            ];
                        }
                    }
                }
                
                // 保存该区域的模块映射
                if (!empty($areaModulesMap)) {
                    $modulesMap[$area] = $areaModulesMap;
                }
            }
            
            // 获取翻译并更新模块映射中的 i18n 数据
            if (!empty($allJsWords)) {
                // 获取当前语言的翻译
                $locale = $_SERVER['WELINE_USER_LANG'] ?? $_SERVER['WELINE_WEBSITE_LANGUAGE'] ?? 'zh_Hans_CN';
                $translations = $this->getTranslations($allJsWords, $locale);
                
                // 更新每个区域的模块映射中的 i18n 数据
                foreach ($modulesMap as $area => $areaModules) {
                    foreach ($areaModules as $moduleName => $moduleData) {
                        if (isset($moduleData['i18n']) && is_array($moduleData['i18n'])) {
                            $moduleTranslations = [];
                            foreach ($moduleData['i18n'] as $word => $original) {
                                // 从翻译字典中获取翻译，如果没有则使用原文
                                $moduleTranslations[$word] = $translations[$word] ?? $word;
                            }
                            $modulesMap[$area][$moduleName]['i18n'] = $moduleTranslations;
                        }
                    }
                }
            }
            
            // 生成模块映射 JSON 文件（此时 i18n 数据已经包含翻译）
            $this->generateModulesMapJson($modulesMap);
            
            if (empty($allJsWords)) {
                return;
            }
            
            // 获取当前语言的翻译（如果上面没有获取，这里再获取一次）
            if (!isset($translations)) {
                $locale = $_SERVER['WELINE_USER_LANG'] ?? $_SERVER['WELINE_WEBSITE_LANGUAGE'] ?? 'zh_Hans_CN';
                $translations = $this->getTranslations($allJsWords, $locale);
            }
            
            // 生成翻译对象和__()函数的JavaScript代码
            $translationCode = $this->generateTranslationCode($translations);
            
            // 将翻译代码追加到每个区域的资源内容中
            $updatedResources = [];
            foreach ($resources as $area => $areaResources) {
                if (is_string($areaResources)) {
                    $updatedResources[$area] = $areaResources . "\n\n" . $translationCode;
                } else {
                    $updatedResources[$area] = $areaResources;
                }
            }
            
            // 更新事件数据
            $eventData->setData('resources', $updatedResources);
        } catch (\Exception $e) {
            // 静默处理错误，避免影响资源编译
            error_log('I18n资源编译观察者执行失败: ' . $e->getMessage());
        }
    }
    
    /**
     * 从文件列表中读取资源内容
     * 
     * @param array $files 文件列表数组
     * @param string $area 区域
     * @return string 资源内容
     */
    private function readResourcesFromFiles(array $files, string $area): string
    {
        $content = '';
        
        // 如果传入的是文件信息数组，读取每个文件的内容
        foreach ($files as $file) {
            if (is_array($file) && isset($file['origin'])) {
                // 文件信息数组格式：['module' => ..., 'dir' => ..., 'area' => ..., 'file' => ..., 'origin' => ...]
                $filePath = $file['origin'];
                if (is_file($filePath)) {
                    $fileContent = file_get_contents($filePath);
                    if ($fileContent) {
                        $content .= $fileContent . "\n";
                    }
                }
            } elseif (is_string($file) && is_file($file)) {
                // 直接是文件路径
                $fileContent = file_get_contents($file);
                if ($fileContent) {
                    $content .= $fileContent . "\n";
                }
            }
        }
        
        return $content;
    }
    
    /**
     * 从资源内容中提取模块配置
     * 
     * @param string $resources 资源内容
     * @return array 模块配置数组
     */
    private function extractModulesFromResources(string $resources): array
    {
        $modules = [];
        
        // 匹配 Object.assign(window.WelineModulesConfig.modules, { ... }) 格式
        // 使用平衡括号匹配来支持嵌套的大括号
        $pattern = '/Object\.assign\s*\(\s*window\.WelineModulesConfig\.modules\s*,\s*(\{.*?\})\s*\)/s';
        
        if (preg_match($pattern, $resources, $assignMatch)) {
            $assignContent = $assignMatch[1];
            
            // 提取 Object.assign 中的整个对象内容（支持嵌套大括号）
            $objectContent = $this->extractBalancedBraces($assignContent);
            if (empty($objectContent)) {
                return $modules;
            }
            
            // 提取每个模块的定义（支持嵌套大括号）
            $offset = 0;
            while (preg_match('/(\w+):\s*(\{)/s', $objectContent, $moduleMatch, PREG_OFFSET_CAPTURE, $offset)) {
                $moduleName = $moduleMatch[1][0];
                $moduleStartPos = (int)$moduleMatch[2][1];
                
                // 提取模块配置对象（支持嵌套大括号）
                $moduleConfigStr = substr($objectContent, $moduleStartPos);
                $moduleConfig = $this->extractBalancedBraces($moduleConfigStr);
                
                if (!empty($moduleConfig)) {
                    $moduleData = [
                        'paths' => [],
                        'globalVar' => null,
                    ];
                    
                    // 提取 paths 数组（支持嵌套数组，需要平衡括号匹配）
                    $pathsArray = $this->extractPathsArray($moduleConfig);
                    if (!empty($pathsArray)) {
                        // 只保留以模块名开头的路径（格式：模块名::路径）
                        $filteredPaths = [];
                        foreach ($pathsArray as $path) {
                            // 跳过CDN资源
                            if (strpos($path, 'http://') === 0 || strpos($path, 'https://') === 0) {
                                continue;
                            }
                            
                            // 只保留以模块名开头的路径（格式：模块名::路径）
                            if (preg_match('/^([A-Z][a-zA-Z0-9_]+)::/', $path)) {
                                $filteredPaths[] = $path;
                            }
                        }
                        
                        if (!empty($filteredPaths)) {
                            $moduleData['paths'] = $filteredPaths;
                        }
                    }
                    
                    // 提取 globalVar（可能为 null）
                    if (preg_match('/globalVar:\s*null/', $moduleConfig)) {
                        $moduleData['globalVar'] = null;
                    } elseif (preg_match('/globalVar:\s*["\']([^"\']+)["\']/', $moduleConfig, $globalVarMatch)) {
                        $moduleData['globalVar'] = $globalVarMatch[1];
                    }
                    
                    // 只有当 paths 不为空时才添加模块
                    if (!empty($moduleData['paths'])) {
                        $modules[$moduleName] = $moduleData;
                    }
                    
                    // 移动到下一个模块
                    $offset = $moduleStartPos + strlen($moduleConfig) + 1;
                } else {
                    break;
                }
            }
        }
        
        return $modules;
    }
    
    /**
     * 提取平衡的大括号内容
     * 
     * @param string $str 字符串（以 { 开头）
     * @return string 大括号内的内容（不包含大括号本身）
     */
    private function extractBalancedBraces(string $str): string
    {
        if (empty($str) || $str[0] !== '{') {
            return '';
        }
        
        $depth = 0;
        $start = 0;
        $inString = false;
        $stringChar = '';
        
        for ($i = 0; $i < strlen($str); $i++) {
            $char = $str[$i];
            $prevChar = $i > 0 ? $str[$i - 1] : '';
            
            // 处理字符串（跳过字符串内的大括号）
            if (!$inString && ($char === '"' || $char === "'")) {
                $inString = true;
                $stringChar = $char;
            } elseif ($inString && $char === $stringChar && $prevChar !== '\\') {
                $inString = false;
                $stringChar = '';
            }
            
            if ($inString) {
                continue;
            }
            
            // 计算大括号深度
            if ($char === '{') {
                if ($depth === 0) {
                    $start = $i + 1;
                }
                $depth++;
            } elseif ($char === '}') {
                $depth--;
                if ($depth === 0) {
                    return substr($str, $start, $i - $start);
                }
            }
        }
        
        return '';
    }
    
    /**
     * 提取 paths 数组内容
     * 
     * @param string $moduleConfig 模块配置字符串
     * @return array 路径数组
     */
    private function extractPathsArray(string $moduleConfig): array
    {
        $paths = [];
        
        // 查找 paths: [ 的位置
        if (preg_match('/paths:\s*\[/', $moduleConfig, $match, PREG_OFFSET_CAPTURE)) {
            $arrayStart = $match[0][1] + strlen($match[0][0]) - 1; // 指向 [ 的位置
            $arrayStr = substr($moduleConfig, $arrayStart);
            
            // 提取平衡的数组内容
            $arrayContent = $this->extractBalancedBrackets($arrayStr);
            if (!empty($arrayContent)) {
                // 提取所有路径（支持单引号、双引号）
                if (preg_match_all('/["\']([^"\']+)["\']/', $arrayContent, $pathMatches)) {
                    $paths = $pathMatches[1];
                }
            }
        }
        
        return $paths;
    }
    
    /**
     * 提取平衡的方括号内容
     * 
     * @param string $str 字符串（以 [ 开头）
     * @return string 方括号内的内容（不包含方括号本身）
     */
    private function extractBalancedBrackets(string $str): string
    {
        if (empty($str) || $str[0] !== '[') {
            return '';
        }
        
        $depth = 0;
        $start = 0;
        $inString = false;
        $stringChar = '';
        
        for ($i = 0; $i < strlen($str); $i++) {
            $char = $str[$i];
            $prevChar = $i > 0 ? $str[$i - 1] : '';
            
            // 处理字符串（跳过字符串内的方括号）
            if (!$inString && ($char === '"' || $char === "'")) {
                $inString = true;
                $stringChar = $char;
            } elseif ($inString && $char === $stringChar && $prevChar !== '\\') {
                $inString = false;
                $stringChar = '';
            }
            
            if ($inString) {
                continue;
            }
            
            // 计算方括号深度
            if ($char === '[') {
                if ($depth === 0) {
                    $start = $i + 1;
                }
                $depth++;
            } elseif ($char === ']') {
                $depth--;
                if ($depth === 0) {
                    return substr($str, $start, $i - $start);
                }
            }
        }
        
        return '';
    }
    
    /**
     * 获取翻译词对应的翻译
     * 
     * @param array $words 翻译词数组 [原文 => 原文]
     * @param string $locale 语言代码
     * @return array 翻译数组 [原文 => 翻译]
     */
    private function getTranslations(array $words, string $locale): array
    {
        $translations = [];
        
        try {
            // 使用 Parser 获取翻译词库
            $allWords = \Weline\Framework\Phrase\Parser::getWords();
            
            foreach ($words as $word => $original) {
                // 从i18n词库中获取翻译，如果没有则使用原文
                $translations[$word] = $allWords[$word] ?? $word;
            }
        } catch (\Exception $e) {
            // 如果获取翻译失败，使用原文
            foreach ($words as $word => $original) {
                $translations[$word] = $word;
            }
        }
        
        return $translations;
    }
    
    /**
     * 将模块路径解析为相对根目录的路径（用于模块映射）
     * 
     * @param string $modulePath 模块路径，如 Weline_Backend::libs/jquery/3.6.0/jquery.min.js
     * @param string $basePathNormalized 项目根目录路径（已标准化）
     * @return string|null 相对根目录的路径，如 app/code/Weline/Frontend/view/statics/libs/jquery/3.6.0/jquery.min.js
     */
    private function resolveModulePathToOriginPath(string $modulePath, string $basePathNormalized): ?string
    {
        // 解析模块路径格式：Weline_Module::path/to/file.js
        if (strpos($modulePath, '::') !== false) {
            $parts = explode('::', $modulePath, 2);
            if (count($parts) === 2) {
                $moduleNamePart = trim($parts[0]);
                $filePath = trim($parts[1], '/');
                
                // 获取模块信息
                $modules = \Weline\Framework\App\Env::getInstance()->getModuleList();
                if (isset($modules[$moduleNamePart])) {
                    $module = $modules[$moduleNamePart];
                    $moduleBasePath = $module['base_path'] ?? '';
                    
                    if ($moduleBasePath && is_string($moduleBasePath)) {
                        // 计算相对于项目根目录的路径
                        $moduleBasePathNormalized = str_replace('\\', '/', rtrim($moduleBasePath, '/\\'));
                        $originPath = str_replace($basePathNormalized . '/', '', $moduleBasePathNormalized) . '/view/statics/' . $filePath;
                        return $originPath;
                    }
                }
            }
        }
        
        return null;
    }
    
    /**
     * 将URL路径转换为实际文件路径
     * 
     * @param string $path URL路径，如 /Weline/Frontend/view/statics/libs/jquery/3.6.0/jquery.min.js
     * @return string|null 实际文件路径
     */
    private function resolvePathToFile(string $path): ?string
    {
        // 如果是模块路径格式：Weline_Module::path/to/file.js
        if (strpos($path, '::') !== false) {
            $parts = explode('::', $path, 2);
            if (count($parts) === 2) {
                $moduleName = trim($parts[0]);
                $filePath = trim($parts[1], '/');
                
                // 获取模块信息
                $modules = \Weline\Framework\App\Env::getInstance()->getModuleList();
                if (isset($modules[$moduleName])) {
                    $module = $modules[$moduleName];
                    $basePath = $module['base_path'] ?? '';
                    
                    if ($basePath) {
                        $fullPath = rtrim($basePath, '/\\') . DS . 'view' . DS . 'statics' . DS . str_replace('/', DS, $filePath);
                        if (is_file($fullPath)) {
                            return $fullPath;
                        }
                    }
                }
            }
            return null;
        }
        
        // 移除开头的斜杠
        $path = ltrim($path, '/');
        
        // 检查是否是模块路径格式：Weline/Frontend/view/statics/...
        if (preg_match('#^([^/]+)/([^/]+)/view/statics/(.+)$#', $path, $matches)) {
            $vendor = $matches[1];
            $module = $matches[2];
            $filePath = $matches[3];
            
            // 构建实际文件路径
            $basePath = \Weline\Framework\App\Env::getInstance()->getBasePath();
            $fullPath = $basePath . DS . 'app' . DS . 'code' . DS . $vendor . DS . $module . DS . 'view' . DS . 'statics' . DS . str_replace('/', DS, $filePath);
            
            if (is_file($fullPath)) {
                return $fullPath;
            }
        }
        
        return null;
    }
    
    /**
     * 生成模块映射 JSON 文件
     * 
     * @param array $modulesMap 模块映射数据，格式：['frontend' => ['模块名' => ['paths' => [...], 'i18n' => [...]]], 'backend' => [...]]
     * @return void
     */
    private function generateModulesMapJson(array $modulesMap): void
    {
        if (empty($modulesMap)) {
            return;
        }
        
        $basePath = \Weline\Framework\App\Env::getInstance()->getBasePath();
        
        // 为每个区域生成 JSON 文件
        foreach ($modulesMap as $area => $areaModules) {
            if (empty($areaModules)) {
                continue;
            }
            
            // 确定目标目录
            $targetDir = null;
            if ($area === 'frontend') {
                $moduleInfo = \Weline\Framework\App\Env::getInstance()->getModuleInfo('Weline_Frontend');
                if (isset($moduleInfo['base_path'])) {
                    $targetDir = rtrim($moduleInfo['base_path'], '/\\') . DS . 'view' . DS . 'statics' . DS . 'base';
                }
            } elseif ($area === 'backend') {
                $moduleInfo = \Weline\Framework\App\Env::getInstance()->getModuleInfo('Weline_Backend');
                if (isset($moduleInfo['base_path'])) {
                    $targetDir = rtrim($moduleInfo['base_path'], '/\\') . DS . 'view' . DS . 'statics' . DS . 'base';
                }
            }
            
            if ($targetDir) {
                // 确保目录存在
                if (!is_dir($targetDir)) {
                    if (!mkdir($targetDir, 0755, true)) {
                        error_log("无法创建目录：{$targetDir}");
                        continue;
                    }
                }
                
                // 转换数据结构：将翻译词直接挂在模块对象上
                $formattedModules = [];
                foreach ($areaModules as $moduleName => $moduleData) {
                    if (is_array($moduleData)) {
                        // 新格式：['paths' => [...], 'i18n' => [...]]
                        if (isset($moduleData['paths'])) {
                            $formattedModule = [
                                'paths' => $moduleData['paths']
                            ];
                            // 将翻译词直接作为模块对象的属性
                            if (isset($moduleData['i18n']) && is_array($moduleData['i18n'])) {
                                foreach ($moduleData['i18n'] as $word => $translation) {
                                    $formattedModule[$word] = $translation;
                                }
                            }
                            $formattedModules[$moduleName] = $formattedModule;
                        } else {
                            // 兼容旧格式：直接是路径数组
                            $formattedModules[$moduleName] = [
                                'paths' => $moduleData
                            ];
                        }
                    } else {
                        // 兼容旧格式：直接是路径数组
                        $formattedModules[$moduleName] = [
                            'paths' => is_array($moduleData) ? $moduleData : [$moduleData]
                        ];
                    }
                }
                
                $jsonFile = $targetDir . DS . 'modules.map.json';
                $jsonContent = json_encode($formattedModules, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                
                // 写入文件
                if (file_put_contents($jsonFile, $jsonContent) === false) {
                    error_log("无法写入模块映射文件：{$jsonFile}");
                }
            }
        }
    }
    
    /**
     * 生成翻译对象和__()函数的JavaScript代码
     * 
     * @param array $translations 翻译数组 [原文 => 翻译]
     * @return string JavaScript代码
     */
    private function generateTranslationCode(array $translations): string
    {
        $translationsJson = json_encode($translations, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        
        $code = [];
        $code[] = "// I18n Translation Dictionary (Compiled at build time)";
        $code[] = "(function() {";
        $code[] = "    // 翻译字典（编译时生成，减少运行时性能损耗）";
        $code[] = "    window.__WelineI18nDictionary = window.__WelineI18nDictionary || {};";
        $code[] = "    Object.assign(window.__WelineI18nDictionary, " . $translationsJson . ");";
        $code[] = "";
        $code[] = "    // 翻译函数（编译时已准备好翻译字典）";
        $code[] = "    window.__ = window.__ || function(text) {";
        $code[] = "        if (!text) return '';";
        $code[] = "        return window.__WelineI18nDictionary[text] || text;";
        $code[] = "    };";
        $code[] = "})();";
        
        return implode("\n", $code);
    }
}


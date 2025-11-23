<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Backend\Observer;

use JSMin\JSMin;
use Weline\Framework\Event\Event;
use Weline\Framework\View\Template;

class Compiler implements \Weline\Framework\Event\ObserverInterface
{
    public const area = 'backend';
    public const modules_require_js_file = 'base' . DS . 'weline.modules.js';
    public const modules_require_js_type = 'weline.modules.js';

    /**
     * @inheritDoc
     */
    public function execute(Event &$event): void
    {
        $eventData = $event->getEvenData();
        $area = $eventData->getData('area');
        $type = $eventData->getData('type');
        
        if (self::area === $area) {
            switch ($type):
                case self::modules_require_js_type:
                    $path = dirname(__DIR__) . DS . 'view' . DS . 'statics' . DS . self::modules_require_js_file;
                    $dir = dirname($path);
                    if (!is_dir($dir)) {
                        mkdir($dir, 0755, true);
                    }
                    // 合并所有模块配置，生成统一的 weline.modules.js
                    // 注意：resources 已经按 area 分组，这里只包含 backend 的资源
                    $resources = $eventData->getData('resources');
                    $content = $this->mergeModulesConfig($resources);
                    // 清空文件后重新写入，确保没有旧内容残留
                    file_put_contents($path, '', LOCK_EX);
                    file_put_contents($path, $content, LOCK_EX);
                    break;
                default:
            endswitch;
        }
    }

    /**
     * 合并所有模块配置（后端专用，去重）
     * 注意：resources 已经按 area 分组，这里只包含 backend 的资源，无需再过滤前端资源
     * @param string $resources 后端模块的 weline.modules.js 文件内容（已连接）
     * @return string 合并后的 JavaScript 代码
     */
    protected function mergeModulesConfig(string $resources): string
    {
        // 解析所有模块配置，收集到一个对象中
        $allModules = [];
        $allAliases = [];
        
        if (!empty($resources)) {
            $lines = explode("\n", $resources);
            $seenModules = [];
            $seenAliases = [];
            $inModuleBlock = false;
            $inAliasBlock = false;
            $currentModuleName = '';
            $skipCurrentModule = false;
            $moduleBlockDepth = 0;
            $currentModuleConfig = [];
            $currentAliasConfig = [];
            
            foreach ($lines as $line) {
                $trimmedLine = trim($line);
                
                // 跳过初始化代码
                if (preg_match('/^\s*window\.WelineModulesConfig\s*=\s*window\.WelineModulesConfig\s*\|\|\s*\{\}\s*;?\s*$/', $trimmedLine) ||
                    preg_match('/^\s*window\.WelineModulesConfig\.modules\s*=\s*window\.WelineModulesConfig\.modules\s*\|\|\s*\{\}\s*;?\s*$/', $trimmedLine) ||
                    preg_match('/^\s*window\.WelineModulesConfig\.moduleAliases\s*=\s*window\.WelineModulesConfig\.moduleAliases\s*\|\|\s*\{\}\s*;?\s*$/', $trimmedLine)) {
                    continue;
                }
                
                // 检测模块配置块开始
                if (preg_match('/Object\.assign\s*\(\s*window\.WelineModulesConfig\.modules\s*,\s*\{/', $line)) {
                    $inModuleBlock = true;
                    $moduleBlockDepth = 0;
                    $skipCurrentModule = false;
                    $currentModuleConfig = [];
                    continue;
                }
                
                // 检测别名配置块开始
                if (preg_match('/Object\.assign\s*\(\s*window\.WelineModulesConfig\.moduleAliases\s*,\s*\{/', $line)) {
                    $inAliasBlock = true;
                    $currentAliasConfig = [];
                    continue;
                }
                
                // 检测块结束
                if (preg_match('/^\s*\}\s*\)\s*;?\s*$/', $trimmedLine)) {
                    if ($inModuleBlock && $moduleBlockDepth === 0) {
                        // 整个 Object.assign 块结束，合并模块配置
                        if (!empty($currentModuleConfig)) {
                            $allModules = array_merge($allModules, $currentModuleConfig);
                        }
                        $inModuleBlock = false;
                        $skipCurrentModule = false;
                        $currentModuleName = '';
                        $currentModuleConfig = [];
                    } elseif ($inAliasBlock) {
                        // 别名块结束，合并别名配置
                        if (!empty($currentAliasConfig)) {
                            $allAliases = array_merge($allAliases, $currentAliasConfig);
                        }
                        $inAliasBlock = false;
                        $currentAliasConfig = [];
                    }
                    continue;
                }
                
                // 处理模块配置去重
                if ($inModuleBlock) {
                    // 检测模块名（模块配置对象的开始，必须在 Object.assign 的第一层）
                    if ($moduleBlockDepth === 0 && preg_match('/^\s*([a-zA-Z_$][a-zA-Z0-9_$]*)\s*:\s*\{/', $trimmedLine, $matches)) {
                        $moduleName = $matches[1];
                        if (isset($seenModules[$moduleName])) {
                            // 跳过重复的模块配置
                            $skipCurrentModule = true;
                            $currentModuleName = $moduleName;
                            $moduleBlockDepth = 1;
                            continue;
                        }
                        $seenModules[$moduleName] = true;
                        $currentModuleName = $moduleName;
                        $skipCurrentModule = false;
                        $moduleBlockDepth = 1;
                        // 保存模块配置的开始行（去掉模块名和第一层大括号，保留配置内容）
                        // 例如：`    jquery: {` -> ``
                        // 例如：`    jquery: {paths: [` -> `paths: [`
                        $configStart = preg_replace('/^\s*' . preg_quote($moduleName, '/') . '\s*:\s*\{/', '', $line);
                        $currentModuleConfig[$moduleName] = trim($configStart);
                        continue;
                    }
                    
                    // 如果当前模块需要跳过，跳过该模块的所有行
                    if ($skipCurrentModule) {
                        // 计算大括号的深度变化
                        $openBraces = substr_count($line, '{');
                        $closeBraces = substr_count($line, '}');
                        $moduleBlockDepth += ($openBraces - $closeBraces);
                        // 如果模块配置对象结束，重置状态
                        if ($moduleBlockDepth === 0) {
                            $currentModuleName = '';
                            $skipCurrentModule = false;
                        }
                        continue;
                    }
                    
                    // 计算大括号的深度变化（先计算，再判断）
                    $openBraces = substr_count($line, '{');
                    $closeBraces = substr_count($line, '}');
                    $newModuleBlockDepth = $moduleBlockDepth + ($openBraces - $closeBraces);
                    
                    // 检测模块配置对象结束（moduleBlockDepth 回到 0）
                    if ($newModuleBlockDepth === 0 && $currentModuleName) {
                        // 模块配置对象结束，当前行包含关闭大括号
                        if (isset($currentModuleConfig[$currentModuleName])) {
                            // 移除当前行末尾的右大括号和逗号，但保留其他内容
                            $lineWithoutClose = rtrim($line, ',}');
                            $lineWithoutClose = trim($lineWithoutClose);
                            // 如果当前行还有其他内容（不只是右大括号），添加到配置中
                            if (!empty($lineWithoutClose)) {
                                $currentModuleConfig[$currentModuleName] .= "\n" . $lineWithoutClose;
                            }
                            // 清理配置内容末尾的逗号和右大括号（确保没有多余的）
                            $configContent = rtrim(trim($currentModuleConfig[$currentModuleName]), ',}');
                            $currentModuleConfig[$currentModuleName] = $configContent;
                        }
                        $currentModuleName = '';
                        $moduleBlockDepth = 0;
                        continue;
                    }
                    
                    // 收集模块配置内容（只有在模块配置对象未结束时才收集）
                    if ($currentModuleName && isset($currentModuleConfig[$currentModuleName])) {
                        $currentModuleConfig[$currentModuleName] .= "\n" . $line;
                    }
                    
                    // 更新深度
                    $moduleBlockDepth = $newModuleBlockDepth;
                    continue;
                }
                
                // 处理别名配置去重
                if ($inAliasBlock && preg_match('/^\s*([a-zA-Z_$][a-zA-Z0-9_$]*)\s*:\s*["\']([^"\']+)["\']/', $trimmedLine, $matches)) {
                    $aliasKey = $matches[1];
                    if (!isset($seenAliases[$aliasKey])) {
                        $seenAliases[$aliasKey] = true;
                        $currentAliasConfig[$aliasKey] = $matches[2];
                    }
                    continue;
                }
            }
        }
        
        // 一次性生成完整的配置对象
        $output = [];
        $output[] = "// Weline Modules Configuration (Compiled)";
        $output[] = "(function() {";
        $output[] = "    window.WelineModulesConfig = window.WelineModulesConfig || {};";
        $output[] = "    window.WelineModulesConfig.modules = window.WelineModulesConfig.modules || {};";
        $output[] = "    window.WelineModulesConfig.moduleAliases = window.WelineModulesConfig.moduleAliases || {};";
        $output[] = "";
        $output[] = "    // 一次性合并所有模块配置";
        $output[] = "    Object.assign(window.WelineModulesConfig.modules, {";
        
        // 添加所有模块配置
        $moduleLines = [];
        foreach ($allModules as $moduleName => $moduleConfig) {
            // 解析并转换模块配置
            $processedConfig = $this->processModuleConfig($moduleConfig, $moduleName);
            $moduleLines[] = "        " . $moduleName . ": " . $processedConfig;
        }
        $output[] = implode(",\n", $moduleLines);
        
        $output[] = "    });";
        $output[] = "";
        $output[] = "    // 一次性合并所有模块别名";
        $output[] = "    Object.assign(window.WelineModulesConfig.moduleAliases, {";
        
        // 添加所有别名配置
        $aliasLines = [];
        foreach ($allAliases as $aliasKey => $aliasValue) {
            $aliasLines[] = "        " . $aliasKey . ": \"" . addslashes($aliasValue) . "\"";
        }
        $output[] = implode(",\n", $aliasLines);
        
        $output[] = "    });";
        $output[] = "})();";
        
        return implode("\n", $output);
    }

    /**
     * 处理模块配置，添加 origin_path 并转换 paths 为环境 URL
     * 
     * @param string $moduleConfig 原始模块配置字符串
     * @param string $moduleName 模块名
     * @return string 处理后的 JavaScript 配置对象字符串
     */
    protected function processModuleConfig(string $moduleConfig, string $moduleName): string
    {
        // 解析 paths 数组
        $pathsPattern = '/paths:\s*\[([^\]]+)\]/s';
        if (!preg_match($pathsPattern, $moduleConfig, $pathsMatch)) {
            // 如果没有 paths，直接返回原配置
            $configContent = rtrim(trim($moduleConfig), ',');
            return "{" . $configContent . "}";
        }
        
        $pathsStr = $pathsMatch[1];
        $pathsArray = [];
        $originPaths = [];
        
        // 提取所有路径（支持单引号、双引号）
        if (preg_match_all('/["\']([^"\']+)["\']/', $pathsStr, $pathMatches)) {
            foreach ($pathMatches[1] as $path) {
                // 跳过 CDN 资源
                if (strpos($path, 'http://') === 0 || strpos($path, 'https://') === 0) {
                    $pathsArray[] = $path;
                    $originPaths[] = null; // CDN 资源没有 origin_path
                    continue;
                }
                
                // 解析模块路径格式：Weline_Module::path/to/file.js
                if (strpos($path, '::') !== false) {
                    $parts = explode('::', $path, 2);
                    if (count($parts) === 2) {
                        $moduleNamePart = trim($parts[0]);
                        $filePath = trim($parts[1], '/');
                        
                        // 获取模块信息
                        $modules = \Weline\Framework\App\Env::getInstance()->getModuleList();
                        if (isset($modules[$moduleNamePart])) {
                            $module = $modules[$moduleNamePart];
                            $basePath = $module['base_path'] ?? '';
                            
                            if ($basePath && is_string($basePath)) {
                                // 计算 origin_path（相对于项目根目录）
                                $basePathNormalized = str_replace('\\', '/', rtrim($basePath, '/\\'));
                                $projectRootNormalized = str_replace('\\', '/', rtrim(BP, '/\\'));
                                $originPath = str_replace($projectRootNormalized . '/', '', $basePathNormalized) . '/view/statics/' . $filePath;
                                
                                // 转换模块名为 URL 格式（Weline_Module -> Weline/Module）
                                $moduleParts = explode('_', $moduleNamePart, 2);
                                $vendorName = $moduleParts[0];
                                $moduleNameUrl = isset($moduleParts[1]) ? $moduleParts[1] : '';
                                
                                // 根据环境生成 URL
                                $isDev = defined('DEV') && DEV;
                                if ($isDev) {
                                    // 开发模式：/Weline/Backend/view/statics/js/theme.js
                                    $urlPath = '/' . $vendorName . '/' . $moduleNameUrl . '/view/statics/' . $filePath;
                                } else {
                                    // 生产模式：/static/Weline/Backend/js/theme.js
                                    $urlPath = '/static/' . $vendorName . '/' . $moduleNameUrl . '/' . $filePath;
                                }
                                
                                $pathsArray[] = $urlPath;
                                $originPaths[] = $originPath;
                            } else {
                                // 无法获取模块信息，保持原路径
                                $pathsArray[] = $path;
                                $originPaths[] = null;
                            }
                        } else {
                            // 模块不存在，保持原路径
                            $pathsArray[] = $path;
                            $originPaths[] = null;
                        }
                    } else {
                        // 格式不正确，保持原路径
                        $pathsArray[] = $path;
                        $originPaths[] = null;
                    }
                } else {
                    // 不是模块路径格式，保持原路径
                    $pathsArray[] = $path;
                    $originPaths[] = null;
                }
            }
        }
        
        // 构建新的配置对象
        $newConfig = [];
        
        // 添加 origin_paths（过滤掉 null 值）
        $validOriginPaths = array_filter($originPaths, function($path) {
            return $path !== null;
        });
        if (!empty($validOriginPaths)) {
            $newConfig[] = 'origin_paths: [' . implode(', ', array_map(function($path) {
                return '"' . addslashes($path) . '"';
            }, $validOriginPaths)) . ']';
        }
        
        // 添加转换后的 paths
        $newConfig[] = 'paths: [' . implode(', ', array_map(function($path) {
            return '"' . addslashes($path) . '"';
        }, $pathsArray)) . ']';
        
        // 提取其他配置项（globalVar, description 等）
        // 先移除 paths 和 origin_paths 数组内容，避免误匹配
        $configWithoutPaths = $moduleConfig;
        
        // 移除 paths 数组（使用平衡括号匹配）
        if (preg_match('/paths:\s*\[/', $configWithoutPaths, $pathsMatch, PREG_OFFSET_CAPTURE)) {
            $pathsStart = (int)$pathsMatch[0][1];
            $pathsStr = substr($configWithoutPaths, $pathsStart);
            $pathsArrayContent = $this->extractBalancedBrackets($pathsStr);
            if ($pathsArrayContent !== '') {
                $pathsFull = 'paths: [' . $pathsArrayContent . ']';
                $configWithoutPaths = str_replace($pathsFull, '', $configWithoutPaths);
            }
        }
        
        // 移除 origin_paths 数组（使用平衡括号匹配）
        if (preg_match('/origin_paths:\s*\[/', $configWithoutPaths, $originPathsMatch, PREG_OFFSET_CAPTURE)) {
            $originPathsStart = (int)$originPathsMatch[0][1];
            $originPathsStr = substr($configWithoutPaths, $originPathsStart);
            $originPathsArrayContent = $this->extractBalancedBrackets($originPathsStr);
            if ($originPathsArrayContent !== '') {
                $originPathsFull = 'origin_paths: [' . $originPathsArrayContent . ']';
                $configWithoutPaths = str_replace($originPathsFull, '', $configWithoutPaths);
            }
        }
        
        // 只匹配已知的配置项
        $knownKeys = ['globalVar', 'description'];
        $otherConfigPattern = '/(' . implode('|', $knownKeys) . '):\s*([^,\n}]+)/';
        if (preg_match_all($otherConfigPattern, $configWithoutPaths, $otherMatches, PREG_SET_ORDER)) {
            foreach ($otherMatches as $match) {
                $key = trim($match[1]);
                $value = trim($match[2]);
                
                // 处理值（移除引号，重新添加）
                $value = trim($value, ' "\'');
                if ($value === 'null') {
                    $newConfig[] = $key . ': null';
                } else {
                    $newConfig[] = $key . ': "' . addslashes($value) . '"';
                }
            }
        }
        
        return "{\n            " . implode(",\n            ", $newConfig) . "\n        }";
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

    protected string $generate_content = '';

    public function generateRequireConfigJsContent(array $resources): string
    {
        p(json_encode($resources));
        foreach ($resources as $key => $resource) {
            if (is_numeric($key)) {
                $this->generate_content .= '"' . $resource . '",';
            } elseif (is_string($resource)) {
                $this->generate_content .= $key . ':"' . $resource . '",';
            } elseif (is_array($resource)) {
                $this->generate_content .= $key . ':[';
                $this->generate_content = $this->generateRequireConfigJsContent($resource) . ',';
                $this->generate_content .= '],';
                /*foreach ($resource as $r_key => $r_item) {
                    if (is_array($r_item)) {
                        $this->generate_content = $this->generateRequireConfigJsContent($r_item) . ',';
                    } elseif (is_numeric($r_key)) {
                        $this->generate_content .= '"' . $r_item . '",';
                    } else {
                        $this->generate_content .= $r_key . ':"' . $r_item . '",';
                    }
                }*/
//                $this->generate_content .= '],';
            }
        }
        return $this->generate_content;
    }
    /*protected ?Template $template=null;
    function getTemplate(){
        if(!$this->template){
            $this->template=Template::getInstance()->init();
        }
        return $this->template;
    }*/
}

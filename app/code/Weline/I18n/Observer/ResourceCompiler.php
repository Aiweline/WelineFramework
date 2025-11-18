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
        $area = $eventData->getData('area');
        $type = $eventData->getData('type');
        $resources = $eventData->getData('resources');
        
        // 只处理 weline.modules.js 类型的资源
        if ($type !== 'weline.modules.js' || empty($resources)) {
            return;
        }
        
        try {
            // 从资源内容中提取模块配置
            $modules = $this->extractModulesFromResources($resources);
            
            if (empty($modules)) {
                return;
            }
            
            // 从模块的JS文件中提取翻译词
            $jsWords = [];
            foreach ($modules as $moduleName => $moduleConfig) {
                if (isset($moduleConfig['paths']) && is_array($moduleConfig['paths'])) {
                    foreach ($moduleConfig['paths'] as $modulePath) {
                        // 跳过CDN资源
                        if (strpos($modulePath, 'http://') === 0 || strpos($modulePath, 'https://') === 0) {
                            continue;
                        }
                        
                        // 解析模块路径为实际文件路径
                        // 编译后的资源中路径可能是URL格式（如 /Weline/Frontend/view/statics/...）
                        // 需要转换为实际文件路径
                        $filePath = $this->resolvePathToFile($modulePath);
                        if ($filePath && is_file($filePath)) {
                            // 从JS文件中提取翻译词
                            $words = JsTranslationsExtractor::extractWordsFromJsFile($filePath);
                            $jsWords = array_merge($jsWords, $words);
                        }
                    }
                }
            }
            
            if (empty($jsWords)) {
                return;
            }
            
            // 获取当前语言的翻译
            $locale = $_SERVER['WELINE_USER_LANG'] ?? $_SERVER['WELINE_WEBSITE_LANGUAGE'] ?? 'zh_Hans_CN';
            $translations = $this->getTranslations($jsWords, $locale);
            
            // 生成翻译对象和__()函数的JavaScript代码
            $translationCode = $this->generateTranslationCode($translations);
            
            // 将翻译代码追加到资源内容中
            $resources .= "\n\n" . $translationCode;
            
            // 更新事件数据
            $eventData->setData('resources', $resources);
        } catch (\Exception $e) {
            // 静默处理错误，避免影响资源编译
            error_log('I18n资源编译观察者执行失败: ' . $e->getMessage());
        }
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
        if (preg_match_all('/Object\.assign\s*\(\s*window\.WelineModulesConfig\.modules\s*,\s*\{([^}]+)\}\s*\)/s', $resources, $matches)) {
            foreach ($matches[1] as $assignContent) {
                // 提取每个模块的定义
                if (preg_match_all('/(\w+):\s*\{([^}]+)\}/s', $assignContent, $moduleMatches, PREG_SET_ORDER)) {
                    foreach ($moduleMatches as $moduleMatch) {
                        $moduleName = $moduleMatch[1];
                        $moduleConfig = $moduleMatch[2];
                        
                        $moduleData = [
                            'paths' => [],
                            'globalVar' => null,
                        ];
                        
                        // 提取 paths 数组
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
        
        return $modules;
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
            /**@var I18n $i18nModel */
            $i18nModel = ObjectManager::getInstance(I18n::class);
            $allTranslations = $i18nModel->getWords([$locale]);
            
            foreach ($words as $word => $original) {
                // 从i18n词库中获取翻译，如果没有则使用原文
                $translations[$word] = $allTranslations[$locale][$word] ?? $word;
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
     * 将URL路径转换为实际文件路径
     * 
     * @param string $path URL路径，如 /Weline/Frontend/view/statics/libs/jquery/3.6.0/jquery.min.js
     * @return string|null 实际文件路径
     */
    private function resolvePathToFile(string $path): ?string
    {
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


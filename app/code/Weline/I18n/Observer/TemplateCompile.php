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
use Weline\I18n\Helper\JsModuleParser;
use Weline\I18n\Helper\JsTranslationsExtractor;
use Weline\I18n\Helper\JsWordsRegistry;
use Weline\Framework\App\Env;
use Weline\I18n\Model\I18n;

/**
 * 模板编译观察者
 * 在模板编译时提取 JS 模块声明和翻译词
 */
class TemplateCompile implements ObserverInterface
{
    public function execute(Event &$event): void
    {
        // 获取模板内容和编译文件名
        $content = $event->getData('content');
        $comFileName = $event->getData('comFileName');
        $tplFile = $event->getData('tplFile');
        if (empty($content) || empty($comFileName)) {
            return;
        }
        
        // 提取 JS 模块声明
        $declaredModules = JsModuleParser::extractDeclaredModules($content);
        if (empty($declaredModules)) {
            return;
        }
        
        // 判断当前区域（根据文件路径判断）
        $area = JsModuleParser::detectAreaFromPath($tplFile);
        
        // 从 modules.map.json 读取模块的翻译词
        $moduleTranslations = $this->loadModuleTranslationsFromMap($declaredModules, $area);
        
        // 解析这些模块的 JS 文件，提取翻译词（用于合并到i18n词库）
        $jsWords = JsTranslationsExtractor::extractWordsFromModules($declaredModules, $area);
        
        // 将收集到的JS翻译词合并到i18n词库中
        if (!empty($jsWords)) {
            $this->mergeJsWordsToI18n($jsWords, $tplFile);
            JsWordsRegistry::addWords(array_keys($jsWords));
        }
        
        // 将模块信息和翻译词注入到编译后的模板中
        $modulesJson = json_encode($declaredModules);
        $wordsJson = json_encode(array_values($jsWords)); // 转换为索引数组
        
        // 生成设置 i18n 词典的 JavaScript 代码
        $i18nDictionaryCode = '';
        if (!empty($moduleTranslations)) {
            $dictionaryJson = json_encode($moduleTranslations, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $i18nDictionaryCode = "\n<script>\n" .
                "// 从 modules.map.json 加载当前页面模块的翻译词\n" .
                "(function() {\n" .
                "    if (window.Weline && window.Weline.i18n && window.Weline.i18n.setDictionary) {\n" .
                "        var moduleTranslations = {$dictionaryJson};\n" .
                "        window.Weline.i18n.setDictionary(moduleTranslations);\n" .
                "    }\n" .
                "})();\n" .
                "</script>";
        }
        
        // 在 </body> 标签前注入（如果存在）
        $jsModulesCode = "<?php\n// JS模块声明和翻译词（编译时提取）\n\$__weline_js_modules = {$modulesJson};\n\$__weline_js_words = {$wordsJson};\n?>" . $i18nDictionaryCode;
        
        if (preg_match('/(<\/body>)/i', $content)) {
            $content = preg_replace('/(<\/body>)/i', $jsModulesCode . "\n$1", $content, 1);
        } else {
            // 如果没有 </body> 标签，在文件末尾添加
            $content .= "\n" . $jsModulesCode;
        }
        
        // 更新内容
        $event->setData('content', $content);
    }
    
    /**
     * 将JS翻译词合并到i18n词库中
     * 
     * @param array $jsWords JS翻译词数组 [原文 => 原文]
     * @param string $tplFile 模板文件路径（用于确定模块）
     */
    private function mergeJsWordsToI18n(array $jsWords, string $tplFile): void
    {
        if (empty($jsWords)) {
            return;
        }
        
        try {
            // 从模板文件路径推断模块名
            $moduleName = $this->extractModuleNameFromTemplatePath($tplFile);
            if (empty($moduleName)) {
                return;
            }
            
            // 获取模块的i18n目录
            $modulePath = $this->getModulePath($moduleName);
            if (empty($modulePath)) {
                return;
            }
            
            $i18nDir = $modulePath . DS . 'i18n';
            if (!is_dir($i18nDir)) {
                mkdir($i18nDir, 0755, true);
            }
            
            // 默认语言（zh_Hans_CN）
            $defaultLocale = 'zh_Hans_CN';
            $csvFile = $i18nDir . DS . $defaultLocale . '.csv';
            
            // 读取现有的翻译文件
            $existingTranslations = [];
            if (file_exists($csvFile)) {
                $handle = @fopen($csvFile, 'r');
                if ($handle !== false) {
                    while (($data = fgetcsv($handle, 100000, ',', '"', '\\')) !== false) {
                        if (isset($data[0]) && isset($data[1])) {
                            $existingTranslations[trim($data[0])] = trim($data[1]);
                        }
                    }
                    fclose($handle);
                }
            }
            
            // 合并JS翻译词（增量更新，不覆盖已有翻译）
            $merged = false;
            foreach ($jsWords as $original => $translation) {
                if (!isset($existingTranslations[$original])) {
                    $existingTranslations[$original] = $translation;
                    $merged = true;
                }
            }
            
            // 如果有新词，写入文件
            if ($merged) {
                $csvHandle = @fopen($csvFile, 'w+');
                if ($csvHandle !== false) {
                    foreach ($existingTranslations as $word => $trans) {
                        fputcsv($csvHandle, [$word, $trans], ',', '"', '\\');
                    }
                    fclose($csvHandle);
                }
            }
        } catch (\Exception $e) {
            // 静默处理错误，避免影响模板编译
            w_log_error('合并JS翻译词到i18n词库失败: ' . $e->getMessage(), [], 'i18n');
        }
    }
    
    /**
     * 从模板文件路径中提取模块名
     * 
     * @param string $tplFile 模板文件路径
     * @return string 模块名，如 Weline_Frontend
     */
    private function extractModuleNameFromTemplatePath(string $tplFile): string
    {
        // 尝试从路径中提取模块名
        // 例如：app/code/Weline/Frontend/view/templates/Frontend/index.phtml -> Weline_Frontend
        if (preg_match('#app/code/([^/]+)/([^/]+)/#', $tplFile, $matches)) {
            return $matches[1] . '_' . $matches[2];
        }
        
        return '';
    }
    
    /**
     * 获取模块路径
     * 
     * @param string $moduleName 模块名
     * @return string 模块路径
     */
    private function getModulePath(string $moduleName): string
    {
        try {
            $moduleInfo = \Weline\Framework\App\Env::getInstance()->getModuleInfo($moduleName);
            if ($moduleInfo && isset($moduleInfo['base_path'])) {
                return $moduleInfo['base_path'];
            }
        } catch (\Exception $e) {
            // 忽略错误
        }
        
        // 如果无法从Env获取，尝试从路径推断
        $parts = explode('_', $moduleName);
        if (count($parts) === 2) {
            $vendor = $parts[0];
            $module = $parts[1];
            $basePath = \Weline\Framework\App\Env::getInstance()->getBasePath();
            $modulePath = $basePath . DS . 'app' . DS . 'code' . DS . $vendor . DS . $module;
            if (is_dir($modulePath)) {
                return $modulePath;
            }
        }
        
        return '';
    }
    
    /**
     * 从 modules.map.json 加载模块的翻译词
     * 
     * @param array $declaredModules 声明的模块列表
     * @param string $area 区域（frontend/backend）
     * @return array 翻译词典 [原文 => 翻译]
     */
    private function loadModuleTranslationsFromMap(array $declaredModules, string $area): array
    {
        $translations = [];
        
        try {
            // 确定 modules.map.json 文件路径
            $modulesMapFile = null;
            if ($area === 'frontend') {
                $moduleInfo = \Weline\Framework\App\Env::getInstance()->getModuleInfo('Weline_Frontend');
                if (isset($moduleInfo['base_path'])) {
                    $modulesMapFile = rtrim($moduleInfo['base_path'], '/\\') . DS . 'view' . DS . 'statics' . DS . 'base' . DS . 'modules.map.json';
                }
            } elseif ($area === 'backend') {
                $moduleInfo = \Weline\Framework\App\Env::getInstance()->getModuleInfo('Weline_Backend');
                if (isset($moduleInfo['base_path'])) {
                    $modulesMapFile = rtrim($moduleInfo['base_path'], '/\\') . DS . 'view' . DS . 'statics' . DS . 'base' . DS . 'modules.map.json';
                }
            }
            
            if (!$modulesMapFile || !is_file($modulesMapFile)) {
                return $translations;
            }
            
            // 读取 modules.map.json 文件
            $modulesMapContent = file_get_contents($modulesMapFile);
            if (empty($modulesMapContent)) {
                return $translations;
            }
            
            $modulesMap = json_decode($modulesMapContent, true);
            if (!is_array($modulesMap)) {
                return $translations;
            }
            
            // 遍历声明的模块，提取翻译词
            foreach ($declaredModules as $moduleName) {
                if (isset($modulesMap[$moduleName]) && is_array($modulesMap[$moduleName])) {
                    $moduleData = $modulesMap[$moduleName];
                    
                    // 提取翻译词
                    // 新格式：翻译词直接作为模块对象的属性（排除 paths 字段）
                    // 兼容旧格式：翻译词在 i18n 字段中
                    if (isset($moduleData['i18n']) && is_array($moduleData['i18n'])) {
                        // 旧格式：从 i18n 字段提取
                        foreach ($moduleData['i18n'] as $word => $translation) {
                            if (is_string($translation)) {
                                $translations[$word] = $translation;
                            }
                        }
                    } else {
                        // 新格式：翻译词直接作为模块对象的属性
                        foreach ($moduleData as $key => $value) {
                            if ($key !== 'paths' && is_string($value)) {
                                // 这是翻译词：key 是原文，value 是翻译
                                $translations[$key] = $value;
                            }
                        }
                    }
                }
            }
        } catch (\Exception $e) {
            // 静默处理错误，避免影响模板编译
            w_log_error('从 modules.map.json 加载模块翻译词失败: ' . $e->getMessage(), [], 'i18n');
        }
        
        return $translations;
    }
}


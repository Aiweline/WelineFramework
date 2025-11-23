<?php
declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 作者：Admin
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Meta\Observer;

use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Framework\Manager\ObjectManager;

/**
 * 模板编译观察者
 * 在模板编译时提取 w:meta type="translate" 标签并提交翻译
 */
class TemplateCompile implements ObserverInterface
{
    public function execute(Event &$event): void
    {
        // 获取模板内容和文件路径
        $content = $event->getData('content');
        $tplFile = $event->getData('tplFile');
        
        if (empty($content) || empty($tplFile)) {
            return;
        }
        
        // 提取 w:meta type="translate" 标签
        $metaTranslations = $this->extractMetaTranslations($content, $tplFile);
        
        if (empty($metaTranslations)) {
            return;
        }
        
        // 触发翻译收集事件
        /** @var \Weline\Framework\Event\EventsManager $eventsManager */
        $eventsManager = ObjectManager::getInstance(\Weline\Framework\Event\EventsManager::class);
        $eventsManager->dispatch('Weline_I18n::collect_translations', [
            'translations' => $metaTranslations,
            'module' => 'Weline_Meta'
        ]);
    }
    
    /**
     * 从模板内容中提取 w:meta type="translate" 标签的翻译信息
     * 
     * @param string $content 模板内容
     * @param string $tplFile 模板文件路径
     * @return array 翻译词数组
     */
    private function extractMetaTranslations(string $content, string $tplFile): array
    {
        $translations = [];
        
        // 匹配 w:meta type="translate" 标签
        // 格式：<w:meta type="translate" prefix="..." scope="...">content</w:meta>
        // 使用非贪婪匹配，支持多行和嵌套标签
        $pattern = '/<w:meta\s+[^>]*type\s*=\s*["\']translate["\'][^>]*>(.*?)<\/w:meta>/is';
        
        if (!preg_match_all($pattern, $content, $matches, PREG_SET_ORDER)) {
            return $translations;
        }
        
        foreach ($matches as $match) {
            $fullTag = $match[0];
            $tagContent = trim($match[1]);
            
            if (empty($tagContent)) {
                continue;
            }
            
            // 提取属性
            $prefix = $this->extractAttribute($fullTag, 'prefix');
            $scope = $this->extractAttribute($fullTag, 'scope') ?: 'default';
            
            // 构建完整的 meta key（确保包含 @meta:: 命名空间）
            $metaKey = $this->buildMetaKey($tagContent, $prefix);
            
            // 如果 meta key 不包含 @meta:: 命名空间，跳过（避免错误）
            if (!str_starts_with($metaKey, '@meta::')) {
                continue;
            }
            
            // 从模板文件路径推断模块名（用于设置默认翻译值）
            $moduleName = $this->extractModuleNameFromPath($tplFile);
            
            // 构建翻译词
            // 使用原始内容作为默认翻译值
            $defaultValue = $tagContent; // 使用原始内容作为默认值
            
            $translations[] = [
                'word' => $metaKey, // 完整的 meta key，确保包含 @meta:: 命名空间
                'translate' => $defaultValue, // 使用原始内容作为默认翻译值
                'module' => $moduleName ?: 'Weline_Meta'
            ];
        }
        
        // 去重（基于 word）
        $uniqueTranslations = [];
        $seenKeys = [];
        foreach ($translations as $translation) {
            $key = $translation['word'];
            if (!isset($seenKeys[$key])) {
                $seenKeys[$key] = true;
                $uniqueTranslations[] = $translation;
            }
        }
        
        return $uniqueTranslations;
    }
    
    /**
     * 从标签中提取属性值
     * 
     * @param string $tag 标签字符串
     * @param string $attrName 属性名
     * @return string 属性值
     */
    private function extractAttribute(string $tag, string $attrName): string
    {
        // 匹配属性，支持单引号和双引号
        $pattern = '/\s+' . preg_quote($attrName, '/') . '\s*=\s*["\']([^"\']+)["\']/i';
        if (preg_match($pattern, $tag, $matches)) {
            return trim($matches[1]);
        }
        return '';
    }
    
    /**
     * 构建完整的 meta key（确保包含 @meta:: 命名空间）
     * 
     * @param string $content 标签内容（meta字段路径）
     * @param string $prefix 前缀
     * @return string 完整的 meta key
     */
    private function buildMetaKey(string $content, string $prefix): string
    {
        $metaKey = trim($content);
        
        // 如果已经是完整路径（以 @meta:: 开头），直接返回
        if (str_starts_with($metaKey, '@meta::')) {
            return $metaKey;
        }
        
        // 如果以 @meta. 开头，替换为 @meta::
        if (str_starts_with($metaKey, '@meta.')) {
            $metaKey = str_replace('@meta.', '', $metaKey);
        }
        
        // 补全前缀和命名空间
        if (!empty($prefix)) {
            // 有前缀时，构建：@meta::{prefix}.{content}
            $metaKey = '@meta::' . $prefix . '.' . $metaKey;
        } else {
            // 没有前缀时，确保有 @meta:: 命名空间
            // 如果内容不包含 @meta::，添加它
            if (!str_starts_with($metaKey, '@meta::')) {
                $metaKey = '@meta::' . $metaKey;
            }
        }
        
        return $metaKey;
    }
    
    /**
     * 从文件路径中提取模块名
     * 
     * @param string $filePath 文件路径
     * @return string 模块名
     */
    private function extractModuleNameFromPath(string $filePath): string
    {
        // 匹配路径中的模块名，如：app/code/Weline/Theme/view/...
        if (preg_match('/app\/code\/([^\/]+(?:_[^\/]+)?)/', $filePath, $matches)) {
            $moduleName = str_replace('/', '_', $matches[1]);
            return $moduleName;
        }
        
        return '';
    }
}


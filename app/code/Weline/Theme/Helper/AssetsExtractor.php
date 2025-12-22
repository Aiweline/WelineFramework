<?php
declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Theme\Helper;

/**
 * CSS/JS提取器
 * 
 * 从模板内容中提取<style>和<script>标签，强制移除内联标签以确保安全
 */
class AssetsExtractor
{
    /**
     * 从模板内容中提取CSS和JS
     * 
     * @param string $content 模板内容
     * @param string $sourceFile 源文件路径（用于标识partials）
     * @return array ['css' => string, 'js' => string, 'content' => string] 提取的CSS、JS和移除标签后的内容
     * @throws \Exception 如果发现内联标签且无法移除
     */
    public function extract(string $content, string $sourceFile = ''): array
    {
        $extractedCss = '';
        $extractedJs = '';
        $modifiedContent = $content;
        
        // 提取并移除style标签
        $modifiedContent = preg_replace_callback(
            '/<style[^>]*>(.*?)<\/style>/is',
            function($matches) use (&$extractedCss, $sourceFile) {
                $cssContent = $matches[1];
                $extractedCss .= $this->formatCssWithSource($cssContent, $sourceFile);
                return ''; // 移除标签
            },
            $modifiedContent
        );
        
        // 提取并移除script标签（排除theme.js的外部引用和data-no-extract标记的标签）
        $modifiedContent = preg_replace_callback(
            '/<script[^>]*>(.*?)<\/script>/is',
            function($matches) use (&$extractedJs, $sourceFile) {
                $scriptContent = $matches[0]; // 完整标签
                
                // 检查是否是theme.js的外部引用（src属性包含theme.js）
                if (preg_match('/src\s*=\s*["\'][^"\']*theme\.js["\']/i', $scriptContent)) {
                    // 保留theme.js的外部引用，不提取
                    return $matches[0];
                }
                
                // 检查是否有data-no-extract属性（不提取，保留在HTML中）
                if (preg_match('/data-no-extract\s*=\s*["\']?true["\']?/i', $scriptContent)) {
                    // 保留在HTML中，不提取
                    return $matches[0];
                }
                
                // 提取内联JS内容
                $jsContent = $matches[1];
                
                // 在提取之前检测PHP代码（使用正则 /<\?(?:php|=|\s)/i）
                if (preg_match('/<\?(?:php|=|\s)/i', $jsContent, $phpMatches, PREG_OFFSET_CAPTURE)) {
                    // 如果检测到PHP代码，阻止提取
                    if (!defined('DEV') || !DEV) {
                        // 生产环境：抛出异常
                        $phpPosition = (int)($phpMatches[0][1] ?? 0);
                        $lineNumber = substr_count(substr($jsContent, 0, $phpPosition), "\n") + 1;
                        $start = max(0, $phpPosition - 50);
                        $length = min(100, strlen($jsContent) - $start);
                        $snippet = substr($jsContent, $start, $length);
                        $snippet = str_replace(["\r", "\n"], ['', ' '], $snippet);
                        $snippet = trim($snippet);
                        if (strlen($snippet) > 100) {
                            $snippet = substr($snippet, 0, 97) . '...';
                        }
                        $source = $this->getSourceIdentifier($sourceFile);
                        throw new \Exception(
                            "编译错误：检测到PHP代码，禁止提取到外部JS文件。\n" .
                            "文件: {$sourceFile}\n" .
                            "来源: {$source}\n" .
                            "行号: {$lineNumber}\n" .
                            "代码片段: {$snippet}\n" .
                            "包含PHP代码的script标签必须添加data-no-extract=\"true\"属性，否则无法提取。"
                        );
                    } else {
                        // 开发环境：记录警告，跳过提取（保留在HTML中）
                        error_log('警告：发现PHP代码，跳过提取。文件: ' . $sourceFile);
                        return $matches[0]; // 保留在HTML中
                    }
                }
                
                $extractedJs .= $this->formatJsWithSource($jsContent, $sourceFile);
                return ''; // 移除标签
            },
            $modifiedContent
        );
        
        // 安全验证：确保无残留的内联标签（生产环境严格检查）
        if (!defined('DEV') || !DEV) {
            if (preg_match('/<style[^>]*>|<script[^>]*>(?!.*src)/i', $modifiedContent)) {
                throw new \Exception('发现内联样式或脚本，安全限制禁止内联代码。文件: ' . $sourceFile);
            }
        } else {
            // 开发环境：记录警告但不阻止
            if (preg_match('/<style[^>]*>|<script[^>]*>(?!.*src)/i', $modifiedContent)) {
                error_log('警告：发现内联样式或脚本残留。文件: ' . $sourceFile);
            }
        }
        
        return [
            'css' => $extractedCss,
            'js' => $extractedJs,
            'content' => $modifiedContent
        ];
    }
    
    /**
     * 格式化CSS，添加来源注释
     * 
     * @param string $css CSS内容
     * @param string $sourceFile 源文件
     * @return string 格式化后的CSS
     * @throws \Exception 如果CSS中包含PHP代码
     */
    private function formatCssWithSource(string $css, string $sourceFile): string
    {
        $css = trim($css);
        if (empty($css)) {
            return '';
        }
        
        // 检测CSS中是否包含PHP代码
        $this->detectPhpCode($css, $sourceFile, 'CSS');
        
        $source = $this->getSourceIdentifier($sourceFile);
        $formatted = "/* === SOURCE: {$source} === */\n";
        $formatted .= $css . "\n";
        $formatted .= "/* === END SOURCE: {$source} === */\n\n";
        
        return $formatted;
    }
    
    /**
     * 格式化JS，添加来源注释
     * 
     * @param string $js JS内容
     * @param string $sourceFile 源文件
     * @return string 格式化后的JS
     * @throws \Exception 如果JS中包含PHP代码
     */
    private function formatJsWithSource(string $js, string $sourceFile): string
    {
        $js = trim($js);
        if (empty($js)) {
            return '';
        }
        
        // 检测JS中是否包含PHP代码
        $this->detectPhpCode($js, $sourceFile, 'JS');
        
        $source = $this->getSourceIdentifier($sourceFile);
        $formatted = "/* === SOURCE: {$source} === */\n";
        $formatted .= $js . "\n";
        $formatted .= "/* === END SOURCE: {$source} === */\n\n";
        
        return $formatted;
    }
    
    /**
     * 检测内容中是否包含PHP代码
     * 
     * @param string $content 要检测的内容
     * @param string $sourceFile 源文件路径
     * @param string $type 内容类型（CSS或JS）
     * @return void
     * @throws \Exception 如果检测到PHP代码
     */
    private function detectPhpCode(string $content, string $sourceFile, string $type): void
    {
        // 检测PHP代码模式：<?、<?=、<?php（不区分大小写）
        $phpPattern = '/<\?(?:php|=|\s)/i';
        
        if (preg_match($phpPattern, $content, $matches, PREG_OFFSET_CAPTURE)) {
            // 获取匹配位置的行号
            $phpPosition = (int)($matches[0][1] ?? 0);
            $lineNumber = substr_count(substr($content, 0, $phpPosition), "\n") + 1;
            
            // 获取匹配位置附近的代码片段（前后各50个字符）
            $start = max(0, $phpPosition - 50);
            $length = min(100, strlen($content) - $start);
            $snippet = substr($content, $start, $length);
            $snippet = str_replace(["\r", "\n"], ['', ' '], $snippet);
            $snippet = trim($snippet);
            if (strlen($snippet) > 100) {
                $snippet = substr($snippet, 0, 97) . '...';
            }
            
            $source = $this->getSourceIdentifier($sourceFile);
            
            throw new \Exception(
                "编译错误：{$type}文件中检测到PHP代码。\n" .
                "文件: {$sourceFile}\n" .
                "来源: {$source}\n" .
                "行号: {$lineNumber}\n" .
                "代码片段: {$snippet}\n" .
                "提取的{$type}文件中禁止包含PHP代码，请使用HTML data属性或window对象传递配置数据。"
            );
        }
    }
    
    /**
     * 从文件路径提取来源标识符
     * 
     * @param string $filePath 文件路径
     * @return string 来源标识符（如 partials/header/default 或 layouts/homepage/default）
     */
    private function getSourceIdentifier(string $filePath): string
    {
        if (empty($filePath)) {
            return 'unknown';
        }
        
        // 提取partials或layouts路径
        if (preg_match('/(?:partials|layouts)\/([^\/]+)\/([^\/]+)\.phtml$/', $filePath, $matches)) {
            return $matches[1] . '/' . $matches[2];
        }
        
        // 提取文件名
        return basename($filePath, '.phtml');
    }
    
    /**
     * 合并多个提取结果
     * 
     * @param array $extractions 提取结果数组
     * @return array ['css' => string, 'js' => string] 合并后的CSS和JS
     */
    public function mergeExtractions(array $extractions): array
    {
        $mergedCss = '';
        $mergedJs = '';
        
        foreach ($extractions as $extraction) {
            if (isset($extraction['css'])) {
                $mergedCss .= $extraction['css'] . "\n";
            }
            if (isset($extraction['js'])) {
                $mergedJs .= $extraction['js'] . "\n";
            }
        }
        
        return [
            'css' => trim($mergedCss),
            'js' => trim($mergedJs)
        ];
    }
    
    /**
     * 增量更新CSS文件中的特定部分
     * 
     * @param string $cssFile CSS文件路径
     * @param string $sourceIdentifier 来源标识符
     * @param string $newCss 新的CSS内容
     * @return string 更新后的CSS内容
     */
    public function updateCssPartial(string $cssFile, string $sourceIdentifier, string $newCss): string
    {
        if (!is_file($cssFile)) {
            return $this->formatCssWithSource($newCss, $sourceIdentifier);
        }
        
        $content = file_get_contents($cssFile);
        
        // 查找并替换对应的部分
        $pattern = '/\/\* === SOURCE: ' . preg_quote($sourceIdentifier, '/') . ' === \*\/.*?\/\* === END SOURCE: ' . preg_quote($sourceIdentifier, '/') . ' === \*\//s';
        
        $replacement = $this->formatCssWithSource($newCss, $sourceIdentifier);
        
        if (preg_match($pattern, $content)) {
            // 替换现有部分
            $content = preg_replace($pattern, $replacement, $content);
        } else {
            // 添加新部分
            $content .= "\n" . $replacement;
        }
        
        return $content;
    }
    
    /**
     * 增量更新JS文件中的特定部分
     * 
     * @param string $jsFile JS文件路径
     * @param string $sourceIdentifier 来源标识符
     * @param string $newJs 新的JS内容
     * @return string 更新后的JS内容
     */
    public function updateJsPartial(string $jsFile, string $sourceIdentifier, string $newJs): string
    {
        if (!is_file($jsFile)) {
            return $this->formatJsWithSource($newJs, $sourceIdentifier);
        }
        
        $content = file_get_contents($jsFile);
        
        // 查找并替换对应的部分
        $pattern = '/\/\* === SOURCE: ' . preg_quote($sourceIdentifier, '/') . ' === \*\/.*?\/\* === END SOURCE: ' . preg_quote($sourceIdentifier, '/') . ' === \*\//s';
        
        $replacement = $this->formatJsWithSource($newJs, $sourceIdentifier);
        
        if (preg_match($pattern, $content)) {
            // 替换现有部分
            $content = preg_replace($pattern, $replacement, $content);
        } else {
            // 添加新部分
            $content .= "\n" . $replacement;
        }
        
        return $content;
    }
}


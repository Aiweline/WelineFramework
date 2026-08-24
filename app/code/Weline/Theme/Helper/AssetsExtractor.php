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
     * @param string|null $extractTargetCss CSS提取目标路径（开发模式下用于生成注释）
     * @param string|null $extractTargetJs JS提取目标路径（开发模式下用于生成注释）
     * @return array ['css' => string, 'js' => string, 'content' => string] 提取的CSS、JS和移除标签后的内容
     * @throws \Exception 如果发现内联标签且无法移除
     */
    public function extract(string $content, string $sourceFile = '', ?string $extractTargetCss = null, ?string $extractTargetJs = null): array
    {
        // 如果源文件路径为空，尝试从调用堆栈获取
        if (empty($sourceFile)) {
            $sourceFile = $this->getSourceFileFromTrace();
        }
        
        $extractedCss = '';
        $extractedJs = '';
        $modifiedContent = $content;
        
        // 提取并移除style标签
        $modifiedContent = preg_replace_callback(
            '/<style[^>]*>(.*?)<\/style>/is',
            function($matches) use (&$extractedCss, $sourceFile, $extractTargetCss) {
                $cssContent = $matches[1];
                $fullTag = $matches[0];
                
                // 检查是否已经有 data-no-extract 属性
                if (preg_match('/data-no-extract\s*=\s*["\']?true["\']?/i', $fullTag)) {
                    // 如果有 data-no-extract，保留在HTML中，不提取
                    return $fullTag;
                }
                
                // 在提取之前检测PHP代码
                // 如果检测到PHP代码，标记为需要后续处理（不提取，保留在HTML中，等待PHP执行后再提取）
                if (preg_match('/<\?(?:php|=|\s)/i', $cssContent)) {
                    // 检测到PHP代码，添加标记属性，不提取，保留在HTML中
                    // 这样在fetch_file_after事件中可以再次提取（此时PHP已执行）
                    // 添加 data-has-php 标记，表示包含PHP代码，需要在渲染后提取
                    return preg_replace('/(<style[^>]*)(>)/i', '$1 data-has-php="true"$2', $fullTag);
                }
                
                $extractedCss .= $this->formatCssWithSource($cssContent, $sourceFile);
                
                // 开发模式下，在原位置留下注释说明提取到哪里了
                if (defined('DEV') && DEV && $extractTargetCss) {
                    return "<!-- CSS已提取到: {$extractTargetCss} -->";
                } elseif (defined('DEV') && DEV) {
                    return "<!-- CSS已提取到布局CSS文件中 -->";
                }
                return ''; // 生产环境直接移除
            },
            $modifiedContent
        );
        
        // 提取并移除script标签（排除theme.js的外部引用和data-no-extract标记的标签）
        $modifiedContent = preg_replace_callback(
            '/<script[^>]*>(.*?)<\/script>/is',
            function($matches) use (&$extractedJs, $sourceFile, $extractTargetJs) {
                $scriptContent = $matches[0]; // 完整标签
                
                // 检查是否是theme.js的外部引用（src属性包含theme.js）
                if (preg_match('/src\s*=\s*["\'][^"\']*theme\.js["\']/i', $scriptContent)) {
                    // 保留theme.js的外部引用，不提取
                    return $matches[0];
                }

                // 任意带 src 的外部脚本：不得按「内联脚本」抽走并删除标签，否则依赖顺序的 jQuery 等会整段丢失（页面报 $ is not defined）。
                if (preg_match('/\ssrc\s*=\s*["\'][^"\']+["\']/i', $scriptContent)) {
                    return $matches[0];
                }
                
                // 检查是否有data-no-extract属性（不提取，保留在HTML中）
                // 正则表达式匹配：data-no-extract="true" 或 data-no-extract='true' 或 data-no-extract=true
                // 注意：必须优先检查 data-no-extract，在检查PHP代码之前
                $hasNoExtract = preg_match('/data-no-extract\s*=\s*["\']?true["\']?/i', $scriptContent);
                if ($hasNoExtract) {
                    // 保留在HTML中，不提取（无论是否包含PHP代码）
                    if (defined('DEV') && DEV) {
                        w_log_debug('AssetsExtractor: 跳过提取带有 data-no-extract="true" 的 script 标签');
                        w_log_debug('AssetsExtractor: script标签内容: ' . substr($scriptContent, 0, 200));
                    }
                    return $matches[0];
                }
                
                // 提取内联JS内容
                $jsContent = $matches[1];
                
                // 在提取之前检测PHP代码
                // 如果检测到PHP代码，说明内容来自编译后的模板文件（PHP未执行）
                // 在这种情况下，不应该提取，应该保留在HTML中等待PHP执行
                if (preg_match('/<\?(?:php|=|\s)/i', $jsContent)) {
                    // 检测到PHP代码，说明内容来自编译后的模板文件（PHP未执行）
                    // 在 after_render 事件中，这种情况不应该发生
                    // 如果发生，说明提取的内容来源错误（可能是从文件系统读取的编译后的模板文件）
                    $source = $this->getSourceIdentifier($sourceFile);
                    $displayPath = $this->getDisplayPath($sourceFile);
                    
                    // 检查是否已经有 data-no-extract 属性
                    // 如果有，说明这个标签本来就不应该被提取，直接返回原标签
                    if (preg_match('/data-no-extract\s*=/i', $scriptContent)) {
                        if (defined('DEV') && DEV) {
                            w_log_debug('AssetsExtractor: 检测到PHP代码，但标签有 data-no-extract 属性，保留在HTML中: ' . $displayPath);
                        }
                        return $scriptContent;
                    }
                    
                    // 如果没有 data-no-extract 属性，但在 after_render 事件中检测到PHP代码，说明内容来源错误
                    throw new \Exception(
                        __("严重错误：在 after_render 事件中检测到PHP代码\n\n位置信息：\n  源文件: %{1}\n  来源标识: %{2}\n\n错误说明：\n  在 after_render 事件中，PHP代码应该已经执行，渲染后的HTML中不应该再包含PHP标签。\n  如果仍然看到PHP代码，说明提取的内容来自编译后的模板文件，而不是渲染后的HTML。\n\n解决方案：\n  1. 确保在 after_render 事件中只处理渲染后的HTML内容\n  2. 不要从文件系统读取编译后的模板文件\n  3. 如果 script 标签包含PHP代码，请添加 data-no-extract=\"true\" 属性，不提取该标签", [
                            $displayPath,
                            $source
                        ])
                    );
                }
                
                $extractedJs .= $this->formatJsWithSource($jsContent, $sourceFile);
                
                // 开发模式下，在原位置留下注释说明提取到哪里了
                if (defined('DEV') && DEV && $extractTargetJs) {
                    return "<!-- JS已提取到: {$extractTargetJs} -->";
                } elseif (defined('DEV') && DEV) {
                    return "<!-- JS已提取到布局JS文件中 -->";
                }
                return ''; // 生产环境直接移除
            },
            $modifiedContent
        );
        
        // 最终验证：确保提取的CSS和JS中没有PHP代码
        // 这是双重保险，即使前面的检测漏掉了，这里也会捕获
        if (!empty($extractedCss)) {
            $this->validateExtractedContent($extractedCss, 'CSS', $sourceFile);
        }
        if (!empty($extractedJs)) {
            $this->validateExtractedContent($extractedJs, 'JS', $sourceFile);
        }
        
        // 安全验证：确保无残留的内联标签（开发和生产环境都严格检查）
        // 注意：排除带有 data-no-extract="true" 的标签和带有 src 属性的外部引用
        // 先匹配所有 style 和 script 标签，然后过滤掉带有 data-no-extract 或 src 的标签
        if (preg_match_all('/<(style|script)[^>]*>(.*?)<\/\1>/is', $modifiedContent, $allMatches, PREG_OFFSET_CAPTURE)) {
            foreach ($allMatches[0] as $index => $match) {
                $tagPosition = (int)($match[1] ?? 0);
                $tagContent = $match[0];
                $tagType = $allMatches[1][$index][0]; // style 或 script
                
                // 检查是否有 data-no-extract="true" 属性
                if (preg_match('/data-no-extract\s*=\s*["\']?true["\']?/i', $tagContent)) {
                    // 如果有 data-no-extract，跳过这个标签
                    continue;
                }
                
                // 检查是否是外部引用（有src属性）
                if (preg_match('/\ssrc\s*=\s*["\'][^"\']+["\']/i', $tagContent)) {
                    // 如果是外部引用，跳过
                    continue;
                }
                
                // 如果到这里，说明这是一个应该被提取但没有被提取的标签
                $lineNumber = substr_count(substr($modifiedContent, 0, $tagPosition), "\n") + 1;
                $columnNumber = $tagPosition - (strrpos(substr($modifiedContent, 0, $tagPosition), "\n") ?: -1);
                
                // 获取匹配位置附近的代码片段
                $start = max(0, $tagPosition - 100);
                $length = min(200, strlen($modifiedContent) - $start);
                $snippet = substr($modifiedContent, $start, $length);
                $snippetLines = explode("\n", $snippet);
                $snippet = implode("\n", array_slice($snippetLines, 0, 5)); // 最多显示5行
                if (count($snippetLines) > 5) {
                    $snippet .= "\n...";
                }
                
                $displayPath = $this->getDisplayPath($sourceFile);
                
                // 在闭包中使用全局函数调用（__函数是全局函数）
                throw new \Exception(
                    __("严重错误：发现内联样式或脚本残留\n\n位置信息：\n  源文件: %{1}\n  标签类型: <%{2}>\n  行号: 第 %{3} 行，第 %{4} 列\n  字符位置: %{5}\n\n问题代码片段：\n%{6}\n\n错误说明：\n  所有内联样式和脚本都应该被提取到外部文件中。\n  如果某些脚本需要保留在HTML中，请添加 data-no-extract=\"true\" 属性。\n\n解决方案：\n  1. 检查为什么该标签没有被提取（可能是提取逻辑问题）\n  2. 如果确实需要保留在HTML中，添加 data-no-extract=\"true\" 属性\n  3. 确保标签格式正确，可以被正则表达式匹配", [
                        $displayPath,
                        $tagType,
                        $lineNumber,
                        $columnNumber,
                        $tagPosition,
                        $snippet
                    ])
                );
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
     * @param string $css CSS内容（应该是编译后的，不包含PHP标签）
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
        
        // 严格检查：编译后的CSS中不允许有PHP代码
        $this->validateExtractedContent($css, 'CSS', $sourceFile);
        
        $source = $this->getSourceIdentifier($sourceFile);
        $formatted = "/* === SOURCE: {$source} === */\n";
        $formatted .= $css . "\n";
        $formatted .= "/* === END SOURCE: {$source} === */\n\n";
        
        return $formatted;
    }
    
    /**
     * 格式化JS，添加来源注释
     * 
     * @param string $js JS内容（应该是编译后的，不包含PHP标签）
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
        
        // 严格检查：编译后的JS中不允许有PHP代码
        $this->validateExtractedContent($js, 'JS', $sourceFile);
        
        $source = $this->getSourceIdentifier($sourceFile);
        $formatted = "/* === SOURCE: {$source} === */\n";
        $formatted .= $js . "\n";
        $formatted .= "/* === END SOURCE: {$source} === */\n\n";
        
        return $formatted;
    }
    
    /**
     * 检测内容中是否包含PHP代码（提取前检查）
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
     * 验证提取后的内容（编译后检查）
     * 严格检查编译后的CSS/JS中是否还有PHP代码，如果有则抛出严重错误
     * 
     * @param string $content 提取后的内容（应该是编译后的，不包含PHP标签）
     * @param string $type 内容类型（CSS或JS）
     * @param string $sourceFile 源文件路径
     * @return void
     * @throws \Exception 如果检测到PHP代码
     */
    private function validateExtractedContent(string $content, string $type, string $sourceFile): void
    {
        // 检测PHP代码模式：<?、<?=、<?php（不区分大小写）
        $phpPattern = '/<\?(?:php|=|\s)/i';
        
        if (preg_match($phpPattern, $content, $matches, PREG_OFFSET_CAPTURE)) {
            $phpPosition = (int)($matches[0][1] ?? 0);
            $lineNumber = substr_count(substr($content, 0, $phpPosition), "\n") + 1;
            
            // 获取匹配位置附近的代码片段（前后各100个字符）
            $start = max(0, $phpPosition - 100);
            $length = min(200, strlen($content) - $start);
            $snippet = substr($content, $start, $length);
            $snippet = str_replace(["\r", "\n"], ['', ' '], $snippet);
            $snippet = trim($snippet);
            if (strlen($snippet) > 200) {
                $snippet = substr($snippet, 0, 197) . '...';
            }
            
            $source = $this->getSourceIdentifier($sourceFile);
            $displayPath = $this->getDisplayPath($sourceFile);
            
            $snippetLines = explode("\n", $snippet);
            $snippet = implode("\n", array_slice($snippetLines, 0, 5)); // 最多显示5行
            if (count($snippetLines) > 5) {
                $snippet .= "\n...";
            }
            
            throw new \Exception(
                __("严重错误：编译后的%{1}文件中检测到PHP代码，这是不允许的\n\n位置信息：\n  源文件: %{2}\n  来源标识: %{3}\n  %{1}内容中的行号: 第 %{4} 行\n  PHP代码位置: 字符位置 %{5}\n\n问题代码片段：\n%{6}\n\n错误说明：\n  主题布局内不允许存在编译后还有PHP代码。\n  编译后的%{1}文件必须是纯%{1}，不能包含任何PHP代码。\n\n正确的做法：\n  1. 使用CSS变量：在模板中定义CSS变量，然后在CSS中使用 var()\n  2. 使用data属性：在HTML元素上设置data属性，然后在JS中读取\n  3. 使用window对象：在模板中设置 window.config = {...}，然后在JS中使用\n  4. 使用标签系统：使用框架提供的标签系统传递数据\n  5. 如果确实需要PHP代码，请添加 data-no-extract=\"true\" 属性，不提取该标签", [
                    $type,
                    $displayPath,
                    $source,
                    $lineNumber,
                    $phpPosition,
                    $snippet
                ])
            );
        }
    }
    
    /**
     * 从调用堆栈获取源文件路径
     * 
     * @return string 源文件路径
     */
    private function getSourceFileFromTrace(): string
    {
        $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 15);
        foreach ($trace as $frame) {
            if (isset($frame['file']) && strpos($frame['file'], '.phtml') !== false) {
                return $frame['file'];
            }
        }
        return '';
    }
    
    /**
     * 获取显示用的文件路径（绝对路径或相对路径）
     * 
     * @param string $filePath 文件路径
     * @return string 显示用的文件路径
     */
    private function getDisplayPath(string $filePath): string
    {
        if (empty($filePath)) {
            // 如果文件路径为空，尝试从调用堆栈获取
            $filePath = $this->getSourceFileFromTrace();
        }
        
        if (empty($filePath)) {
            return '未知文件';
        }
        
        // 尝试获取绝对路径
        $absolutePath = realpath($filePath);
        if ($absolutePath) {
            return $absolutePath;
        }
        
        // 如果无法获取绝对路径，返回原始路径
        return $filePath;
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
            // 如果文件路径为空，尝试从调用堆栈获取
            $filePath = $this->getSourceFileFromTrace();
            if (empty($filePath)) {
                return 'unknown';
            }
        }
        
        // 提取模块名和相对路径（优先）
        if (preg_match('/([^\/\\\\]+)[\/\\\\]view[\/\\\\](?:templates|theme)[\/\\\\](.+?)\.phtml$/i', $filePath, $matches)) {
            $moduleName = $matches[1];
            $relativePath = str_replace(DS, '/', $matches[2]);
            return $moduleName . '::' . $relativePath;
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


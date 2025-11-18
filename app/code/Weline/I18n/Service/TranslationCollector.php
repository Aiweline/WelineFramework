<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\I18n\Service;

use Weline\Framework\App\Env;

/**
 * 翻译字符串收集服务
 * 统一的翻译字符串提取服务，供模块管理器、主题创建和i18n收集命令使用
 */
class TranslationCollector
{
    /**
     * 从指定目录或模块收集翻译字符串
     * @param string|null $modulePath 模块路径，如果为null则收集所有模块
     * @param string|null $moduleName 模块名称（用于显示），如果为null则从路径推断
     * @return array [原文 => ['file' => 文件路径, 'context' => 上下文]]
     */
    public function collect(?string $modulePath = null, ?string $moduleName = null): array
    {
        $strings = [];
        
        if ($modulePath === null) {
            // 收集所有模块
            // 使用 path_CODE 收集 app/code 目录下的模块
            $appCodePath = Env::path_CODE;
            if (is_dir($appCodePath)) {
                $dirIterator = new \RecursiveDirectoryIterator($appCodePath, \RecursiveDirectoryIterator::SKIP_DOTS);
                $iterator = new \RecursiveIteratorIterator($dirIterator, \RecursiveIteratorIterator::SELF_FIRST);
                
                foreach ($iterator as $file) {
                    if ($file->isDir()) {
                        $depth = $iterator->getDepth();
                        if ($depth === 1) {
                            // 这是模块目录（Vendor/ModuleName）
                            $modulePath = $file->getPathname();
                            $pathParts = explode(DS, str_replace($appCodePath, '', $modulePath));
                            $pathParts = array_filter($pathParts);
                            $pathParts = array_values($pathParts);
                            
                            if (count($pathParts) === 2) {
                                $moduleName = $pathParts[0] . '_' . $pathParts[1];
                                $moduleStrings = $this->extractFromDirectory($modulePath, $moduleName);
                                $strings = array_merge($strings, $moduleStrings);
                            }
                        }
                    }
                }
            }
        } else {
            // 收集指定模块
            if (!is_dir($modulePath)) {
                return $strings;
            }
            
            if ($moduleName === null) {
                // 从路径推断模块名
                $pathParts = explode(DS, trim($modulePath, DS));
                if (count($pathParts) >= 2) {
                    $moduleName = $pathParts[count($pathParts) - 2] . '_' . $pathParts[count($pathParts) - 1];
                } else {
                    $moduleName = basename($modulePath);
                }
            }
            
            $strings = $this->extractFromDirectory($modulePath, $moduleName);
        }
        
        return $strings;
    }
    
    /**
     * 从指定目录提取翻译字符串
     * @param string $directory 目录路径
     * @param string $moduleName 模块名称
     * @return array [原文 => ['file' => 文件路径, 'context' => 上下文]]
     */
    private function extractFromDirectory(string $directory, string $moduleName): array
    {
        $strings = [];
        
        if (!is_dir($directory)) {
            return $strings;
        }
        
        try {
            // 使用递归迭代器扫描所有文件
            $dirIterator = new \RecursiveDirectoryIterator($directory, \RecursiveDirectoryIterator::SKIP_DOTS);
            $filterIterator = new \RecursiveCallbackFilterIterator($dirIterator, function ($current, $key, $iterator) {
                return true;
            });
            $iterator = new \RecursiveIteratorIterator($filterIterator);
            
            foreach ($iterator as $file) {
                if ($file->isFile() && in_array($file->getExtension(), ['php', 'phtml', 'js'])) {
                    $content = file_get_contents($file->getPathname());
                    $relativePath = str_replace($directory, '', $file->getPathname());
                    
                    // 1. 匹配 <lang>...</lang> 标签
                    if (preg_match_all('/<lang>(.*?)<\/lang>/s', $content, $matches)) {
                        foreach ($matches[1] as $match) {
                            $match = trim($match);
                            if (!empty($match) && $this->isValidTranslationString($match)) {
                                $strings[$match] = [
                                    'file' => $relativePath,
                                    'context' => 'Template',
                                    'module' => $moduleName
                                ];
                            }
                        }
                    }
                    
                    // 2. 匹配 @lang(...) 格式（只匹配字符串字面量，不匹配复杂表达式）
                    if (preg_match_all('/@lang\s*\(\s*(["\'])((?:[^\\\\\1\n\r]|\\\\.)*?)\1\s*\)/s', $content, $matches, PREG_OFFSET_CAPTURE)) {
                        foreach ($matches[2] as $index => $matchData) {
                            $match = $matchData[0];
                            
                            // 处理转义字符
                            $match = str_replace(['\\"', "\\'", '\\\\'], ['"', "'", '\\'], $match);
                            $match = trim($match);
                            if (!empty($match) && $this->isValidTranslationString($match)) {
                                $strings[$match] = [
                                    'file' => $relativePath,
                                    'context' => 'Template',
                                    'module' => $moduleName
                                ];
                            }
                        }
                    }
                    
                    // 3. 匹配 @lang{...} 格式
                    if (preg_match_all('/@lang\{(.*?)}/s', $content, $matches)) {
                        foreach ($matches[1] as $match) {
                            $match = trim($match);
                            if (!empty($match) && $this->isValidTranslationString($match)) {
                                $strings[$match] = [
                                    'file' => $relativePath,
                                    'context' => 'Template',
                                    'module' => $moduleName
                                ];
                            }
                        }
                    }
                    
                    // 4. 匹配 __('...') 或 __("...") 格式（使用改进的正则）
                    // 先匹配单行的简单情况，确保后面跟着逗号或右括号，且不包含换行
                    if (preg_match_all('/__\s*\(\s*(["\'])((?:[^\\\\\1\n\r]|\\\\.)*?)\1\s*([,\\)])/', $content, $matches, PREG_OFFSET_CAPTURE)) {
                        foreach ($matches[2] as $index => $matchData) {
                            $match = $matchData[0];
                            $quoteChar = $matches[1][$index][0];
                            
                            // 验证：确保匹配到的内容确实是完整的字符串（引号正确闭合）
                            // 检查字符串中是否有未转义的引号
                            $escapedQuote = '\\' . $quoteChar;
                            $quoteCount = substr_count($match, $quoteChar);
                            $escapedQuoteCount = substr_count($match, $escapedQuote);
                            $realQuoteCount = $quoteCount - $escapedQuoteCount;
                            
                            // 如果字符串中有未转义的引号，说明匹配可能有问题，跳过
                            if ($realQuoteCount > 0) {
                                continue;
                            }
                            
                            // 注意：即使后面跟着数组参数，我们也应该提取字符串内容
                            // 因为这是正常的__('...', [...])调用，字符串本身是有效的翻译字符串
                            
                            // 处理转义字符
                            $match = str_replace(['\\"', "\\'", '\\\\'], ['"', "'", '\\'], $match);
                            $match = trim($match);
                            if (!empty($match) && $this->isValidTranslationString($match)) {
                                $strings[$match] = [
                                    'file' => $relativePath,
                                    'context' => $file->getExtension() === 'php' ? 'PHP' : 'Template',
                                    'module' => $moduleName
                                ];
                            }
                        }
                    }
                    // 再匹配多行情况（但限制长度和复杂度，且不包含代码特征）
                    if (preg_match_all('/__\s*\(\s*(["\'])((?:(?<!\\\\)(?:\\\\\\\\)*\\\\\1|(?!\1).)*?)\1\s*([,\\)])/s', $content, $matches, PREG_OFFSET_CAPTURE)) {
                        foreach ($matches[2] as $index => $matchData) {
                            $match = $matchData[0];
                            $quoteChar = $matches[1][$index][0];
                            
                            // 验证：确保匹配到的内容确实是完整的字符串（引号正确闭合）
                            $escapedQuote = '\\' . $quoteChar;
                            $quoteCount = substr_count($match, $quoteChar);
                            $escapedQuoteCount = substr_count($match, $escapedQuote);
                            $realQuoteCount = $quoteCount - $escapedQuoteCount;
                            
                            if ($realQuoteCount > 0) {
                                continue;
                            }
                            
                            // 处理转义字符
                            $match = str_replace(['\\"', "\\'", '\\\\'], ['"', "'", '\\'], $match);
                            $match = trim($match);
                            
                            // 多行字符串需要更严格的验证
                            // 如果包含换行符，检查是否包含代码特征
                            if (strpos($match, "\n") !== false) {
                                // 检查是否包含代码特征（变量、方法调用等）
                                if (preg_match('/\$[a-zA-Z_]|->|::|\[.*\$|\(.*\$/', $match)) {
                                    continue;
                                }
                            }
                            
                            if (!empty($match) && strlen($match) <= 200 && $this->isValidTranslationString($match)) {
                                $strings[$match] = [
                                    'file' => $relativePath,
                                    'context' => $file->getExtension() === 'php' ? 'PHP' : 'Template',
                                    'module' => $moduleName
                                ];
                            }
                        }
                    }
                }
            }
        } catch (\Exception $e) {
            // 静默处理错误，避免中断收集过程
        }
        
        return $strings;
    }
    
    /**
     * 验证字符串是否为有效的翻译字符串
     * 过滤掉代码片段和不合理的内容
     * @param string $str
     * @return bool
     */
    public function isValidTranslationString(string $str): bool
    {
        // 空字符串或只包含空白字符
        if (empty(trim($str))) {
            return false;
        }
        
        // 过滤包含代码特征的内容
        $codePatterns = [
            '/\$[a-zA-Z_][a-zA-Z0-9_]*/',           // 变量：$var
            '/->[a-zA-Z_][a-zA-Z0-9_]*\s*\(/',      // 方法调用：->method(
            '/::[a-zA-Z_][a-zA-Z0-9_]*/',           // 静态调用：::CONSTANT
            '/\[.*?\$.*?\]/',                       // 数组访问包含变量：[]
            '/\(.*?\$.*?\)/',                       // 包含变量的括号
            '/function\s*\(/',                      // function(
            '/return\s+/',                          // return
            '/if\s*\(/',                            // if(
            '/else\s*\{/',                          // else {
            '/foreach\s*\(/',                       // foreach(
            '/while\s*\(/',                         // while(
            '/for\s*\(/',                           // for(
            '/class\s+/',                           // class
            '/namespace\s+/',                       // namespace
            '/use\s+/',                             // use
            '/extends\s+/',                         // extends
            '/implements\s+/',                      // implements
            '/getMessageManager\(\)/',              // getMessageManager()
            '/getData\(/',                          // getData(
            '/addWarning\(/',                       // addWarning(
            '/fields_[A-Z_]+/',                     // fields_CONSTANT
            '/php\s+bin/',                          // php bin
            '/translate:model/',                    // translate:model
            '/i18n:collect/',                       // i18n:collect
            '/\$this->/',                           // $this->
            '/\]\)/',                               // ]) 代码结束标记
            '/\)\)/',                               // )) 嵌套括号结束
            '/\'\s*,\s*\[/',                        // ', [' 数组参数开始
            '/\'\s*\)/',                            // ') 字符串后跟括号
            '/\$this->countries/',                  // $this->countries
            '/\$this->localeNames/',                // $this->localeNames
            '/Name::fields/',                       // Name::fields
        ];
        
        foreach ($codePatterns as $pattern) {
            if (preg_match($pattern, $str)) {
                return false;
            }
        }
        
        // 过滤包含多个连续特殊字符的（可能是代码）
        if (preg_match('/[{};]{2,}/', $str)) {
            return false;
        }
        
        // 过滤包含未闭合括号的（可能是代码片段）
        $openParens = substr_count($str, '(');
        $closeParens = substr_count($str, ')');
        if ($openParens > 0 && $openParens !== $closeParens) {
            // 允许占位符 %{1} 等，但不允许不匹配的括号
            $placeholderParens = preg_match_all('/%\{[^}]+\}/', $str);
            $realOpenParens = $openParens - $placeholderParens;
            $realCloseParens = $closeParens - $placeholderParens;
            if ($realOpenParens !== $realCloseParens) {
                return false;
            }
        }
        
        // 过滤包含多个连续空格或制表符的（可能是代码缩进）
        if (preg_match('/[ \t]{4,}/', $str)) {
            return false;
        }
        
        // 过滤以引号开头或结尾但中间包含代码的
        if (preg_match('/^["\'].*[,\[\]\$].*["\']$/', $str)) {
            // 检查是否包含代码特征
            if (preg_match('/\$|->|::|\[.*\$|\(.*\$/', $str)) {
                return false;
            }
        }
        
        // 过滤包含换行符且长度过长的（可能是多行代码片段）
        if (strpos($str, "\n") !== false && strlen($str) > 100) {
            return false;
        }
        
        // 过滤包含数组参数标记的（如 ', [' 或 ',['）
        if (preg_match('/["\']\s*,\s*\[/', $str)) {
            return false;
        }
        
        // 过滤包含方法调用参数的（如 '...', [$var 或 '...', $var）
        if (preg_match('/["\']\s*,\s*\$/', $str)) {
            return false;
        }
        
        // 过滤包含未闭合引号的（可能是代码片段的一部分）
        $singleQuotes = substr_count($str, "'") - substr_count($str, "\\'");
        $doubleQuotes = substr_count($str, '"') - substr_count($str, '\\"');
        if (($singleQuotes % 2 !== 0 && strpos($str, "'") !== false) || 
            ($doubleQuotes % 2 !== 0 && strpos($str, '"') !== false)) {
            // 允许占位符中的引号，但检查是否有未闭合的引号
            if (preg_match('/["\'].*[^\\\]$/', $str) && !preg_match('/%\{[^}]*["\']/', $str)) {
                return false;
            }
        }
        
        return true;
    }
}


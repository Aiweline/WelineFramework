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
     * 从指定目录或模块收集翻译字符串（数组版本，向后兼容）
     * 需要完整数组时使用（如 array_keys、count 等操作）
     * @param string|null $modulePath 模块路径，如果为null则收集所有模块
     * @param string|null $moduleName 模块名称（用于显示），如果为null则从路径推断
     * @return array [原文 => ['file' => 文件路径, 'context' => 上下文]]
     */
    public function collect(?string $modulePath = null, ?string $moduleName = null): array
    {
        return iterator_to_array($this->collectLazy($modulePath, $moduleName));
    }

    private function yieldFiberCheckpoint(int $processedFiles): void
    {
        if ($processedFiles % self::FIBER_CHECKPOINT_INTERVAL !== 0 || !class_exists(\Fiber::class)) {
            return;
        }

        if (\Fiber::getCurrent() !== null) {
            \Fiber::suspend();
        }
    }

    /**
     * 从指定目录或模块收集翻译字符串（惰性 Generator 版本）
     * 逐条产出翻译字符串，不在内存中累积完整数组，适合大规模扫描场景
     * @param string|null $modulePath 模块路径，如果为null则收集所有模块
     * @param string|null $moduleName 模块名称（用于显示），如果为null则从路径推断
     * @return \Generator<string, array{file: string, context: string, module: string}>
     */
    public function collectLazy(?string $modulePath = null, ?string $moduleName = null): \Generator
    {
        if ($modulePath === null) {
            // 收集所有模块
            $appCodePath = Env::path_CODE;
            if (is_dir($appCodePath)) {
                $dirIterator = new \RecursiveDirectoryIterator($appCodePath, \RecursiveDirectoryIterator::SKIP_DOTS);
                $iterator = new \RecursiveIteratorIterator($dirIterator, \RecursiveIteratorIterator::SELF_FIRST);
                
                foreach ($iterator as $file) {
                    if ($file->isDir()) {
                        $depth = $iterator->getDepth();
                        if ($depth === 1) {
                            $modPath = $file->getPathname();
                            $pathParts = explode(DS, str_replace($appCodePath, '', $modPath));
                            $pathParts = array_filter($pathParts);
                            $pathParts = array_values($pathParts);
                            
                            if (count($pathParts) === 2) {
                                $modName = $pathParts[0] . '_' . $pathParts[1];
                                // yield from 直接透传子生成器，无中间数组
                                yield from $this->yieldFromDirectory($modPath, $modName);
                            }
                        }
                    }
                }
            }
        } else {
            if (!is_dir($modulePath)) {
                return;
            }
            
            if ($moduleName === null) {
                $pathParts = explode(DS, trim($modulePath, DS));
                if (count($pathParts) >= 2) {
                    $moduleName = $pathParts[count($pathParts) - 2] . '_' . $pathParts[count($pathParts) - 1];
                } else {
                    $moduleName = basename($modulePath);
                }
            }
            
            yield from $this->yieldFromDirectory($modulePath, $moduleName);
        }
    }
    
    /**
     * 需要排除的目录名（这些目录不包含需要翻译的源码）
     */
    private const EXCLUDED_DIRS = [
        'node_modules' => true,
        'vendor' => true,
        'dist' => true,
        'build' => true,
        '.git' => true,
        '.svn' => true,
        'cache' => true,
        'generated' => true,
        'var' => true,
        'pub' => true,
        'doc' => true,
        'docs' => true,
        'test' => true,
        'tests' => true,
        'coverage' => true,
        'tmp' => true,
        'temp' => true,
        'log' => true,
        'logs' => true,
        'wasm' => true,
        'lib' => true,
        'libs' => true,
        'third-party' => true,
        'third_party' => true,
        'thirdparty' => true,
        'browser-extension' => true,
        'browser-extension-backup' => true,
        'weline-browser-mcp' => true,
        'bower_components' => true,
        '.idea' => true,
        '.vscode' => true,
        '.cursor' => true,
    ];

    private const SCAN_FILE_EXTENSIONS = [
        'php' => true,
        'phtml' => true,
        'js' => true,
    ];

    private const FIBER_CHECKPOINT_INTERVAL = 8;

    /**
     * 读取文件的最大大小（字节），超过此大小的文件跳过（512KB）
     * 正常的翻译源文件不会超过这个大小，大文件通常是打包产物
     */
    private const MAX_FILE_SIZE = 524288;

    private const CODE_PATTERNS = [
        '/\$[a-zA-Z_][a-zA-Z0-9_]*/',
        '/->[a-zA-Z_][a-zA-Z0-9_]*\s*\(/',
        '/::[a-zA-Z_][a-zA-Z0-9_]*/',
        '/\[.*?\$.*?\]/',
        '/\(.*?\$.*?\)/',
        '/function\s*\(/',
        '/return\s+/',
        '/if\s*\(/',
        '/else\s*\{/',
        '/foreach\s*\(/',
        '/while\s*\(/',
        '/for\s*\(/',
        '/class\s+/',
        '/namespace\s+/',
        '/use\s+/',
        '/extends\s+/',
        '/implements\s+/',
        '/getMessageManager\(\)/',
        '/getData\(/',
        '/addWarning\(/',
        '/fields_[A-Z_]+/',
        '/php\s+bin/',
        '/translate:model/',
        '/i18n:collect/',
        '/\$this->/',
        '/\]\)/',
        '/\)\)/',
        '/\'\s*,\s*\[/',
        '/\'\s*\)/',
        '/\$this->countries/',
        '/\$this->localeNames/',
        '/Name::fields/',
    ];

    /**
     * 从指定目录提取翻译字符串（Generator 版本）
     * 逐文件读取、逐条产出，内存中同时只持有一个文件的内容
     * @param string $directory 目录路径
     * @param string $moduleName 模块名称
     * @return \Generator<string, array{file: string, context: string, module: string}>
     */
    private function yieldFromDirectory(string $directory, string $moduleName): \Generator
    {
        if (!is_dir($directory)) {
            return;
        }
        
        try {
            $dirIterator = new \RecursiveDirectoryIterator($directory, \RecursiveDirectoryIterator::SKIP_DOTS);
            $filterIterator = new \RecursiveCallbackFilterIterator($dirIterator, function ($current, $key, $iterator) {
                if ($current->isDir() && isset(self::EXCLUDED_DIRS[strtolower($current->getFilename())])) {
                    return false;
                }
                return true;
            });
            $iterator = new \RecursiveIteratorIterator($filterIterator);
            $processedFiles = 0;
            $directoryPrefixLength = strlen(rtrim($directory, '/\\'));
            
            foreach ($iterator as $file) {
                $ext = strtolower($file->getExtension());
                if (!$file->isFile() || !isset(self::SCAN_FILE_EXTENSIONS[$ext])) {
                    continue;
                }
                // 跳过超大文件（打包产物、minified JS 等）
                $fileSize = $file->getSize();
                if ($fileSize > self::MAX_FILE_SIZE || $fileSize === 0) {
                    continue;
                }
                
                $pathname = $file->getPathname();
                $content = file_get_contents($pathname);
                if ($content === false) {
                    continue;
                }
                $processedFiles++;
                if (!$this->hasTranslationMarkers($content)) {
                    unset($content);
                    $this->yieldFiberCheckpoint($processedFiles);
                    continue;
                }
                $relativePath = substr($pathname, $directoryPrefixLength) ?: $pathname;
                
                // 1. 匹配 <lang>...</lang> 标签
                if (preg_match_all('/<lang>(.*?)<\/lang>/s', $content, $matches)) {
                    foreach ($matches[1] as $match) {
                        $match = trim($match);
                        if (!empty($match) && $this->isValidTranslationString($match)) {
                            yield $match => ['file' => $relativePath, 'context' => 'Template', 'module' => $moduleName];
                        }
                    }
                }
                
                // 2. 匹配 @lang(...) 格式（只匹配字符串字面量）
                if (preg_match_all('/@lang\s*\(\s*(["\'])((?:[^\\\\\1\n\r]|\\\\.)*?)\1\s*\)/s', $content, $matches, PREG_OFFSET_CAPTURE)) {
                    foreach ($matches[2] as $index => $matchData) {
                        $match = str_replace(['\\"', "\\'", '\\\\'], ['"', "'", '\\'], $matchData[0]);
                        $match = trim($match);
                        if (!empty($match) && $this->isValidTranslationString($match)) {
                            yield $match => ['file' => $relativePath, 'context' => 'Template', 'module' => $moduleName];
                        }
                    }
                }
                
                // 3. 匹配 @lang{...} 格式
                if (preg_match_all('/@lang\{(.*?)}/s', $content, $matches)) {
                    foreach ($matches[1] as $match) {
                        $match = trim($match);
                        if (!empty($match) && $this->isValidTranslationString($match)) {
                            yield $match => ['file' => $relativePath, 'context' => 'Template', 'module' => $moduleName];
                        }
                    }
                }
                
                // 4. 匹配 __('...') 或 __("...") 格式 - 单行
                $context = ($ext === 'php') ? 'PHP' : 'Template';
                if (preg_match_all('/__\s*\(\s*(["\'])((?:[^\\\\\1\n\r]|\\\\.)*?)\1\s*([,\\)])/', $content, $matches, PREG_OFFSET_CAPTURE)) {
                    foreach ($matches[2] as $index => $matchData) {
                        $match = $matchData[0];
                        $quoteChar = $matches[1][$index][0];
                        
                        $escapedQuote = '\\' . $quoteChar;
                        if ((substr_count($match, $quoteChar) - substr_count($match, $escapedQuote)) > 0) {
                            continue;
                        }
                        
                        $match = str_replace(['\\"', "\\'", '\\\\'], ['"', "'", '\\'], $match);
                        $match = trim($match);
                        if (!empty($match) && $this->isValidTranslationString($match)) {
                            yield $match => ['file' => $relativePath, 'context' => $context, 'module' => $moduleName];
                        }
                    }
                }
                
                // 5. 匹配 __(...) 多行（限制长度和复杂度）
                if (preg_match_all('/__\s*\(\s*(["\'])((?:(?<!\\\\)(?:\\\\\\\\)*\\\\\1|(?!\1).)*?)\1\s*([,\\)])/s', $content, $matches, PREG_OFFSET_CAPTURE)) {
                    foreach ($matches[2] as $index => $matchData) {
                        $match = $matchData[0];
                        $quoteChar = $matches[1][$index][0];
                        
                        $escapedQuote = '\\' . $quoteChar;
                        if ((substr_count($match, $quoteChar) - substr_count($match, $escapedQuote)) > 0) {
                            continue;
                        }
                        
                        $match = str_replace(['\\"', "\\'", '\\\\'], ['"', "'", '\\'], $match);
                        $match = trim($match);
                        
                        if (strpos($match, "\n") !== false && preg_match('/\$[a-zA-Z_]|->|::|\[.*\$|\(.*\$/', $match)) {
                            continue;
                        }
                        
                        if (!empty($match) && strlen($match) <= 200 && $this->isValidTranslationString($match)) {
                            yield $match => ['file' => $relativePath, 'context' => $context, 'module' => $moduleName];
                        }
                    }
                }
                
                // Generator 自然释放：$content 在下次循环迭代时被覆盖
                // 显式 unset 确保大文件立即释放
                unset($content);
                $this->yieldFiberCheckpoint($processedFiles);
            }
        } catch (\Exception $e) {
            // 静默处理错误，避免中断收集过程
        }
    }

    private function hasTranslationMarkers(string $content): bool
    {
        return str_contains($content, '__')
            || str_contains($content, '@lang')
            || str_contains($content, '<lang');
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
        foreach (self::CODE_PATTERNS as $pattern) {
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


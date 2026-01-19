<?php

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

use Weline\Framework\App\Exception;

if (!defined('BP')) {
    define('BP', dirname(__DIR__) . DIRECTORY_SEPARATOR);
}
// 检查安装
if ((PHP_SAPI !== 'cli') and !file_exists(BP . 'setup' . DIRECTORY_SEPARATOR . 'install.lock')) {
    require BP . 'setup' . DIRECTORY_SEPARATOR . 'index.php';
    exit();
}
// 第三方代码目录
if (!defined('VENDOR_PATH')) {
    define('VENDOR_PATH', BP . 'vendor' . DIRECTORY_SEPARATOR);
}
// 应用代码目录
if (!defined('APP_CODE_PATH')) {
    define('APP_CODE_PATH', BP . 'app' . DIRECTORY_SEPARATOR . 'code' . DIRECTORY_SEPARATOR);
}
// 注册 app/code 和 generated/code 优先的自动加载器（在 Composer 之前）
// 只在类未加载时检查，性能影响最小
// 使用静态变量记录已加载的文件，防止重复加载
spl_autoload_register(function ($class) {
    static $loadedFiles = [];
    
    // 如果类已加载，直接返回（避免重复检查）
    if (class_exists($class, false) || interface_exists($class, false) || trait_exists($class, false)) {
        return true; // 已加载，停止自动加载链
    }
    
    // 规范化路径，确保路径一致性
    $relativePath = str_replace('\\', DIRECTORY_SEPARATOR, $class) . '.php';
    
    // 首先检查 generated/code 目录（拦截器类）
    $generatedPath = BP . 'generated' . DIRECTORY_SEPARATOR . 'code' . DIRECTORY_SEPARATOR . $relativePath;
    $normalizedGeneratedPath = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $generatedPath);
    
    if (!isset($loadedFiles[$normalizedGeneratedPath]) && file_exists($normalizedGeneratedPath)) {
        $loadedFiles[$normalizedGeneratedPath] = true;
        require_once $normalizedGeneratedPath;
        if (class_exists($class, false) || interface_exists($class, false) || trait_exists($class, false)) {
            return true;
        }
    }
    
    // 然后检查 app/code 目录
    $fullPath = APP_CODE_PATH . $relativePath;
    $normalizedPath = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $fullPath);
    
    // 如果文件已被加载过，直接返回
    if (isset($loadedFiles[$normalizedPath])) {
        return true; // 文件已加载，停止自动加载链
    }
    
    // 如果 app/code 下存在该类文件，优先加载它
    if (file_exists($normalizedPath)) {
        // 标记文件为已加载（在 require 之前，防止并发问题和重复加载）
        $loadedFiles[$normalizedPath] = true;
        // 使用 require_once 防止重复加载（即使类定义失败，文件也只加载一次）
        require_once $normalizedPath;
        // 验证类是否成功加载
        if (class_exists($class, false) || interface_exists($class, false) || trait_exists($class, false)) {
            return true; // 已加载，停止自动加载链
        }
        // 即使类没有成功定义，也返回 true 阻止其他自动加载器再次加载同一文件
        // 这样可以避免 "Cannot redeclare class" 错误
        return true;
    }
    
    return false; // 返回 false 让其他自动加载器继续处理
}, true, true); // prepend=true 表示优先于其他自动加载器

// 检测Composer自动加载代理
try {
    $autoloader = VENDOR_PATH . 'autoload.php';
    if (is_file($autoloader)) {
        // 如果是 Web 请求（非 CLI），阻止加载 Pest 测试框架的函数文件
        // 请求生命周期中不允许运行测试框架
        if (PHP_SAPI !== 'cli') {
            // 在加载 Composer 自动加载器之前，定义 Pest 函数为已存在（阻止加载）
            // 这样可以防止 Composer 的 autoload_files.php 加载 Pest 的函数文件
            if (!function_exists('beforeEach')) {
                function beforeEach() { throw new \Exception('Pest 测试框架不允许在 Web 请求生命周期中运行'); }
            }
            if (!function_exists('test')) {
                function test() { throw new \Exception('Pest 测试框架不允许在 Web 请求生命周期中运行'); }
            }
            if (!function_exists('it')) {
                function it() { throw new \Exception('Pest 测试框架不允许在 Web 请求生命周期中运行'); }
            }
            if (!function_exists('afterEach')) {
                function afterEach() { throw new \Exception('Pest 测试框架不允许在 Web 请求生命周期中运行'); }
            }
        }
        
        $composerLoader = require $autoloader;
        
        // 获取所有 PSR-4 映射并修改，确保 app/code 路径优先
        $psr4Map = $composerLoader->getPrefixesPsr4();
        
        foreach ($psr4Map as $prefix => $paths) {
            // 将命名空间前缀转换为目录路径
            // Weline\Admin\ -> Weline/Admin/
            $relativePath = str_replace('\\', DIRECTORY_SEPARATOR, trim($prefix, '\\'));
            $appCodePath = APP_CODE_PATH . $relativePath . DIRECTORY_SEPARATOR;
            
            // 如果 app/code 下存在对应的目录
            if (is_dir($appCodePath)) {
                // 移除已存在的 app/code 路径（避免重复）
                $paths = array_filter($paths, function($path) use ($appCodePath) {
                    $normalizedPath = rtrim($path, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
                    return $normalizedPath !== $appCodePath;
                });
                // 将 app/code 路径添加到数组最前面
                array_unshift($paths, $appCodePath);
                // 重新设置映射
                $composerLoader->setPsr4($prefix, array_values($paths));
            }
        }
    } else {
        exit('Composer自动加载异常!尝试执行：php composer.phar install');
    }
} catch (Exception $exception) {
    exit('自动加载异常：' . $exception->getMessage());
}
// 加载通用函数

// 尝试加载应用
try {
    /**
     * 初始化应用...
     */
    \Weline\Framework\App::run();
} catch (Exception $exception) {
    if (DEV) {
        // 美化错误显示
        echo '<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>应用错误</title>
    <style>
        body { 
            font-family: "Consolas", "Monaco", "Courier New", monospace; 
            background: #1e1e1e; 
            color: #d4d4d4; 
            margin: 0; 
            padding: 20px; 
            line-height: 1.6;
        }
        .error-container { 
            background: #2d2d30; 
            border: 1px solid #3e3e42; 
            border-radius: 8px; 
            padding: 20px; 
            margin: 20px 0;
            box-shadow: 0 4px 8px rgba(0,0,0,0.3);
        }
        .error-title { 
            color: #f48771; 
            font-size: 18px; 
            font-weight: bold; 
            margin-bottom: 15px;
            border-bottom: 2px solid #f48771;
            padding-bottom: 10px;
        }
        .error-message { 
            color: #ce9178; 
            background: #3c3c3c; 
            padding: 15px; 
            border-radius: 4px; 
            margin: 10px 0;
            border-left: 4px solid #f48771;
        }
        .stack-trace { 
            background: #252526; 
            padding: 15px; 
            border-radius: 4px; 
            margin: 10px 0;
            overflow-x: auto;
        }
        .stack-line { 
            margin: 5px 0; 
            padding: 5px 0;
        }
        .stack-line.highlight { 
            background: #4a4a4a; 
            color: #ff6b6b; 
            font-weight: bold; 
            padding: 8px; 
            border-radius: 4px;
            border-left: 4px solid #ff6b6b;
        }
        .file-path { 
            color: #9cdcfe; 
        }
        .line-number { 
            color: #b5cea8; 
        }
        .method-name { 
            color: #dcdcaa; 
        }
        .debug-info { 
            background: #1e1e1e; 
            border: 1px solid #3e3e42; 
            border-radius: 4px; 
            padding: 15px; 
            margin: 10px 0;
        }
    </style>
</head>
<body>
    <div class="error-container">
        <div class="error-title">🚨 应用启动失败</div>
        <div class="error-message">' . htmlspecialchars($exception->getMessage()) . '</div>
        
        <div style="margin: 20px 0;">
            <strong style="color: #569cd6;">错误位置：</strong>
            <div class="file-path">' . htmlspecialchars($exception->getFile()) . '</div>
            <div class="line-number">第 ' . $exception->getLine() . ' 行</div>
        </div>
        
        <div style="margin: 20px 0;">
            <strong style="color: #569cd6;">调用堆栈：</strong>
            <div class="stack-trace">';
        
        // 处理堆栈跟踪
        $trace = $exception->getTrace();
        $traceString = $exception->getTraceAsString();
        $lines = explode("\n", $traceString);
        
        foreach ($lines as $index => $line) {
            if (empty(trim($line))) continue;
            
            // 检查是否是关键错误行（包含call_user_func的行）
            $isHighlight = false;
            if (strpos($line, 'call_user_func') !== false) {
                $isHighlight = true;
            }
            
            // 检查是否是用户代码行（不包含框架内部文件）
            $isUserCode = false;
            if (strpos($line, 'Weline\\I18n\\') !== false || 
                strpos($line, 'app/code/Weline/I18n/') !== false) {
                $isUserCode = true;
            }
            
            $lineClass = '';
            if ($isHighlight) {
                $lineClass = 'highlight';
            } elseif ($isUserCode) {
                $lineClass = 'style="background: #2d4a2d; padding: 5px; border-radius: 3px; border-left: 3px solid #4caf50;"';
            }
            
            echo '<div class="stack-line ' . $lineClass . '" ' . ($isUserCode ? $lineClass : '') . '>' . htmlspecialchars($line) . '</div>';
        }
        
        echo '</div></div>';
        
        if (DEBUG) {
            echo '<div class="debug-info">
                <strong style="color: #569cd6;">DEBUG 详细信息：</strong>
                <pre style="margin: 10px 0; overflow-x: auto;">' . htmlspecialchars(print_r(debug_backtrace(), true)) . '</pre>
            </div>';
        }
        
        echo '</div></body></html>';
    } else {
        echo '<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <title>系统异常</title>
    <style>
        body { 
            font-family: Arial, sans-serif; 
            background: #f5f5f5; 
            color: #333; 
            margin: 0; 
            padding: 50px; 
            text-align: center;
        }
        .error-container { 
            background: white; 
            border: 1px solid #ddd; 
            border-radius: 8px; 
            padding: 40px; 
            margin: 0 auto; 
            max-width: 500px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        .error-title { 
            color: #e74c3c; 
            font-size: 24px; 
            margin-bottom: 20px;
        }
    </style>
</head>
<body>
    <div class="error-container">
        <div class="error-title">⚠️ 系统异常</div>
        <p>请联系网站管理员进行修复！</p>
    </div>
</body>
</html>';
        exit(0);
    }
}

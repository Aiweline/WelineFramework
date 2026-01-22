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
// 说明：类映射缓存在 setup:upgrade 时生成，运行时只读取不更新
spl_autoload_register(function ($class) {
    // 静态缓存：记录已加载的文件
    static $loadedFiles = [];
    static $classMap = null;
    static $classMapLoaded = false;
    
    // 如果类已加载，直接返回（避免重复检查）
    if (class_exists($class, false) || interface_exists($class, false) || trait_exists($class, false)) {
        return true;
    }
    
    // 首次调用时加载类映射缓存（仅在生产模式有效）
    // 缓存文件由 setup:upgrade 命令生成
    if (!$classMapLoaded) {
        $classMapLoaded = true;
        $classMapFile = BP . 'generated' . DIRECTORY_SEPARATOR . 'classmap.php';
        if (is_file($classMapFile)) {
            $classMap = @include $classMapFile;
            if (!is_array($classMap)) {
                $classMap = null;
            }
        }
    }
    
    // 如果有类映射缓存且命中，直接加载
    if ($classMap !== null && isset($classMap[$class])) {
        $cachedPath = $classMap[$class];
        if (!isset($loadedFiles[$cachedPath]) && is_file($cachedPath)) {
            $loadedFiles[$cachedPath] = true;
            require_once $cachedPath;
            return true;
        }
    }
    
    // 规范化路径
    $relativePath = str_replace('\\', DIRECTORY_SEPARATOR, $class) . '.php';
    
    // 首先检查 generated/code 目录（拦截器类）
    $generatedPath = BP . 'generated' . DIRECTORY_SEPARATOR . 'code' . DIRECTORY_SEPARATOR . $relativePath;
    
    if (!isset($loadedFiles[$generatedPath]) && is_file($generatedPath)) {
        $loadedFiles[$generatedPath] = true;
        require_once $generatedPath;
        if (class_exists($class, false) || interface_exists($class, false) || trait_exists($class, false)) {
            return true;
        }
    }
    
    // 然后检查 app/code 目录
    $fullPath = APP_CODE_PATH . $relativePath;
    
    // 如果文件已被加载过，直接返回
    if (isset($loadedFiles[$fullPath])) {
        return true;
    }
    
    // 如果 app/code 下存在该类文件，优先加载它
    if (is_file($fullPath)) {
        $loadedFiles[$fullPath] = true;
        require_once $fullPath;
        if (class_exists($class, false) || interface_exists($class, false) || trait_exists($class, false)) {
            return true;
        }
        return true;
    }
    
    return false;
}, true, true);

// 检测Composer自动加载代理
try {
    $autoloader = VENDOR_PATH . 'autoload.php';
    if (is_file($autoloader)) {
        // 如果是 Web 请求（非 CLI），阻止加载 Pest 测试框架的函数文件
        if (PHP_SAPI !== 'cli') {
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
        
        // PSR-4 映射优化：缓存由 setup:upgrade 生成，运行时只读取
        $psr4CacheFile = BP . 'generated' . DIRECTORY_SEPARATOR . 'psr4_map.php';
        
        // 尝试加载缓存
        if (is_file($psr4CacheFile)) {
            $cachedPsr4 = @include $psr4CacheFile;
            if (is_array($cachedPsr4) && !empty($cachedPsr4)) {
                // 直接应用缓存的 PSR-4 映射
                foreach ($cachedPsr4 as $prefix => $paths) {
                    $composerLoader->setPsr4($prefix, $paths);
                }
            }
        } else {
            // 缓存不存在时，实时计算（开发模式或首次运行）
            $psr4Map = $composerLoader->getPrefixesPsr4();
            
            foreach ($psr4Map as $prefix => $paths) {
                $relativePath = str_replace('\\', DIRECTORY_SEPARATOR, trim($prefix, '\\'));
                $appCodePath = APP_CODE_PATH . $relativePath . DIRECTORY_SEPARATOR;
                
                if (is_dir($appCodePath)) {
                    $paths = array_filter($paths, function($path) use ($appCodePath) {
                        $normalizedPath = rtrim($path, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
                        return $normalizedPath !== $appCodePath;
                    });
                    array_unshift($paths, $appCodePath);
                    $composerLoader->setPsr4($prefix, array_values($paths));
                }
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

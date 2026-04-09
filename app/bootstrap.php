<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */
use Weline\Framework\App\Exception;
use Weline\Framework\Exception\ExceptionBootstrap;
use Weline\Framework\Log\Context\TraceContext;

if (!defined('BP')) {
    define('BP', dirname(__DIR__) . DIRECTORY_SEPARATOR);
}

// 检查安装
if ((PHP_SAPI !== 'cli') and !file_exists(BP . 'setup' . DIRECTORY_SEPARATOR . 'install.lock')) {
    require BP . 'setup' . DIRECTORY_SEPARATOR . 'index.php';
    exit();
}

// 统一自动加载：app/code 与 generated/code 优先于 vendor（与 WLS worker 共用 app/autoload.php）
require __DIR__ . DIRECTORY_SEPARATOR . 'autoload.php';
// 加载框架通用函数（必须在 TraceContext::init() 之前，因为 TraceContext 依赖 w_env() 等函数）
require __DIR__ . '/code/Weline/Framework/Common/functions.php';
// 初始化统一的异常处理系统
ExceptionBootstrap::init(PHP_SAPI === 'cli' ? 'CLI' : 'FPM');

// 初始化链路追踪上下文
TraceContext::init();

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
// 加载通用函数

// 尝试加载应用
try {
    /**
     * 初始化应用...
     */
    $result = \Weline\Framework\App::run();
    // 输出前先发送通过 Response::setHeader() 收集的响应头（如 Website-Id 等），否则 FPM 下不会出现在响应中
    if (!headers_sent()) {
        \Weline\Framework\Http\HeaderCollector::getInstance()->emit(true);
    }
    // 输出正常响应内容
    if (!empty($result)) {
        echo $result;
    }
} catch (\Weline\Framework\Http\ResponseTerminateException $e) {
    // 捕获响应终止异常，在 FPM 模式下直接发送响应
    $e->emit(true);
    exit(0);
} catch (Exception $exception) {
    // 使用统一的异常渲染器系统
    $renderer = \Weline\Framework\Exception\Renderer\RendererFactory::create();
    
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: ' . $renderer->getContentType());
    }
    
    echo $renderer->render($exception);
    exit(1);
}

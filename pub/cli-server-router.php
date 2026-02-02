<?php

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

/**
 * PHP CLI Server 路径映射处理器
 * 
 * 作用：
 * - 模拟 Nginx location 配置的路径映射
 * - 仅处理特定路径的映射问题（如 /sitemaps/ -> pub/sitemaps/）
 * - 不拦截其他请求，交给框架正常处理
 * 
 * 对应的 Nginx 配置：
 * location ~ ^/sitemaps/ {
 *     root $WELINE_ROOT/pub;
 * }
 * 
 * 使用场景：
 * - PHP 内置服务器无法像 Nginx 那样配置路径映射
 * - 此文件补充这个能力，仅用于开发环境
 * 
 * 启动方式：
 * php -S 127.0.0.1:9981
 */

// 仅在 PHP CLI Server 环境下执行
if (php_sapi_name() !== 'cli-server') {
    return;
}

$requestUri = $_SERVER['REQUEST_URI'] ?? '';
$path = parse_url($requestUri, PHP_URL_PATH);

// 定义需要映射的路径规则
// 格式：[路径前缀 => 映射到的目录]
$routeMappings = [
    '/sitemaps/' => __DIR__ . '/sitemaps/',  // /sitemaps/* -> pub/sitemaps/*
    '/static/'   => __DIR__ . '/static/',    // /static/* -> pub/static/*
];

// 添加调试日志（仅用于开发环境）
if (str_contains($path, '/dev/tool/rest')) {
    error_log("[CLI Server Router] REST API Request: {$path}");
}

// 检查是否匹配需要映射的路径
foreach ($routeMappings as $prefix => $targetDir) {
    if (strpos($path, $prefix) === 0) {
        // 构建实际文件路径
        $relativePath = substr($path, strlen($prefix));
        $filePath = $targetDir . $relativePath;
        
        // 文件存在，返回文件内容
        if (is_file($filePath)) {
            // 根据扩展名设置 Content-Type
            $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
            $mimeTypes = [
                'xml'   => 'application/xml',
                'css'   => 'text/css',
                'js'    => 'application/javascript',
                'json'  => 'application/json',
                'png'   => 'image/png',
                'jpg'   => 'image/jpeg',
                'jpeg'  => 'image/jpeg',
                'gif'   => 'image/gif',
                'svg'   => 'image/svg+xml',
                'woff'  => 'font/woff',
                'woff2' => 'font/woff2',
                'ttf'   => 'font/ttf',
            ];
            
            $contentType = $mimeTypes[$ext] ?? 'application/octet-stream';
            
            // 设置响应头
            header('Content-Type: ' . $contentType);
            header('Content-Length: ' . filesize($filePath));
            header('Cache-Control: public, max-age=86400');
            
            // 输出文件内容
            readfile($filePath);
            exit;
        }
        
        // 映射路径下的文件不存在，返回 404
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        echo "404 Not Found: {$path}\n";
        echo "Mapped to: {$filePath}\n";
        exit;
    }
}

// 不匹配映射规则，交给框架处理
return;

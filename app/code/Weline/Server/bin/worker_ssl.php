<?php
declare(strict_types=1);

/**
 * Weline Server Worker 独立进程 (SSL/HTTPS)
 * 
 * 用法: php worker_ssl.php <host> <port> <worker_id> <instance_name> <ssl_cert> <ssl_key>
 */

if (PHP_SAPI !== 'cli') {
    exit('CLI only');
}

// 获取参数
$host = $argv[1] ?? '127.0.0.1';
$port = (int) ($argv[2] ?? 9981);
$workerId = (int) ($argv[3] ?? 1);
$instanceName = $argv[4] ?? 'default';
$sslCert = $argv[5] ?? '';
$sslKey = $argv[6] ?? '';

// 静默模式，不输出到控制台
error_reporting(0);

// 确定最高支持的 TLS 版本
$cryptoMethod = STREAM_CRYPTO_METHOD_TLS_SERVER;
if (defined('STREAM_CRYPTO_METHOD_TLSv1_3_SERVER')) {
    // PHP 7.4+ 支持 TLS 1.3（最高协议）
    $cryptoMethod = STREAM_CRYPTO_METHOD_TLSv1_3_SERVER | STREAM_CRYPTO_METHOD_TLSv1_2_SERVER | STREAM_CRYPTO_METHOD_TLSv1_1_SERVER | STREAM_CRYPTO_METHOD_TLSv1_0_SERVER;
} elseif (defined('STREAM_CRYPTO_METHOD_TLSv1_2_SERVER')) {
    // TLS 1.2
    $cryptoMethod = STREAM_CRYPTO_METHOD_TLSv1_2_SERVER | STREAM_CRYPTO_METHOD_TLSv1_1_SERVER | STREAM_CRYPTO_METHOD_TLSv1_0_SERVER;
}

// 创建 SSL 上下文（支持所有协议，默认使用最高版本）
$context = stream_context_create([
    'socket' => [
        'backlog' => 1024,
        'so_reuseaddr' => true,
    ],
    'ssl' => [
        'local_cert' => $sslCert,
        'local_pk' => $sslKey,
        'verify_peer' => false,
        'verify_peer_name' => false,
        'allow_self_signed' => true,
        'disable_compression' => true,
        'crypto_method' => $cryptoMethod,
        // 安全密码套件（优先使用高强度加密）
        'ciphers' => 'ECDHE-ECDSA-AES128-GCM-SHA256:ECDHE-RSA-AES128-GCM-SHA256:ECDHE-ECDSA-AES256-GCM-SHA384:ECDHE-RSA-AES256-GCM-SHA384:ECDHE-ECDSA-CHACHA20-POLY1305:ECDHE-RSA-CHACHA20-POLY1305:DHE-RSA-AES128-GCM-SHA256:DHE-RSA-AES256-GCM-SHA384:HIGH:!aNULL:!MD5:!RC4',
        // 禁用不安全的协议
        'single_dh_use' => true,
        'honor_cipher_order' => true,
    ]
]);

$socket = @stream_socket_server(
    "ssl://{$host}:{$port}",
    $errno,
    $errstr,
    STREAM_SERVER_BIND | STREAM_SERVER_LISTEN,
    $context
);

if (!$socket) {
    exit(1);
}

stream_set_blocking($socket, false);

$connections = [];
$requestCount = 0;

// 事件循环
while (true) {
    $read = array_merge([$socket], $connections);
    $write = [];
    $except = [];
    
    $changed = @stream_select($read, $write, $except, 0, 100000);
    
    if ($changed === false) {
        continue;
    }
    
    // 新连接
    if (in_array($socket, $read)) {
        $conn = @stream_socket_accept($socket, 0);
        if ($conn) {
            stream_set_blocking($conn, false);
            $connections[(int)$conn] = $conn;
        }
        $key = array_search($socket, $read);
        unset($read[$key]);
    }
    
    // 处理连接
    foreach ($read as $conn) {
        $data = @fread($conn, 65535);
        
        if ($data === false || $data === '') {
            @fclose($conn);
            unset($connections[(int)$conn]);
            continue;
        }
        
        $requestCount++;
        
        // 高性能响应
        $body = "Hello Weline Server (HTTPS)! Instance: {$instanceName}, Worker: {$workerId}, Port: {$port}, Request: {$requestCount}";
        $response = "HTTP/1.1 200 OK\r\n";
        $response .= "Content-Type: text/plain; charset=utf-8\r\n";
        $response .= "Content-Length: " . strlen($body) . "\r\n";
        $response .= "Connection: keep-alive\r\n";
        $response .= "\r\n";
        $response .= $body;
        
        @fwrite($conn, $response);
    }
}
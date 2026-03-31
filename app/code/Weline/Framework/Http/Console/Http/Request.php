<?php

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Framework\Http\Console\Http;

use Weline\Framework\App\Env;
use Weline\Framework\Console\CommandAbstract;
use Weline\Framework\Console\CommandHelper;
use Weline\Framework\Output\Cli\Printing;
use Weline\Framework\Session\SessionFactory;

class Request extends CommandAbstract
{
    /**
     * 命令别名
     */
    public const ALIASES = [
        'http:req',  // http:request 的简短形式
    ];

    function __construct()
    {
    }

    /**
     * @inheritDoc
     */
    public function execute(array $args = [], array $data = [])
    {
        // 获取路径参数 - 增强的参数解析
        // 问题：`http:req -b path` 会将 -b 解析为 $args[1]，导致路径变成 -b
        // 解决：遍历位置参数，找到第一个非选项参数作为路径
        $path = $args['path'] ?? '';
        if (empty($path)) {
            // 从位置参数中找到第一个非选项参数（不以 - 开头）
            for ($i = 1; $i <= 10; $i++) {
                if (isset($args[$i])) {
                    $arg = $args[$i];
                    // 跳过选项参数（以 - 开头）
                    if (is_string($arg) && !str_starts_with($arg, '-')) {
                        $path = $arg;
                        break;
                    }
                }
            }
        }
        
        if (empty($path)) {
            $this->printer->error(__('请指定请求路径！'));
            $this->printer->note(__('使用 -h 或 --help 查看帮助信息'));
            $this->printer->note('');
            $this->printer->note(__('正确用法示例:'));
            $this->printer->note('  php bin/w http:req "/" -b');
            $this->printer->note('  php bin/w http:req "admin/dashboard" -b');
            $this->printer->note('  php bin/w http:req -b "admin/dashboard"');
            $this->printer->note('  php bin/w http:req "pagebuilder/backend/domain-management" -b');
            return;
        }
        // Git Bash/MSYS2 下单独输入 / 会被转换为 C:/Program Files/Git/，规范为根路径 /
        $path = $this->normalizeRequestPath($path);

        // 获取其他参数
        $isBackend = isset($args['b']) || isset($args['backend']);
        $isApiBackend = isset($args['api']) || isset($args['api-backend']);
        $cookieFile = $args['cookie'] ?? $args['c'] ?? '';
        $saveCookie = isset($args['save-cookie']) || isset($args['s']);
        $filter = $args['filter'] ?? '';
        $lines = isset($args['n']) ? (int)($args['n']) : 3;
        $verifyTls = isset($args['tls']) ? (bool)($args['tls']) : false;
        $method = strtoupper($args['method'] ?? $args['m'] ?? 'GET');
        $headers = $this->normalizeHeaders($args['header'] ?? $args['H'] ?? []);
        $body = $args['data'] ?? $args['d'] ?? '';
        $overridePort = isset($args['port']) || isset($args['P']) ? (int)($args['port'] ?? $args['P']) : null;
        // --https 强制 HTTPS，--http 强制 HTTP
        $overrideHttps = isset($args['https']) ? true : (isset($args['http']) ? false : null);
        $isSse = isset($args['sse']) || isset($args['S']);
        $sessionId = $args['session'] ?? $args['sid'] ?? '';

        // 检查是否是不需要登录的页面（如登录页、静态资源等）
        $noLoginRequired = $this->isPublicPath($path, $isBackend);
        
        // 如果访问后台或API，需要处理登录态
        if (($isBackend || $isApiBackend) && !$noLoginRequired) {
            // 使用默认cookie文件路径
            $defaultCookieFile = BP . 'var' . DIRECTORY_SEPARATOR . 'http_request_cookies.txt';
            if (empty($cookieFile)) {
                $cookieFile = $defaultCookieFile;
            }
            
            // 如果提供了 session ID，直接使用
            if ($sessionId) {
                $this->printer->note(__('使用指定的 Session ID: %{1}...', [substr($sessionId, 0, 16)]));
                $this->writeSessionToCookieFile($sessionId, $cookieFile);
            } else {
                // 尝试从 session 文件中查找有效的后台登录态
                $foundSession = $this->findValidBackendSession();
                if ($foundSession) {
                    $this->printer->success(__('找到有效的后台登录 Session: %{1}... (用户: %{2})', [
                        substr($foundSession['session_id'], 0, 16),
                        $foundSession['username']
                    ]));
                    $this->writeSessionToCookieFile($foundSession['session_id'], $cookieFile);
                } else {
                    $this->printer->error(__('未找到有效的后台登录 Session'));
                    $this->printer->note(__(''));
                    $this->printer->note(__('请先在浏览器中登录后台，然后重试。'));
                    $this->printer->note(__(''));
                    $this->printer->note(__('其他选项：'));
                    $this->printer->note(__('  --sid=<WELINE_SESSID>  手动指定 Session ID'));
                    $this->printer->note(__('  --port=<端口>          指定 Worker 端口（从浏览器响应头获取）'));
                    return;
                }
            }
        }

        // 构建完整URL
        $url = $this->buildUrl($path, $isBackend, $isApiBackend, $overridePort, $overrideHttps);
        
        $this->printer->note(__('正在请求: %{1}', [$url]));
        $this->printer->note(__('请求方法: %{1}', [$method]));
        
        // 性能监控：记录总体开始时间
        $totalStartTime = microtime(true);
        
        // 检查是否并发请求
        $concurrent = isset($args['concurrent']) || isset($args['C']);
        $times = isset($args['times']) || isset($args['t']) ? (int)($args['times'] ?? $args['t']) : 1;
        
        if ($concurrent && $times > 1) {
            $this->executeConcurrentRequests($url, $times, $method, $headers, $body, $verifyTls, $cookieFile, $filter, $lines);
            return;
        }
        
        // SSE 模式：使用流式请求
        if ($isSse) {
            $this->sendSseRequest($url, $method, $headers, $body, $verifyTls, $cookieFile);
            return;
        }
        
        // 发送HTTP请求（使用Guzzle）
        $response = $this->sendGuzzleRequest($url, $method, $headers, $body, $verifyTls, $cookieFile, $saveCookie);
        
        if ($response === false) {
            $this->printer->error(__('请求失败！'));
            return;
        }

        // 检查是否返回了登录页面（session 过期或 Worker 不匹配）
        if (($isBackend || $isApiBackend) && !$noLoginRequired) {
            if ($this->isLoginPage($response['body'])) {
                $this->printer->error(__('Session 无效或 Worker 路由不匹配'));
                $this->printer->note(__(''));
                $this->printer->note(__('WLS 多 Worker 环境下，Session 可能绑定到特定 Worker。'));
                $this->printer->note(__('解决方案：'));
                $this->printer->note(__('  1. 在浏览器中重新登录后台'));
                $this->printer->note(__('  2. 使用 --port 参数指定 Worker 端口（从浏览器响应头 X-Weline-Route-Hint 获取）'));
                $this->printer->note(__('     示例: php bin/w http:req <path> -b --port=19982'));
                return;
            }
        }

        // 性能监控：计算总体耗时和资源大小
        $totalEndTime = microtime(true);
        $totalDuration = ($totalEndTime - $totalStartTime) * 1000;
        $responseSize = strlen($response['body']);
        $responseSizeKB = round($responseSize / 1024, 2);
        $responseSizeMB = round($responseSize / 1024 / 1024, 2);
        
        
        
        // 输出响应信息（简化版，详细性能信息在底部显示）
        $this->printer->success(__('请求成功！'));
        $this->printer->note(__('响应状态码: %{1}', [$response['status_code']]));
        
        if (!empty($response['headers'])) {
            $this->printer->note(__('响应头:'));
            foreach ($response['headers'] as $key => $value) {
                $this->printer->printing("  {$key}: {$value}");
            }
        }

        // 处理响应内容，并将性能信息传递到底部显示
        $performanceInfo = [
            'status_code' => $response['status_code'],
            'http_time' => round($response['time'] * 1000, 2),
            'total_time' => round($totalDuration, 2),
            'response_size' => $responseSize,
            'response_size_kb' => $responseSizeKB,
            'response_size_mb' => $responseSizeMB,
            'url' => $url,
            'method' => $method
        ];
        $this->processResponse($response['body'], $filter, $lines, $performanceInfo);
    }

    /**
     * 规范化请求路径：Git Bash/MSYS2 下单独输入 / 会被转换为 C:/Program Files/Git/，需还原为 /
     */
    private function normalizeRequestPath(string $path): string
    {
        $normalized = str_replace('\\', '/', $path);
        // Windows 盘符路径且为 Git for Windows 的 MSYS 根目录时，视为用户本想输入 /
        if (preg_match('#^[A-Za-z]:/#', $normalized) && str_contains($normalized, 'Program Files/Git')) {
            return '/';
        }
        // 仅盘符根（如 C:/ 或 D:/）无其它路径时也视为 /
        if (preg_match('#^[A-Za-z]:/?$#', $normalized)) {
            return '/';
        }
        return $path;
    }

    /**
     * 规范化请求头参数，兼容 string|array 输入。
     *
     * - 单个请求头字符串：'Host: example.com'
     * - 多个请求头数组：['Host: a', 'X-Test: 1'] 或 ['Host' => 'a']
     */
    private function normalizeHeaders(mixed $headers): array
    {
        if (\is_array($headers)) {
            $normalized = [];
            foreach ($headers as $key => $value) {
                // 保持已是 key=>value 的形式
                if (!\is_int($key)) {
                    $normalized[(string)$key] = (string)$value;
                    continue;
                }
                // 兼容 "Header: value" 字符串数组
                if (\is_string($value)) {
                    $parsed = $this->parseSingleHeader($value);
                    if ($parsed !== null) {
                        [$hKey, $hValue] = $parsed;
                        $normalized[$hKey] = $hValue;
                    }
                }
            }
            return $normalized;
        }
        if (\is_string($headers)) {
            $header = \trim($headers);
            if ($header === '') {
                return [];
            }
            $parsed = $this->parseSingleHeader($header);
            if ($parsed === null) {
                return [];
            }
            return [$parsed[0] => $parsed[1]];
        }
        return [];
    }

    /**
     * 解析单条请求头字符串，格式必须为 "Header: value"。
     *
     * @return array{0:string,1:string}|null
     */
    private function parseSingleHeader(string $header): ?array
    {
        $line = \trim($header);
        if ($line === '' || !\str_contains($line, ':')) {
            return null;
        }
        [$name, $value] = \explode(':', $line, 2);
        $name = \trim($name);
        $value = \trim($value);
        if ($name === '') {
            return null;
        }
        return [$name, $value];
    }

    /**
     * 判断是否是公共路径（不需要登录）
     */
    private function isPublicPath(string $path, bool $isBackend): bool
    {
        if (!$isBackend) {
            return true; // 前端默认都是公共的
        }
        
        // 后台的公共路径列表
        $publicPaths = [
            'admin/login',
            'admin/login/post',
            'admin/login/index',
            'admin/logout',
            'captcha',
            'static',
            'media',
            'pub',
        ];
        
        $path = trim($path, '/');
        foreach ($publicPaths as $publicPath) {
            if (str_starts_with($path, $publicPath)) {
                return true;
            }
        }
        
        return false;
    }

    /**
     * 从 Session 存储中查找有效的后台登录态
     *
     * 使用 SessionFactory 透明 API，自动适配 WLS Session Server 或文件存储。
     *
     * @return array|null ['session_id' => string, 'username' => string, 'user_id' => int] 或 null
     */
    private function findValidBackendSession(): ?array
    {
        $storage = SessionFactory::getInstance()->createStorage();
        $sessions = $storage->list([
            'filter' => ['type' => 'backend'],
            'limit' => 10,
        ]);
        
        foreach ($sessions as $session) {
            $data = $session['data'] ?? [];
            $userId = $data['WF_BACKEND_USER_ID'] ?? null;
            $username = $data['WF_BACKEND_USER'] ?? null;
            
            if ($userId && $username) {
                return [
                    'session_id' => $session['session_id'],
                    'username' => $username,
                    'user_id' => $userId,
                ];
            }
        }
        
        return null;
    }
    
    /**
     * 将 session ID 写入 cookie 文件（Guzzle FileCookieJar 格式）
     */
    private function writeSessionToCookieFile(string $sessionId, string $cookieFile): void
    {
        $cookieData = [[
            'Name' => 'WELINE_SESSID',
            'Value' => $sessionId,
            'Domain' => '127.0.0.1',
            'Path' => '/',
            'Max-Age' => null,
            'Expires' => time() + 86400,
            'Secure' => true,
            'Discard' => false,
            'HttpOnly' => true,
            'SameSite' => 'Lax'
        ]];
        file_put_contents($cookieFile, json_encode($cookieData));
    }

    /**
     * 检查响应内容是否是登录页面
     */
    private function isLoginPage(string $content): bool
    {
        // 检查多个登录页面的特征标识
        $loginIndicators = [
            'Login\index.phtml',           // 登录模板文件路径
            'Weline 登录面板',              // 登录页面标题
            'admin/login/post',            // 登录表单提交地址
            '<title>Weline 登录面板</title>', // 完整标题标签
        ];
        
        foreach ($loginIndicators as $indicator) {
            if (str_contains($content, $indicator)) {
                return true;
            }
        }
        
        return false;
    }
    /**
     * 构建完整的URL
     * 
     * WLS 模式下 server:start 默认启用 HTTPS，故请求默认使用 https；
     * 可通过 wls.https = false 改为 http。
     */
    private function buildUrl(string $path, bool $isBackend, bool $isApiBackend = false, ?int $overridePort = null, ?bool $overrideHttps = null): string
    {
        $env = Env::getInstance();
        $serverConfig = $env->get('wls') ?? [];
        
        // 获取服务器配置（默认 0.0.0.0 监听所有网卡，支持公网访问）
        $host = $serverConfig['host'] ?? '0.0.0.0';
        $port = (int) ($serverConfig['port'] ?? 9981);
        // 命令行 --port/-P 覆盖
        if ($overridePort !== null) {
            $port = $overridePort;
        }
        // 若未显式配置 https，则根据端口推断：80 用 http，443 用 https，避免 https://host:80 导致 TLS 错误
        $useHttps = \array_key_exists('https', $serverConfig)
            ? (bool) $serverConfig['https']
            : ($port === 443);
        // 命令行 --https 覆盖
        if ($overrideHttps !== null) {
            $useHttps = $overrideHttps;
        }
        $scheme = $useHttps ? 'https' : 'http';
        
        // 处理路径
        $path = ltrim($path, '/');
        
        // 智能检测：如果路径包含 REST API 特征，自动识别为 API 后端路径
        // 支持模式：rest/v1, api/rest, /rest/
        $isRestApiPath = (
            str_contains($path, 'rest/v1') || 
            str_contains($path, 'api/rest') ||
            preg_match('#(^|/)rest/v\d+/#', $path)
        );
        
        // 如果是后端请求且路径是 REST API，自动切换到 API 后端模式
        if ($isBackend && $isRestApiPath && !$isApiBackend) {
            $isApiBackend = true;
            $isBackend = false;
        }
        
        if ($isApiBackend) {
            // REST 后端路径 - 需要加上 rest_backend 前缀
            $restBackendPrefix = $env::getAreaRoutePrefix('rest_backend') ?? '';
            if (empty($restBackendPrefix)) {
                $this->printer->warning(__('警告：未找到 rest_backend 前缀配置，可能无法访问 REST 后端路径！'));
                $this->printer->note(__('请检查 app/etc/env.php 中的 area_routes.rest_backend.prefix 配置'));
            }
            $fullPath = "{$restBackendPrefix}/{$path}";
        } elseif ($isBackend) {
            // 后端路径 - 需要加上 backend 前缀
            $backendPrefix = $env::getAreaRoutePrefix('backend') ?? '';
            if (empty($backendPrefix)) {
                $this->printer->warning(__('警告：未找到 backend 前缀配置，可能无法访问后端路径！'));
                $this->printer->note(__('请检查 app/etc/env.php 中的 area_routes.backend.prefix 配置'));
                $fullPath = $path;
            } else {
                $fullPath = "{$backendPrefix}/{$path}";
            }
        } else {
            // 前端路径
            $fullPath = $path;
        }
        
        // 构建URL（WLS 默认 HTTPS）
        return "{$scheme}://{$host}:{$port}/{$fullPath}";
    }

    /**
     * 发送HTTP请求（支持HTTP/2）
     */
    private function sendRequest(
        string $url, 
        string $method = 'GET', 
        array $headers = [], 
        string $body = '',
        bool $verifyTls = false
    ): array|false {
        // 优先尝试使用cURL（支持HTTP/2）
        if (function_exists('curl_init')) {
            return $this->sendCurlRequest($url, $method, $headers, $body, $verifyTls);
        }
        
        // 降级到file_get_contents
        return $this->sendFileGetContentsRequest($url, $method, $headers, $body, $verifyTls);
    }

    /**
     * 使用cURL发送请求（支持HTTP/2）
     */
    private function sendCurlRequest(
        string $url, 
        string $method, 
        array $headers, 
        string $body,
        bool $verifyTls,
        string $cookieFile = '',
        bool $saveCookie = true
    ): array|false {
        $ch = curl_init();
        
        // 设置基本选项
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HEADER, false);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_MAXREDIRS, 5);
        curl_setopt($ch, CURLOPT_TIMEOUT, 60);
        
        // 默认使用HTTP/1.1以确保兼容性
        curl_setopt($ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_1);
        
        // 禁用代理
        curl_setopt($ch, CURLOPT_PROXY, '');
        curl_setopt($ch, CURLOPT_PROXYUSERPWD, '');
        curl_setopt($ch, CURLOPT_PROXYTYPE, CURLPROXY_HTTP);
        
        // TLS验证设置
        if (!$verifyTls) {
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        }
        
        // Cookie处理
        if (!empty($cookieFile)) {
            // 使用现有cookie文件
            curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieFile);
            if ($saveCookie) {
                curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieFile);
            }
        } elseif ($saveCookie) {
            // 创建新cookie文件到var目录
            $varPath = BP . 'var';
            if (!is_dir($varPath)) {
                mkdir($varPath, 0755, true);
            }
            $cookieFile = $varPath . '/http_request_cookies.txt';
            curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieFile);
            $this->printer->note(__('Cookie将保存到: %{1}', [$cookieFile]));
        }
        
        // 设置请求方法
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        
        // 设置请求头
        if (!empty($headers)) {
            $formattedHeaders = [];
            foreach ($headers as $key => $value) {
                if (is_numeric($key)) {
                    $formattedHeaders[] = $value;
                } else {
                    $formattedHeaders[] = "{$key}: {$value}";
                }
            }
            curl_setopt($ch, CURLOPT_HTTPHEADER, $formattedHeaders);
        }
        
        // 设置请求体
        if (!empty($body) && in_array($method, ['POST', 'PUT', 'PATCH'])) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        
        // 捕获响应头
        $responseHeaders = [];
        curl_setopt($ch, CURLOPT_HEADERFUNCTION, function($curl, $header) use (&$responseHeaders) {
            $len = strlen($header);
            $header = explode(':', $header, 2);
            if (count($header) < 2) {
                return $len;
            }
            
            $key = strtolower(trim($header[0]));
            $value = trim($header[1]);
            $responseHeaders[$key] = $value;
            
            return $len;
        });
        
        // 执行请求
        $this->printer->note(__('开始执行请求...'));
        $response = curl_exec($ch);
        $statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $redirectCount = curl_getinfo($ch, CURLINFO_REDIRECT_COUNT);
        $error = curl_error($ch);
        
        curl_close($ch);
        
        if ($response === false) {
            $this->printer->error(__('cURL错误: %{1}', [$error]));
            $this->printer->note(__('重定向次数: %{1}', [$redirectCount]));
            return false;
        }
        
        $this->printer->note(__('请求完成，重定向次数: %{1}', [$redirectCount]));
        
        return [
            'status_code' => $statusCode,
            'headers' => $responseHeaders,
            'body' => $response
        ];
    }

    /**
     * 使用file_get_contents发送请求（降级方案）
     */
    private function sendFileGetContentsRequest(
        string $url, 
        string $method, 
        array $headers, 
        string $body,
        bool $verifyTls
    ): array|false {
        $options = [
            'http' => [
                'method' => $method,
                'header' => '',
                'content' => $body,
                'ignore_errors' => true,
                'timeout' => 30,
            ],
            'ssl' => [
                'verify_peer' => $verifyTls,
                'verify_peer_name' => $verifyTls,
            ]
        ];
        
        // 设置请求头
        if (!empty($headers)) {
            $headerLines = [];
            foreach ($headers as $key => $value) {
                if (is_numeric($key)) {
                    $headerLines[] = $value;
                } else {
                    $headerLines[] = "{$key}: {$value}";
                }
            }
            $options['http']['header'] = implode("\r\n", $headerLines);
        }
        
        $context = stream_context_create($options);
        $response = @file_get_contents($url, false, $context);
        
        if ($response === false) {
            $this->printer->error(__('请求失败！'));
            return false;
        }
        
        // 解析响应头
        $responseHeaders = [];
        // PHP 8.4+ 优先使用 http_get_last_response_headers，避免使用已弃用的 $http_response_header
        $headers_source = [];
        if (function_exists('http_get_last_response_headers')) {
            $headers_source = http_get_last_response_headers() ?: [];
        }
        // 注意：PHP 8.4+ 中 $http_response_header 已被弃用，不再使用
        // 如果 http_get_last_response_headers 不存在，说明 http 扩展可能未安装，返回空数组

        if ($headers_source) {
            foreach ($headers_source as $header) {
                if (strpos($header, ':') !== false) {
                    list($key, $value) = explode(':', $header, 2);
                    $responseHeaders[strtolower(trim($key))] = trim($value);
                }
            }
        }
        
        // 获取状态码
        $statusCode = 200;
        if (isset($headers_source[0])) {
            preg_match('/HTTP\/\d\.\d\s+(\d+)/', $headers_source[0], $matches);
            if (isset($matches[1])) {
                $statusCode = (int)$matches[1];
            }
        }
        
        return [
            'status_code' => $statusCode,
            'headers' => $responseHeaders,
            'body' => $response
        ];
    }

    /**
     * 发送 SSE (Server-Sent Events) 请求
     * 实时输出服务器推送的事件流
     */
    private function sendSseRequest(
        string $url,
        string $method,
        array $headers,
        string $body,
        bool $verifyTls,
        string $cookieFile
    ): void {
        $this->printer->note(__('SSE 模式：开始接收事件流...'));
        $this->printer->printing($this->printer->colorize('═══════════════════════════════════════════════════════════', 'cyan'));
        
        try {
            // 准备 cookie jar
            $cookieJar = null;
            if ($cookieFile && file_exists($cookieFile)) {
                $cookieJar = new \GuzzleHttp\Cookie\FileCookieJar($cookieFile, true);
            }
            
            $options = [
                'timeout' => 0, // 无超时，SSE 是长连接
                'connect_timeout' => 30,
                'verify' => $verifyTls,
                'proxy' => false,
                'http_errors' => false,
                'allow_redirects' => false, // SSE 不跟随重定向
                'stream' => true, // 关键：启用流式响应
                'headers' => array_merge($headers, [
                    'Accept' => 'text/event-stream',
                    'Cache-Control' => 'no-cache',
                ])
            ];
            
            if ($cookieJar) {
                $options['cookies'] = $cookieJar;
            }
            
            // POST 请求体
            if (!empty($body) && in_array($method, ['POST', 'PUT', 'PATCH'])) {
                $options['body'] = $body;
            }
            
            $client = new \GuzzleHttp\Client();
            $response = $client->request($method, $url, $options);
            
            $statusCode = $response->getStatusCode();
            $this->printer->note(__('HTTP 状态码: %{1}', [$statusCode]));
            
            // 处理重定向
            if ($statusCode >= 300 && $statusCode < 400) {
                $location = $response->getHeaderLine('Location');
                if ($location) {
                    // 检查是否是登录页面重定向
                    if (str_contains($location, '/login') || str_contains($location, 'login')) {
                        $this->printer->error(__('Session 无效或 Worker 路由不匹配'));
                        $this->printer->note(__(''));
                        $this->printer->note(__('WLS 多 Worker 环境下，Session 可能绑定到特定 Worker。'));
                        $this->printer->note(__('解决方案：'));
                        $this->printer->note(__('  1. 在浏览器中重新登录后台'));
                        $this->printer->note(__('  2. 使用 --port 参数指定 Worker 端口'));
                        return;
                    }
                    // 跟随重定向（只跟随一次）
                    $this->printer->note(__('跟随重定向: %{1}', [$location]));
                    // 构建完整 URL
                    if (!str_starts_with($location, 'http')) {
                        $parsedUrl = parse_url($url);
                        $location = ($parsedUrl['scheme'] ?? 'https') . '://' . ($parsedUrl['host'] ?? '127.0.0.1');
                        if (!empty($parsedUrl['port'])) {
                            $location .= ':' . $parsedUrl['port'];
                        }
                        $location .= $response->getHeaderLine('Location');
                    }
                    // 重新请求
                    $response = $client->request($method, $location, $options);
                    $statusCode = $response->getStatusCode();
                    $this->printer->note(__('重定向后状态码: %{1}', [$statusCode]));
                }
            }
            
            // 检查 Content-Type 是否为 SSE
            $contentType = $response->getHeaderLine('Content-Type');
            
            // 如果返回的是 HTML（可能是登录页或错误页），不进行流式处理
            if (str_contains($contentType, 'text/html')) {
                $bodyContent = $response->getBody()->getContents();
                if ($this->isLoginPage($bodyContent)) {
                    $this->printer->error(__('Session 无效或 Worker 路由不匹配'));
                    $this->printer->note(__(''));
                    $this->printer->note(__('WLS 多 Worker 环境下，Session 可能绑定到特定 Worker。'));
                    $this->printer->note(__('解决方案：'));
                    $this->printer->note(__('  1. 在浏览器中重新登录后台'));
                    $this->printer->note(__('  2. 使用 --port 参数指定 Worker 端口'));
                    return;
                }
                // 不是登录页面，直接输出内容
                $this->printer->warning(__('响应 Content-Type 为 text/html，非 SSE 流'));
                $this->printer->printing($bodyContent);
                return;
            }
            
            // 检查是否是 event-stream
            if (!str_contains($contentType, 'text/event-stream')) {
                $this->printer->warning(__('响应 Content-Type 为 %{1}，非标准 SSE (text/event-stream)', [$contentType]));
            }
            
            // 获取响应流
            $stream = $response->getBody();
            $buffer = '';
            $eventCount = 0;
            $startTime = microtime(true);
            
            $this->printer->printing('');
            
            // 实时读取 SSE 事件流
            while (!$stream->eof()) {
                $chunk = $stream->read(1024);
                if ($chunk === '') {
                    usleep(10000); // 10ms
                    continue;
                }
                
                $buffer .= $chunk;
                
                // 处理缓冲区中的完整事件（以双换行分隔）
                while (($pos = strpos($buffer, "\n\n")) !== false) {
                    $eventData = substr($buffer, 0, $pos);
                    $buffer = substr($buffer, $pos + 2);
                    
                    if (trim($eventData) === '') {
                        continue;
                    }
                    
                    $eventCount++;
                    $this->parseSseEvent($eventData, $eventCount);
                }
            }
            
            // 处理剩余数据
            if (trim($buffer) !== '') {
                $eventCount++;
                $this->parseSseEvent($buffer, $eventCount);
            }
            
            $duration = round((microtime(true) - $startTime) * 1000, 2);
            
            $this->printer->printing('');
            $this->printer->printing($this->printer->colorize('═══════════════════════════════════════════════════════════', 'cyan'));
            $this->printer->success(__('SSE 流结束，共接收 %{1} 个事件，耗时 %{2} ms', [$eventCount, $duration]));
            
        } catch (\Exception $e) {
            $this->printer->error(__('SSE 请求失败: %{1}', [$e->getMessage()]));
        }
    }
    
    /**
     * 解析并输出单个 SSE 事件
     */
    private function parseSseEvent(string $eventData, int $eventCount): void
    {
        $lines = explode("\n", $eventData);
        $event = 'message';
        $data = '';
        $id = '';
        
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            
            if (str_starts_with($line, 'event:')) {
                $event = trim(substr($line, 6));
            } elseif (str_starts_with($line, 'data:')) {
                $data .= ($data !== '' ? "\n" : '') . trim(substr($line, 5));
            } elseif (str_starts_with($line, 'id:')) {
                $id = trim(substr($line, 3));
            } elseif (str_starts_with($line, ':')) {
                // 注释行，忽略
                continue;
            }
        }
        
        // 根据事件类型使用不同颜色输出
        $eventColor = match($event) {
            'start' => 'cyan',
            'done' => 'green',
            'error', 'article_error' => 'red',
            'skip' => 'yellow',
            'article_start' => 'blue',
            'article_done' => 'green',
            'quota_info', 'fallback_keywords' => 'magenta',
            default => 'white'
        };
        
        // 格式化输出
        $timestamp = date('H:i:s');
        $eventLabel = $this->printer->colorize("[{$event}]", $eventColor);
        
        // 尝试解析 JSON 数据
        $decoded = json_decode($data, true);
        if (is_array($decoded)) {
            $dataStr = json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        } else {
            $dataStr = $data;
        }
        
        $this->printer->printing(sprintf('%s %s %s', 
            $this->printer->colorize($timestamp, 'gray'),
            $eventLabel,
            ''
        ));
        
        if ($dataStr !== '') {
            // 缩进数据内容
            $dataLines = explode("\n", $dataStr);
            foreach ($dataLines as $dataLine) {
                $this->printer->printing('    ' . $dataLine);
            }
        }
    }
    
    /**
     * 执行并发请求
     */
    private function executeConcurrentRequests(
        string $url,
        int $times,
        string $method,
        array $headers,
        string $body,
        bool $verifyTls,
        string $cookieFile,
        string $filter,
        int $lines
    ): void {
        $this->printer->note(__('准备发送 %{1} 个并发请求到: %{2}', [$times, $url]));
        $this->printer->note(__('请求方法: %{1}', [$method]));
        
        $start = microtime(true);
        
        // 使用Guzzle Pool进行并发请求
        $client = new \GuzzleHttp\Client([
            'timeout' => 60,
            'connect_timeout' => 10,
            'verify' => $verifyTls,
            'proxy' => false,
            'http_errors' => false
        ]);
        
        // 准备cookie jar
        $cookieJar = null;
        if ($cookieFile) {
            // FileCookieJar会自动处理文件的读写
            $cookieJar = new \GuzzleHttp\Cookie\FileCookieJar($cookieFile, true);
        }
        
        $requests = function() use ($times, $url, $method, $headers, $body, $cookieJar, $client) {
            for ($i = 0; $i < $times; $i++) {
                $options = [
                    'headers' => $headers
                ];
                
                if ($cookieJar) {
                    $options['cookies'] = $cookieJar;
                }
                
                if (!empty($body) && in_array($method, ['POST', 'PUT', 'PATCH'])) {
                    $options['body'] = $body;
                }
                
                yield function() use ($client, $method, $url, $options) {
                    return $client->requestAsync($method, $url, $options);
                };
            }
        };
        
        // 统计数据
        $successCount = 0;
        $failedCount = 0;
        $totalTime = 0;
        $responseTimes = [];
        $statusCodes = [];
        
        $pool = new \GuzzleHttp\Pool($client, $requests(), [
            'concurrency' => min($times, 100), // 最大并发数
            'fulfilled' => function($response, $index) use (&$successCount, &$responseTimes, &$statusCodes, $start) {
                $successCount++;
                $requestTime = (microtime(true) - $start) * 1000;
                $responseTimes[] = $requestTime;
                $statusCode = $response->getStatusCode();
                $statusCodes[$statusCode] = ($statusCodes[$statusCode] ?? 0) + 1;
                
                // 实时输出进度
                if ($successCount % 10 == 0 || $successCount == 1) {
                    $this->printer->printing("已完成: {$successCount} 个请求");
                }
            },
            'rejected' => function($reason, $index) use (&$failedCount) {
                $failedCount++;
                $this->printer->warning(__('请求 #%{1} 失败: %{2}', [$index + 1, $reason->getMessage()]));
            },
        ]);
        
        // 执行所有请求
        $promise = $pool->promise();
        $promise->wait();
        
        $totalDuration = (microtime(true) - $start) * 1000;
        
        // 输出统计信息（优化格式，统一显示在底部）
        $this->printer->printing('');
        $this->printer->success(__('并发请求完成！'));
        $this->printer->printing($this->printer->colorize('═══════════════════════════════════════════════════════════', 'cyan'));
        $this->printer->printing($this->printer->colorize('                   并发请求性能统计', 'cyan'));
        $this->printer->printing($this->printer->colorize('═══════════════════════════════════════════════════════════', 'cyan'));
        
        // 请求统计
        $this->printer->printing($this->printer->colorize('请求统计:', 'yellow'));
        $this->printer->printing(sprintf('  %-20s %s', __('总请求数:'), $times));
        $successColor = ($successCount == $times) ? 'green' : (($successCount > $times * 0.8) ? 'yellow' : 'red');
        $this->printer->printing(sprintf('  %-20s %s', __('成功数:'), 
            $this->printer->colorize($successCount, $successColor)));
        $failColor = ($failedCount == 0) ? 'green' : (($failedCount < $times * 0.2) ? 'yellow' : 'red');
        $this->printer->printing(sprintf('  %-20s %s', __('失败数:'), 
            $this->printer->colorize($failedCount, $failColor)));
        
        $this->printer->printing('');
        
        // 时间统计
        $this->printer->printing($this->printer->colorize('时间统计:', 'yellow'));
        $totalTimeColor = $totalDuration < 1000 ? 'green' : (($totalDuration < 5000) ? 'yellow' : 'red');
        $this->printer->printing(sprintf('  %-20s %s', __('总耗时:'), 
            $this->printer->colorize(sprintf('%.2f ms', $totalDuration), $totalTimeColor)));
        
        $avgTime = $totalDuration / $times;
        $avgTimeColor = $avgTime < 100 ? 'green' : (($avgTime < 500) ? 'yellow' : 'red');
        $this->printer->printing(sprintf('  %-20s %s', __('平均耗时:'), 
            $this->printer->colorize(sprintf('%.2f ms', $avgTime), $avgTimeColor)));
        
        if (!empty($responseTimes)) {
            sort($responseTimes);
            $min = min($responseTimes);
            $max = max($responseTimes);
            $avg = array_sum($responseTimes) / count($responseTimes);
            $median = $responseTimes[floor(count($responseTimes) / 2)];
            
            $this->printer->printing(sprintf('  %-20s %s', __('最快响应:'), 
                $this->printer->colorize(sprintf('%.2f ms', $min), 'green')));
            $this->printer->printing(sprintf('  %-20s %s', __('最慢响应:'), 
                $this->printer->colorize(sprintf('%.2f ms', $max), ($max > 1000 ? 'red' : 'yellow'))));
            $this->printer->printing(sprintf('  %-20s %s', __('平均响应:'), 
                $this->printer->colorize(sprintf('%.2f ms', $avg), ($avg < 100 ? 'green' : 'yellow'))));
            $this->printer->printing(sprintf('  %-20s %s', __('中位响应:'), 
                $this->printer->colorize(sprintf('%.2f ms', $median), ($median < 100 ? 'green' : 'yellow'))));
        }
        
        $this->printer->printing('');
        
        // 吞吐量
        $qps = round($successCount / ($totalDuration / 1000), 2);
        $qpsColor = $qps > 100 ? 'green' : (($qps > 10) ? 'yellow' : 'red');
        $this->printer->printing($this->printer->colorize('吞吐量:', 'yellow'));
        $this->printer->printing(sprintf('  %-20s %s', __('QPS:'), 
            $this->printer->colorize(sprintf('%.2f 请求/秒', $qps), $qpsColor)));
        
        $this->printer->printing('');
        
        // 状态码分布
        if (!empty($statusCodes)) {
            $this->printer->printing($this->printer->colorize('状态码分布:', 'yellow'));
            foreach ($statusCodes as $code => $count) {
                $codeColor = ($code >= 200 && $code < 300) ? 'green' : (($code >= 300 && $code < 400) ? 'yellow' : 'red');
                $this->printer->printing(sprintf('  %-20s %s', 
                    sprintf('HTTP %d:', $code), 
                    $this->printer->colorize(sprintf('%d 次', $count), $codeColor)));
            }
        }
        
        $this->printer->printing($this->printer->colorize('═══════════════════════════════════════════════════════════', 'cyan'));
    }
    
    /**
     * 使用Guzzle发送单个请求
     */
    private function sendGuzzleRequest(
        string $url,
        string $method = 'GET',
        array $headers = [],
        string|array $body = '',
        bool $verifyTls = false,
        string $cookieFile = '',
        bool $saveCookie = true
    ): array|false {
        // 处理数组类型的 body，转换为 JSON 字符串
        if (is_array($body)) {
            $body = json_encode($body, JSON_UNESCAPED_UNICODE);
        }

        try {
            $options = [
                'timeout' => 60,
                'connect_timeout' => 10,
                'verify' => $verifyTls,
                'proxy' => false,
                'http_errors' => false,
                'allow_redirects' => [
                    'max' => 5,
                    'strict' => false,
                    'track_redirects' => true
                ]
            ];
            
            // 处理headers
            if (!empty($headers)) {
                $options['headers'] = $headers;
            }
            
            // 处理cookie - FileCookieJar会自动读写文件
            $cookieJar = null;
            if ($cookieFile) {
                // 确保目录存在
                $cookieDir = dirname($cookieFile);
                if (!is_dir($cookieDir)) {
                    mkdir($cookieDir, 0755, true);
                }
                // FileCookieJar 会自动保存cookie到文件
                $cookieJar = new \GuzzleHttp\Cookie\FileCookieJar($cookieFile, true);
                $options['cookies'] = $cookieJar;
            } elseif ($saveCookie) {
                $varPath = BP . 'var';
                if (!is_dir($varPath)) {
                    mkdir($varPath, 0755, true);
                }
                $cookieFile = $varPath . '/http_request_cookies.txt';
                $cookieJar = new \GuzzleHttp\Cookie\FileCookieJar($cookieFile, true);
                $options['cookies'] = $cookieJar;
            }
            
            // 处理请求体
            if (!empty($body) && in_array($method, ['POST', 'PUT', 'PATCH'])) {
                $trimmedBody = trim($body);
                
                // 如果是JSON数据
                if (str_starts_with($trimmedBody, '{') || str_starts_with($trimmedBody, '[')) {
                    $options['headers']['Content-Type'] = 'application/json';
                    
                    // 确保JSON格式正确（添加双引号）
                    if (str_starts_with($trimmedBody, '{') && str_ends_with($trimmedBody, '}')) {
                        // 简单的JSON格式修复：将单引号或无引号的键名改为双引号
                        $trimmedBody = preg_replace('/([{,]\s*)([a-zA-Z_][a-zA-Z0-9_]*)\s*:/', '$1"$2":', $trimmedBody);
                        
                        // 修复值：将无引号的字符串值改为双引号（跳过数字和布尔值）
                        $trimmedBody = preg_replace('/:\s*([a-zA-Z_][a-zA-Z0-9_-]*)\s*([,}])/', ':"$1"$2', $trimmedBody);
                    }
                    $options['body'] = $trimmedBody;
                }
                // 如果是 URL 编码的表单数据（如 key=value&key2=value2）
                elseif (str_contains($body, '=')) {
                    parse_str($body, $formParams);
                    if (!empty($formParams)) {
                        $options['form_params'] = $formParams;
                    } else {
                        $options['body'] = $body;
                    }
                }
                // 其他情况直接发送原始数据
                else {
                    $options['body'] = $body;
                }
            }
            
            $client = new \GuzzleHttp\Client();
            $start = microtime(true);
            
            $response = $client->request($method, $url, $options);
            $duration = microtime(true) - $start;
            
            
            // 提取响应头
            $responseHeaders = [];
            foreach ($response->getHeaders() as $key => $values) {
                $responseHeaders[strtolower($key)] = implode(', ', $values);
            }
            
            $bodyContent = $response->getBody()->getContents();
            $bodySize = strlen($bodyContent);
            
            
            return [
                'status_code' => $response->getStatusCode(),
                'headers' => $responseHeaders,
                'body' => $bodyContent,
                'time' => $duration
            ];
            
        } catch (\Exception $e) {
            $this->printer->error(__('Guzzle请求失败: %{1}', [$e->getMessage()]));
            return false;
        }
    }
    
    /**
     * 处理响应内容
     */
    private function processResponse(string $content, string $filter, int $lines, array $performanceInfo = []): void
    {
        if ($filter) {
            // 使用filter参数提取内容
            $this->printer->note(__('正在搜索: %{1} (上下文行数: %{2})', [$filter, $lines]));
            $this->printer->printing('');
            $filteredContent = $this->filterContent($content, $filter, $lines);
            
            if ($filteredContent) {
                $this->printer->success(__('找到 %{1} 处匹配:', [count($filteredContent)]));
                $this->printer->printing('');
                
                foreach ($filteredContent as $index => $match) {
                    $this->printer->note(__('匹配 #%{1} (行 %{2}):', [$index + 1, $match['line_number']]));
                    $this->printer->printing($this->printer->colorize('----------------------------------------', 'cyan'));
                    foreach ($match['lines'] as $line) {
                        if ($line['is_match']) {
                            $this->printer->printing($this->printer->colorize(
                                sprintf('%4d| %s', $line['number'], $line['content']),
                                'green'
                            ));
                        } else {
                            $this->printer->printing(sprintf('%4d| %s', $line['number'], $line['content']));
                        }
                    }
                    $this->printer->printing('');
                }
            } else {
                $this->printer->warning(__('未找到匹配内容: %{1}', [$filter]));
            }
        } else {
            // 直接输出响应内容
            $this->printer->note(__('响应内容:'));
            $this->printer->printing($this->printer->colorize('======================================', 'cyan'));
            $this->printer->printing($content);
            $this->printer->printing($this->printer->colorize('======================================', 'cyan'));
        }
        
        // 在底部显示性能信息
        if (!empty($performanceInfo)) {
            $this->displayPerformanceInfo($performanceInfo);
        } else {
            // 如果没有传递性能信息，只显示响应大小
            $size = strlen($content);
            $this->printer->note(__('响应大小: %{1} 字节', [$size]));
        }
    }
    
    /**
     * 显示性能信息
     */
    private function displayPerformanceInfo(array $info): void
    {
        $this->printer->printing('');
        $this->printer->printing($this->printer->colorize('═══════════════════════════════════════════════════════════', 'cyan'));
        $this->printer->printing($this->printer->colorize('                   性能信息 / Performance Info', 'cyan'));
        $this->printer->printing($this->printer->colorize('═══════════════════════════════════════════════════════════', 'cyan'));
        
        // 请求信息
        $this->printer->printing($this->printer->colorize('请求信息:', 'yellow'));
        $this->printer->printing(sprintf('  %-20s %s', __('请求方法:'), $info['method'] ?? 'GET'));
        if (isset($info['url'])) {
            $this->printer->printing(sprintf('  %-20s %s', __('请求URL:'), $info['url']));
        }
        
        $this->printer->printing('');
        
        // 响应信息
        $this->printer->printing($this->printer->colorize('响应信息:', 'yellow'));
        $statusCode = $info['status_code'] ?? 0;
        $statusColor = ($statusCode >= 200 && $statusCode < 300) ? 'green' : (($statusCode >= 300 && $statusCode < 400) ? 'yellow' : 'red');
        $this->printer->printing(sprintf('  %-20s %s', __('状态码:'), 
            $this->printer->colorize($statusCode, $statusColor)));
        
        $this->printer->printing('');
        
        // 性能指标
        $this->printer->printing($this->printer->colorize('性能指标:', 'yellow'));
        
        // HTTP响应时间
        $httpTime = $info['http_time'] ?? 0;
        $httpTimeColor = $httpTime < 100 ? 'green' : (($httpTime < 500) ? 'yellow' : 'red');
        $this->printer->printing(sprintf('  %-20s %s', __('HTTP响应时间:'), 
            $this->printer->colorize(sprintf('%.2f ms', $httpTime), $httpTimeColor)));
        
        // 总耗时
        $totalTime = $info['total_time'] ?? 0;
        $totalTimeColor = $totalTime < 200 ? 'green' : (($totalTime < 1000) ? 'yellow' : 'red');
        $this->printer->printing(sprintf('  %-20s %s', __('总耗时:'), 
            $this->printer->colorize(sprintf('%.2f ms', $totalTime), $totalTimeColor)));
        
        // 响应大小
        $responseSize = $info['response_size'] ?? 0;
        $responseSizeKB = $info['response_size_kb'] ?? 0;
        $responseSizeMB = $info['response_size_mb'] ?? 0;
        
        $sizeText = '';
        if ($responseSizeMB >= 1) {
            $sizeText = sprintf('%.2f MB (%.2f KB / %d bytes)', $responseSizeMB, $responseSizeKB, $responseSize);
        } elseif ($responseSizeKB >= 1) {
            $sizeText = sprintf('%.2f KB (%d bytes)', $responseSizeKB, $responseSize);
        } else {
            $sizeText = sprintf('%d bytes', $responseSize);
        }
        
        $sizeColor = $responseSizeMB < 1 ? 'green' : (($responseSizeMB < 5) ? 'yellow' : 'red');
        $this->printer->printing(sprintf('  %-20s %s', __('响应大小:'), 
            $this->printer->colorize($sizeText, $sizeColor)));
        
        // 计算传输速度（如果总耗时大于0）
        if ($totalTime > 0 && $responseSize > 0) {
            $speedKBps = ($responseSize / 1024) / ($totalTime / 1000);
            $speedMBps = $speedKBps / 1024;
            
            $speedText = '';
            if ($speedMBps >= 1) {
                $speedText = sprintf('%.2f MB/s', $speedMBps);
            } else {
                $speedText = sprintf('%.2f KB/s', $speedKBps);
            }
            
            $speedColor = $speedMBps > 1 ? 'green' : (($speedKBps > 100) ? 'yellow' : 'red');
            $this->printer->printing(sprintf('  %-20s %s', __('传输速度:'), 
                $this->printer->colorize($speedText, $speedColor)));
        }
        
        $this->printer->printing($this->printer->colorize('═══════════════════════════════════════════════════════════', 'cyan'));
    }

    /**
     * 过滤内容，提取匹配行及其上下文
     */
    private function filterContent(string $content, string $filter, int $contextLines = 3): array
    {
        $lines = explode("\n", $content);
        $results = [];
        $totalLines = count($lines);
        
        // 搜索匹配的行
        foreach ($lines as $lineNumber => $line) {
            // 使用不区分大小写的搜索
            if (stripos($line, $filter) !== false) {
                // 计算上下文范围
                $startLine = max(0, $lineNumber - $contextLines);
                $endLine = min($totalLines - 1, $lineNumber + $contextLines);
                
                $contextLines_array = [];
                for ($i = $startLine; $i <= $endLine; $i++) {
                    $contextLines_array[] = [
                        'number' => $i + 1,
                        'content' => $lines[$i],
                        'is_match' => $i === $lineNumber
                    ];
                }
                
                $results[] = [
                    'line_number' => $lineNumber + 1,
                    'lines' => $contextLines_array
                ];
            }
        }
        
        return $results;
    }

    /**
     * @inheritDoc
     */
    public function tip(): string
    {
        return 'HTTP请求测试工具。支持HTTP/2协议，可以快速测试前端和后端路径，并支持内容过滤和搜索功能。';
    }

    /**
     * @inheritDoc
     */
    public function help(): array|string
    {
        return CommandHelper::formatHelp(
            'http:request',
            $this->tip(),
            [
                '-b, -backend' => '指定为后端路径（自动从 session 文件获取登录态）',
                '-api, -api-backend' => '指定为API后端路径（使用api_admin密钥）',
                '-c, --cookie=<文件>' => '使用指定的cookie文件',
                '--session, --sid=<ID>' => '手动指定 WELINE_SESSID（从浏览器复制）',
                '-s, --save-cookie' => '保存cookie到文件',
                'filter=<关键词>' => '搜索并提取包含关键词的内容及其上下文',
                '-n=<行数>' => '指定提取的上下文行数（默认3行）',
                'tls' => '启用HTTPS TLS证书验证（默认不验证）',
                '-m, method=<方法>' => '指定HTTP请求方法（默认GET）',
                '-H, header=<头>' => '添加HTTP请求头',
                '-d, data=<数据>' => '发送POST/PUT数据',
                '-P, --port=<端口>' => '指定服务器端口（覆盖wls.port）',
                '--https' => '强制使用HTTPS协议（覆盖wls.https）',
                '-S, --sse' => '启用 SSE (Server-Sent Events) 模式，实时输出事件流',
                '-C, --concurrent' => '启用并发请求模式（需配合-t使用）',
                '-t, --times=<次数>' => '并发请求次数（默认1次）',
                '-h, --help' => '显示帮助信息',
            ],
            [
                'path' => '请求路径（必需参数）',
            ],
            [
                '测试前端首页' => 'php bin/w http:request /',
                '测试后台（自动获取登录态）' => 'php bin/w http:request admin/dashboard -b',
                '测试 SSE 接口' => 'php bin/w http:request blog/backend/post/trigger-ai-publish-sse -b --sse',
                '手动指定 Session' => 'php bin/w http:request admin/dashboard -b --sid=<WELINE_SESSID>',
                '搜索响应中的特定内容' => 'php bin/w http:request / filter=welcome',
                '搜索并显示上下5行' => 'php bin/w http:request / filter=welcome -n=5',
                '发送POST请求' => 'php bin/w http:request api/data -m=POST -d=\'{"key":"value"}\'',
                '添加自定义请求头' => 'php bin/w http:request / -H="User-Agent: CustomBot"',
                '并发测试100次' => 'php bin/w http:request / -C -t=100',
            ],
            'php bin/w http:request <path> [选项]'
        );
    }
}


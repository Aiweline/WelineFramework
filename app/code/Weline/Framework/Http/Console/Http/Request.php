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
        // 获取路径参数 - $args[1]是第一个位置参数
        $path = $args['path'] ?? $args[1] ?? '';
        if (empty($path)) {
            $this->printer->error(__('请指定请求路径！'));
            $this->printer->note(__('使用 -h 或 --help 查看帮助信息'));
            return;
        }

        // 获取其他参数
        $isBackend = isset($args['b']) || isset($args['backend']);
        $isApiBackend = isset($args['api']) || isset($args['api-backend']);
        $username = $args['username'] ?? $args['u'] ?? 'admin';
        $password = $args['password'] ?? $args['p'] ?? 'admin';
        $cookieFile = $args['cookie'] ?? $args['c'] ?? '';
        $saveCookie = isset($args['save-cookie']) || isset($args['s']);
        $filter = $args['filter'] ?? '';
        $lines = isset($args['n']) ? (int)($args['n']) : 3;
        $verifyTls = isset($args['tls']) ? (bool)($args['tls']) : false;
        $method = strtoupper($args['method'] ?? $args['m'] ?? 'GET');
        $headers = $args['header'] ?? $args['H'] ?? [];
        $body = $args['data'] ?? $args['d'] ?? '';

        // 检查是否是不需要登录的页面（如登录页、静态资源等）
        $noLoginRequired = $this->isPublicPath($path, $isBackend);
        
        // 如果访问后台或API，自动处理登录
        if (($isBackend || $isApiBackend) && !$noLoginRequired) {
            // 使用默认cookie文件路径
            $defaultCookieFile = BP . 'var' . DIRECTORY_SEPARATOR . 'http_request_cookies.txt';
            if (empty($cookieFile)) {
                $cookieFile = $defaultCookieFile;
            }
        }

        // 构建完整URL
        $url = $this->buildUrl($path, $isBackend, $isApiBackend);
        
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
        
        // 发送HTTP请求（使用Guzzle）
        $response = $this->sendGuzzleRequest($url, $method, $headers, $body, $verifyTls, $cookieFile, $saveCookie);
        
        if ($response === false) {
            $this->printer->error(__('请求失败！'));
            return;
        }

        // 检查是否返回了登录页面（cookie过期）
        if (($isBackend || $isApiBackend) && !$noLoginRequired) {
            if ($this->isLoginPage($response['body'])) {
                $this->printer->warning(__('Cookie已过期，正在重新登录...'));
                
                // 删除旧的cookie文件
                if (file_exists($cookieFile)) {
                    @unlink($cookieFile);
                }
                
                // 执行自动登录
                $newCookieFile = $this->performLogin($username, $password, $isBackend, $verifyTls);
                if (!$newCookieFile) {
                    $this->printer->error(__('重新登录失败！无法访问受保护的资源。'));
                    return;
                }
                
                // 使用新cookie重新请求
                $this->printer->note(__('登录成功，正在重新请求...'));
                $response = $this->sendGuzzleRequest($url, $method, $headers, $body, $verifyTls, $newCookieFile, true);
                
                if ($response === false) {
                    $this->printer->error(__('重新请求失败！'));
                    return;
                }
                
                // 再次检查是否还是登录页面
                if ($this->isLoginPage($response['body'])) {
                    $this->printer->error(__('登录后仍然返回登录页面，请检查用户权限或路由配置！'));
                    return;
                }
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
     * 执行登录获取Session
     */
    private function performLogin(string $username, string $password, bool $isBackend, bool $verifyTls): string|false
    {
        // 如果没有提供用户名密码，使用默认值
        if (!$username || !$password) {
            $this->printer->note(__('未提供登录凭据，使用默认账号尝试登录...'));
            $username = $username ?: 'admin';
            $password = $password ?: 'admin';
        }
        
        $env = Env::getInstance();
        $serverConfig = $env->get('server') ?? [];
        $host = $serverConfig['host'] ?? '127.0.0.1';
        $port = $serverConfig['port'] ?? '9981';
        $adminKey = $env->get('admin') ?? '';
        
        // 构建登录URL
        $loginPageUrl = "http://{$host}:{$port}/{$adminKey}/admin/login";
        $loginPostUrl = $loginPageUrl . '/post';
        
        $this->printer->note(__('正在登录: %{1}', [$username]));
        
        // 创建cookie文件到var目录
        $varPath = BP . 'var';
        if (!is_dir($varPath)) {
            mkdir($varPath, 0755, true);
        }
        $cookieFile = $varPath . '/http_request_cookies.txt';
        
        // 第一步：访问登录页面获取form_key
        $this->printer->note(__('正在获取登录页面: %{1}', [$loginPageUrl]));
        
        // 使用Guzzle HTTP客户端，禁用代理
        try {
            $client = new \GuzzleHttp\Client([
                'timeout' => 30,
                'connect_timeout' => 10,
                'verify' => false,
                'cookies' => true,
                'allow_redirects' => true,
                'proxy' => false, // 禁用代理
                'http_errors' => false // 不抛出HTTP错误异常
            ]);
            
            $response = $client->get($loginPageUrl, [
                'headers' => [
                    'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
                    'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                    'Accept-Language' => 'zh-CN,zh;q=0.9,en;q=0.8'
                ]
            ]);
            
            $loginPage = $response->getBody()->getContents();
            $httpCode = $response->getStatusCode();
            $totalTime = 0;
            $receivedBytes = strlen($loginPage);
            $error = '';
            
            // 保存cookie（Guzzle自动处理）
            // 注意：Guzzle的CookieJar会自动保存cookie到文件
            
        } catch (\Exception $e) {
            $loginPage = '';
            $httpCode = 0;
            $totalTime = 0;
            $receivedBytes = 0;
            $error = $e->getMessage();
        }
        
        $this->printer->note(__('HTTP状态码: %{1}, 耗时: %{2}秒', [$httpCode, round($totalTime, 2)]));
        $this->printer->note(__('接收数据长度: %{1} 字节', [strlen($loginPage)]));
        
        // 检查是否完全失败（没有收到任何数据）
        if (($loginPage === false || empty($loginPage)) && $httpCode == 0) {
            $this->printer->error(__('无法访问登录页面: %{1}', [$error ?: '未知错误']));
            $this->printer->note(__('请确认开发服务器是否已启动（php bin/w server:start -b）'));
            if (file_exists($cookieFile)) {
                unlink($cookieFile);
            }
            return false;
        }
        
        // 即使有错误（如超时），只要收到了数据就继续
        if ($error && !empty($loginPage)) {
            $this->printer->warning(__('登录页面请求警告: %{1}', [$error]));
            $this->printer->note(__('但已收到 %{1} 字节数据，尝试继续...', [strlen($loginPage)]));
        } elseif (!empty($loginPage)) {
            $this->printer->success(__('成功访问登录页面，已收到 %{1} 字节', [strlen($loginPage)]));
        } else {
            $this->printer->error(__('登录页面返回空内容！'));
            $this->printer->note(__('HTTP状态码: %{1}, 错误: %{2}', [$httpCode, $error ?: '无']));
            if (file_exists($cookieFile)) {
                unlink($cookieFile);
            }
            return false;
        }
        
        // 提取form_key
        $formKey = '';
        // 尝试多种form_key格式
        if (preg_match('/name=["\']form_key["\'].*?value=["\'](.*?)["\']/is', $loginPage, $matches)) {
            $formKey = $matches[1];
            $this->printer->success(__('成功获取form_key: %{1}', [substr($formKey, 0, 16) . '...']));
        } elseif (preg_match('/value=["\'](.*?)["\'].*?name=["\']form_key["\']/is', $loginPage, $matches)) {
            $formKey = $matches[1];
            $this->printer->success(__('成功获取form_key: %{1}', [substr($formKey, 0, 16) . '...']));
        } elseif (preg_match('/<INPUT[^>]*name=["\']form_key["\'][^>]*value=["\'](.*?)["\'][^>]*>/is', $loginPage, $matches)) {
            $formKey = $matches[1];
            $this->printer->success(__('成功获取form_key: %{1}', [substr($formKey, 0, 16) . '...']));
                        } else {
            $this->printer->error(__('未找到form_key，无法进行登录！'));
            $this->printer->note(__('页面内容片段: %{1}', [substr($loginPage, 0, 500) . '...']));
            $this->printer->note(__('请检查登录页面是否正常加载'));
            if (file_exists($cookieFile)) {
                unlink($cookieFile);
            }
            return false;
        }
        
        // 准备登录数据
        $loginData = http_build_query([
            'username' => $username,
            'password' => $password,
            'form_key' => $formKey,
            'remember' => '1'
        ]);
        
        // 第二步：发送登录请求（使用Guzzle HTTP客户端）
        $this->printer->note(__('正在提交登录信息到: %{1}', [$loginPostUrl]));
        
        try {
            // 创建cookie jar - 第二个参数true表示自动保存
            $cookieJar = new \GuzzleHttp\Cookie\FileCookieJar($cookieFile, true);
            
            $client = new \GuzzleHttp\Client([
                'timeout' => 30,
                'connect_timeout' => 10,
                'verify' => false,
                'cookies' => $cookieJar,
                'allow_redirects' => true,
                'proxy' => false, // 禁用代理
                'http_errors' => false // 不抛出HTTP错误异常
            ]);
            
            $response = $client->post($loginPostUrl, [
                'headers' => [
                    'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
                    'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                    'Accept-Language' => 'zh-CN,zh;q=0.9,en;q=0.8',
                    'Content-Type' => 'application/x-www-form-urlencoded'
                ],
                'form_params' => [
                    'username' => $username,
                    'password' => $password,
                    'form_key' => $formKey,
                    'remember' => '1'
                ]
            ]);
            
            $statusCode = $response->getStatusCode();
            $redirectCount = 0; // Guzzle自动处理重定向
            $error = '';
            
            // 保存cookie（Guzzle自动处理）
            // 注意：FileCookieJar会自动保存cookie到文件
            
        } catch (\Exception $e) {
            $statusCode = 0;
            $redirectCount = 0;
            $error = $e->getMessage();
        }
        
        $this->printer->note(__('登录响应状态码: %{1}, 重定向次数: %{2}', [$statusCode, $redirectCount]));
        
        // 检查登录是否成功
        if ($statusCode === 200) {
            // 检查响应内容是否包含登录成功标识
            $responseBody = $response->getBody()->getContents();
            if (strpos($responseBody, '登录成功') !== false || 
                strpos($responseBody, 'dashboard') !== false ||
                strpos($responseBody, 'admin') !== false ||
                strpos($responseBody, '后台') !== false) {
                $this->printer->success(__('登录成功！'));
                return $cookieFile;
            }
        }
        
        // 即使请求失败或超时，也检查cookie是否已生成
        if (file_exists($cookieFile) && filesize($cookieFile) > 0) {
            $cookieContent = file_get_contents($cookieFile);
            // 检查是否包含session cookie
            if (strpos($cookieContent, 'WELINE_SESSID') !== false || 
                strpos($cookieContent, 'session') !== false) {
                $this->printer->success(__('登录成功！（已获取到Session Cookie）'));
                $this->printer->note(__('Cookie已保存到: %{1}', [$cookieFile]));
                if ($error) {
                    $this->printer->warning(__('提示：登录过程中发生超时，但cookie已成功获取'));
                }
                return $cookieFile;
            }
        }
        
        if ($response === false) {
            $this->printer->error(__('登录请求失败: %{1}', [$error]));
            if (file_exists($cookieFile)) {
                unlink($cookieFile);
            }
            return false;
        }
        
        $this->printer->error(__('登录失败！状态码: %{1}', [$statusCode]));
        $this->printer->note(__('请检查用户名和密码是否正确'));
        
        // 清理失败的cookie文件
        if (file_exists($cookieFile)) {
            unlink($cookieFile);
        }
        
        return false;
    }

    /**
     * 构建完整的URL
     */
    private function buildUrl(string $path, bool $isBackend, bool $isApiBackend = false): string
    {
        $env = Env::getInstance();
        $serverConfig = $env->get('server') ?? [];
        
        // 获取服务器配置
        $host = $serverConfig['host'] ?? '127.0.0.1';
        $port = $serverConfig['port'] ?? '9981';
        
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
            // API后端路径 - 需要加上api_admin_key
            $apiAdminKey = $env->get('api_admin') ?? '';
            if (empty($apiAdminKey)) {
                $this->printer->warning(__('警告：未找到api_admin密钥配置，可能无法访问API后端路径！'));
                $this->printer->note(__('请检查 app/etc/env.php 中的 api_admin 配置'));
            }
            $fullPath = "{$apiAdminKey}/{$path}";
        } elseif ($isBackend) {
            // 后端路径 - 需要加上admin_key
            $adminKey = $env->get('admin') ?? '';
            if (empty($adminKey)) {
                $this->printer->warning(__('警告：未找到admin密钥配置，可能无法访问后端路径！'));
                $this->printer->note(__('请检查 app/etc/env.php 中的 admin 配置'));
                $fullPath = $path;
            } else {
                $fullPath = "{$adminKey}/{$path}";
            }
        } else {
            // 前端路径
            $fullPath = $path;
        }
        
        // 构建URL
        return "http://{$host}:{$port}/{$fullPath}";
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
        string $body = '',
        bool $verifyTls = false,
        string $cookieFile = '',
        bool $saveCookie = true
    ): array|false {
        try {
            $options = [
                'timeout' => 60,
                'connect_timeout' => 10,
                'verify' => $verifyTls,
                'proxy' => false,
                'http_errors' => false,
                'allow_redirects' => true
            ];
            
            // 处理headers
            if (!empty($headers)) {
                $options['headers'] = $headers;
            }
            
            // 处理cookie - FileCookieJar会自动读写文件
            if ($cookieFile) {
                // 确保目录存在
                $cookieDir = dirname($cookieFile);
                if (!is_dir($cookieDir)) {
                    mkdir($cookieDir, 0755, true);
                }
                // FileCookieJar 会自动保存cookie到文件
                $options['cookies'] = new \GuzzleHttp\Cookie\FileCookieJar($cookieFile, true);
            } elseif ($saveCookie) {
                $varPath = BP . 'var';
                if (!is_dir($varPath)) {
                    mkdir($varPath, 0755, true);
                }
                $cookieFile = $varPath . '/http_request_cookies.txt';
                $options['cookies'] = new \GuzzleHttp\Cookie\FileCookieJar($cookieFile, true);
            }
            
            // 处理请求体
            if (!empty($body) && in_array($method, ['POST', 'PUT', 'PATCH'])) {
                $options['body'] = $body;
                
                // 如果是JSON数据，自动设置Content-Type头
                if (is_string($body) && (str_starts_with(trim($body), '{') || str_starts_with(trim($body), '['))) {
                    $options['headers']['Content-Type'] = 'application/json';
                    
                    // 确保JSON格式正确（添加双引号）
                    $trimmedBody = trim($body);
                    if (str_starts_with($trimmedBody, '{') && str_ends_with($trimmedBody, '}')) {
                        // 简单的JSON格式修复：将单引号或无引号的键名改为双引号
                        $trimmedBody = preg_replace('/([{,]\s*)([a-zA-Z_][a-zA-Z0-9_]*)\s*:/', '$1"$2":', $trimmedBody);
                        
                        // 修复值：将无引号的字符串值改为双引号（跳过数字和布尔值）
                        $trimmedBody = preg_replace('/:\s*([a-zA-Z_][a-zA-Z0-9_-]*)\s*([,}])/', ':"$1"$2', $trimmedBody);
                        
                        $options['body'] = $trimmedBody;
                    }
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
                '-b, -backend' => '指定为后端路径（使用admin密钥，默认为前端路径）',
                '-api, -api-backend' => '指定为API后端路径（使用api_admin密钥）',
                '--login, -l' => '自动登录后台（需配合-u和-p使用）',
                '-u, --username=<用户名>' => '登录用户名（默认：admin）',
                '-p, --password=<密码>' => '登录密码（默认：admin123456）',
                '-c, --cookie=<文件>' => '使用指定的cookie文件',
                '-s, --save-cookie' => '保存cookie到文件',
                'filter=<关键词>' => '搜索并提取包含关键词的内容及其上下文',
                '-n=<行数>' => '指定提取的上下文行数（默认3行）',
                'tls' => '启用HTTPS TLS证书验证（默认不验证）',
                '-m, method=<方法>' => '指定HTTP请求方法（默认GET）',
                '-H, header=<头>' => '添加HTTP请求头',
                '-d, data=<数据>' => '发送POST/PUT数据',
                '-C, --concurrent' => '启用并发请求模式（需配合-t使用）',
                '-t, --times=<次数>' => '并发请求次数（默认1次）',
                '-h, --help' => '显示帮助信息',
            ],
            [
                'path' => '请求路径（必需参数）',
            ],
            [
                '测试前端首页' => 'php bin/w http:request /',
                '自动登录并测试后端' => 'php bin/w http:request admin/dashboard -b --login -u=admin -p=123456',
                '使用已有cookie访问后台' => 'php bin/w http:request admin/dashboard -b -c=cookies.txt',
                '测试API接口（自动登录）' => 'php bin/w http:request rest/v1/data -api --login -u=admin -p=123456',
                '搜索响应中的特定内容' => 'php bin/w http:request / filter=welcome',
                '搜索并显示上下5行' => 'php bin/w http:request / filter=welcome -n=5',
                '发送POST请求' => 'php bin/w http:request api/data -m=POST -d=\'{"key":"value"}\'',
                '添加自定义请求头' => 'php bin/w http:request / -H="User-Agent: CustomBot"',
                '并发测试100次' => 'php bin/w http:request / -C -t=100',
                '并发压测后台（带登录）' => 'php bin/w http:request admin/dashboard -b -C -t=50 -c=cookies.txt',
            ],
            'php bin/w http:request <path> [选项]'
        );
    }
}


<?php
declare(strict_types=1);

/**
 * Weline Framework - WLS 请求对象
 * 
 * 从原始 HTTP 数据解析为框架 Request 对象
 * 
 * @author Aiweline
 * @email aiweline@qq.com
 */

namespace Weline\Framework\Http;

use Weline\Framework\App\Env;
use Weline\Server\Log\LogConfig;

/**
 * WLS 请求对象
 * 
 * 功能：
 * - 解析原始 HTTP 请求数据
 * - 兼容现有 Request API
 * - 支持 HTTP/1.1 Keep-Alive
 */
class WlsRequest extends Request
{
    /**
     * 原始 HTTP 数据
     */
    private string $rawData = '';
    
    /**
     * 解析后的 HTTP 头
     */
    private array $parsedHeaders = [];
    
    /**
     * 请求体
     */
    private string $body = '';
    
    /** 解析出的完整 URI（path?query），不依赖 $_SERVER，供 emulate 后 buildServerArray 使用 */
    private string $parsedUri = '';
    /** 解析出的查询字符串 */
    private string $parsedQueryString = '';
    /** 解析出的 GET 参数 */
    private array $parsedGetParams = [];
    /** 解析出的 POST 参数 */
    private array $parsedPostParams = [];
    /** @var array{post: array, files: array} */
    private array $parsedBodyPayload = ['post' => [], 'files' => []];
    /** 解析出的请求方法 */
    private string $parsedMethod = 'GET';
    /** 是否 HTTPS（不依赖 $_SERVER） */
    private bool $parsedHttps = false;
    /** 解析出的 Host（不依赖 $_SERVER） */
    private string $parsedHost = '';
    
    /**
     * 从原始 HTTP 数据创建请求对象
     * 
     * @param string $rawData 原始 HTTP 数据
     * @param array $serverInfo 额外的服务器信息
     * @return self
     */
    public static function fromRaw(string $rawData, array $serverInfo = []): self
    {
        $request = new self();
        $request->rawData = $rawData;
        $request->parseRawHttp($rawData, $serverInfo);
        return $request;
    }

    private static function normalizeHeaderName(string $name): string
    {
        $name = \strtolower($name);

        return \implode('-', \array_map(static fn(string $part): string => \ucfirst($part), \explode('-', $name)));
    }

    /**
     * @return array{host: string, server_name: string, port: int|null}|null
     */
    private static function parseHostAuthority(string $host): ?array
    {
        $host = \trim($host);
        if ($host === ''
            || \str_contains($host, '/')
            || \str_contains($host, '\\')
            || \preg_match('/[\r\n]/', $host)
        ) {
            return null;
        }

        if ($host[0] === '[') {
            if (!\preg_match('/^\[([0-9A-Fa-f:.]+)\](?::([0-9]{1,5}))?$/', $host, $matches)) {
                return null;
            }
            if (!\filter_var($matches[1], \FILTER_VALIDATE_IP, \FILTER_FLAG_IPV6)) {
                return null;
            }
            $port = isset($matches[2]) ? (int)$matches[2] : null;
            if ($port !== null && !self::isValidTcpPort($port)) {
                return null;
            }

            return [
                'host' => '[' . $matches[1] . ']',
                'server_name' => $matches[1],
                'port' => $port,
            ];
        }

        if (\str_contains($host, '[') || \str_contains($host, ']') || \substr_count($host, ':') > 1) {
            return null;
        }

        $hostName = $host;
        $port = null;
        if (\str_contains($host, ':')) {
            [$hostName, $portPart] = \explode(':', $host, 2);
            if (!\ctype_digit($portPart)) {
                return null;
            }
            $port = (int)$portPart;
            if (!self::isValidTcpPort($port)) {
                return null;
            }
        }

        $hostName = \trim($hostName);
        if ($hostName === ''
            || !\preg_match('/^(?:[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?\.)*[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?$/', $hostName)
        ) {
            return null;
        }

        return [
            'host' => $hostName,
            'server_name' => $hostName,
            'port' => $port,
        ];
    }

    private static function isValidTcpPort(int $port): bool
    {
        return $port > 0 && $port <= 65535;
    }
    
    /**
     * 解析原始 HTTP 数据
     */
    private function parseRawHttp(string $rawData, array $serverInfo): void
    {
        // 分离头部和正文
        $parts = \explode("\r\n\r\n", $rawData, 2);
        $headerSection = $parts[0] ?? '';
        $this->body = $parts[1] ?? '';
        
        // 解析请求行
        $lines = \explode("\r\n", $headerSection);
        $requestLine = \array_shift($lines);
        
        $method = 'GET';
        $uri = '/';
        $protocol = 'HTTP/1.1';
        
        if (\preg_match('/^(\w+)\s+([^\s]+)\s+(HTTP\/[\d.]+)?$/', $requestLine, $matches)) {
            $method = \strtoupper($matches[1]);
            $uri = $matches[2];
            $protocol = $matches[3] ?? 'HTTP/1.1';
        }
        
        // 解析头部
        $headers = [];
        foreach ($lines as $line) {
            if (\strpos($line, ':') !== false) {
                list($name, $value) = \explode(':', $line, 2);
                $name = self::normalizeHeaderName((string)\trim($name));
                $value = \trim($value);
                if (isset($headers[$name]) && $headers[$name] !== '') {
                    // RFC 7230: 重复头按逗号拼接（Cookie/Set-Cookie 不在请求头场景）。
                    $headers[$name] .= ',' . $value;
                } else {
                    $headers[$name] = $value;
                }
            }
        }
        $this->parsedHeaders = $headers;
        $this->parsedUri = $uri;
        $this->parsedMethod = $method;
        // parsedHttps 将在后面根据实际检测结果设置
        
        // 解析 URI
        try {
            $uriParts = \parse_url($uri);
            $uriParts = \is_array($uriParts) ? $uriParts : [];
        } catch (\ValueError $e) {
            $uriParts = [];
            if (\function_exists('w_log_warning')) {
                \w_log_warning('[WlsRequest] parse_url failed for malformed request URI: ' . $e->getMessage());
            }
        }
        $path = $uriParts['path'] ?? '/';
        $queryString = $uriParts['query'] ?? '';
        $this->parsedQueryString = $queryString;
        $isStaticResource = weline_is_static_file_path($path);
        
        // 解析 GET 参数
        $getParams = [];
        if ($queryString) {
            \parse_str($queryString, $getParams);
        }
        $this->parsedGetParams = $getParams;
        
        // 解析请求体（POST / PUT / PATCH / DELETE 均可携带 body）
        $contentType = $headers['Content-Type'] ?? '';
        $parsed = self::parseRequestBody($this->body, $contentType, $method);
        $this->parsedBodyPayload = $parsed;
        $postParams = $parsed['post'] ?? [];
        $this->parsedPostParams = $postParams;
        $_FILES = $parsed['files'] ?? [];
        
        // 解析 Cookie。静态资源请求也必须保留原始 Cookie，避免同源资源请求覆盖已有 WELINE_SESSID。
        $cookies = [];
        $cookieHeader = $headers['Cookie'] ?? '';
        if ($cookieHeader) {
            $cookieParts = \explode(';', $cookieHeader);
            foreach ($cookieParts as $cookie) {
                $parts = \explode('=', \trim($cookie), 2);
                if (\count($parts) === 2) {
                    $cookies[\trim($parts[0])] = \urldecode(\trim($parts[1]));
                }
            }
        }
        
        // ========== 优先使用 Weline- 自定义头（WLS Dispatcher 专用）==========
        // 检测是否经过 WLS Dispatcher
        $viaDispatcher = isset($headers['Weline-Via-Dispatcher']) && $headers['Weline-Via-Dispatcher'] === '1';
        
        if ($viaDispatcher) {
            // 使用 Weline- 头还原原始请求信息
            $originalHost = \trim(\explode(',', (string)($headers['Weline-Original-Host'] ?? ($headers['Host'] ?? '')), 2)[0]);
            $originalScheme = \strtolower(\trim(\explode(',', $headers['Weline-Original-Scheme'] ?? 'http', 2)[0]));
            $originalPort = \trim(\explode(',', $headers['Weline-Original-Port'] ?? '', 2)[0]);
            $originalSsl = $headers['Weline-Original-Ssl'] ?? 'off';
            $realIp = $headers['CF-Connecting-IP'] ?? ($headers['Weline-Real-Ip'] ?? '');
            $isHttps = ($originalScheme === 'https' || $originalSsl === 'on');
        } else {
            // 回退到标准 X-Forwarded-* 头（兼容其他代理）
            $originalHost = \trim(\explode(',', (string)($headers['X-Forwarded-Host'] ?? ($headers['Host'] ?? '')), 2)[0]);
            $originalPort = \trim(\explode(',', $headers['X-Forwarded-Port'] ?? '', 2)[0]); // 非 Dispatcher 模式，优先使用 X-Forwarded-Port
            $forwardedProto = \strtolower(\trim(\explode(',', $headers['X-Forwarded-Proto'] ?? '', 2)[0]));
            $realIp = $headers['CF-Connecting-IP'] ?? ($headers['X-Real-IP'] ?? '');
            
            // 检测 HTTPS：多种检测方式
            $isHttps = false;
            if ($forwardedProto === 'https' || isset($headers['X-Forwarded-Ssl'])) {
                $isHttps = true;
            } elseif (isset($serverInfo['ssl']) && $serverInfo['ssl']) {
                $isHttps = true;
            } elseif (isset($serverInfo['HTTPS']) && $serverInfo['HTTPS'] === 'on') {
                // TCP 透传模式：Worker 直接传递 HTTPS 标志
                $isHttps = true;
            } elseif (isset($serverInfo['REQUEST_SCHEME']) && $serverInfo['REQUEST_SCHEME'] === 'https') {
                // TCP 透传模式：Worker 直接传递 REQUEST_SCHEME
                $isHttps = true;
            } else {
                // 回退检测：从 env.php 配置判断
                // 如果服务器配置为 HTTPS 模式，即使直接访问 Worker 也应识别为 HTTPS
                if (\defined('BP') && \file_exists(BP . 'etc/env.php')) {
                    $envConfig = include BP . 'etc/env.php';
                    $serverConfig = $envConfig['wls'] ?? [];
                    // 检查服务器是否配置为 HTTPS 模式
                    if (isset($serverConfig['https']) && $serverConfig['https'] === true) {
                        $isHttps = true;
                        // 同时修正 Host 头，移除 Worker 端口，使用配置的端口
                        $configPort = $serverConfig['port'] ?? '443';
                        $hostParts = \explode(':', $originalHost);
                        $hostName = $hostParts[0];
                        // 如果当前 Host 包含 Worker 端口（10xxx），替换为正确的端口
                        if (isset($hostParts[1]) && \str_starts_with($hostParts[1], '10')) {
                            $originalHost = $hostName; // 使用默认端口 443
                            $originalPort = $configPort;
                        }
                    }
                }
            }
        }

        // Host 必须来源于用户请求；禁止回退 localhost，避免登录后跳转到 localhost。
        $originalHost = \trim($originalHost);
        if ($originalHost === '') {
            $originalHost = \trim((string)($headers['Host'] ?? ''));
        }
        if ($originalHost === '') {
            throw new \InvalidArgumentException('Missing Host header in request.');
        }
        $hostAuthority = self::parseHostAuthority($originalHost);
        if ($hostAuthority === null) {
            throw new \InvalidArgumentException('Invalid Host header value.');
        }

        // ========== 构建完整的 $_SERVER（兼容 FPM 环境）==========
        // 基础路径信息
        $documentRoot = \defined('BP') ? \rtrim(BP, DIRECTORY_SEPARATOR) : \getcwd();
        $scriptName = '/index.php';
        $scriptFilename = $documentRoot . $scriptName;
        
        $server = \array_merge($serverInfo, [
            // ===== 请求方法和 URI =====
            'REQUEST_METHOD' => $method,
            'REQUEST_URI' => $uri,
            'QUERY_STRING' => $queryString,
            'PATH_INFO' => $path,
            // 唯一判断处：静态文件仅按 path 判断，其他处只读此标志
            'WELINE_IS_STATIC_FILE' => $isStaticResource,

            // ===== 服务器信息（FPM 标准）=====
            'SERVER_SOFTWARE' => Response::SERVER_SIGNATURE,
            'SERVER_PROTOCOL' => $protocol,
            'GATEWAY_INTERFACE' => 'CGI/1.1',
            'DOCUMENT_ROOT' => $documentRoot,
            'SCRIPT_FILENAME' => $scriptFilename,
            'SCRIPT_NAME' => $scriptName,
            'PHP_SELF' => $scriptName,
            
            // ===== Host 相关 =====
            'HTTP_HOST' => $originalHost,
            'SERVER_ADDR' => '127.0.0.1',
            
            // ===== 客户端信息 =====
            'REMOTE_PORT' => '0', // WLS 模式下不可知
            
            // ===== HTTP 头 =====
            'HTTP_USER_AGENT' => $isStaticResource ? '' : ($headers['User-Agent'] ?? ''),
            'HTTP_ACCEPT' => $isStaticResource ? '*/*' : ($headers['Accept'] ?? '*/*'),
            'HTTP_ACCEPT_LANGUAGE' => $isStaticResource ? '' : ($headers['Accept-Language'] ?? ''),
            'HTTP_ACCEPT_ENCODING' => $isStaticResource ? '' : ($headers['Accept-Encoding'] ?? ''),
            'HTTP_ACCEPT_CHARSET' => $isStaticResource ? '' : ($headers['Accept-Charset'] ?? ''),
            'HTTP_CONNECTION' => $headers['Connection'] ?? 'keep-alive',
            'HTTP_CACHE_CONTROL' => $isStaticResource ? '' : ($headers['Cache-Control'] ?? ''),
            'HTTP_PRAGMA' => $isStaticResource ? '' : ($headers['Pragma'] ?? ''),
            'HTTP_REFERER' => $isStaticResource ? '' : ($headers['Referer'] ?? ''),
            'HTTP_ORIGIN' => $isStaticResource ? '' : ($headers['Origin'] ?? ''),
            'HTTP_COOKIE' => $headers['Cookie'] ?? '',
            
            // ===== 内容相关 =====
            'CONTENT_TYPE' => $headers['Content-Type'] ?? '',
            'CONTENT_LENGTH' => $headers['Content-Length'] ?? \strlen($this->body),
            
            // ===== 时间戳 =====
            'REQUEST_TIME' => \time(),
            'REQUEST_TIME_FLOAT' => \microtime(true),
            
            // ===== FastCGI 兼容 =====
            'REDIRECT_STATUS' => '200',
            'FCGI_ROLE' => 'RESPONDER',
        ]);
        
        // 保存 Host 信息（供 getBaseHost() 使用）
        
        // 保存 HTTPS 状态（供 isSecure() 和 getBaseHost() 使用）
        $this->parsedHttps = $isHttps;
        
        // 设置 HTTPS 相关
        $server['HTTPS'] = $isHttps ? 'on' : '';
        $server['REQUEST_SCHEME'] = $isHttps ? 'https' : 'http';
        
        // 设置客户端 IP（优先使用代理头，回退到默认值）
        if ($realIp) {
            $server['REMOTE_ADDR'] = $realIp;
        } elseif (!isset($server['REMOTE_ADDR']) || empty($server['REMOTE_ADDR'])) {
            // 从 X-Forwarded-For 取第一个 IP
            $forwardedFor = $headers['CF-Connecting-IP'] ?? ($headers['X-Forwarded-For'] ?? $headers['Weline-Forwarded-For'] ?? '');
            if ($forwardedFor) {
                $ips = \explode(',', $forwardedFor);
                $server['REMOTE_ADDR'] = \trim($ips[0]);
            } else {
                $server['REMOTE_ADDR'] = '127.0.0.1';
            }
        }
        
        // 解析端口（优先使用 Weline-Original-Port）
        $hostName = $hostAuthority['host'];
        $serverName = $hostAuthority['server_name'];
        $hostPort = $hostAuthority['port'];
        
        // 端口优先级：Weline-Original-Port > Host 头中的端口 > 协议默认端口
        if ($originalPort !== '') {
            if (!\ctype_digit((string)$originalPort) || !self::isValidTcpPort((int)$originalPort)) {
                throw new \InvalidArgumentException('Invalid forwarded port value.');
            }
            $serverPort = (int)$originalPort;
        } elseif ($hostPort !== null) {
            $serverPort = $hostPort;
        } else {
            $serverPort = $isHttps ? 443 : 80;
        }
        
        // 清理 Host 头：如果包含默认端口号（:80 或 :443），去掉它
        // HTTP 默认端口是 80，HTTPS 默认端口是 443，URL 中不应显示这些默认端口
        if ($hostPort !== null) {
            $portNum = (int)$hostPort;
            if (($portNum == 80 && !$isHttps) || ($portNum == 443 && $isHttps)) {
                // 默认端口，从 Host 头中移除
                $server['HTTP_HOST'] = $hostName;
            }
        }
        
        $isDefaultPort = (($serverPort == 80 && !$isHttps) || ($serverPort == 443 && $isHttps));
        $normalizedHost = $hostName . ($isDefaultPort ? '' : ':' . $serverPort);
        $server['HTTP_HOST'] = $normalizedHost;
        $this->parsedHost = $normalizedHost;

        $server['SERVER_NAME'] = $serverName;
        $server['SERVER_PORT'] = (string)$serverPort;
        
        // 构建完整请求 URI（默认端口 80/443 不在 URL 中显示）
        $server['WELINE_ORIGIN_REQUEST_URI'] = $uri;
        // 如果是默认端口（HTTP 80 或 HTTPS 443），不在 URL 中包含端口号
        $portSuffix = $isDefaultPort ? '' : ':' . $serverPort;
        $server['WELINE_FULL_REQUEST_URI'] = $server['REQUEST_SCHEME'] . '://' . 
            $hostName . $portSuffix . $uri;
        
        // 添加所有 HTTP 头到 $_SERVER
        foreach ($headers as $name => $value) {
            $serverKey = 'HTTP_' . \strtoupper(\str_replace('-', '_', $name));
            if (!isset($server[$serverKey])) {
                $server[$serverKey] = $value;
            }
        }
        
        // 静态资源：保留 Cookie，只去掉浏览器附带的大量非必要头，降低 $_SERVER 噪音。
        if ($isStaticResource) {
            $staticStrip = [
                'HTTP_SEC_FETCH_SITE', 'HTTP_SEC_FETCH_MODE', 'HTTP_SEC_FETCH_DEST', 'HTTP_SEC_FETCH_USER',
                'HTTP_SEC_CH_UA', 'HTTP_SEC_CH_UA_MOBILE', 'HTTP_SEC_CH_UA_PLATFORM', 'HTTP_SEC_CH_UA_ARCH',
                'HTTP_SEC_CH_UA_BITNESS', 'HTTP_SEC_CH_UA_MODEL', 'HTTP_SEC_CH_UA_FULL_VERSION_LIST',
                'HTTP_UPGRADE_INSECURE_REQUESTS', 'HTTP_PRIORITY', 'HTTP_DNT',
            ];
            foreach ($staticStrip as $rk) {
                unset($server[$rk]);
            }
            foreach (\array_keys($server) as $sk) {
                if (!\is_string($sk) || !\str_starts_with($sk, 'HTTP_SEC_')) {
                    continue;
                }
                unset($server[$sk]);
            }
        }
        
        // 设置超全局变量
        $_GET = $getParams;
        $_POST = $postParams;
        $_COOKIE = $cookies;
        \Weline\Framework\Env\WelineEnv::set('cookie', $cookies);
        $_SERVER = $server;
        $_REQUEST = \array_merge($getParams, $postParams);
        
        // 开发模式下打印 $_SERVER 用于调试（通过 WLS_DEV_MODE 常量控制）
        if (self::shouldWriteDevServerDump(\defined('WLS_DEV_MODE') && WLS_DEV_MODE)) {
            $debugServerVars = [
                // ===== 核心请求信息 =====
                'REQUEST_METHOD' => $_SERVER['REQUEST_METHOD'] ?? '(未设置)',
                'REQUEST_URI' => $_SERVER['REQUEST_URI'] ?? '(未设置)',
                'REQUEST_SCHEME' => $_SERVER['REQUEST_SCHEME'] ?? '(未设置)',
                'HTTPS' => $_SERVER['HTTPS'] ?? '(未设置)',
                'QUERY_STRING' => $_SERVER['QUERY_STRING'] ?? '(未设置)',
                'PATH_INFO' => $_SERVER['PATH_INFO'] ?? '(未设置)',
                // ===== 服务器信息 =====
                'HTTP_HOST' => $_SERVER['HTTP_HOST'] ?? '(未设置)',
                'SERVER_NAME' => $_SERVER['SERVER_NAME'] ?? '(未设置)',
                'SERVER_PORT' => $_SERVER['SERVER_PORT'] ?? '(未设置)',
                'SERVER_ADDR' => $_SERVER['SERVER_ADDR'] ?? '(未设置)',
                'SERVER_SOFTWARE' => $_SERVER['SERVER_SOFTWARE'] ?? '(未设置)',
                'SERVER_PROTOCOL' => $_SERVER['SERVER_PROTOCOL'] ?? '(未设置)',
                // ===== 路径信息（FPM 兼容）=====
                'DOCUMENT_ROOT' => $_SERVER['DOCUMENT_ROOT'] ?? '(未设置)',
                'SCRIPT_FILENAME' => $_SERVER['SCRIPT_FILENAME'] ?? '(未设置)',
                'SCRIPT_NAME' => $_SERVER['SCRIPT_NAME'] ?? '(未设置)',
                'PHP_SELF' => $_SERVER['PHP_SELF'] ?? '(未设置)',
                // ===== 客户端信息 =====
                'REMOTE_ADDR' => $_SERVER['REMOTE_ADDR'] ?? '(未设置)',
                'HTTP_USER_AGENT' => \substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 50) . '...',
                // ===== Weline 自定义头 =====
                'HTTP_WELINE_VIA_DISPATCHER' => $_SERVER['HTTP_WELINE_VIA_DISPATCHER'] ?? '(未设置)',
                'HTTP_WELINE_ORIGINAL_SCHEME' => $_SERVER['HTTP_WELINE_ORIGINAL_SCHEME'] ?? '(未设置)',
                'HTTP_WELINE_ORIGINAL_HOST' => $_SERVER['HTTP_WELINE_ORIGINAL_HOST'] ?? '(未设置)',
                'HTTP_WELINE_ORIGINAL_PORT' => $_SERVER['HTTP_WELINE_ORIGINAL_PORT'] ?? '(未设置)',
                // ===== 标准代理头 =====
                'HTTP_X_FORWARDED_PROTO' => $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '(未设置)',
                'HTTP_X_FORWARDED_HOST' => $_SERVER['HTTP_X_FORWARDED_HOST'] ?? '(未设置)',
                // ===== 完整 URI =====
                'WELINE_FULL_REQUEST_URI' => $_SERVER['WELINE_FULL_REQUEST_URI'] ?? '(未设置)',
            ];
            $jsonOutput = \json_encode($debugServerVars, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
            
            // 始终写入日志文件（便于查看）
            $logDir = \defined('BP') ? BP . 'var/log/wls/' : '';
            if ($logDir && !\is_dir($logDir)) {
                @\mkdir($logDir, 0755, true);
            }
            if ($logDir && \is_dir($logDir)) {
                @\file_put_contents($logDir . 'wls.log', '[' . \date('Y-m-d H:i:s') . "] [WlsRequest] \$_SERVER:\n{$jsonOutput}\n", FILE_APPEND);
            }
            
            // 前台模式同时输出到 STDOUT
            if (\defined('WLS_FRONTEND_MODE') && WLS_FRONTEND_MODE && \defined('STDOUT') && \is_resource(STDOUT)) {
                \fwrite(STDOUT, "\033[36m[WlsRequest] \$_SERVER:\n{$jsonOutput}\033[0m\n");
                \fflush(STDOUT);
            }
        }
        
        // 初始化请求对象数据
        $this->setData(\array_merge($getParams, $postParams));
        
        // ========== 初步判断是否为后端请求（在 URL 解析之前）==========
        // 检查 URI 路径中是否包含后端路径标识
        // 注意：这里只是初步判断，最终判断会在 Url::parser() 之后通过 getRequestArea() 完成
        $backendPath = '/admin';
        $envFile = \defined('BP') ? BP . 'app/etc/env.php' : '';
        if ($envFile && \is_file($envFile)) {
            $envConfig = @include $envFile;
            // 检查 env.php 中是否有自定义的后端路径配置
            if (isset($envConfig['admin']) && \is_string($envConfig['admin']) && $envConfig['admin'] !== '') {
                // env.php 中的 'admin' 配置是网站代码，不是路径
                // 我们需要检查 URI 中是否包含这个网站代码，然后检查是否有 /admin 路径
                $websiteCode = $envConfig['admin'];
                // 如果 URI 以 /{websiteCode}/admin 开头，则可能是后端请求
                if (\str_starts_with($path, '/' . $websiteCode . '/admin')) {
                    $this->setBackend();
                }
            }
        }
        
        // 通用检查：如果路径中包含 /admin，初步标记为后端请求
        // 注意：这只是一个启发式判断，最终判断需要等待 Url::parser() 完成
        if (\str_contains($path, '/admin')) {
            $this->setBackend();
        }
    }
    
    /**
     * 获取解析后的头部
     */
    public function getParsedHeaders(): array
    {
        return $this->parsedHeaders;
    }
    
    /**
     * 获取请求体
     */
    public function getRawBody(): string
    {
        return $this->body;
    }
    
    /**
     * 获取原始 HTTP 数据
     */
    public function getRawData(): string
    {
        return $this->rawData;
    }
    
    /**
     * 重写 getParameterBag()：WLS 模式下直接注入解析后的 body 数据
     * 
     * FPM 模式下 ParameterBag 通过 php://input 读取请求体，
     * 但 WLS 模式下 php://input 不可用，需要从已解析的原始 HTTP 数据中注入。
     * 
     * 使用 parsedGetParams/parsedPostParams（parseRawHttp 阶段保存的不可变数据），
     * 而非 $_GET/$_POST（可能被 GlobalsEmulator::reset() 清空后尚未恢复）。
     * 
     * @return \Weline\Framework\Http\Request\ParameterBag
     */
    public function getParameterBag(): \Weline\Framework\Http\Request\ParameterBag
    {
        if ($this->parameterBag === null) {
            $parsed = $this->parsedBodyPayload;
            $bodyData = $parsed['post'];
            $_FILES = $parsed['files'] ?? [];
            
            $this->parameterBag = new \Weline\Framework\Http\Request\ParameterBag(
                $this->parsedGetParams ?: ($_GET ?? []),
                $this->parsedPostParams ?: ($_POST ?? []),
                $bodyData
            );
            $this->parameterBag->setRawBody($this->body);
        }
        return $this->parameterBag;
    }
    
    /**
     * 重写 getBodyParams()：WLS 模式下使用解析的原始 body 替代 php://input
     * 
     * @param bool $array 是否强制返回数组
     * @return mixed
     */
    public function getBodyParams(bool $array = false)
    {
        $body_params_key = $array ? 'array_body_params' : 'body_params';
        if ($params = $this->getData($body_params_key)) {
            return $params;
        }
        
        // 使用 ParameterBag 获取已解析的 Body 参数
        $params = $this->getParameterBag()->getBody();
        
        // 如果解析后的参数为空且不要求数组格式，返回原始 body 字符串
        // WLS 模式：使用 $this->body 替代 file_get_contents('php://input')
        if (!$array && empty($params)) {
            $params = $this->body;
        }
        
        $this->setData($body_params_key, $params);
        return $params;
    }
    
    /**
     * 检查是否为 Keep-Alive 连接
     */
    public function isKeepAlive(): bool
    {
        $connection = \strtolower(\trim((string)($this->parsedHeaders['Connection'] ?? '')));
        if ($connection === 'close') {
            return false;
        }
        if ($connection === 'keep-alive') {
            return true;
        }
        // HTTP/1.1 默认 Keep-Alive；仅在明确 close 时关闭
        return \str_contains($this->rawData, 'HTTP/1.1');
    }
    
    /**
     * 获取特定头部值
     */
    public function getHeader(string $key = ''): array|string|null
    {
        if (empty($key)) {
            return $this->parsedHeaders;
        }
        
        // 尝试原始名称
        if (isset($this->parsedHeaders[$key])) {
            return $this->parsedHeaders[$key];
        }
        
        // 尝试不区分大小写匹配
        foreach ($this->parsedHeaders as $name => $value) {
            if (\strtolower($name) === \strtolower($key)) {
                return $value;
            }
        }
        
        return null;
    }
    
    /**
     * 获取所有头部
     */
    public function getHeaders(): array
    {
        return $this->parsedHeaders;
    }
    
    /**
     * 获取完整 URI（path?query），不依赖 $_SERVER
     * 这样 GlobalsEmulator::emulate 在 reset 后 buildServerArray 仍能拿到正确 REQUEST_URI
     * WLS 下 URL 解析后 REQUEST_URI 已由 Url::parser 改写，此时应返回解析后的值供 Router 使用
     * 
     * 关键修复：WLS 模式下 $_SERVER['REQUEST_URI'] 经历两个阶段：
     * 1. emulate() 后 = 原始 URI（含 backend key、货币、语言前缀）
     * 2. Url::parser() 后 = 纯路由 URI（已去除前缀）
     * 
     * parent::getUri() 会缓存第一次读取的值。如果在 Url::parser() 之前被调用（如 run_before 事件），
     * 会缓存阶段1的原始 URI。之后 Router 使用这个缓存值就会导致路由匹配失败（间歇性 404）。
     * 
     * 修复：URL 解析完成后（WELINE_URL_PARSED=true），直接从 $_SERVER 读取，绕过 parent 缓存。
     */
    public function getUri(): string
    {
        // WLS 模式：URL 解析完成后，$_SERVER['REQUEST_URI'] 已是最终的纯路由
        // 必须直接读取，不能依赖 parent 的缓存（可能缓存了解析前的原始 URI）
        if (\Weline\Framework\Runtime\Runtime::isPersistent()) {
            if (\w_env('url_parsed', false)) {
                // URL 已解析完成，从 $_SERVER 读取最新的纯路由 URI
                return \w_env('request.uri', '/');
            }
            // URL 尚未解析，使用从原始 HTTP 请求中解析的 URI
            if ($this->parsedUri !== '') {
                return $this->parsedUri;
            }
        }
        return parent::getUri();
    }
    
    /**
     * 获取查询字符串，不依赖 $_SERVER
     */
    public function getQueryString(): string
    {
        if ($this->parsedQueryString !== '' || $this->parsedUri !== '') {
            return $this->parsedQueryString;
        }
        return parent::getQueryString();
    }
    
    /**
     * 获取 GET+POST 合并参数，不依赖 $_SERVER/$_GET/$_POST
     * 这样 emulate 在 reset 后 populateFromRequest 能正确设置 $_GET
     */
    public function getParams()
    {
        $params = \array_merge($this->parsedGetParams, $this->parsedPostParams);
        if ($params !== []) {
            return $params;
        }
        return parent::getParams();
    }
    
    /**
     * 获取 URL 查询参数，供 GlobalsEmulator 设置 $_GET 使用
     */
    public function getQueryParams(): array
    {
        return $this->parsedGetParams;
    }
    
    /**
     * 获取 POST 参数，供 GlobalsEmulator::populateFromRequest 使用
     */
    public function getPostParams(): array
    {
        return $this->parsedPostParams;
    }
    
    /**
     * 获取请求方法，不依赖 $_SERVER
     */
    public function getMethod(): string
    {
        if ($this->parsedMethod !== '') {
            return $this->parsedMethod;
        }
        return parent::getMethod();
    }
    
    /**
     * 判断是否为 HTTPS，不依赖 $_SERVER
     */
    public function isSecure(): bool
    {
        return $this->parsedHttps;
    }
    
    /**
     * 判断是否为 GET 请求，不依赖 $_SERVER
     */
    public function isGet(bool $set_get = false): bool
    {
        if ($this->parsedMethod !== '') {
            return $this->parsedMethod === 'GET';
        }
        return parent::isGet($set_get);
    }
    
    /**
     * 判断是否为 POST 请求，不依赖 $_SERVER
     */
    public function isPost(): bool
    {
        if ($this->parsedMethod !== '') {
            return $this->parsedMethod === 'POST';
        }
        return parent::isPost();
    }
    
    /**
     * 判断是否为 AJAX 请求，不依赖 $_SERVER
     */
    public function isAjax(): bool
    {
        // 检查 X-Requested-With 头部
        $xRequestedWith = $this->getHeader('X-Requested-With');
        if ($xRequestedWith && strtolower($xRequestedWith) === 'xmlhttprequest') {
            return true;
        }
        // 检查 GET/POST 参数
        return isset($this->parsedGetParams['isAjax']) || isset($this->parsedPostParams['isAjax']);
    }
    
    /**
     * 判断是否为 iframe 请求，不依赖 $_SERVER
     */
    public function isIframe(): bool
    {
        return isset($this->parsedGetParams['isIframe']) || isset($this->parsedPostParams['isIframe']);
    }
    
    /**
     * 获取基础主机 URL，不依赖 $_SERVER
     */
    public function getBaseHost(): string
    {
        $currentScheme = $this->isSecure() ? 'https' : 'http';
        $host = $this->parsedHost ?: (string)($this->getHeader('Host') ?? '');
        if ($host === '') {
            throw new \RuntimeException('Host is not available for current request.');
        }

        // 透传模式：只信 Host 头（含端口），不做代理头推断
        $currentPort = '';
        if (\str_contains($host, ':')) {
            $parts = \explode(':', $host, 2);
            $hostName = $parts[0];
            $currentPort = $parts[1] ?? '';
        } else {
            $hostName = $host;
            $currentPort = $currentScheme === 'https' ? '443' : '80';
        }
        $isNonStandardPort = $currentPort !== '' && !(($currentScheme === 'https' && $currentPort === '443') || ($currentScheme !== 'https' && $currentPort === '80'));

        // 直接从 $_SERVER 读取 WELINE_WEBSITE_URL（Url::parser → processUrlParse 写入）
        // 不能用 getServer() / ServerBag，因为 ServerBag 可能在 parser 之前就已初始化
        // URL 生成时始终参考当前 WLS 请求的端口，非标准端口（如 9981）必须带上
        $websiteUrl = (string) \w_env('website_url', '');
        if ($websiteUrl !== '') {
            $parsed = \parse_url($websiteUrl);
            $wHost = $parsed['host'] ?? 'localhost';
            $wPath = $this->sanitizeWebsiteUrlPathForBaseHost((string)($parsed['path'] ?? ''));

            if ($websiteUrl !== '') {
                if (isset($parsed['port'])) {
                    $currentPort = (string)$parsed['port'];
                    $isNonStandardPort = !(($currentScheme === 'https' && $currentPort === '443') || ($currentScheme !== 'https' && $currentPort === '80'));
                }
                $portSuffix = $isNonStandardPort ? ':' . $currentPort : '';
                return $currentScheme . '://' . $wHost . $portSuffix . $wPath;
            }
        }

        return $currentScheme . '://' . $hostName . ($isNonStandardPort ? ':' . $currentPort : '');
    }

    // ==================== 请求体解析引擎 ====================

    /**
     * 统一解析 HTTP 请求体
     *
     * FPM 下由 PHP SAPI 自动完成，WLS 常驻内存模式需手动解析原始字节流。
     * 支持所有常见 Content-Type，且不限于 POST（PUT / PATCH / DELETE 也可携带 body）。
     *
     * 支持的 Content-Type：
     *   - application/x-www-form-urlencoded
     *   - application/json 及 +json 后缀（如 application/vnd.api+json、application/ld+json）
     *   - multipart/form-data（含文件上传、RFC 5987 filename* 编码文件名）
     *   - text/xml / application/xml 及 +xml 后缀（如 application/soap+xml）
     *   - text/plain（尝试 JSON → URL-encoded → 原始文本）
     *   - text/csv / application/csv
     *   - application/graphql
     *   - application/x-ndjson（Newline Delimited JSON）
     *   - application/msgpack / application/x-msgpack（需 msgpack 扩展）
     *
     * @return array{post: array, files: array}
     */
    public static function parseRequestBody(string $body, string $contentType, string $method = 'POST'): array
    {
        $empty = ['post' => [], 'files' => []];

        // 无 body 或 GET/HEAD/OPTIONS 不解析
        if ($body === '' || \in_array($method, ['GET', 'HEAD', 'OPTIONS'], true)) {
            return $empty;
        }

        // 提取主类型（去掉 charset / boundary 等参数）
        $ct = \strtolower(\trim(\explode(';', $contentType, 2)[0]));

        return match (true) {
            // ── URL-encoded ──
            $ct === 'application/x-www-form-urlencoded' => self::parseUrlEncoded($body),

            // ── JSON 系列：application/json, text/json, application/vnd.api+json, application/ld+json ──
            $ct === 'application/json'
                || $ct === 'text/json'
                || \str_ends_with($ct, '+json') => self::parseJson($body),

            // ── Multipart form-data（含文件上传）──
            $ct === 'multipart/form-data' => self::parseMultipartFormData($body, $contentType),

            // ── XML 系列：text/xml, application/xml, application/soap+xml, +xml 后缀 ──
            $ct === 'text/xml'
                || $ct === 'application/xml'
                || \str_ends_with($ct, '+xml') => self::parseXml($body),

            // ── CSV ──
            $ct === 'text/csv' || $ct === 'application/csv' => self::parseCsv($body),

            // ── GraphQL ──
            $ct === 'application/graphql' => ['post' => ['query' => $body], 'files' => []],

            // ── Newline Delimited JSON（Elasticsearch bulk、日志流等）──
            $ct === 'application/x-ndjson' || $ct === 'application/ndjson' => self::parseNdJson($body),

            // ── MessagePack（需 msgpack 扩展）──
            ($ct === 'application/msgpack' || $ct === 'application/x-msgpack')
                && \function_exists('msgpack_unpack') => self::parseMsgPack($body),

            // ── text/plain：智能嗅探（依次尝试 JSON → URL-encoded → 原始文本）──
            $ct === 'text/plain' || $ct === '' => self::parsePlainText($body),

            // ── 未知/未声明类型：用 parsePlainText 兜底（尝试 JSON → URL-encoded → 原始文本），避免漏解析
            default => self::parsePlainText($body),
        };
    }

    // ── 各格式解析器 ─────────────────────────────────────

    private static function parseUrlEncoded(string $body): array
    {
        \parse_str($body, $params);
        return ['post' => $params, 'files' => []];
    }

    private static function parseJson(string $body): array
    {
        $data = \json_decode($body, true);
        return ['post' => \is_array($data) ? $data : [], 'files' => []];
    }

    /**
     * 解析 multipart/form-data
     *
     * - 文本字段收集后交给 parse_str() 处理 PHP 数组命名（name[], name[key]）
     * - 文件上传写入临时文件，构造 $_FILES 兼容结构
     * - 支持 RFC 5987 filename*=UTF-8''encoded 编码文件名
     * - 支持同名多文件（files[]）
     */
    public static function parseMultipartFormData(string $body, string $contentType): array
    {
        // 提取 boundary（可能被引号包裹）
        if (!\preg_match('/boundary=(["\']?)(.+?)\1(?:\s*;|$)/i', $contentType, $m)) {
            return ['post' => [], 'files' => []];
        }
        $boundary = \trim($m[2]);
        $delimiter = '--' . $boundary;

        // 按 boundary 分割
        $parts = \explode($delimiter, $body);
        \array_shift($parts); // 首段空
        \array_pop($parts);   // 尾段 --

        $textPairs = [];
        $files = [];

        foreach ($parts as $part) {
            $part = \ltrim($part, "\r\n");

            // header / body 以双 CRLF 或双 LF 分隔（Linux 下可能只有 \n）
            $sep = \strpos($part, "\r\n\r\n");
            $sepLen = 4;
            if ($sep === false) {
                $sep = \strpos($part, "\n\n");
                $sepLen = 2;
            }
            if ($sep === false) {
                continue;
            }
            $head = \substr($part, 0, $sep);
            $data = \substr($part, $sep + $sepLen);
            $data = \rtrim($data, "\r\n");

            // 必须有 name
            if (!\preg_match('/Content-Disposition:\s*form-data;\s*name="([^"]*)"/i', $head, $nm)) {
                continue;
            }
            $fieldName = $nm[1];

            // ── 文件字段 ──
            if (\preg_match('/filename="([^"]*)"/i', $head, $fnm)
                || \preg_match('/filename\*=(?:UTF-8|utf-8)\'\'.+/i', $head)) {

                // 优先 RFC 5987 filename*
                $filename = '';
                if (\preg_match('/filename\*=(?:UTF-8|utf-8)\'\'(.+)/i', $head, $fnStar)) {
                    $filename = \rawurldecode(\trim($fnStar[1]));
                } elseif (isset($fnm[1])) {
                    $filename = $fnm[1];
                }

                if ($filename === '') {
                    // 无文件名且有 filename 属性 → 未选择文件，记为空上传
                    self::addFileEntry($files, $fieldName, [
                        'name'     => '',
                        'type'     => '',
                        'tmp_name' => '',
                        'error'    => \UPLOAD_ERR_NO_FILE,
                        'size'     => 0,
                    ]);
                    continue;
                }

                $fileContentType = 'application/octet-stream';
                if (\preg_match('/Content-Type:\s*(.+)/i', $head, $ctm)) {
                    $fileContentType = \trim($ctm[1]);
                }

                $tmpFile = \tempnam(\sys_get_temp_dir(), 'wls_');
                if ($tmpFile !== false) {
                    \file_put_contents($tmpFile, $data);
                    self::addFileEntry($files, $fieldName, [
                        'name'     => $filename,
                        'type'     => $fileContentType,
                        'tmp_name' => $tmpFile,
                        'error'    => \UPLOAD_ERR_OK,
                        'size'     => \strlen($data),
                    ]);
                }
            } else {
                // ── 文本字段 ──
                $textPairs[] = \urlencode($fieldName) . '=' . \urlencode($data);
            }
        }

        $postParams = [];
        if ($textPairs !== []) {
            \parse_str(\implode('&', $textPairs), $postParams);
        }

        return ['post' => $postParams, 'files' => $files];
    }

    /**
     * 向 $_FILES 兼容结构中添加文件条目
     *
     * 处理同名多文件（如 files[]）：PHP 原生 $_FILES 对同名字段会合并为
     * ['name' => [...], 'type' => [...], ...] 结构，此方法模拟该行为。
     */
    private static function addFileEntry(array &$files, string $fieldName, array $entry): void
    {
        // 数组字段名（如 files[] 或 photos[profile]）
        $isArray = \str_contains($fieldName, '[]');
        $baseName = $isArray ? \str_replace('[]', '', $fieldName) : $fieldName;

        if ($isArray) {
            // 多文件数组
            foreach (['name', 'type', 'tmp_name', 'error', 'size'] as $key) {
                $files[$baseName][$key][] = $entry[$key];
            }
        } elseif (isset($files[$fieldName])) {
            // 同名重复字段 → 自动转为数组
            if (isset($files[$fieldName]['name']) && !\is_array($files[$fieldName]['name'])) {
                $existing = $files[$fieldName];
                $files[$fieldName] = [];
                foreach (['name', 'type', 'tmp_name', 'error', 'size'] as $key) {
                    $files[$fieldName][$key] = [$existing[$key]];
                }
            }
            foreach (['name', 'type', 'tmp_name', 'error', 'size'] as $key) {
                $files[$fieldName][$key][] = $entry[$key];
            }
        } else {
            $files[$fieldName] = $entry;
        }
    }

    /**
     * 解析 XML 请求体（text/xml, application/xml, application/soap+xml, +xml）
     *
     * 转为关联数组。SOAP Envelope 自动提取 Body 内容。
     */
    private static function parseXml(string $body): array
    {
        // 安全：抑制 libxml 警告，LIBXML_NONET 已阻止外部实体
        $prevErrors = \libxml_use_internal_errors(true);

        try {
            $xml = \simplexml_load_string($body, 'SimpleXMLElement', \LIBXML_NOCDATA | \LIBXML_NONET);
            if ($xml === false) {
                return ['post' => [], 'files' => []];
            }

            // SOAP Envelope → 自动提取 Body 内容
            $namespaces = $xml->getNamespaces(true);
            foreach ($namespaces as $ns) {
                if (\str_contains($ns, 'schemas.xmlsoap.org/soap/envelope')
                    || \str_contains($ns, 'www.w3.org/2003/05/soap-envelope')) {
                    $xml->registerXPathNamespace('soap', $ns);
                    $soapBody = $xml->xpath('//soap:Body');
                    if (!empty($soapBody)) {
                        $xml = $soapBody[0];
                    }
                    break;
                }
            }

            $data = \json_decode(\json_encode($xml), true);
            return ['post' => \is_array($data) ? $data : [], 'files' => []];
        } finally {
            \libxml_use_internal_errors($prevErrors);
        }
    }

    /**
     * 解析 CSV 请求体（text/csv, application/csv）
     *
     * 首行视为 header，后续行为数据行，返回 ['rows' => [...]]。
     */
    private static function parseCsv(string $body): array
    {
        $rows = [];
        $headers = null;

        // 逐行解析，兼容 \r\n 和 \n
        foreach (\explode("\n", \str_replace("\r\n", "\n", $body)) as $line) {
            $line = \trim($line);
            if ($line === '') {
                continue;
            }
            $fields = \str_getcsv($line);
            if ($headers === null) {
                $headers = $fields;
                continue;
            }
            // 将 header 与值配对（列数不足用空串填充）
            $row = [];
            foreach ($headers as $i => $h) {
                $row[$h] = $fields[$i] ?? '';
            }
            $rows[] = $row;
        }

        return ['post' => ['rows' => $rows, 'headers' => $headers ?? []], 'files' => []];
    }

    /**
     * 解析 Newline Delimited JSON（application/x-ndjson）
     *
     * 每行一个 JSON 对象，常用于 Elasticsearch bulk API、日志流。
     */
    private static function parseNdJson(string $body): array
    {
        $items = [];
        foreach (\explode("\n", $body) as $line) {
            $line = \trim($line);
            if ($line === '') {
                continue;
            }
            $decoded = \json_decode($line, true);
            if (\is_array($decoded)) {
                $items[] = $decoded;
            }
        }
        return ['post' => ['items' => $items], 'files' => []];
    }

    /**
     * 解析 MessagePack（application/msgpack, application/x-msgpack）
     */
    private static function parseMsgPack(string $body): array
    {
        $data = \msgpack_unpack($body);
        return ['post' => \is_array($data) ? $data : [], 'files' => []];
    }

    /**
     * 解析 text/plain —— 智能嗅探
     *
     * 依次尝试：JSON → URL-encoded → 作为 body 键返回原始文本
     */
    private static function parsePlainText(string $body): array
    {
        // 1. 尝试 JSON（以 { 或 [ 开头）
        $trimmed = \ltrim($body);
        if (($trimmed[0] ?? '') === '{' || ($trimmed[0] ?? '') === '[') {
            $data = \json_decode($body, true);
            if (\is_array($data)) {
                return ['post' => $data, 'files' => []];
            }
        }

        // 2. 尝试 URL-encoded（包含 = 且无换行）
        if (\str_contains($body, '=') && !\str_contains($body, "\n")) {
            \parse_str($body, $params);
            if (!empty($params)) {
                return ['post' => $params, 'files' => []];
            }
        }

        // 3. 作为原始文本
        return ['post' => ['body' => $body], 'files' => []];
    }
    private static function shouldWriteDevServerDump(bool $devMode): bool
    {
        if (!$devMode) {
            return false;
        }

        if ((bool)Env::get('wls.debug.request_server_dump', false)) {
            return true;
        }

        return \class_exists(LogConfig::class) && LogConfig::isVerboseWlsLog();
    }
}

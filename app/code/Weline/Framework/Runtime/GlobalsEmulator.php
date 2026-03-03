<?php
declare(strict_types=1);

/**
 * Weline Framework - 超全局变量模拟器
 * 
 * 在 WLS 常驻内存模式下模拟 PHP 超全局变量
 * 
 * @author Aiweline
 * @email aiweline@qq.com
 */

namespace Weline\Framework\Runtime;

use Weline\Framework\Http\Request;

/**
 * 超全局变量模拟器
 * 
 * 功能：
 * - 在 WLS 模式下模拟 $_GET、$_POST、$_SERVER 等超全局变量
 * - 每请求开始时填充，结束时清空
 * - 保持现有代码兼容性
 */
class GlobalsEmulator
{
    /**
     * 备份的原始值
     */
    private array $backup = [];
    
    /**
     * 是否已模拟
     */
    private bool $emulated = false;
    
    /**
     * 从 Request 对象模拟超全局变量
     * 
     * @param Request $request 请求对象
     * @return void
     */
    public function emulate(Request $request): void
    {
        if ($this->emulated) {
            $this->reset();
        }
        
        // 备份当前值
        $this->backup = [
            '_GET' => $_GET ?? [],
            '_POST' => $_POST ?? [],
            '_COOKIE' => $_COOKIE ?? [],
            '_FILES' => $_FILES ?? [],
            '_SERVER' => $_SERVER ?? [],
            '_REQUEST' => $_REQUEST ?? [],
        ];
        
        // 从 Request 对象填充超全局变量
        $this->populateFromRequest($request);
        
        $this->emulated = true;
    }
    
    /**
     * 从原始 HTTP 数据模拟超全局变量
     * 
     * @param string $rawData 原始 HTTP 数据
     * @param array $serverInfo 服务器信息
     * @return void
     */
    public function emulateFromRaw(string $rawData, array $serverInfo = []): void
    {
        if ($this->emulated) {
            $this->reset();
        }
        
        // 备份当前值
        $this->backup = [
            '_GET' => $_GET ?? [],
            '_POST' => $_POST ?? [],
            '_COOKIE' => $_COOKIE ?? [],
            '_FILES' => $_FILES ?? [],
            '_SERVER' => $_SERVER ?? [],
            '_REQUEST' => $_REQUEST ?? [],
        ];
        
        // 解析原始 HTTP 数据
        $parsed = $this->parseRawHttp($rawData);
        
        // 填充超全局变量
        $_GET = $parsed['get'] ?? [];
        $_POST = $parsed['post'] ?? [];
        $_COOKIE = $parsed['cookies'] ?? [];
        $_FILES = $parsed['files'] ?? [];
        $_SERVER = \array_merge($serverInfo, $parsed['server'] ?? []);
        $_REQUEST = \array_merge($_GET, $_POST);
        
        $this->emulated = true;
    }
    
    /**
     * 从 Request 对象填充超全局变量
     */
    private function populateFromRequest(Request $request): void
    {
        // WLS 兼容：先设置 $_POST，再设置 $_GET
        // 必须先设置超全局变量，然后再重置 ParameterBag
        $_POST = $request->getPostParams() ?? [];
        
        // 获取 GET 参数：WlsRequest 有 getQueryParams()，普通 Request 没有
        if (\method_exists($request, 'getQueryParams')) {
            $_GET = $request->getQueryParams() ?? [];
        } else {
            // 普通 Request 不应该出现在 WLS 中
            $_GET = [];
        }
        
        // 重置 Request 的 ParameterBag，强制它重新从新的 $_GET/$_POST 初始化
        if (\method_exists($request, 'resetParameterBag')) {
            $request->resetParameterBag();
        }
        
        // 获取 Cookie
        $_COOKIE = [];
        $cookieHeader = $request->getHeader('Cookie');
        if ($cookieHeader) {
            $cookies = \explode(';', $cookieHeader);
            foreach ($cookies as $cookie) {
                $parts = \explode('=', \trim($cookie), 2);
                if (\count($parts) === 2) {
                    $_COOKIE[\trim($parts[0])] = \urldecode(\trim($parts[1]));
                }
            }
        }
        
        // 获取上传文件
        $_FILES = $request->getFiles() ?? [];
        
        // 构建 $_SERVER
        $_SERVER = $this->buildServerArray($request);
        // 合并 GET 和 POST
        $_REQUEST = \array_merge($_GET, $_POST);
    }
    
    /**
     * 构建 $_SERVER 数组
     * 
     * WLS 模式下必须完全基于当前 request 重新构造，不能继承上次请求的 $_SERVER，
     * 否则 WELINE_*、REQUEST_URI 等会残留导致路由/缓存键错误。
     */
    private function buildServerArray(Request $request): array
    {
        // 仅保留 PHP 运行时相关键，不继承上次请求的 REQUEST_URI、WELINE_* 等
        $keepKeys = [
            'PHP_SELF', 'SCRIPT_NAME', 'SCRIPT_FILENAME', 'PATH_TRANSLATED',
            'DOCUMENT_ROOT', 'GATEWAY_INTERFACE', 'SERVER_SOFTWARE', 'SERVER_PROTOCOL',
            'SERVER_ADMIN', 'argc', 'argv',
        ];
        $server = [];
        foreach ($keepKeys as $key) {
            if (isset($_SERVER[$key])) {
                $server[$key] = $_SERVER[$key];
            }
        }
        
        // 以下全部从当前 request 构造
        $server['REQUEST_METHOD'] = $request->getMethod() ?? 'GET';
        $server['REQUEST_URI'] = $request->getUri() ?? '/';
        $server['QUERY_STRING'] = $request->getQueryString() ?? '';
        $server['HTTP_HOST'] = $request->getHeader('Host') ?? 'localhost';
        $server['HTTP_USER_AGENT'] = $request->getHeader('User-Agent') ?? '';
        $server['HTTP_ACCEPT'] = $request->getHeader('Accept') ?? '*/*';
        $server['HTTP_ACCEPT_LANGUAGE'] = $request->getHeader('Accept-Language') ?? '';
        $server['HTTP_ACCEPT_ENCODING'] = $request->getHeader('Accept-Encoding') ?? '';
        $server['HTTP_CONNECTION'] = $request->getHeader('Connection') ?? 'keep-alive';
        $server['CONTENT_TYPE'] = $request->getHeader('Content-Type') ?? '';
        $server['CONTENT_LENGTH'] = $request->getHeader('Content-Length') ?? '';
        
        // 解析 URI
        $uriParts = \parse_url($server['REQUEST_URI']);
        $server['PATH_INFO'] = $uriParts['path'] ?? '/';
        
        // HTTPS 检测
        $server['HTTPS'] = $request->isSecure() ? 'on' : '';
        $server['REQUEST_SCHEME'] = $request->isSecure() ? 'https' : 'http';
        
        // 端口
        $hostParts = \explode(':', $server['HTTP_HOST']);
        $server['SERVER_NAME'] = $hostParts[0];
        $server['SERVER_PORT'] = $hostParts[1] ?? ($request->isSecure() ? '443' : '80');
        
        // 时间戳
        $server['REQUEST_TIME'] = \time();
        $server['REQUEST_TIME_FLOAT'] = \microtime(true);
        
        // 完整请求 URI
        $server['WELINE_ORIGIN_REQUEST_URI'] = $server['REQUEST_URI'];
        $server['WELINE_FULL_REQUEST_URI'] = $server['REQUEST_SCHEME'] . '://' . 
            $server['HTTP_HOST'] . $server['REQUEST_URI'];
        
        // 重置 WELINE_* 变量为默认值，避免跨请求状态污染
        // 这些值会在 Url::parser() 中根据实际 URL 重新设置
        $server['WELINE_AREA'] = 'frontend';
        $server['WELINE_AREA_ROUTE'] = '';
        // 重要：不设置 WELINE_IS_BACKEND，让 CheckFullPageCache 知道 URL 尚未解析
        // 这样 CheckFullPageCache 会跳过检查，等待 url_parsed_after 事件再处理
        // $server['WELINE_IS_BACKEND'] = false;  // 故意不设置
        
        // 重要：不设置 WELINE_USER_LANG/WELINE_USER_CURRENCY 为空字符串！
        // 空字符串会导致 ?? 运算符无法回退到默认值。
        // 这些变量应该由 Url::parser() 根据 URL 路径或 Cookie 设置。
        // 不在此设置，让 syncFromServer() 的 ?? 能正确使用默认值。
        // $server['WELINE_USER_LANG'] = '';     // 不设置
        // $server['WELINE_USER_CURRENCY'] = ''; // 不设置
        // $server['WELINE_WEBSITE_ID'] = '';    // 不设置
        // $server['WELINE_WEBSITE_CODE'] = '';  // 不设置
        $server['WELINE_WEBSITE_URL'] = '';
        // URL 解析标志 - 初始为 false，Url::parser() 完成后设置为 true
        $server['WELINE_URL_PARSED'] = false;
        
        // 添加所有其他 HTTP 头
        foreach ($request->getHeaders() as $name => $value) {
            $serverKey = 'HTTP_' . \strtoupper(\str_replace('-', '_', $name));
            if (!isset($server[$serverKey])) {
                $server[$serverKey] = $value;
            }
        }
        
        return $server;
    }
    
    /**
     * 解析原始 HTTP 数据
     */
    private function parseRawHttp(string $rawData): array
    {
        $result = [
            'get' => [],
            'post' => [],
            'cookies' => [],
            'files' => [],
            'server' => [],
        ];
        
        // 分离头部和正文
        $parts = \explode("\r\n\r\n", $rawData, 2);
        $headerSection = $parts[0] ?? '';
        $body = $parts[1] ?? '';
        
        // 解析请求行
        $lines = \explode("\r\n", $headerSection);
        $requestLine = \array_shift($lines);
        
        if (\preg_match('/^(\w+)\s+([^\s]+)\s+HTTP\/[\d.]+$/', $requestLine, $matches)) {
            $result['server']['REQUEST_METHOD'] = $matches[1];
            $result['server']['REQUEST_URI'] = $matches[2];
            
            // 解析 GET 参数
            $uriParts = \parse_url($matches[2]);
            if (isset($uriParts['query'])) {
                \parse_str($uriParts['query'], $result['get']);
            }
        }
        
        // 解析头部
        foreach ($lines as $line) {
            if (\strpos($line, ':') !== false) {
                list($name, $value) = \explode(':', $line, 2);
                $name = \trim($name);
                $value = \trim($value);
                
                $serverKey = 'HTTP_' . \strtoupper(\str_replace('-', '_', $name));
                $result['server'][$serverKey] = $value;
                
                // 解析 Cookie
                if (\strtolower($name) === 'cookie') {
                    $cookies = \explode(';', $value);
                    foreach ($cookies as $cookie) {
                        $cookieParts = \explode('=', \trim($cookie), 2);
                        if (\count($cookieParts) === 2) {
                            $result['cookies'][\trim($cookieParts[0])] = \urldecode(\trim($cookieParts[1]));
                        }
                    }
                }
                
                // Content-Type 和 Content-Length
                if (\strtolower($name) === 'content-type') {
                    $result['server']['CONTENT_TYPE'] = $value;
                }
                if (\strtolower($name) === 'content-length') {
                    $result['server']['CONTENT_LENGTH'] = $value;
                }
            }
        }
        
        // 解析 POST 数据
        if (!empty($body) && ($result['server']['REQUEST_METHOD'] ?? '') === 'POST') {
            $contentType = $result['server']['CONTENT_TYPE'] ?? '';
            
            if (\stripos($contentType, 'application/x-www-form-urlencoded') !== false) {
                \parse_str($body, $result['post']);
            } elseif (\stripos($contentType, 'application/json') !== false) {
                $result['post'] = \json_decode($body, true) ?? [];
            }
            // TODO: 支持 multipart/form-data 解析文件上传
        }
        
        // 设置默认服务器变量
        $result['server']['REQUEST_TIME'] = \time();
        $result['server']['REQUEST_TIME_FLOAT'] = \microtime(true);
        
        return $result;
    }
    
    /**
     * 重置超全局变量
     * 
     * @return void
     */
    public function reset(): void
    {
        if (!$this->emulated) {
            return;
        }
        
        // 清空超全局变量（不恢复备份，因为在常驻内存模式下备份可能已过时）
        $_GET = [];
        $_POST = [];
        $_COOKIE = [];
        $_FILES = [];
        $_REQUEST = [];
        
        // 保留必要的 $_SERVER 变量
        $keepKeys = [
            'PHP_SELF',
            'SCRIPT_NAME',
            'SCRIPT_FILENAME',
            'PATH_TRANSLATED',
            'DOCUMENT_ROOT',
            'GATEWAY_INTERFACE',
            'SERVER_SOFTWARE',
            'SERVER_PROTOCOL',
            'SERVER_ADMIN',
            'argc',
            'argv',
        ];
        
        $newServer = [];
        foreach ($keepKeys as $key) {
            if (isset($_SERVER[$key])) {
                $newServer[$key] = $_SERVER[$key];
            }
        }
        $_SERVER = $newServer;
        
        $this->emulated = false;
        $this->backup = [];
    }
    
    /**
     * 判断是否已模拟
     * 
     * @return bool
     */
    public function isEmulated(): bool
    {
        return $this->emulated;
    }
}

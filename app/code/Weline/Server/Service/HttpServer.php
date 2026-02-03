<?php
declare(strict_types=1);

/**
 * Weline Server - HTTP 服务器服务
 * 
 * 与 Weline Framework 集成的 HTTP 服务器
 * 
 * @author Aiweline
 * @email aiweline@qq.com
 */

namespace Weline\Server\Service;

use Weline\Server\Worker;
use Weline\Server\Protocol\Request;
use Weline\Server\Protocol\Response;

/**
 * HttpServer - HTTP 服务器服务类
 * 
 * 提供更简单的 API 来创建和管理 HTTP 服务器
 * 
 * 用法示例：
 * ```php
 * $server = new HttpServer('0.0.0.0', 8080);
 * 
 * $server->get('/', function(Request $request) {
 *     return Response::html('<h1>Hello Weline!</h1>');
 * });
 * 
 * $server->post('/api/users', function(Request $request) {
 *     $data = $request->post();
 *     return Response::json(['success' => true, 'data' => $data]);
 * });
 * 
 * $server->start();
 * ```
 */
class HttpServer
{
    /**
     * Worker 实例
     */
    protected Worker $worker;
    
    /**
     * 路由表
     */
    protected array $routes = [];
    
    /**
     * 中间件
     */
    protected array $middleware = [];
    
    /**
     * 静态文件目录
     */
    protected ?string $staticDir = null;
    
    /**
     * 是否启用静态文件服务
     */
    protected bool $enableStatic = false;
    
    /**
     * 构造函数
     * 
     * @param string $host 监听地址
     * @param int $port 监听端口
     * @param int $count Worker 进程数
     */
    public function __construct(
        string $host = '0.0.0.0',
        int $port = 8080,
        int $count = 4
    ) {
        $this->worker = new Worker("http://{$host}:{$port}");
        $this->worker->count = $count;
        $this->worker->name = 'WelineHttpServer';
        
        $this->setupCallbacks();
    }
    
    /**
     * 设置回调
     */
    protected function setupCallbacks(): void
    {
        $this->worker->onMessage = function ($connection, Request $request) {
            $response = $this->handleRequest($request);
            $connection->send($response);
        };
    }
    
    /**
     * 处理请求
     */
    protected function handleRequest(Request $request): Response
    {
        try {
            // 运行中间件
            foreach ($this->middleware as $middleware) {
                $result = $middleware($request);
                if ($result instanceof Response) {
                    return $result;
                }
            }
            
            // 尝试静态文件
            if ($this->enableStatic) {
                $staticResponse = $this->tryStaticFile($request);
                if ($staticResponse !== null) {
                    return $staticResponse;
                }
            }
            
            // 路由匹配
            $method = $request->method();
            $path = $request->path();
            
            $handler = $this->matchRoute($method, $path);
            
            if ($handler === null) {
                return Response::html(
                    '<h1>404 Not Found</h1><p>The requested URL was not found on this server.</p>',
                    404
                );
            }
            
            $result = $handler($request);
            
            if ($result instanceof Response) {
                return $result;
            }
            
            if (is_array($result)) {
                return Response::json($result);
            }
            
            return Response::html((string) $result);
            
        } catch (\Throwable $e) {
            Worker::log(\__('请求处理错误：%{1}', [$e->getMessage()]));
            
            return Response::html(
                '<h1>500 Internal Server Error</h1><p>' . htmlspecialchars($e->getMessage()) . '</p>',
                500
            );
        }
    }
    
    /**
     * 匹配路由
     */
    protected function matchRoute(string $method, string $path): ?callable
    {
        // 精确匹配
        $key = "{$method}:{$path}";
        if (isset($this->routes[$key])) {
            return $this->routes[$key];
        }
        
        // 通配符匹配
        foreach ($this->routes as $pattern => $handler) {
            list($routeMethod, $routePath) = explode(':', $pattern, 2);
            
            if ($routeMethod !== $method && $routeMethod !== '*') {
                continue;
            }
            
            // 检查是否为正则模式
            if (strpos($routePath, '{') !== false) {
                $regex = preg_replace('/\{[^}]+\}/', '([^/]+)', $routePath);
                $regex = '#^' . $regex . '$#';
                
                if (preg_match($regex, $path)) {
                    return $handler;
                }
            }
        }
        
        return null;
    }
    
    /**
     * 尝试提供静态文件
     */
    protected function tryStaticFile(Request $request): ?Response
    {
        if ($this->staticDir === null) {
            return null;
        }
        
        $path = $request->path();
        
        // 安全检查
        if (strpos($path, '..') !== false) {
            return null;
        }
        
        $filePath = $this->staticDir . $path;
        
        if (!is_file($filePath)) {
            // 尝试 index.html
            if (is_file($filePath . '/index.html')) {
                $filePath = $filePath . '/index.html';
            } else {
                return null;
            }
        }
        
        $mimeType = $this->getMimeType($filePath);
        $content = file_get_contents($filePath);
        
        return new Response(
            200,
            [
                'Content-Type' => $mimeType,
                'Cache-Control' => 'public, max-age=3600',
            ],
            $content
        );
    }
    
    /**
     * 获取 MIME 类型
     */
    protected function getMimeType(string $filePath): string
    {
        $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        
        $mimeTypes = [
            'html' => 'text/html; charset=utf-8',
            'htm' => 'text/html; charset=utf-8',
            'css' => 'text/css; charset=utf-8',
            'js' => 'application/javascript; charset=utf-8',
            'json' => 'application/json; charset=utf-8',
            'xml' => 'application/xml; charset=utf-8',
            'png' => 'image/png',
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'gif' => 'image/gif',
            'svg' => 'image/svg+xml',
            'ico' => 'image/x-icon',
            'webp' => 'image/webp',
            'woff' => 'font/woff',
            'woff2' => 'font/woff2',
            'ttf' => 'font/ttf',
            'eot' => 'application/vnd.ms-fontobject',
            'pdf' => 'application/pdf',
            'zip' => 'application/zip',
            'txt' => 'text/plain; charset=utf-8',
        ];
        
        return $mimeTypes[$extension] ?? 'application/octet-stream';
    }
    
    /**
     * 添加 GET 路由
     */
    public function get(string $path, callable $handler): self
    {
        $this->routes["GET:{$path}"] = $handler;
        return $this;
    }
    
    /**
     * 添加 POST 路由
     */
    public function post(string $path, callable $handler): self
    {
        $this->routes["POST:{$path}"] = $handler;
        return $this;
    }
    
    /**
     * 添加 PUT 路由
     */
    public function put(string $path, callable $handler): self
    {
        $this->routes["PUT:{$path}"] = $handler;
        return $this;
    }
    
    /**
     * 添加 DELETE 路由
     */
    public function delete(string $path, callable $handler): self
    {
        $this->routes["DELETE:{$path}"] = $handler;
        return $this;
    }
    
    /**
     * 添加任意方法路由
     */
    public function any(string $path, callable $handler): self
    {
        $this->routes["*:{$path}"] = $handler;
        return $this;
    }
    
    /**
     * 添加中间件
     */
    public function use(callable $middleware): self
    {
        $this->middleware[] = $middleware;
        return $this;
    }
    
    /**
     * 启用静态文件服务
     */
    public function static(string $directory): self
    {
        $this->staticDir = rtrim($directory, '/\\');
        $this->enableStatic = true;
        return $this;
    }
    
    /**
     * 设置 Worker 回调
     */
    public function onWorkerStart(callable $callback): self
    {
        $this->worker->onWorkerStart = $callback;
        return $this;
    }
    
    /**
     * 设置 Worker 停止回调
     */
    public function onWorkerStop(callable $callback): self
    {
        $this->worker->onWorkerStop = $callback;
        return $this;
    }
    
    /**
     * 设置连接回调
     */
    public function onConnect(callable $callback): self
    {
        $this->worker->onConnect = $callback;
        return $this;
    }
    
    /**
     * 设置连接关闭回调
     */
    public function onClose(callable $callback): self
    {
        $this->worker->onClose = $callback;
        return $this;
    }
    
    /**
     * 设置日志文件
     */
    public function setLogFile(string $path): self
    {
        Worker::$logFile = $path;
        return $this;
    }
    
    /**
     * 设置 PID 文件
     */
    public function setPidFile(string $path): self
    {
        Worker::$pidFile = $path;
        return $this;
    }
    
    /**
     * 设置守护进程模式
     */
    public function daemonize(bool $daemonize = true): self
    {
        Worker::$daemonize = $daemonize;
        return $this;
    }
    
    /**
     * 获取 Worker 实例
     */
    public function getWorker(): Worker
    {
        return $this->worker;
    }
    
    /**
     * 启动服务器
     */
    public function start(): void
    {
        Worker::runAll();
    }
}

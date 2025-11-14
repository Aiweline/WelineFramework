<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Api\Service;

use Weline\Framework\App\Env;
use Weline\Framework\Cache\CacheInterface;
use Weline\Framework\Cache\CacheFactory;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Module\Handle;

/**
 * API文档生成服务
 * 
 * 用于扫描API控制器并生成Swagger格式的API文档
 */
class ApiDocService
{
    private CacheInterface $cache;
    private Handle $moduleHandle;
    
    public function __construct()
    {
        // 直接实例化CacheFactory，避免ObjectManager缓存问题
        $cacheFactory = new CacheFactory('api_doc', __('API文档缓存'), false);
        $this->cache = $cacheFactory->create();
        $this->moduleHandle = ObjectManager::getInstance(Handle::class);
    }
    
    /**
     * 生成所有API文档
     * 
     * @param bool $force 是否强制重新生成（忽略缓存）
     * @return array API文档数据
     */
    public function generateAll(bool $force = false): array
    {
        $cacheKey = 'api_doc_all';
        
        // 检查缓存
        if (!$force) {
            $cached = $this->cache->get($cacheKey);
            if ($cached !== false) {
                return $cached;
            }
        }
        
        $apis = [];
        $modules = $this->moduleHandle->getModules();
        
        foreach ($modules as $moduleName => $module) {
            $moduleApis = $this->scanModuleApis($module);
            if (!empty($moduleApis)) {
                $apis[$moduleName] = $moduleApis;
            }
        }
        
        // 缓存结果（开发环境1小时，生产环境24小时）
        $cacheTime = (defined('DEV') && DEV) ? 3600 : 86400;
        $this->cache->set($cacheKey, $apis, $cacheTime);
        
        return $apis;
    }
    
    /**
     * 扫描模块的API控制器
     * 
     * @param array $module 模块信息
     * @return array API列表
     */
    private function scanModuleApis(array $module): array
    {
        $apis = [];
        $modulePath = $module['base_path'] ?? '';
        
        // 扫描多个可能的API目录
        $apiDirs = [
            $modulePath . '/Api',           // 标准API目录
            $modulePath . '/Controller/Api', // Controller下的Api子目录
            $modulePath . '/Controller',     // Controller目录（可能包含API控制器）
        ];
        
        foreach ($apiDirs as $apiDir) {
            if (!is_dir($apiDir)) {
                continue;
            }
            
            // 扫描API目录
            $files = $this->scanDirectory($apiDir);
            
            foreach ($files as $file) {
                $className = $this->getClassNameFromFile($file, $module);
                if (empty($className) || !class_exists($className)) {
                    continue;
                }
                
                try {
                    $reflection = new \ReflectionClass($className);
                    
                    // 检查是否是API控制器（继承AbstractRestController）
                    try {
                        if (!$reflection->isSubclassOf(\Weline\Framework\Controller\AbstractRestController::class)) {
                            continue;
                        }
                    } catch (\Exception $e) {
                        // 如果无法检查继承关系，跳过
                        continue;
                    }
                    
                    // 提取API信息
                    $classApis = $this->extractClassApis($reflection, $module);
                    $apis = array_merge($apis, $classApis);
                    
                } catch (\Exception $e) {
                    // 忽略无法反射的类
                    continue;
                }
            }
        }
        
        return $apis;
    }
    
    /**
     * 扫描目录下的所有PHP文件
     */
    private function scanDirectory(string $dir): array
    {
        $files = [];
        if (!is_dir($dir)) {
            return $files;
        }
        
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS)
        );
        
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }
        
        return $files;
    }
    
    /**
     * 从文件路径获取类名
     */
    private function getClassNameFromFile(string $filePath, array $module): ?string
    {
        $modulePath = $module['base_path'] ?? '';
        $namespacePath = $module['namespace_path'] ?? '';
        
        // 标准化路径分隔符
        $modulePath = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $modulePath);
        $filePath = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $filePath);
        
        // 计算相对路径
        $relativePath = str_replace($modulePath . DIRECTORY_SEPARATOR, '', $filePath);
        $relativePath = str_replace('.php', '', $relativePath);
        
        // 转换为命名空间（将路径分隔符转换为命名空间分隔符）
        $namespace = $namespacePath . '\\' . str_replace(DIRECTORY_SEPARATOR, '\\', $relativePath);
        
        return $namespace;
    }
    
    /**
     * 提取类的API信息
     */
    private function extractClassApis(\ReflectionClass $reflection, array $module): array
    {
        $apis = [];
        $methods = $reflection->getMethods(\ReflectionMethod::IS_PUBLIC);
        
        foreach ($methods as $method) {
            // 跳过魔术方法和构造函数
            if ($method->isConstructor() || $method->isDestructor() || str_starts_with($method->getName(), '__')) {
                continue;
            }
            
            // 提取方法API信息
            $apiInfo = $this->extractMethodApi($reflection, $method, $module);
            if ($apiInfo) {
                $apis[] = $apiInfo;
            }
        }
        
        return $apis;
    }
    
    /**
     * 提取方法的API信息
     */
    private function extractMethodApi(\ReflectionClass $reflection, \ReflectionMethod $method, array $module): ?array
    {
        $docComment = $method->getDocComment();
        if (empty($docComment)) {
            return null;
        }
        
        // 提取@Document信息
        $document = $this->parseDocumentTag($docComment);
        if (empty($document)) {
            return null;
        }
        
        // 提取版本号（从命名空间路径）
        $version = $this->extractVersionFromNamespace($reflection->getName());
        
        // 提取路由信息
        $route = $this->extractRoute($reflection, $method, $module);
        
        // 提取参数信息
        $parameters = $this->extractParameters($method, $docComment);
        
        // 提取响应信息
        $responses = $this->extractResponses($docComment);
        
        // 提取示例信息
        $example = $this->parseExampleTag($docComment);
        
        // 提取Acl信息
        $acl = $this->extractAclInfo($reflection, $method);
        
        return [
            'module' => $module['name'] ?? '',
            'version' => $version,
            'class' => $reflection->getName(),
            'method' => $method->getName(),
            'route' => $route,
            'document' => $document,
            'parameters' => $parameters,
            'responses' => $responses,
            'example' => $example,
            'acl' => $acl,
        ];
    }
    
    /**
     * 解析@Document标签
     */
    private function parseDocumentTag(string $docComment): ?array
    {
        if (!preg_match('/@Document\s*\((.*?)\)/is', $docComment, $matches)) {
            return null;
        }
        
        $content = $matches[1] ?? '';
        $document = [];
        
        // 解析summary
        if (preg_match('/summary\s*[:=]\s*["\']([^"\']+)["\']/', $content, $m)) {
            $document['summary'] = $m[1] ?? '';
        }
        
        // 解析description
        if (preg_match('/description\s*[:=]\s*["\']([^"\']+)["\']/', $content, $m)) {
            $document['description'] = $m[1] ?? '';
        }
        
        // 解析tags
        if (preg_match('/tags\s*[:=]\s*\[(.*?)\]/', $content, $m)) {
            $tagsStr = $m[1] ?? '';
            $tags = [];
            if (preg_match_all('/["\']([^"\']+)["\']/', $tagsStr, $tagMatches)) {
                $tags = $tagMatches[1] ?? [];
            }
            $document['tags'] = $tags;
        }
        
        // 解析category
        if (preg_match('/category\s*[:=]\s*["\']([^"\']+)["\']/', $content, $m)) {
            $document['category'] = $m[1] ?? '';
        }
        
        // 解析deprecated
        if (preg_match('/deprecated\s*[:=]\s*(true|false)/i', $content, $m)) {
            $document['deprecated'] = strtolower($m[1] ?? 'false') === 'true';
        }
        
        // 解析deprecatedReason
        if (preg_match('/deprecatedReason\s*[:=]\s*["\']([^"\']+)["\']/', $content, $m)) {
            $document['deprecatedReason'] = $m[1] ?? '';
        }
        
        return $document;
    }
    
    /**
     * 从命名空间提取版本号
     */
    private function extractVersionFromNamespace(string $className): string
    {
        if (preg_match('/\\\V(\d+)\\\/', $className, $matches)) {
            return 'v' . ($matches[1] ?? '1');
        }
        return 'v1'; // 默认版本
    }
    
    /**
     * 提取路由信息
     */
    private function extractRoute(\ReflectionClass $reflection, \ReflectionMethod $method, array $module): array
    {
        // 从方法名推断HTTP方法
        $httpMethod = 'GET';
        $methodName = strtolower($method->getName());
        if (str_starts_with($methodName, 'post')) {
            $httpMethod = 'POST';
        } elseif (str_starts_with($methodName, 'put')) {
            $httpMethod = 'PUT';
        } elseif (str_starts_with($methodName, 'delete')) {
            $httpMethod = 'DELETE';
        } elseif (str_starts_with($methodName, 'patch')) {
            $httpMethod = 'PATCH';
        }
        
        // 构建路由路径（简化版，实际应该从路由注册表获取）
        $version = $this->extractVersionFromNamespace($reflection->getName());
        $className = $reflection->getShortName();
        $methodName = $method->getName();
        
        // 转换为kebab-case
        $path = strtolower(preg_replace('/([A-Z])/', '-$1', $className));
        $path = trim($path, '-');
        
        return [
            'method' => $httpMethod,
            'path' => "/api/{$version}/{$path}/{$methodName}",
        ];
    }
    
    /**
     * 提取参数信息
     */
    private function extractParameters(\ReflectionMethod $method, string $docComment): array
    {
        $parameters = [];
        $params = $method->getParameters();
        
        foreach ($params as $param) {
            $paramInfo = [
                'name' => $param->getName(),
                'type' => $param->getType() ? $param->getType()->getName() : 'mixed',
                'required' => !$param->isOptional(),
            ];
            
            // 从PHPDoc提取参数描述
            if (preg_match('/@param\s+([^\s]+)\s+\$' . preg_quote($param->getName(), '/') . '\s+(.*)/', $docComment, $m)) {
                $paramInfo['description'] = trim($m[2] ?? '');
            }
            
            // 获取默认值
            if ($param->isDefaultValueAvailable()) {
                $paramInfo['default'] = $param->getDefaultValue();
            }
            
            $parameters[] = $paramInfo;
        }
        
        return $parameters;
    }
    
    /**
     * 提取响应信息
     */
    private function extractResponses(string $docComment): array
    {
        $responses = [];
        
        // 从@return提取响应信息
        if (preg_match('/@return\s+([^\s]+)\s+(.*)/', $docComment, $m)) {
            $responses['200'] = [
                'description' => trim($m[2] ?? '成功'),
                'type' => trim($m[1] ?? 'mixed'),
            ];
        }
        
        return $responses;
    }
    
    /**
     * 解析@example标签
     */
    private function parseExampleTag(string $docComment): ?array
    {
        if (!preg_match('/@example\s*(.*?)@example-end/is', $docComment, $matches)) {
            return null;
        }
        
        $content = $matches[1] ?? '';
        $example = [];
        
        // 解析Method
        if (preg_match('/Method:\s*(\w+)/i', $content, $m)) {
            $example['method'] = strtoupper(trim($m[1] ?? ''));
        }
        
        // 解析Path
        if (preg_match('/Path:\s*([^\n]+)/i', $content, $m)) {
            $example['path'] = trim($m[1] ?? '');
        }
        
        // 解析Header
        if (preg_match('/Header:\s*([\s\S]*?)(?=Cookie:|Body:|Response:|$)/i', $content, $m)) {
            $headers = [];
            $headerLines = explode("\n", $m[1] ?? '');
            foreach ($headerLines as $line) {
                $line = trim($line);
                if (preg_match('/^-\s*([^:]+):\s*(.+)$/', $line, $h)) {
                    $headers[trim($h[1] ?? '')] = trim($h[2] ?? '');
                }
            }
            $example['headers'] = $headers;
        }
        
        // 解析Cookie
        if (preg_match('/Cookie:\s*([\s\S]*?)(?=Body:|Response:|$)/i', $content, $m)) {
            $cookies = [];
            $cookieLines = explode("\n", $m[1] ?? '');
            foreach ($cookieLines as $line) {
                $line = trim($line);
                if (preg_match('/^-\s*([^:]+):\s*(.+)$/', $line, $c)) {
                    $cookies[trim($c[1] ?? '')] = trim($c[2] ?? '');
                }
            }
            $example['cookies'] = $cookies;
        }
        
        // 解析Body
        if (preg_match('/Body:\s*([\s\S]*?)(?=Response:|$)/i', $content, $m)) {
            $body = trim($m[1] ?? '');
            // 去掉每行开头的星号和空格（PHPDoc注释格式）
            $body = preg_replace('/^\s*\*\s*/m', '', $body);
            $body = trim($body);
            $example['body'] = json_decode($body ?? '', true) ?: $body;
        }
        
        // 解析Response
        if (preg_match('/Response:\s*([\s\S]*?)$/i', $content, $m)) {
            $response = trim($m[1] ?? '');
            // 去掉每行开头的星号和空格（PHPDoc注释格式）
            $response = preg_replace('/^\s*\*\s*/m', '', $response);
            $response = trim($response);
            $example['response'] = json_decode($response ?? '', true) ?: $response;
        }
        
        return $example;
    }
    
    /**
     * 提取Acl信息
     */
    private function extractAclInfo(\ReflectionClass $reflection, \ReflectionMethod $method): ?array
    {
        $acl = [];
        
        // 类级别Acl
        $classAclAttributes = $reflection->getAttributes(\Weline\Framework\Acl\Acl::class);
        if (!empty($classAclAttributes)) {
            $classAcl = $classAclAttributes[0]->newInstance();
            $acl['class'] = [
                'source_id' => $classAcl->source_id ?? '',
                'source_name' => $classAcl->source_name ?? '',
            ];
        }
        
        // 方法级别Acl
        $methodAclAttributes = $method->getAttributes(\Weline\Framework\Acl\Acl::class);
        if (!empty($methodAclAttributes)) {
            $methodAcl = $methodAclAttributes[0]->newInstance();
            $acl['method'] = [
                'source_id' => $methodAcl->source_id ?? '',
                'source_name' => $methodAcl->source_name ?? '',
            ];
        }
        
        return !empty($acl) ? $acl : null;
    }
}


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
use Weline\Framework\Cache\Contract\CachePoolInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Module\Handle;
use Weline\Framework\Service\Query\QueryProviderRegistry;

/**
 * API文档生成服务
 * 
 * 用于扫描API控制器并生成Swagger格式的API文档
 */
class ApiDocService
{
    private CachePoolInterface $cache;
    private Handle $moduleHandle;
    private QueryProviderRegistry $queryProviderRegistry;
    
    public function __construct()
    {
        $this->cache = w_cache('api_doc');
        $this->moduleHandle = ObjectManager::getInstance(Handle::class);
        $this->queryProviderRegistry = ObjectManager::getInstance(QueryProviderRegistry::class);
    }

    private function getReflectionTypeName(?\ReflectionType $type): string
    {
        if ($type === null) {
            return 'mixed';
        }

        if ($type instanceof \ReflectionNamedType) {
            return $type->getName();
        }

        if ($type instanceof \ReflectionUnionType) {
            $names = [];
            foreach ($type->getTypes() as $childType) {
                $names[] = $this->getReflectionTypeName($childType);
            }
            return implode('|', $names);
        }

        if ($type instanceof \ReflectionIntersectionType) {
            $names = [];
            foreach ($type->getTypes() as $childType) {
                $names[] = $this->getReflectionTypeName($childType);
            }
            return implode('&', $names);
        }

        return (string)$type;
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
            if (is_array($cached)) {
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

        $frontendWorkerApis = $this->generateFrontendWorkerApis();
        if (!empty($frontendWorkerApis)) {
            $apis['Frontend Worker API'] = $frontendWorkerApis;
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
     * Build the REST controller path from namespace segments after the version.
     *
     * Route registration keeps nested API namespaces in the URL, for example
     * Api\Rest\V1\Backend\Auth -> backend/auth. The docs must mirror that
     * shape so generated examples resolve to registered routes.
     */
    private function extractControllerPathFromNamespace(string $className, string $version): string
    {
        $segments = explode('\\', trim($className, '\\'));
        $versionSegment = strtolower($version);
        $versionIndex = null;

        foreach ($segments as $index => $segment) {
            if (strtolower($segment) === $versionSegment && strtolower((string)($segments[$index - 1] ?? '')) === 'rest') {
                $versionIndex = $index;
                break;
            }
        }

        $controllerSegments = $versionIndex === null
            ? [end($segments) ?: $className]
            : array_slice($segments, $versionIndex + 1);

        if ($controllerSegments === []) {
            $controllerSegments = [end($segments) ?: $className];
        }

        return implode('/', array_map([$this, 'toRoutePathSegment'], $controllerSegments));
    }

    private function toRoutePathSegment(string $segment): string
    {
        $segment = preg_replace('/([a-z0-9])([A-Z])/', '$1-$2', $segment) ?? $segment;
        $segment = preg_replace('/([A-Z]+)([A-Z][a-z])/', '$1-$2', $segment) ?? $segment;

        return strtolower(trim($segment, '-'));
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
        
        // 判断是前端API还是后端API
        $isBackendApi = $reflection->isSubclassOf(\Weline\Framework\App\Controller\BackendRestController::class);
        
        // 获取模块路由前缀
        $moduleRouter = $module['router'] ?? strtolower($module['name'] ?? '');
        if (empty($moduleRouter)) {
            // 如果模块没有配置router，使用模块名（转换为小写，下划线转横线）
            $moduleRouter = str_replace('_', '-', strtolower($module['name'] ?? ''));
        }
        
        // 构建路由路径
        $version = $this->extractVersionFromNamespace($reflection->getName());
        $methodName = $method->getName();
        $controllerPath = $this->extractControllerPathFromNamespace($reflection->getName(), $version);
        
        // 转换为kebab-case（方法名）
        $methodPath = $this->toRoutePathSegment($methodName);
        // 移除HTTP方法前缀（如 get-, post-, put-, delete-）
        $methodPath = preg_replace('/^(get|post|put|delete|patch)-/', '', $methodPath);
        $methodPath = trim($methodPath, '-');
        
        // 构建完整路径
        // 前端API: {模块router}/rest/v1/{控制器名}/{方法名}
        // 后端API: {api_admin}/{模块router}/rest/v1/{控制器名}/{方法名}
        if ($isBackendApi) {
            // 后端API需要包含 api_admin 前缀（但这里只存储相对路径，前端会根据当前页面类型添加前缀）
            $path = "{$moduleRouter}/rest/{$version}/{$controllerPath}/{$methodPath}";
        } else {
            // 前端API
            $path = "{$moduleRouter}/rest/{$version}/{$controllerPath}/{$methodPath}";
        }
        
        return [
            'method' => $httpMethod,
            'path' => '/' . ltrim($path, '/'),
            'is_backend' => $isBackendApi,
        ];
    }
    
    /**
     * 提取参数信息
     * 
     * 从方法签名和@param注释中提取完整的参数信息，包括：
     * - 参数名称、类型、是否必填
     * - 参数描述（从@param注释中提取）
     * - 参数获取方式（从@param注释中解析，如"通过POST参数获取"）
     * - 默认值（从方法签名中提取）
     */
    private function extractParameters(\ReflectionMethod $method, string $docComment): array
    {
        $parameters = [];
        $params = $method->getParameters();
        
        // 首先提取所有@param注释（包括通过request获取的参数）
        $paramComments = $this->extractAllParamComments($docComment);
        
        // 处理方法签名中的参数
        foreach ($params as $param) {
            $paramName = $param->getName();
            $paramInfo = [
                'name' => $paramName,
                'type' => $this->getReflectionTypeName($param->getType()),
                'required' => !$param->isOptional(),
                'source' => 'method_signature', // 参数来源：方法签名
            ];
            
            // 从PHPDoc提取参数描述
            if (isset($paramComments[$paramName])) {
                $comment = $paramComments[$paramName];
                $paramInfo['description'] = $comment['description'] ?? '';
                $paramInfo['required'] = $comment['required'] ?? $paramInfo['required'];
                $paramInfo['source'] = $comment['source'] ?? $paramInfo['source'];
                $paramInfo['source_description'] = $comment['source_description'] ?? '';
            } elseif (preg_match('/@param\s+([^\s]+)\s+\$' . preg_quote($paramName, '/') . '\s+(.*)/', $docComment, $m)) {
                // 兼容旧格式：直接解析@param注释
                $description = trim($m[2] ?? '');
                $paramInfo['description'] = $description;
                // 从描述中解析是否必填
                $paramInfo['required'] = $this->parseRequiredFromDescription($description);
                // 从描述中解析获取方式
                $paramInfo['source'] = $this->parseSourceFromDescription($description);
                $paramInfo['source_description'] = $this->parseSourceDescription($description);
            }
            
            // 获取默认值
            if ($param->isDefaultValueAvailable()) {
                $defaultValue = $param->getDefaultValue();
                $paramInfo['default'] = $defaultValue;
                // 如果有默认值，通常不是必填的
                if (!isset($paramInfo['required']) || $paramInfo['required'] === null) {
                    $paramInfo['required'] = false;
                }
            }
            
            $parameters[] = $paramInfo;
        }
        
        // 处理通过request获取的参数（在@param注释中但不在方法签名中）
        foreach ($paramComments as $paramName => $comment) {
            // 检查是否已经在方法签名参数中
            $exists = false;
            foreach ($params as $param) {
                if ($param->getName() === $paramName) {
                    $exists = true;
                    break;
                }
            }
            
            // 如果不在方法签名中，说明是通过request获取的参数
            if (!$exists) {
                $paramInfo = [
                    'name' => $paramName,
                    'type' => $comment['type'] ?? 'mixed',
                    'required' => $comment['required'] ?? false,
                    'description' => $comment['description'] ?? '',
                    'source' => $comment['source'] ?? 'request',
                    'source_description' => $comment['source_description'] ?? '',
                ];
                
                // 如果有默认值说明
                if (isset($comment['default'])) {
                    $paramInfo['default'] = $comment['default'];
                }
                
                $parameters[] = $paramInfo;
            }
        }
        
        return $parameters;
    }
    
    /**
     * 提取所有@param注释
     * 
     * 解析所有@param注释，包括方法签名参数和通过request获取的参数
     */
    private function extractAllParamComments(string $docComment): array
    {
        $paramComments = [];
        
        // 匹配所有@param注释
        if (preg_match_all('/@param\s+([^\s]+)\s+\$([^\s]+)\s+(.*)/', $docComment, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $type = trim($match[1] ?? '');
                $name = trim($match[2] ?? '');
                $description = trim($match[3] ?? '');
                
                $paramComments[$name] = [
                    'type' => $type,
                    'description' => $description,
                    'required' => $this->parseRequiredFromDescription($description),
                    'source' => $this->parseSourceFromDescription($description),
                    'source_description' => $this->parseSourceDescription($description),
                ];
                
                // 解析默认值
                if (preg_match('/默认["\']?([^"\'）)]+)/', $description, $defaultMatch)) {
                    $defaultValue = trim($defaultMatch[1] ?? '');
                    // 尝试转换类型
                    if (is_numeric($defaultValue)) {
                        $paramComments[$name]['default'] = strpos($defaultValue, '.') !== false ? (float)$defaultValue : (int)$defaultValue;
                    } elseif ($defaultValue === 'true' || $defaultValue === 'false') {
                        $paramComments[$name]['default'] = $defaultValue === 'true';
                    } elseif ($defaultValue === '[]' || $defaultValue === 'array()') {
                        $paramComments[$name]['default'] = [];
                    } else {
                        $paramComments[$name]['default'] = $defaultValue;
                    }
                }
            }
        }
        
        return $paramComments;
    }
    
    /**
     * 从描述中解析是否必填
     */
    private function parseRequiredFromDescription(string $description): bool
    {
        // 检查是否包含"必填"、"required"等关键词
        if (preg_match('/（必填|必填|required|必须|必需/i', $description)) {
            return true;
        }
        // 检查是否包含"可选"、"optional"等关键词
        if (preg_match('/（可选|可选|optional|可选的/i', $description)) {
            return false;
        }
        // 默认返回null，让调用者根据其他信息判断
        return null;
    }
    
    /**
     * 从描述中解析参数获取方式
     */
    private function parseSourceFromDescription(string $description): string
    {
        // 检查获取方式关键词
        if (preg_match('/通过POST参数获取|通过post参数获取|POST参数|post参数/i', $description)) {
            return 'POST';
        }
        if (preg_match('/通过GET参数获取|通过get参数获取|GET参数|get参数/i', $description)) {
            return 'GET';
        }
        if (preg_match('/通过URL参数获取|通过url参数获取|URL参数|url参数/i', $description)) {
            return 'URL';
        }
        if (preg_match('/通过请求头获取|通过Header获取|通过header获取|请求头|Header|header/i', $description)) {
            return 'HEADER';
        }
        if (preg_match('/通过Body参数获取|通过body参数获取|Body参数|body参数/i', $description)) {
            return 'BODY';
        }
        if (preg_match('/通过方法签名参数获取|方法签名参数|方法参数/i', $description)) {
            return 'method_signature';
        }
        if (preg_match('/通过Authorization头|Authorization|Bearer/i', $description)) {
            return 'AUTH_BEARER';
        }
        if (preg_match('/通过X-API-Token头|X-API-Token/i', $description)) {
            return 'HEADER_X_API_TOKEN';
        }
        
        // 默认返回request，表示通过request获取
        return 'request';
    }
    
    /**
     * 从描述中解析参数获取方式的详细说明
     * 
     * 只提取额外的详细说明，避免与source重复
     */
    private function parseSourceDescription(string $description): string
    {
        // 提取获取方式的详细说明（只提取额外的信息，如多个获取方式）
        // 例如："通过Authorization头、X-API-Token头、URL参数或POST参数获取"
        if (preg_match('/通过([^，,）)]+)(参数|头|获取)/', $description, $matches)) {
            $extracted = trim($matches[1] ?? '');
            // 如果提取的内容包含多个获取方式，返回完整描述
            if (strpos($description, '、') !== false || strpos($description, '或') !== false) {
                // 提取完整的获取方式描述
                if (preg_match('/通过([^）)]+)(获取|参数|头)/', $description, $fullMatches)) {
                    return trim($fullMatches[1] ?? '');
                }
            }
            // 如果只是单个获取方式，返回空（避免与source重复）
            return '';
        }
        
        return '';
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
        if (preg_match('/Header:\s*([\s\S]*?)(?=Cookie:|Request Parameters:|Body:|Response:|$)/i', $content, $m)) {
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
        
        // 解析Request Parameters（GET请求的URL参数）
        if (preg_match('/Request Parameters:\s*([\s\S]*?)(?=Cookie:|Body:|Response:|$)/i', $content, $m)) {
            $requestParams = [];
            $paramLines = explode("\n", $m[1] ?? '');
            foreach ($paramLines as $line) {
                $line = trim($line);
                // 匹配格式：- param_name: value (description)
                if (preg_match('/^-\s*([^:]+):\s*(.+?)(?:\s*\([^)]*\))?\s*$/', $line, $p)) {
                    $paramName = trim($p[1] ?? '');
                    $paramValue = trim($p[2] ?? '');
                    // 移除可能的描述部分
                    $paramValue = preg_replace('/\s*\([^)]*\)\s*$/', '', $paramValue);
                    $requestParams[$paramName] = $paramValue;
                }
            }
            if (!empty($requestParams)) {
                $example['request_parameters'] = $requestParams;
            }
        }
        
        // 解析Cookie
        if (preg_match('/Cookie:\s*([\s\S]*?)(?=Request Parameters:|Body:|Response:|$)/i', $content, $m)) {
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
        if (preg_match('/Body:\s*([\s\S]*?)(?=Request Parameters:|Response:|$)/i', $content, $m)) {
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

    /**
     * @return array<int, array<string, mixed>>
     */
    private function generateFrontendWorkerApis(): array
    {
        $apis = [];
        foreach ($this->queryProviderRegistry->getAllDescriptors() as $providerDescriptor) {
            $provider = (string)($providerDescriptor['provider'] ?? '');
            if ($provider === '') {
                continue;
            }

            foreach (($providerDescriptor['operations'] ?? []) as $operationDescriptor) {
                if (!\is_array($operationDescriptor) || ($operationDescriptor['frontend'] ?? false) !== true) {
                    continue;
                }

                $operation = (string)($operationDescriptor['name'] ?? '');
                if ($operation === '') {
                    continue;
                }

                $apis[] = [
                    'module' => (string)($providerDescriptor['module'] ?? 'Frontend Worker API'),
                    'version' => 'worker-v1',
                    'class' => 'FrontendWorker\\' . $provider,
                    'method' => $provider . '.' . $operation,
                    'route' => [
                        'method' => 'WORKER',
                        'path' => 'Weline.Api.resource("' . $provider . '").' . $operation . '(params)',
                        'is_backend' => false,
                        'browser_direct' => false,
                        'implementation' => '/api/framework/query-bin',
                    ],
                    'document' => [
                        'summary' => (string)($operationDescriptor['summary'] ?? $operationDescriptor['description'] ?? ($provider . '.' . $operation)),
                        'description' => (string)($operationDescriptor['description'] ?? ''),
                        'tags' => ['Frontend Worker API', $provider],
                        'category' => 'Frontend Worker API',
                        'deprecated' => false,
                    ],
                    'parameters' => $this->normalizeFrontendWorkerParameters($operationDescriptor['params'] ?? []),
                    'responses' => [
                        '200' => [
                            'description' => 'Worker returns a Weline binary response decoded by Weline.Api.',
                            'type' => (string)($operationDescriptor['returns']['type'] ?? 'mixed'),
                        ],
                    ],
                    'example' => [
                        'frontend_worker' => true,
                        'provider' => $provider,
                        'operation' => $operation,
                        'mode' => (string)($operationDescriptor['mode'] ?? ''),
                        'graph' => (bool)($operationDescriptor['graph'] ?? false),
                        'cost' => (int)($operationDescriptor['cost'] ?? 1),
                        'cache_ttl' => (int)($operationDescriptor['cache_ttl'] ?? 0),
                        'auth' => (string)($operationDescriptor['auth'] ?? ''),
                        'sample_params' => $this->buildFrontendWorkerSampleParams($operationDescriptor['params'] ?? [], [
                            'provider' => $provider,
                            'operation' => $operation,
                            'module' => (string)($providerDescriptor['module'] ?? ''),
                        ]),
                        'code' => $this->buildFrontendWorkerExampleCode($provider, $operation, $operationDescriptor['params'] ?? [], [
                            'provider' => $provider,
                            'operation' => $operation,
                            'module' => (string)($providerDescriptor['module'] ?? ''),
                        ]),
                    ],
                    'frontend_worker' => true,
                    'worker' => [
                        'provider' => $provider,
                        'operation' => $operation,
                        'mode' => (string)($operationDescriptor['mode'] ?? ''),
                        'graph' => (bool)($operationDescriptor['graph'] ?? false),
                        'cost' => (int)($operationDescriptor['cost'] ?? 1),
                        'cache_ttl' => (int)($operationDescriptor['cache_ttl'] ?? 0),
                        'auth' => (string)($operationDescriptor['auth'] ?? ''),
                    ],
                ];
            }
        }

        return $apis;
    }

    /**
     * @param mixed $paramsDescriptor
     * @return array<int, array<string, mixed>>
     */
    private function buildFrontendWorkerExampleCode(string $provider, string $operation, mixed $paramsDescriptor, array $sampleContext = []): string
    {
        $sampleParams = $this->buildFrontendWorkerSampleParams($paramsDescriptor, $sampleContext ?: [
            'provider' => $provider,
            'operation' => $operation,
        ]);
        $jsonFlags = \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_PRETTY_PRINT;
        $payload = \json_encode($sampleParams, $jsonFlags);
        if (!\is_string($payload) || $payload === '[]') {
            $payload = '{}';
        }

        $providerLiteral = \json_encode($provider, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);
        $operationAccessor = \preg_match('/^[A-Za-z_$][A-Za-z0-9_$]*$/', $operation) === 1
            ? '.' . $operation
            : '[' . \json_encode($operation, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE) . ']';

        return "const Api = await Weline.Api.resource({$providerLiteral});\nawait Api{$operationAccessor}({$payload});";
    }

    /**
     * @param mixed $paramsDescriptor
     * @return array<string, mixed>
     */
    private function buildFrontendWorkerSampleParams(mixed $paramsDescriptor, array $sampleContext = []): array
    {
        if (!\is_array($paramsDescriptor)) {
            return [];
        }

        $params = [];
        foreach ($paramsDescriptor as $key => $rule) {
            if (!\is_array($rule)) {
                continue;
            }
            $name = \is_string($key) ? $key : (string)($rule['name'] ?? '');
            if ($name === '') {
                continue;
            }
            $params[$name] = $this->sampleFrontendWorkerParamValue($name, $rule, $sampleContext);
        }

        return $params;
    }

    /**
     * @param array<string, mixed> $rule
     */
    private function sampleFrontendWorkerParamValue(string $name, array $rule, array $sampleContext = []): mixed
    {
        if (\array_key_exists('example', $rule)) {
            return $rule['example'];
        }
        if (\array_key_exists('default', $rule)) {
            return $rule['default'];
        }

        $lowerName = \strtolower($name);
        if ($lowerName === 'provider') {
            return (string)($sampleContext['provider'] ?? 'query_help');
        }
        if ($lowerName === 'operation') {
            return (string)($sampleContext['operation'] ?? 'providers');
        }
        if ($lowerName === 'module') {
            $module = (string)($sampleContext['module'] ?? '');
            return ($module !== '' && $module !== 'Frontend Worker API') ? $module : 'Weline_Framework';
        }
        if (\str_contains($lowerName, 'email')) {
            return 'customer@example.com';
        }
        if (\str_contains($lowerName, 'password')) {
            return 'password123';
        }
        if ($lowerName === 'username' || $lowerName === 'login') {
            return 'customer@example.com';
        }
        if ($lowerName === 'firstname' || $lowerName === 'first_name') {
            return 'Jane';
        }
        if ($lowerName === 'lastname' || $lowerName === 'last_name') {
            return 'Doe';
        }
        if ($lowerName === 'agree_terms') {
            return true;
        }
        if ($lowerName === 'remember_duration') {
            return 2592000;
        }
        if (\str_contains($lowerName, 'challenge_token')) {
            return 'challenge-token';
        }
        if ($lowerName === 'token' || \str_ends_with($lowerName, '_token')) {
            return 'token-value';
        }
        if ($lowerName === 'code') {
            return '123456';
        }

        $type = \strtolower((string)($rule['type'] ?? 'mixed'));
        return match ($type) {
            'int', 'integer' => isset($rule['min']) ? (int)$rule['min'] : 1,
            'float', 'double', 'number' => isset($rule['min']) ? (float)$rule['min'] : 1.0,
            'bool', 'boolean' => true,
            'list' => [],
            'map', 'object' => ['key' => 'value'],
            'array' => [],
            default => 'value',
        };
    }

    /**
     * @param mixed $paramsDescriptor
     * @return array<int, array<string, mixed>>
     */
    private function normalizeFrontendWorkerParameters(mixed $paramsDescriptor): array
    {
        if (!\is_array($paramsDescriptor)) {
            return [];
        }

        $params = [];
        foreach ($paramsDescriptor as $key => $rule) {
            if (!\is_array($rule)) {
                continue;
            }
            $name = \is_string($key) ? $key : (string)($rule['name'] ?? '');
            if ($name === '') {
                continue;
            }

            $descriptionParts = [];
            if (isset($rule['min'])) {
                $descriptionParts[] = 'min=' . (string)$rule['min'];
            }
            if (isset($rule['max'])) {
                $descriptionParts[] = 'max=' . (string)$rule['max'];
            }
            if (isset($rule['max_items'])) {
                $descriptionParts[] = 'max_items=' . (string)$rule['max_items'];
            }
            if (isset($rule['max_length'])) {
                $descriptionParts[] = 'max_length=' . (string)$rule['max_length'];
            }

            $params[] = [
                'name' => $name,
                'type' => (string)($rule['type'] ?? 'mixed'),
                'required' => (bool)($rule['required'] ?? false),
                'description' => (string)($rule['description'] ?? \implode(', ', $descriptionParts)),
                'source' => 'worker_params',
            ];
        }

        return $params;
    }
}


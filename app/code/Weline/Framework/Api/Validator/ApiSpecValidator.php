<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Framework\Api\Validator;

use Weline\Framework\App\Exception;

/**
 * API规范验证器
 * 
 * 用于验证API接口是否符合开发规范
 */
class ApiSpecValidator
{
    /**
     * 验证API控制器方法是否符合规范
     * 
     * @param string $className 控制器类名
     * @param string $methodName 方法名
     * @param bool $isBackend 是否为后台API
     * @return array 验证结果 ['valid' => bool, 'errors' => array]
     * @throws Exception 如果不符合规范且为严格模式，抛出异常
     */
    public function validateMethod(string $className, string $methodName, bool $isBackend = false): array
    {
        $errors = [];
        
        try {
            $reflection = new \ReflectionClass($className);
            if (!$reflection->hasMethod($methodName)) {
                $errors[] = "方法 {$methodName} 不存在";
                return ['valid' => false, 'errors' => $errors];
            }
            
            $method = $reflection->getMethod($methodName);
            
            // 1. 检查PHPDoc注释
            $docComment = $method->getDocComment();
            if (empty($docComment)) {
                $errors[] = "方法 {$methodName} 缺少PHPDoc注释";
            } else {
                // 2. 检查@Document注释
                if (!$this->hasDocumentTag($docComment)) {
                    $errors[] = "方法 {$methodName} 缺少 @Document 注释";
                } else {
                    // 验证@Document注释格式
                    $documentErrors = $this->validateDocumentTag($docComment, $className, $methodName);
                    $errors = array_merge($errors, $documentErrors);
                }
                
                // 3. 检查@param注释
                $paramErrors = $this->validateParamTags($method, $docComment);
                $errors = array_merge($errors, $paramErrors);
                
                // 4. 检查@return注释
                if (!$this->hasReturnTag($docComment)) {
                    $errors[] = "【严重错误】方法 {$methodName} 缺少 @return 注释。必须包含返回数据格式说明，例如：@return array 返回数据格式：{\"code\": 0, \"msg\": \"success\", \"data\": {}}";
                } else {
                    // 验证@return注释是否包含返回数据格式
                    $returnErrors = $this->validateReturnTag($docComment, $methodName);
                    $errors = array_merge($errors, $returnErrors);
                }
                
                // 5. 检查@throws注释（推荐但非必填）
                // 不强制要求，但建议提供
                
                // 6. 检查@example注释（必填）
                if (!$this->hasExampleTag($docComment)) {
                    $errors[] = "【严重错误】方法 {$methodName} 缺少 @example 注释。必须包含完整的请求和响应示例，参考 Test.php 的注释格式。";
                } else {
                    // 验证@example注释格式
                    $exampleErrors = $this->validateExampleTag($docComment, $methodName);
                    $errors = array_merge($errors, $exampleErrors);
                }
            }
            
            // 7. 检查后台API是否有Acl注解
            if ($isBackend) {
                $aclErrors = $this->validateAclAttribute($reflection, $method);
                $errors = array_merge($errors, $aclErrors);
            }
            
            // 8. 检查需要登录的API是否明确说明了所有请求参数（严重错误）
            if (!empty($docComment)) {
                $requestParamErrors = $this->validateRequestParameters($reflection, $method, $docComment);
                $errors = array_merge($errors, $requestParamErrors);
            }
            
        } catch (\Exception $e) {
            $errors[] = "验证方法 {$methodName} 时出错: " . $e->getMessage();
        }
        
        return [
            'valid' => empty($errors),
            'errors' => $errors
        ];
    }
    
    /**
     * 检查是否有@Document标签
     */
    private function hasDocumentTag(string $docComment): bool
    {
        return preg_match('/@Document\s*\(/i', $docComment) === 1;
    }
    
    /**
     * 验证@Document标签格式
     */
    private function validateDocumentTag(string $docComment, string $className, string $methodName): array
    {
        $errors = [];
        
        // 提取@Document内容
        if (preg_match('/@Document\s*\((.*?)\)/is', $docComment, $matches)) {
            $content = $matches[1] ?? '';
            
            // 检查summary参数
            if (!preg_match('/summary\s*[:=]\s*["\']([^"\']+)["\']/', $content, $summaryMatch)) {
                $errors[] = "方法 {$methodName} 的 @Document 注释缺少 summary 参数";
            } else {
                $summary = $summaryMatch[1] ?? '';
                if (empty(trim($summary))) {
                    $errors[] = "方法 {$methodName} 的 @Document 注释的 summary 参数不能为空";
                } elseif (strlen($summary) > 200) {
                    $errors[] = "方法 {$methodName} 的 @Document 注释的 summary 参数长度不能超过200字符";
                }
            }
            
            // 检查deprecated参数
            if (preg_match('/deprecated\s*[:=]\s*true/i', $content)) {
                // 如果deprecated=true，必须提供deprecatedReason
                if (!preg_match('/deprecatedReason\s*[:=]\s*["\']([^"\']+)["\']/', $content)) {
                    $errors[] = "方法 {$methodName} 的 @Document 注释中 deprecated=true 时必须提供 deprecatedReason 参数";
                }
            }
        } else {
            $errors[] = "方法 {$methodName} 的 @Document 注释格式不正确";
        }
        
        return $errors;
    }
    
    /**
     * 验证@param标签
     */
    private function validateParamTags(\ReflectionMethod $method, string $docComment): array
    {
        $errors = [];
        
        $parameters = $method->getParameters();
        foreach ($parameters as $param) {
            $paramName = $param->getName();
            // 检查是否有对应的@param注释
            if (!preg_match('/@param\s+[^\s]+\s+\$' . preg_quote($paramName, '/') . '/', $docComment)) {
                $errors[] = "【严重错误】方法 {$method->getName()} 的参数 \${$paramName} 缺少 @param 注释。必须包含参数类型、名称和描述，例如：@param string \$title 测试标题（必填）";
            } else {
                // 验证@param注释是否包含完整的描述信息
                if (preg_match('/@param\s+([^\s]+)\s+\$' . preg_quote($paramName, '/') . '\s+(.+)/', $docComment, $matches)) {
                    $paramDescription = $matches[2] ?? '';
                    // 检查描述是否包含是否必填的说明
                    if (!preg_match('/（必填|（可选|必填|可选|required|optional/i', $paramDescription)) {
                        $errors[] = "【严重错误】方法 {$method->getName()} 的参数 \${$paramName} 的 @param 注释缺少是否必填的说明。必须明确说明参数是否必填，例如：@param string \$title 测试标题（必填）或 @param string \$title 测试标题（可选，默认\"test\"）";
                    }
                }
            }
        }
        
        return $errors;
    }
    
    /**
     * 检查是否有@return标签
     */
    private function hasReturnTag(string $docComment): bool
    {
        return preg_match('/@return\s+/', $docComment) === 1;
    }
    
    /**
     * 验证@return标签格式
     * 
     * 要求@return注释必须包含返回数据格式说明
     */
    private function validateReturnTag(string $docComment, string $methodName): array
    {
        $errors = [];
        
        // 提取@return注释
        if (preg_match('/@return\s+([^\s]+)\s+(.+)/', $docComment, $matches)) {
            $returnType = $matches[1] ?? '';
            $returnDescription = $matches[2] ?? '';
            
            // 检查是否包含返回数据格式说明
            if (!preg_match('/返回数据格式|返回格式|格式：|format|response/i', $returnDescription)) {
                $errors[] = "【严重错误】方法 {$methodName} 的 @return 注释缺少返回数据格式说明。必须包含返回数据格式，例如：@return array 返回数据格式：{\"code\": 0, \"msg\": \"success\", \"data\": {}}";
            }
        }
        
        return $errors;
    }
    
    /**
     * 检查是否有@example标签
     */
    private function hasExampleTag(string $docComment): bool
    {
        return preg_match('/@example\s+/', $docComment) === 1;
    }
    
    /**
     * 验证@example标签格式
     * 
     * 要求@example注释必须包含完整的请求和响应示例
     */
    private function validateExampleTag(string $docComment, string $methodName): array
    {
        $errors = [];
        
        // 提取@example和@example-end之间的内容
        if (preg_match('/@example\s+(.*?)@example-end/s', $docComment, $matches)) {
            $exampleContent = $matches[1] ?? '';
            
            // 检查是否包含Method
            if (!preg_match('/Method\s*[:：]\s*(GET|POST|PUT|DELETE|PATCH)/i', $exampleContent)) {
                $errors[] = "【严重错误】方法 {$methodName} 的 @example 注释缺少 Method 字段。必须包含 HTTP 方法，例如：Method: POST";
            }
            
            // 检查是否包含Path
            if (!preg_match('/Path\s*[:：]\s*[\/\w\-]+/i', $exampleContent)) {
                $errors[] = "【严重错误】方法 {$methodName} 的 @example 注释缺少 Path 字段。必须包含 API 路径，例如：Path: /api/rest/v1/test/create";
            }
            
            // 检查是否包含Response
            if (!preg_match('/Response\s*[:：]/i', $exampleContent)) {
                $errors[] = "【严重错误】方法 {$methodName} 的 @example 注释缺少 Response 字段。必须包含响应示例，例如：Response: {\"code\": 0, \"msg\": \"success\", \"data\": {}}";
            }
            
            // 对于POST/PUT/PATCH方法，检查是否包含Body
            $methodNameLower = strtolower($methodName);
            if (str_starts_with($methodNameLower, 'post') || 
                str_starts_with($methodNameLower, 'put') || 
                str_starts_with($methodNameLower, 'patch')) {
                if (!preg_match('/Body\s*[:：]/i', $exampleContent)) {
                    $errors[] = "【严重错误】方法 {$methodName} 是 POST/PUT/PATCH 方法，@example 注释必须包含 Body 字段。必须包含请求体示例，例如：Body: {\"key\": \"value\"}。如果没有请求体，Body可以为空对象：Body: {}";
                }
            }
            
            // 对于GET请求，如果有参数，检查是否包含Request Parameters
            if (str_starts_with($methodNameLower, 'get')) {
                // 检查方法是否有参数（通过@param注释判断）
                if (preg_match_all('/@param\s+/', $docComment, $paramMatches)) {
                    $paramCount = count($paramMatches[0] ?? []);
                    // 如果有参数，应该提供Request Parameters示例（允许为空，但建议提供）
                    // 这里不强制要求，因为GET请求的参数可能都在URL路径中
                }
            }
        } else {
            $errors[] = "【严重错误】方法 {$methodName} 的 @example 注释格式不正确。必须使用 @example 开始，@example-end 结束，参考 Test.php 的注释格式。";
        }
        
        return $errors;
    }
    
    /**
     * 验证Acl注解
     */
    private function validateAclAttribute(\ReflectionClass $reflection, \ReflectionMethod $method): array
    {
        $errors = [];
        
        // 检查是否为后端API控制器（继承BackendRestController）
        $isBackendApi = $this->isBackendApiController($reflection);
        
        if (!$isBackendApi) {
            // 前端API不需要Acl注解
            return $errors;
        }
        
        // 检查类级别Acl注解
        $classAclAttributes = $reflection->getAttributes(\Weline\Framework\Acl\Acl::class);
        if (empty($classAclAttributes)) {
            $errors[] = "后台API控制器类 {$reflection->getName()} 缺少类级别 Acl 注解";
        }
        
        // 检查方法级别Acl注解
        $methodAclAttributes = $method->getAttributes(\Weline\Framework\Acl\Acl::class);
        if (empty($methodAclAttributes)) {
            $errors[] = "后台API方法 {$method->getName()} 缺少方法级别 Acl 注解";
        }
        
        return $errors;
    }
    
    /**
     * 检查是否为后端API控制器
     * 
     * @param ReflectionClass $reflection 反射类
     * @return bool 是否为后端API控制器
     */
    private function isBackendApiController(\ReflectionClass $reflection): bool
    {
        // 检查是否继承BackendRestController
        $parentClass = $reflection->getParentClass();
        while ($parentClass) {
            if ($parentClass->getName() === 'Weline\Framework\App\Controller\BackendRestController') {
                return true;
            }
            $parentClass = $parentClass->getParentClass();
        }
        
        return false;
    }
    
    /**
     * 验证需要登录的API是否明确说明了所有请求参数
     * 
     * 如果API有ACL注解（需要登录），必须明确说明所有通过request获取的参数
     * 
     * @param \ReflectionClass $reflection 反射类
     * @param \ReflectionMethod $method 反射方法
     * @param string $docComment PHPDoc注释
     * @return array 错误列表
     */
    private function validateRequestParameters(\ReflectionClass $reflection, \ReflectionMethod $method, string $docComment): array
    {
        $errors = [];
        
        // 检查是否有ACL注解（需要登录）
        $hasAcl = false;
        
        // 检查类级别ACL
        $classAclAttributes = $reflection->getAttributes(\Weline\Framework\Acl\Acl::class);
        if (!empty($classAclAttributes)) {
            $hasAcl = true;
        }
        
        // 检查方法级别ACL
        $methodAclAttributes = $method->getAttributes(\Weline\Framework\Acl\Acl::class);
        if (!empty($methodAclAttributes)) {
            $hasAcl = true;
        }
        
        // 如果没有ACL注解，不需要验证
        if (!$hasAcl) {
            return $errors;
        }
        
        // 读取方法源码
        $filename = $method->getFileName();
        if (!is_file($filename)) {
            return $errors;
        }
        
        $startLine = $method->getStartLine() - 1; // 转换为0-based索引
        $endLine = $method->getEndLine();
        $lines = file($filename);
        if ($lines === false) {
            return $errors;
        }
        
        $methodCode = implode('', array_slice($lines, $startLine, $endLine - $startLine));
        
        // 提取所有通过request获取的参数
        $requestParams = [];
        
        // 匹配 request->getPost('param'), request->getPost("param")
        if (preg_match_all('/\$this->request->getPost\s*\(\s*["\']([^"\']+)["\']/', $methodCode, $matches)) {
            foreach ($matches[1] as $param) {
                $requestParams[$param] = 'POST';
            }
        }
        
        // 匹配 request->getParam('param'), request->getParam("param")
        if (preg_match_all('/\$this->request->getParam\s*\(\s*["\']([^"\']+)["\']/', $methodCode, $matches)) {
            foreach ($matches[1] as $param) {
                if (!isset($requestParams[$param])) {
                    $requestParams[$param] = 'PARAM';
                }
            }
        }
        
        // 匹配 request->getGet('param'), request->getGet("param")
        if (preg_match_all('/\$this->request->getGet\s*\(\s*["\']([^"\']+)["\']/', $methodCode, $matches)) {
            foreach ($matches[1] as $param) {
                if (!isset($requestParams[$param])) {
                    $requestParams[$param] = 'GET';
                }
            }
        }
        
        // 匹配 request->getBodyParam('param'), request->getBodyParam("param")
        if (preg_match_all('/\$this->request->getBodyParam\s*\(\s*["\']([^"\']+)["\']/', $methodCode, $matches)) {
            foreach ($matches[1] as $param) {
                if (!isset($requestParams[$param])) {
                    $requestParams[$param] = 'BODY';
                }
            }
        }
        
        // 匹配 request->getAuth('bearer'), request->getHeader('X-API-Token') 等
        // 这些通常用于token认证，需要特殊处理
        if (preg_match_all('/\$this->request->getAuth\s*\(\s*["\']([^"\']+)["\']/', $methodCode, $matches)) {
            foreach ($matches[1] as $authType) {
                // 如果是bearer token，参数名为token
                if (strtolower($authType) === 'bearer') {
                    if (!isset($requestParams['token'])) {
                        $requestParams['token'] = 'AUTH_BEARER';
                    }
                }
            }
        }
        
        if (preg_match_all('/\$this->request->getHeader\s*\(\s*["\']([^"\']+)["\']/', $methodCode, $matches)) {
            foreach ($matches[1] as $headerName) {
                // 如果是X-API-Token头，参数名为token
                if (strtolower($headerName) === 'x-api-token') {
                    if (!isset($requestParams['token'])) {
                        $requestParams['token'] = 'HEADER_X_API_TOKEN';
                    }
                }
            }
        }
        
        // 检查是否调用了私有方法获取token（如 getTokenFromRequest()）
        // 如果调用了，需要检查该私有方法中的参数获取
        if (preg_match('/\$this->getTokenFromRequest\s*\(/', $methodCode)) {
            // 检查私有方法 getTokenFromRequest
            try {
                $privateMethod = $reflection->getMethod('getTokenFromRequest');
                if ($privateMethod && $privateMethod->isPrivate()) {
                    $privateStartLine = $privateMethod->getStartLine() - 1;
                    $privateEndLine = $privateMethod->getEndLine();
                    $privateCode = implode('', array_slice($lines, $privateStartLine, $privateEndLine - $privateStartLine));
                    
                    // 从私有方法中提取token相关的参数
                    if (preg_match('/\$this->request->getAuth\s*\(\s*["\']bearer["\']/', $privateCode) ||
                        preg_match('/\$this->request->getHeader\s*\(\s*["\']X-API-Token["\']/i', $privateCode) ||
                        preg_match('/\$this->request->getParam\s*\(\s*["\']token["\']/', $privateCode) ||
                        preg_match('/\$this->request->getPost\s*\(\s*["\']token["\']/', $privateCode)) {
                        if (!isset($requestParams['token'])) {
                            $requestParams['token'] = 'TOKEN';
                        }
                    }
                }
            } catch (\ReflectionException $e) {
                // 私有方法不存在，忽略
            }
        }
        
        // 检查每个请求参数是否在@param注释中有说明
        foreach ($requestParams as $paramName => $paramType) {
            // 检查是否有对应的@param注释
            // @param注释格式：@param type $paramName description
            // 或者：@param type $paramName description (从request获取)
            $paramPattern = '/@param\s+[^\s]+\s+\$' . preg_quote($paramName, '/') . '\s+/';
            if (!preg_match($paramPattern, $docComment)) {
                $errors[] = "【严重错误】方法 {$method->getName()} 需要登录（有ACL注解），但使用了请求参数 '{$paramName}'（通过request->get{$paramType}获取）却没有在@param注释中说明。请添加 @param 注释说明此参数。";
            }
        }
        
        return $errors;
    }
    
    /**
     * 验证API控制器类
     * 
     * @param string $className 控制器类名
     * @param bool $isBackend 是否为后台API
     * @return array 验证结果 ['valid' => bool, 'errors' => array]
     */
    public function validateController(string $className, bool $isBackend = false): array
    {
        $errors = [];
        $allValid = true;
        
        try {
            $reflection = new \ReflectionClass($className);
            
            // 获取所有公共方法
            $methods = $reflection->getMethods(\ReflectionMethod::IS_PUBLIC);
            
            foreach ($methods as $method) {
                // 跳过魔术方法和构造函数
                if ($method->isConstructor() || $method->isDestructor() || str_starts_with($method->getName(), '__')) {
                    continue;
                }
                
                // 验证方法
                $result = $this->validateMethod($className, $method->getName(), $isBackend);
                if (!$result['valid']) {
                    $allValid = false;
                    $errors = array_merge($errors, $result['errors']);
                }
            }
            
        } catch (\Exception $e) {
            $errors[] = "验证控制器 {$className} 时出错: " . $e->getMessage();
            $allValid = false;
        }
        
        return [
            'valid' => $allValid,
            'errors' => $errors
        ];
    }
}


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
                    $errors[] = "方法 {$methodName} 缺少 @return 注释";
                }
            }
            
            // 5. 检查后台API是否有Acl注解
            if ($isBackend) {
                $aclErrors = $this->validateAclAttribute($reflection, $method);
                $errors = array_merge($errors, $aclErrors);
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
                $errors[] = "方法 {$method->getName()} 的参数 \${$paramName} 缺少 @param 注释";
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


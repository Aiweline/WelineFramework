<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Api\Service;

use Weline\Framework\Http\Request;
use Weline\Framework\Reflection\ReflectionClass;

/**
 * API安全服务
 * 
 * 用于判断API接口的公开性、是否需要登录等
 */
class ApiSecurityService
{
    /**
     * 检查是否为完全公开接口（无Acl，不需要登录）
     * 
     * @param Request $request 请求对象
     * @return bool 是否为公开接口
     */
    public function isPublicApi(Request $request): bool
    {
        try {
            // 获取控制器和方法信息
            $controller = $request->getController();
            $action = $request->getAction();
            
            if (!$controller || !$action) {
                return false;
            }
            
            // 使用反射检查方法是否有Acl注解
            $reflection = new ReflectionClass($controller);
            if (!$reflection->hasMethod($action)) {
                return false;
            }
            
            $method = $reflection->getMethod($action);
            
            // 检查方法是否有Acl注解
            $aclAttributes = $method->getAttributes(\Weline\Framework\Acl\Acl::class);
            if (!empty($aclAttributes)) {
                return false; // 有Acl注解，不是公开接口
            }
            
            // 检查类是否有Acl注解
            $classAclAttributes = $reflection->getAttributes(\Weline\Framework\Acl\Acl::class);
            if (!empty($classAclAttributes)) {
                return false; // 类有Acl注解，不是公开接口
            }
            
            // 没有Acl注解，认为是公开接口
            return true;
            
        } catch (\Exception $e) {
            w_log_error('ApiSecurityService isPublicApi error: ' . $e->getMessage());
            return false;
        }
    }
}


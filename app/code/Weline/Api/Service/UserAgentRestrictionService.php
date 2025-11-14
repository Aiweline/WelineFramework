<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Api\Service;

/**
 * 用户代理限制服务
 * 
 * 用于验证User-Agent是否在允许列表中
 */
class UserAgentRestrictionService
{
    /**
     * 检查User-Agent是否允许
     * 
     * @param string $userAgent User-Agent字符串
     * @param array $allowedUserAgents 允许的User-Agent列表
     * @return bool 是否允许
     */
    public function isUserAgentAllowed(string $userAgent, array $allowedUserAgents): bool
    {
        if (empty($allowedUserAgents)) {
            return true; // 如果列表为空，允许所有User-Agent
        }
        
        foreach ($allowedUserAgents as $allowed) {
            $allowed = trim($allowed ?? '');
            if (empty($allowed)) {
                continue;
            }
            
            // 精确匹配
            if ($userAgent === $allowed) {
                return true;
            }
            
            // 正则表达式匹配（以 / 开头和结尾）
            if (strlen($allowed) >= 2 && $allowed[0] === '/' && substr($allowed, -1) === '/') {
                $pattern = substr($allowed, 1, -1);
                if (@preg_match('/' . $pattern . '/', $userAgent) === 1) {
                    return true;
                }
            }
            
            // 通配符匹配（*）
            if (strpos($allowed, '*') !== false) {
                $pattern = str_replace(['*', '.'], ['.*', '\.'], $allowed);
                if (@preg_match('/^' . $pattern . '$/', $userAgent) === 1) {
                    return true;
                }
            }
        }
        
        return false;
    }
}


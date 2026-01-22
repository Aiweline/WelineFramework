<?php

declare(strict_types=1);

namespace Weline\Theme\Controller;

use Weline\Framework\Router\RouterInterface;

/**
 * Theme 路由重写器
 * 
 * 功能：处理政策页面的动态路由
 * 例如：/policy/cookie   -> /theme/frontend/policy/cookie
 *      /policy/privacy  -> /theme/frontend/policy/privacy
 *      /policy          -> /theme/frontend/policy/index
 */
class Router implements RouterInterface
{
    /**
     * @inheritDoc
     */
    public static function process(string &$path, array &$rule): void
    {
        return;
        // 1. 跳过已经匹配的路由
        if (!empty($rule['module'])) {
            return;
        }

        // 2. 只处理 /policy 开头的路径
        $policyPrefix = '/policy';
        if (!str_starts_with($path, $policyPrefix)) {
            return;
        }

        // 3. 提取布局名称
        // /policy/cookie -> cookie
        // /policy -> index (默认)
        $remainingPath = substr($path, strlen($policyPrefix));
        $remainingPath = trim($remainingPath, '/');

        // 4. 设置路由规则
        $rule['module'] = 'Weline_Theme';

        // 如果有布局名称，设置为 action；否则使用 index
        if (!empty($remainingPath)) {
            // 验证布局名称格式（只允许字母、数字、连字符、下划线）
            if (preg_match('/^[a-zA-Z0-9_-]+$/', $remainingPath)) {
                $path = '/theme/frontend/policy/' . $remainingPath;
            } else {
                // 格式不正确，使用默认布局
                $path = '/theme/frontend/policy/index';
            }
        } else {
            // 没有指定布局，使用默认
            $path = '/theme/frontend/policy/index';
        }
    }
}

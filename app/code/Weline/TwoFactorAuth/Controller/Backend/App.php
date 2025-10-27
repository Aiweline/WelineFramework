<?php

declare(strict_types=1);

namespace Weline\TwoFactorAuth\Controller\Backend;

use Weline\Framework\App\Controller\BackendController;

/**
 * 验证器APP访问控制器
 * 确保只有登录用户才能访问PWA应用
 * 
 * @package Weline\TwoFactorAuth\Controller\Backend
 */
class App extends BackendController
{
    /**
     * 显示验证器APP
     * BackendController会自动检查登录状态
     * 如果未登录，会自动重定向到登录页
     */
    public function index()
    {
        // BackendController的loginCheck()已经确保用户已登录
        // 此处访问已经通过登录验证
        
        // 重定向到PWA应用（带登录标记参数）
        return $this->redirect('/static/Weline/default/Weline/TwoFactorAuth/view/statics/twofa-app/index.html?from=backend&logged=1');
    }
}


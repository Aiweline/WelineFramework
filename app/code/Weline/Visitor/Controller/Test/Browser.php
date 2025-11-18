<?php

namespace Weline\Visitor\Controller\Test;

use Weline\Framework\App\Controller\FrontendController;

/**
 * 浏览器测试控制器
 * 
 * 提供浏览器端像素跟踪测试页面
 */
class Browser extends FrontendController
{
    /**
     * 显示浏览器测试页面
     * 
     * 访问: /visitor/test/browser
     * 
     * @return string
     */
    public function index(): string
    {
        // 读取浏览器测试HTML文件
        $testFile = BP . 'app/code/Weline/Visitor/test/Browser/PixelTrackingBrowserTest.html';
        
        if (file_exists($testFile)) {
            return file_get_contents($testFile);
        }
        
        return $this->error('测试文件未找到');
    }
}


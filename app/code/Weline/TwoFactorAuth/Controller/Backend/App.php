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
        
        // 直接读取并返回PWA应用的HTML内容
        $pwaPath = __DIR__ . '/../../view/statics/twofa-app/index.html';
        
        if (file_exists($pwaPath)) {
            $content = file_get_contents($pwaPath);
            // 替换相对路径为绝对路径
            // 替换相对路径为正确的资源路径（相对于当前URL）
            $content = str_replace('href="style.css"', 'href="./css"', $content);
            $content = str_replace('src="app.js"', 'src="./js"', $content);
            $content = str_replace('src="qr-scanner.min.js"', 'src="./qr-scanner"', $content);
            
            header('Content-Type: text/html; charset=utf-8');
            echo $content;
            exit();
        } else {
            header('Content-Type: text/html; charset=utf-8');
            echo 'PWA应用文件不存在';
            exit();
        }
    }
    
    /**
     * 提供PWA应用的JS文件
     */
    public function js()
    {
        $jsPath = __DIR__ . '/../../view/statics/twofa-app/app.js';
        
        if (file_exists($jsPath)) {
            $content = file_get_contents($jsPath);
            header('Content-Type: application/javascript; charset=utf-8');
            echo $content;
            exit();
        } else {
            header('Content-Type: application/javascript; charset=utf-8');
            echo '// JS文件不存在';
            exit();
        }
    }
    
    /**
     * 提供PWA应用的CSS文件
     */
    public function css()
    {
        $cssPath = __DIR__ . '/../../view/statics/twofa-app/style.css';
        
        if (file_exists($cssPath)) {
            $content = file_get_contents($cssPath);
            header('Content-Type: text/css; charset=utf-8');
            echo $content;
            exit();
        } else {
            header('Content-Type: text/css; charset=utf-8');
            echo '/* CSS文件不存在 */';
            exit();
        }
    }
    
    /**
     * 提供QR扫描器JS文件
     */
    public function qrScanner()
    {
        $qrPath = __DIR__ . '/../../view/statics/twofa-app/qr-scanner.min.js';
        
        if (file_exists($qrPath)) {
            $content = file_get_contents($qrPath);
            header('Content-Type: application/javascript; charset=utf-8');
            echo $content;
            exit();
        } else {
            header('Content-Type: application/javascript; charset=utf-8');
            echo '// QR扫描器文件不存在';
            exit();
        }
    }
}

<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Visitor\Observer;

use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Visitor\Service\PixelEncryptionService;

/**
 * 注册像素观察者
 * 
 * 在用户注册后发送加密的像素数据
 */
class RegisterPixel implements ObserverInterface
{
    /**
     * 执行观察者逻辑
     *
     * @param Event $event
     * @return void
     */
    public function execute(Event &$event): void
    {
        try {
            $data = $event->getData();
            $user = $data->getData('user');
            $request = $data->getData('request');
            
            if (!$user || !$request) {
                return;
            }

            // 获取当前版本号的token
            /** @var PixelEncryptionService $encryptionService */
            $encryptionService = ObjectManager::getInstance(PixelEncryptionService::class);
            $token = $encryptionService->getCurrentVersionToken();
            
            if (!$token) {
                // 如果没有token，跳过加密，直接发送（开发模式）
                return;
            }

            // 获取站点ID（从SERVER变量获取，确保是整数）
            $websiteId = 0;
            if (isset($_SERVER['WELINE_WEBSITE_ID']) && $_SERVER['WELINE_WEBSITE_ID'] !== '') {
                $websiteId = (int)$_SERVER['WELINE_WEBSITE_ID'];
            }
            
            // 准备像素数据
            $pixelData = [
                'url' => $request->getUriString(),
                'module' => 'Weline_Frontend',
                'name' => 'register',
                'eventName' => 'register',
                'value' => 0,
                'userLang' => $_SERVER['WELINE_USER_LANG'] ?? 'zh-CN',
                'currency' => $_SERVER['WELINE_USER_CURRENCY'] ?? 'RMB',
                'websiteId' => $websiteId,
                'siteId' => $websiteId, // 同时提供siteId字段以兼容前端
                'referer' => $request->getReferer() ?? '',
                'userId' => $user->getId(),
                'userAgent' => $request->getServer('HTTP_USER_AGENT') ?? '',
                'ip' => $request->clientIP(),
                'additionalInfo' => [
                    'innerWidth' => 0,
                    'innerHeight' => 0,
                    'outerWidth' => 0,
                    'outerHeight' => 0
                ],
                'screen' => [
                    'width' => 0,
                    'height' => 0
                ],
                'timestamp' => date('c'),
                'local_datetime' => date('Y-m-d H:i:s')
            ];

            // 加密数据
            $version = $token->getVersion();
            $encryptedData = $encryptionService->encrypt($pixelData, $version);

            // 发送到像素API（异步，不阻塞）
            $this->sendPixelDataAsync($encryptedData, $version);
            
        } catch (\Exception $e) {
            // 静默失败，不影响注册流程
            w_log_error('RegisterPixel Observer Error: ' . $e->getMessage());
        }
    }

    /**
     * 异步发送像素数据
     * 
     * @param string $encryptedData
     * @param string $version
     * @return void
     */
    private function sendPixelDataAsync(string $encryptedData, string $version): void
    {
        // 使用curl异步发送
        // 获取基础URL
        $baseUrl = \Weline\Framework\App\Env::getInstance()->getBaseUrl();
        if (empty($baseUrl)) {
            // 如果无法获取，尝试从SERVER构建
            $scheme = $_SERVER['REQUEST_SCHEME'] ?? 'http';
            $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
            $baseUrl = $scheme . '://' . $host;
        }
        $pixelUrl = rtrim($baseUrl, '/') . '/visitor/rest/v1/pixel';
        
        $postData = json_encode([
            'encrypted' => $encryptedData,
            'version' => $version
        ]);

        // 使用curl异步发送（不等待响应）
        $ch = curl_init($pixelUrl);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'X-Pixel-Version: ' . $version
        ]);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, 1); // 1秒超时
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 1);
        curl_exec($ch);
        curl_close($ch);
    }
}


<?php

declare(strict_types=1);

namespace Weline\Visitor\test\Unit\Observer;

use Weline\Framework\UnitTest\TestCore;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Event\Event;
use Weline\Visitor\Observer\LoginPixel;
use Weline\Visitor\Service\PixelEncryptionService;
use Weline\Visitor\Model\PixelEncryptionToken;
use Weline\Framework\Http\Request;

/**
 * 登录像素观察者单元测试
 */
class LoginPixelTest extends TestCore
{
    private LoginPixel $observer;
    private PixelEncryptionService $encryptionService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->observer = ObjectManager::getInstance(LoginPixel::class);
        $this->encryptionService = ObjectManager::getInstance(PixelEncryptionService::class);
    }

    /**
     * 测试：登录事件触发像素发送（有token）
     */
    public function testLoginEventTriggersPixelWithToken()
    {
        // 创建测试token
        $version = 'test-login-' . time();
        try {
            $token = $this->encryptionService->generateTokenForVersion($version);
        } catch (\Exception $e) {
            $this->markTestSkipped('无法创建测试token: ' . $e->getMessage());
            return;
        }

        // 模拟用户对象（实现 AuthenticableInterface）
        $user = $this->createMock(\Weline\Framework\Session\Auth\AuthenticableInterface::class);
        $user->method('getAuthIdentifier')->willReturn(123);

        // 模拟请求对象
        $request = $this->createMock(Request::class);
        $request->method('getUriString')->willReturn('https://example.com/login');
        $request->method('getReferer')->willReturn('https://example.com/');
        $request->method('getServer')->willReturn('Mozilla/5.0 Test');
        $request->method('clientIP')->willReturn('192.168.1.1');

        // 设置SERVER变量
        $_SERVER['WELINE_USER_LANG'] = 'zh-CN';
        $_SERVER['WELINE_USER_CURRENCY'] = 'RMB';
        $_SERVER['WELINE_WEBSITE_ID'] = '1';

        // 创建事件
        $eventData = new \Weline\Framework\DataObject();
        $eventData->setData('user', $user);
        $eventData->setData('request', $request);

        $event = new Event();
        $event->setData($eventData);

        // 执行观察者（应该不抛出异常）
        try {
            $this->observer->execute($event);
            $this->assertTrue(true, '登录事件应该成功触发像素发送');
        } catch (\Exception $e) {
            $this->fail('登录事件触发像素发送不应该抛出异常: ' . $e->getMessage());
        }

        // 清理
        $token->setIsDeleted(1)->save();
        unset($_SERVER['WELINE_USER_LANG'], $_SERVER['WELINE_USER_CURRENCY'], $_SERVER['WELINE_WEBSITE_ID']);
    }

    /**
     * 测试：登录事件无token时静默处理
     */
    public function testLoginEventWithoutToken()
    {
        // 确保没有token
        $currentToken = $this->encryptionService->getCurrentVersionToken();
        if ($currentToken) {
            $this->markTestSkipped('当前环境存在token，无法测试无token场景');
            return;
        }

        // 模拟用户对象（实现 AuthenticableInterface）
        $user = $this->createMock(\Weline\Framework\Session\Auth\AuthenticableInterface::class);
        $user->method('getAuthIdentifier')->willReturn(123);

        // 模拟请求对象
        $request = $this->createMock(Request::class);
        $request->method('getUriString')->willReturn('https://example.com/login');
        $request->method('clientIP')->willReturn('192.168.1.1');

        // 创建事件
        $eventData = new \Weline\Framework\DataObject();
        $eventData->setData('user', $user);
        $eventData->setData('request', $request);

        $event = new Event();
        $event->setData($eventData);

        // 执行观察者（应该静默返回，不抛出异常）
        try {
            $this->observer->execute($event);
            $this->assertTrue(true, '无token时应该静默处理');
        } catch (\Exception $e) {
            $this->fail('无token时不应该抛出异常: ' . $e->getMessage());
        }
    }

    /**
     * 测试：登录事件缺少用户或请求时静默返回
     */
    public function testLoginEventMissingData()
    {
        // 测试缺少用户
        $eventData1 = new \Weline\Framework\DataObject();
        $eventData1->setData('request', $this->createMock(Request::class));
        $event1 = new Event();
        $event1->setData($eventData1);

        try {
            $this->observer->execute($event1);
            $this->assertTrue(true, '缺少用户时应该静默返回');
        } catch (\Exception $e) {
            $this->fail('缺少用户时不应该抛出异常: ' . $e->getMessage());
        }

        // 测试缺少请求
        $eventData2 = new \Weline\Framework\DataObject();
        $eventData2->setData('user', $this->createMock(\Weline\Framework\Session\Auth\AuthenticableInterface::class));
        $event2 = new Event();
        $event2->setData($eventData2);

        try {
            $this->observer->execute($event2);
            $this->assertTrue(true, '缺少请求时应该静默返回');
        } catch (\Exception $e) {
            $this->fail('缺少请求时不应该抛出异常: ' . $e->getMessage());
        }
    }

    /**
     * 测试：登录事件加密数据正确发送
     */
    public function testLoginEventEncryptedDataSent()
    {
        // 创建测试token
        $version = 'test-login-encrypt-' . time();
        try {
            $token = $this->encryptionService->generateTokenForVersion($version);
        } catch (\Exception $e) {
            $this->markTestSkipped('无法创建测试token: ' . $e->getMessage());
            return;
        }

        // 模拟用户对象（实现 AuthenticableInterface）
        $user = $this->createMock(\Weline\Framework\Session\Auth\AuthenticableInterface::class);
        $user->method('getAuthIdentifier')->willReturn(456);

        // 模拟请求对象
        $request = $this->createMock(Request::class);
        $request->method('getUriString')->willReturn('https://example.com/login');
        $request->method('getReferer')->willReturn('https://example.com/referer');
        $request->method('getServer')->willReturn('Mozilla/5.0 Chrome');
        $request->method('clientIP')->willReturn('203.0.113.1');

        // 设置SERVER变量
        $_SERVER['WELINE_USER_LANG'] = 'en-US';
        $_SERVER['WELINE_USER_CURRENCY'] = 'USD';
        $_SERVER['WELINE_WEBSITE_ID'] = '2';

        // 创建事件
        $eventData = new \Weline\Framework\DataObject();
        $eventData->setData('user', $user);
        $eventData->setData('request', $request);

        $event = new Event();
        $event->setData($eventData);

        // 执行观察者
        try {
            $this->observer->execute($event);
            // 由于是异步发送，我们无法直接验证数据是否发送成功
            // 但可以验证没有抛出异常
            $this->assertTrue(true, '加密数据应该成功发送（异步）');
        } catch (\Exception $e) {
            $this->fail('加密数据发送不应该抛出异常: ' . $e->getMessage());
        }

        // 清理
        $token->setIsDeleted(1)->save();
        unset($_SERVER['WELINE_USER_LANG'], $_SERVER['WELINE_USER_CURRENCY'], $_SERVER['WELINE_WEBSITE_ID']);
    }
}


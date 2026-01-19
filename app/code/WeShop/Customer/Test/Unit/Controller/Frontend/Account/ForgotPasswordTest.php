<?php

declare(strict_types=1);

namespace WeShop\Customer\Test\Unit\Controller\Frontend\Account;

use PHPUnit\Framework\TestCase;
use WeShop\Customer\Controller\Frontend\Account\ForgotPassword;

/**
 * 密码重置页控制器单元测试
 */
class ForgotPasswordTest extends TestCase
{
    /**
     * 测试：控制器类存在
     */
    public function testControllerClassExists(): void
    {
        $this->assertTrue(class_exists(ForgotPassword::class));
    }

    /**
     * 测试：layoutType 属性设置为 'account_auth'
     */
    public function testLayoutTypeIsAccountAuth(): void
    {
        $reflection = new \ReflectionClass(ForgotPassword::class);
        $property = $reflection->getProperty('layoutType');
        $property->setAccessible(true);
        
        $controller = new ForgotPassword();
        $this->assertEquals('account_auth', $property->getValue($controller));
    }

    /**
     * 测试：控制器有 postForgotPassword 方法
     */
    public function testControllerHasPostForgotPasswordMethod(): void
    {
        $reflection = new \ReflectionClass(ForgotPassword::class);
        $this->assertTrue($reflection->hasMethod('postForgotPassword'));
    }

    /**
     * 测试：控制器有 postResetPassword 方法
     */
    public function testControllerHasPostResetPasswordMethod(): void
    {
        $reflection = new \ReflectionClass(ForgotPassword::class);
        $this->assertTrue($reflection->hasMethod('postResetPassword'));
    }
}

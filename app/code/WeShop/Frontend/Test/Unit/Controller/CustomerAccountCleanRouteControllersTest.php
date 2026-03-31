<?php

declare(strict_types=1);

namespace WeShop\Frontend\Test\Unit\Controller;

use PHPUnit\Framework\TestCase;
use WeShop\Frontend\Controller\Customer\Account\Challenge;
use WeShop\Frontend\Controller\Customer\Account\ForgotPassword;
use WeShop\Frontend\Controller\Customer\Account\Index;
use WeShop\Frontend\Controller\Customer\Account\Login;
use WeShop\Frontend\Controller\Customer\Account\Logout;
use WeShop\Frontend\Controller\Customer\Account\Register;

class CustomerAccountCleanRouteControllersTest extends TestCase
{
    public function testLoginAliasProvidesEntryPointsAndExtendsFrontendBaseController(): void
    {
        $reflection = new \ReflectionClass(Login::class);

        $this->assertTrue(class_exists(Login::class));
        $this->assertTrue($reflection->hasMethod('index'));
        $this->assertTrue($reflection->hasMethod('postIndex'));
        $this->assertTrue($reflection->hasMethod('postLogin'));
        $this->assertTrue($reflection->isSubclassOf(\WeShop\Frontend\Controller\BaseController::class));
    }

    public function testRegisterAliasExists(): void
    {
        $reflection = new \ReflectionClass(Register::class);

        $this->assertTrue(class_exists(Register::class));
        $this->assertTrue($reflection->hasMethod('index'));
        $this->assertTrue($reflection->hasMethod('postIndex'));
        $this->assertTrue($reflection->hasMethod('postRegister'));
        $this->assertTrue($reflection->isSubclassOf(\WeShop\Customer\Controller\Frontend\Account\Register::class));
    }

    public function testAccountIndexAliasExists(): void
    {
        $reflection = new \ReflectionClass(Index::class);

        $this->assertTrue(class_exists(Index::class));
        $this->assertTrue($reflection->hasMethod('index'));
        $this->assertTrue($reflection->isSubclassOf(\WeShop\Customer\Controller\Frontend\Account\Index::class));
    }

    public function testForgotPasswordAliasExists(): void
    {
        $reflection = new \ReflectionClass(ForgotPassword::class);

        $this->assertTrue(class_exists(ForgotPassword::class));
        $this->assertTrue($reflection->hasMethod('index'));
        $this->assertTrue($reflection->hasMethod('postIndex'));
        $this->assertTrue($reflection->hasMethod('postResetPassword'));
        $this->assertTrue($reflection->hasMethod('postForgotPassword'));
        $this->assertTrue($reflection->isSubclassOf(\WeShop\Customer\Controller\Frontend\Account\ForgotPassword::class));
    }

    public function testChallengeAliasExists(): void
    {
        $reflection = new \ReflectionClass(Challenge::class);

        $this->assertTrue(class_exists(Challenge::class));
        $this->assertTrue($reflection->hasMethod('index'));
        $this->assertTrue($reflection->hasMethod('postIndex'));
        $this->assertTrue($reflection->isSubclassOf(\WeShop\Customer\Controller\Frontend\Account\Challenge::class));
    }

    public function testLogoutAliasExists(): void
    {
        $reflection = new \ReflectionClass(Logout::class);

        $this->assertTrue(class_exists(Logout::class));
        $this->assertTrue($reflection->hasMethod('index'));
        $this->assertTrue($reflection->isSubclassOf(\WeShop\Customer\Controller\Frontend\Account\Logout::class));
    }
}

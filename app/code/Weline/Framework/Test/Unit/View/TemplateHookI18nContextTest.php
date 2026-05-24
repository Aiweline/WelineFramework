<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\View;

use ReflectionMethod;
use Weline\Framework\Env\WelineEnv;
use Weline\Framework\Http\Request;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\UnitTest\TestCore;
use Weline\Framework\View\Template;

final class TemplateHookI18nContextTest extends TestCore
{
    protected function setUp(): void
    {
        parent::setUp();
        self::initRequest('/USD/en_US/index/get-index');
        WelineEnv::setLang('en_US');
        WelineEnv::setCurrency('USD');
    }

    public function testHookRenderingAddsHookSourceModuleToRequestI18nContext(): void
    {
        $request = ObjectManager::getInstance(Request::class);
        $request->setModules(['Weline_Theme']);

        $template = ObjectManager::getInstance(Template::class);
        $method = new ReflectionMethod($template, 'fetchHookHtml');
        $method->setAccessible(true);

        foreach ([
            'Weline_Customer::hooks/header-account-links.phtml' => 'Weline_Customer',
            'WeShop_Notification::hooks/header-account-links.phtml' => 'WeShop_Notification',
            'Weline_Shipping::hooks/header-account-links.phtml' => 'Weline_Shipping',
        ] as $hookFile => $moduleName) {
            $method->invoke($template, $hookFile);
            $this->assertContains($moduleName, $request->getModules());
        }

        $this->assertSame('Notification Settings', (string) __('通知设置'));
        $this->assertSame('Security settings', (string) __('安全设置'));
        $this->assertSame('Shipping Address', (string) __('发货地址'));
        $this->assertSame('Delivery Address', (string) __('收货地址'));
    }
}

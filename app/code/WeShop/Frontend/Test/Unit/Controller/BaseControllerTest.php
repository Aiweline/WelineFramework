<?php

declare(strict_types=1);

namespace WeShop\Frontend\Test\Unit\Controller;

use PHPUnit\Framework\TestCase;
use WeShop\Frontend\Controller\BaseController;
use Weline\Framework\Http\Request;
use Weline\Framework\Http\Response;
use Weline\Framework\Manager\MessageManager;
use Weline\Framework\View\Template;

/**
 * 控制器测试基类
 * 
 * 提供通用的Mock设置和测试辅助方法
 */
abstract class BaseControllerTest extends TestCase
{
    protected Request $request;
    protected Response $response;
    protected MessageManager $messageManager;
    protected Template $template;

    protected function setUp(): void
    {
        parent::setUp();
        
        // Mock common dependencies
        $this->request = $this->createMock(Request::class);
        $this->response = $this->createMock(Response::class);
        $this->messageManager = $this->createMock(MessageManager::class);
        $this->template = $this->createMock(Template::class);
    }

    protected function tearDown(): void
    {
        $this->request = null;
        $this->response = null;
        $this->messageManager = null;
        $this->template = null;
        
        parent::tearDown();
    }

    /**
     * 创建控制器Mock
     * 
     * @param string $controllerClass 控制器类名
     * @param array $methods 需要Mock的方法
     * @return mixed
     */
    protected function createControllerMock(string $controllerClass, array $methods = []): mixed
    {
        $builder = $this->getMockBuilder($controllerClass)
            ->disableOriginalConstructor();
        
        if (!empty($methods)) {
            $builder->onlyMethods($methods);
        }
        
        return $builder->getMock();
    }

    /**
     * 设置Request参数
     */
    protected function setRequestParam(string $key, mixed $value): void
    {
        $this->request->expects($this->any())
            ->method('getParam')
            ->with($key)
            ->willReturn($value);
    }

    /**
     * 设置Request POST数据
     */
    protected function setRequestPost(string $key, mixed $value): void
    {
        $this->request->expects($this->any())
            ->method('getPost')
            ->with($key)
            ->willReturn($value);
    }
}

<?php

declare(strict_types=1);

namespace WeShop\Frontend\Test\Unit\Controller;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Http\Request;
use Weline\Framework\Http\Response;
use Weline\Framework\Manager\MessageManager;
use Weline\Framework\View\Template;

abstract class BaseControllerTestCase extends TestCase
{
    protected ?Request $request = null;
    protected ?Response $response = null;
    protected ?MessageManager $messageManager = null;
    protected ?Template $template = null;

    protected function setUp(): void
    {
        parent::setUp();

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

    protected function createControllerMock(string $controllerClass, array $methods = []): mixed
    {
        $builder = $this->getMockBuilder($controllerClass)
            ->disableOriginalConstructor();

        if ($methods !== []) {
            $builder->onlyMethods($methods);
        }

        return $builder->getMock();
    }

    protected function setRequestParam(string $key, mixed $value): void
    {
        $this->request->expects($this->any())
            ->method('getParam')
            ->with($key)
            ->willReturn($value);
    }

    protected function setRequestPost(string $key, mixed $value): void
    {
        $this->request->expects($this->any())
            ->method('getPost')
            ->with($key)
            ->willReturn($value);
    }
}

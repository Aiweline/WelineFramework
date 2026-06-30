<?php

declare(strict_types=1);

namespace Weline\Customer\Test\Unit\Controller\Account;

use PHPUnit\Framework\TestCase;
use Weline\Customer\Api\CustomerLoginChallengeHandlerInterface;
use Weline\Customer\Controller\Account\Challenge;
use Weline\Framework\Http\Request;
use Weline\Framework\Manager\MessageManager;
use Weline\Framework\View\Template;

class ChallengeViewRoutingTest extends TestCase
{
    public function testGetIndexRedirectsWhenTokenIsMissing(): void
    {
        $handler = $this->createMock(CustomerLoginChallengeHandlerInterface::class);
        $handler->expects($this->never())->method('getChallengeExpiresAt');

        $controller = $this->getMockBuilder(Challenge::class)
            ->setConstructorArgs([$this->createMock(Template::class), $handler])
            ->onlyMethods(['redirect', 'getMessageManager'])
            ->getMock();

        $messageManager = $this->createMock(MessageManager::class);
        $messageManager->expects($this->once())->method('addError');
        $controller->expects($this->once())->method('getMessageManager')->willReturn($messageManager);
        $controller->expects($this->once())->method('redirect')->with('/customer/account/login')->willReturn('redirected');

        $request = $this->createMock(Request::class);
        $request->method('getParam')->with('challenge_token')->willReturn('');
        $this->setProtectedProperty($controller, 'request', $request);

        $this->assertSame('redirected', $controller->getIndex());
    }

    public function testGetIndexUsesChallengeTemplateWhenChallengeExists(): void
    {
        $handler = $this->createMock(CustomerLoginChallengeHandlerInterface::class);
        $handler->expects($this->once())->method('getChallengeExpiresAt')->with('token-123')->willReturn(1730000000);

        $controller = $this->getMockBuilder(Challenge::class)
            ->setConstructorArgs([$this->createMock(Template::class), $handler])
            ->onlyMethods(['assign', 'fetch', 'redirect'])
            ->getMock();
        $assignCalls = 0;
        $controller->expects($this->exactly(3))
            ->method('assign')
            ->willReturnCallback(function (string $key, mixed $value) use (&$assignCalls, $controller): Challenge {
                $expectedKeys = [
                    'challenge_token',
                    'expires_at',
                    'title',
                ];
                TestCase::assertSame($expectedKeys[$assignCalls], $key);
                $assignCalls++;
                return $controller;
            });
        $controller->expects($this->once())
            ->method('fetch')
            ->with('Weline_Customer::templates/frontend/account/challenge.phtml')
            ->willReturn('challenge-page');
        $controller->expects($this->never())->method('redirect');

        $request = $this->createMock(Request::class);
        $request->method('getParam')->with('challenge_token')->willReturn('token-123');
        $this->setProtectedProperty($controller, 'request', $request);

        $this->assertSame('challenge-page', $controller->getIndex());
    }

    private function setProtectedProperty(object $target, string $property, mixed $value): void
    {
        $reflection = new \ReflectionObject($target);
        while (!$reflection->hasProperty($property) && ($reflection = $reflection->getParentClass())) {
        }

        $reflectionProperty = $reflection->getProperty($property);
        $reflectionProperty->setAccessible(true);
        $reflectionProperty->setValue($target, $value);
    }
}

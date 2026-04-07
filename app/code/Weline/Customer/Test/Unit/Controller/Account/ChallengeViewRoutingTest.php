<?php

declare(strict_types=1);

namespace Weline\Customer\Test\Unit\Controller\Account;

use PHPUnit\Framework\TestCase;
use WeShop\Auth\Model\PendingAuthChallenge;
use WeShop\Customer\Service\CustomerWebAuthService;
use Weline\Customer\Controller\Account\Challenge;
use Weline\Framework\Http\Request;
use Weline\Framework\Manager\MessageManager;
use Weline\Framework\View\Template;

class ChallengeViewRoutingTest extends TestCase
{
    public function testGetIndexRedirectsWhenTokenIsMissing(): void
    {
        $service = $this->createMock(CustomerWebAuthService::class);
        $service->expects($this->never())->method('getChallenge');

        $controller = $this->getMockBuilder(Challenge::class)
            ->setConstructorArgs([$this->createMock(Template::class), $service])
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
        $challenge = $this->getMockBuilder(PendingAuthChallenge::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getData'])
            ->getMock();
        $challenge->method('getData')->with(PendingAuthChallenge::schema_fields_EXPIRES_AT)->willReturn(1730000000);

        $service = $this->createMock(CustomerWebAuthService::class);
        $service->expects($this->once())->method('getChallenge')->with('token-123')->willReturn($challenge);

        $controller = $this->getMockBuilder(Challenge::class)
            ->setConstructorArgs([$this->createMock(Template::class), $service])
            ->onlyMethods(['assign', 'fetch', 'redirect'])
            ->getMock();
        $assignCalls = 0;
        $controller->expects($this->exactly(5))
            ->method('assign')
            ->willReturnCallback(function (string $key, mixed $value) use (&$assignCalls, $controller): Challenge {
                $expectedKeys = [
                    'challenge_token',
                    'expires_at',
                    'title',
                    'error_message',
                    'success_message',
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

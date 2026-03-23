<?php

declare(strict_types=1);

namespace WeShop\Auth\Test\Unit\Api\Rest\V1\Auth;

use PHPUnit\Framework\TestCase;
use WeShop\Auth\Api\Rest\V1\Auth\Challenge;
use WeShop\Auth\Service\AuthGrantService;
use Weline\Framework\Http\Request;

class ChallengeControllerTest extends TestCase
{
    public function testPostVerifyUsesChallengeGrantService(): void
    {
        $authGrantService = $this->createMock(AuthGrantService::class);
        $authGrantService->expects($this->once())
            ->method('verifyChallenge')
            ->with('challenge-token-1', '123456')
            ->willReturn([
                'status' => 'authenticated',
                'access_token' => 'access-token-1',
            ]);

        $request = $this->createMock(Request::class);
        $request->method('getBodyParam')->willReturnCallback(static function (string $key) {
            return match ($key) {
                'challenge_token' => 'challenge-token-1',
                'code' => '123456',
                default => null,
            };
        });
        $request->method('getPost')->willReturn(null);

        $controller = $this->getMockBuilder(Challenge::class)
            ->setConstructorArgs([$authGrantService])
            ->onlyMethods(['success', 'exception'])
            ->getMock();
        $controller->expects($this->once())
            ->method('success')
            ->with(
                $this->stringContains('Challenge verification succeeded'),
                $this->callback(static fn (array $data): bool => ($data['access_token'] ?? '') === 'access-token-1')
            )
            ->willReturn('challenge-ok');
        $controller->expects($this->never())->method('exception');

        $this->setProtectedProperty($controller, 'request', $request);

        $this->assertSame('challenge-ok', $controller->postVerify());
    }

    public function testPostVerifyRequiresChallengeTokenAndCode(): void
    {
        $authGrantService = $this->createMock(AuthGrantService::class);
        $authGrantService->expects($this->never())->method('verifyChallenge');

        $request = $this->createMock(Request::class);
        $request->method('getBodyParam')->willReturn(null);
        $request->method('getPost')->willReturn(null);

        $controller = $this->getMockBuilder(Challenge::class)
            ->setConstructorArgs([$authGrantService])
            ->onlyMethods(['success', 'exception'])
            ->getMock();
        $controller->expects($this->never())->method('success');
        $controller->expects($this->once())
            ->method('exception')
            ->with(
                $this->callback(static fn (\Throwable $throwable): bool => $throwable instanceof \InvalidArgumentException),
                $this->stringContains('Challenge verification failed')
            )
            ->willReturn('challenge-error');

        $this->setProtectedProperty($controller, 'request', $request);

        $this->assertSame('challenge-error', $controller->postVerify());
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

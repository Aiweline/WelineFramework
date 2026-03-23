<?php

declare(strict_types=1);

namespace WeShop\Auth\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use WeShop\Auth\Data\ActorContext;
use WeShop\Auth\Service\GoogleCodeAuthenticator;
use WeShop\GoogleAuth\Service\GoogleLoginService;

class GoogleCodeAuthenticatorTest extends TestCase
{
    public function testAuthenticateBuildsActorContextFromGoogleLoginService(): void
    {
        $googleLoginService = $this->createMock(GoogleLoginService::class);
        $googleLoginService->expects($this->once())
            ->method('authenticateByCode')
            ->with('backend', 'google-code-1')
            ->willReturn([
                'actor_type' => ActorContext::ACTOR_BACKEND,
                'actor_id' => 51,
                'scopes' => ['backend'],
            ]);

        $service = new GoogleCodeAuthenticator($googleLoginService);
        $context = $service->authenticate('backend', 'google-code-1');

        $this->assertInstanceOf(ActorContext::class, $context);
        $this->assertSame(ActorContext::ACTOR_BACKEND, $context->getActorType());
        $this->assertSame(51, $context->getActorId());
        $this->assertSame('backend', $context->getArea());
        $this->assertSame(['backend'], $context->getScopes());
    }

    public function testAuthenticateRejectsMissingGoogleActorIdentity(): void
    {
        $googleLoginService = $this->createMock(GoogleLoginService::class);
        $googleLoginService->expects($this->once())
            ->method('authenticateByCode')
            ->with('frontend', 'google-code-2')
            ->willReturn([
                'actor_type' => '',
                'actor_id' => 0,
                'scopes' => [],
            ]);

        $service = new GoogleCodeAuthenticator($googleLoginService);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Google login failed');

        $service->authenticate('frontend', 'google-code-2');
    }
}

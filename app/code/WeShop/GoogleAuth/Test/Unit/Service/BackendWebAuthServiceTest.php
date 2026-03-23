<?php

declare(strict_types=1);

namespace WeShop\GoogleAuth\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use WeShop\Auth\Data\ActorContext;
use WeShop\Auth\Model\PendingAuthChallenge;
use WeShop\Auth\Service\PendingAuthChallengeService;
use WeShop\Auth\Service\WeShopAuth2FAOrchestrator;
use WeShop\GoogleAuth\Service\BackendWebAuthService;
use Weline\Backend\Model\BackendUser;
use Weline\Backend\Model\BackendUserToken;
use Weline\Backend\Service\MenuServiceInterface;
use Weline\Framework\Http\Request;
use Weline\Framework\Http\Url;

class BackendWebAuthServiceTest extends TestCase
{
    public function testGetChallengeRejectsNonBackendChallenge(): void
    {
        $challenge = $this->createMock(PendingAuthChallenge::class);
        $challenge->expects($this->once())
            ->method('getData')
            ->with(PendingAuthChallenge::schema_fields_ACTOR_TYPE)
            ->willReturn(ActorContext::ACTOR_CUSTOMER);

        $pendingAuthChallengeService = $this->createMock(PendingAuthChallengeService::class);
        $pendingAuthChallengeService->expects($this->once())
            ->method('getValidChallenge')
            ->with('challenge-token')
            ->willReturn($challenge);

        $service = $this->createService($pendingAuthChallengeService);

        $this->assertNull($service->getChallenge('challenge-token'));
    }

    public function testGetChallengeReturnsBackendChallenge(): void
    {
        $challenge = $this->createMock(PendingAuthChallenge::class);
        $challenge->expects($this->once())
            ->method('getData')
            ->with(PendingAuthChallenge::schema_fields_ACTOR_TYPE)
            ->willReturn(ActorContext::ACTOR_BACKEND);

        $pendingAuthChallengeService = $this->createMock(PendingAuthChallengeService::class);
        $pendingAuthChallengeService->expects($this->once())
            ->method('getValidChallenge')
            ->with('challenge-token')
            ->willReturn($challenge);

        $service = $this->createService($pendingAuthChallengeService);

        $this->assertSame($challenge, $service->getChallenge('challenge-token'));
    }

    private function createService(PendingAuthChallengeService $pendingAuthChallengeService): BackendWebAuthService
    {
        return new BackendWebAuthService(
            $this->createMock(WeShopAuth2FAOrchestrator::class),
            $pendingAuthChallengeService,
            $this->createMock(MenuServiceInterface::class),
            $this->createMock(BackendUserToken::class),
            $this->createMock(BackendUser::class),
            $this->createMock(Url::class),
            $this->createMock(Request::class)
        );
    }
}

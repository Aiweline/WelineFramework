<?php

declare(strict_types=1);

namespace WeShop\Auth\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use WeShop\Auth\Service\IntegrationCredentialAuthenticator;
use Weline\Api\Model\ApiUser;

class IntegrationCredentialAuthenticatorTest extends TestCase
{
    public function testAuthenticateReturnsEnabledIntegrationUserWithValidSecret(): void
    {
        $apiUser = $this->createApiUserMock();
        $apiUser->method('getId')->willReturn(77);
        $apiUser->method('verifySecret')->willReturn(true);
        $apiUser->method('getIsEnabled')->willReturn(true);

        $service = new IntegrationCredentialAuthenticator($apiUser);

        $this->assertSame($apiUser, $service->authenticate('api-key-77', 'api-secret-77'));
    }

    public function testAuthenticateRejectsInvalidSecret(): void
    {
        $apiUser = $this->createApiUserMock();
        $apiUser->method('getId')->willReturn(77);
        $apiUser->method('verifySecret')->willReturn(false);
        $apiUser->method('getIsEnabled')->willReturn(true);

        $service = new IntegrationCredentialAuthenticator($apiUser);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Invalid integration credentials');

        $service->authenticate('api-key-77', 'api-secret-77');
    }

    private function createApiUserMock(): ApiUser
    {
        $apiUser = $this->getMockBuilder(ApiUser::class)
            ->disableOriginalConstructor()
            ->addMethods(['reset', 'where', 'find', 'fetch'])
            ->onlyMethods(['getId', 'verifySecret', 'getIsEnabled'])
            ->getMock();
        $apiUser->method('reset')->willReturnSelf();
        $apiUser->method('where')->willReturnSelf();
        $apiUser->method('find')->willReturnSelf();
        $apiUser->method('fetch')->willReturnSelf();

        return $apiUser;
    }
}

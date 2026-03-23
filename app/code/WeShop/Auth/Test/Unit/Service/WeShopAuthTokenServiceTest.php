<?php

declare(strict_types=1);

namespace WeShop\Auth\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use WeShop\Auth\Data\ActorContext;
use WeShop\Auth\Model\AuthToken;
use WeShop\Auth\Service\WeShopAuthTokenService;

class WeShopAuthTokenServiceTest extends TestCase
{
    public function testCreateTokenPairStoresActorAreaOnAccessAndRefreshTokens(): void
    {
        $revokeRecord = $this->getMockBuilder(AuthToken::class)
            ->disableOriginalConstructor()
            ->addMethods(['where', 'delete', 'fetch'])
            ->getMock();
        $revokeRecord->method('where')->willReturnSelf();
        $revokeRecord->method('delete')->willReturnSelf();
        $revokeRecord->method('fetch')->willReturnSelf();

        $accessData = [];
        $accessRecord = $this->createMutableRecordMock($accessData);

        $refreshData = [];
        $refreshRecord = $this->createMutableRecordMock($refreshData);

        $authToken = $this->getMockBuilder(AuthToken::class)
            ->disableOriginalConstructor()
            ->addMethods(['reset'])
            ->getMock();
        $authToken->expects($this->exactly(3))
            ->method('reset')
            ->willReturnOnConsecutiveCalls($revokeRecord, $accessRecord, $refreshRecord);

        $service = new WeShopAuthTokenService($authToken);
        $tokens = $service->createTokenPair(
            new ActorContext(ActorContext::ACTOR_CUSTOMER, 12, 'frontend', ['customer'], true)
        );

        $this->assertSame('frontend', $accessData[AuthToken::schema_fields_AREA] ?? null);
        $this->assertSame('frontend', $refreshData[AuthToken::schema_fields_AREA] ?? null);
        $this->assertSame('customer', $accessData[AuthToken::schema_fields_ACTOR_TYPE] ?? null);
        $this->assertSame('customer', $refreshData[AuthToken::schema_fields_ACTOR_TYPE] ?? null);
        $this->assertSame($accessData[AuthToken::schema_fields_TOKEN] ?? null, $tokens['access_token']);
        $this->assertSame($refreshData[AuthToken::schema_fields_TOKEN] ?? null, $tokens['refresh_token']);
    }

    public function testResolveAccessTokenUsesStoredArea(): void
    {
        $record = $this->getMockBuilder(AuthToken::class)
            ->disableOriginalConstructor()
            ->addMethods(['where', 'find', 'fetch'])
            ->onlyMethods(['getId', 'isExpired', 'isRevoked', 'getData', 'getScopes'])
            ->getMock();
        $record->method('where')->willReturnSelf();
        $record->method('find')->willReturnSelf();
        $record->method('fetch')->willReturnSelf();
        $record->method('getId')->willReturn(9);
        $record->method('isExpired')->willReturn(false);
        $record->method('isRevoked')->willReturn(false);
        $record->method('getScopes')->willReturn(['backend']);
        $record->method('getData')->willReturnCallback(static function (string $key) {
            return match ($key) {
                AuthToken::schema_fields_ACTOR_TYPE => ActorContext::ACTOR_BACKEND,
                AuthToken::schema_fields_ACTOR_ID => 9,
                AuthToken::schema_fields_AREA => 'backend',
                AuthToken::schema_fields_IS_2FA_VERIFIED => 1,
                default => null,
            };
        });

        $authToken = $this->getMockBuilder(AuthToken::class)
            ->disableOriginalConstructor()
            ->addMethods(['reset'])
            ->getMock();
        $authToken->expects($this->once())
            ->method('reset')
            ->willReturn($record);

        $service = new WeShopAuthTokenService($authToken);
        $context = $service->resolveAccessToken('access-token');

        $this->assertInstanceOf(ActorContext::class, $context);
        $this->assertSame('backend', $context?->getArea());
        $this->assertSame(['backend'], $context?->getScopes());
        $this->assertTrue((bool) $context?->is2faVerified());
    }

    public function testRefreshPreservesStoredAreaInNewTokenPair(): void
    {
        $lookupRecord = $this->getMockBuilder(AuthToken::class)
            ->disableOriginalConstructor()
            ->addMethods(['where', 'find', 'fetch'])
            ->onlyMethods(['getId', 'isExpired', 'isRevoked', 'getData', 'getScopes'])
            ->getMock();
        $lookupRecord->method('where')->willReturnSelf();
        $lookupRecord->method('find')->willReturnSelf();
        $lookupRecord->method('fetch')->willReturnSelf();
        $lookupRecord->method('getId')->willReturn(3);
        $lookupRecord->method('isExpired')->willReturn(false);
        $lookupRecord->method('isRevoked')->willReturn(false);
        $lookupRecord->method('getScopes')->willReturn(['integration']);
        $lookupRecord->method('getData')->willReturnCallback(static function (string $key) {
            return match ($key) {
                AuthToken::schema_fields_ACTOR_TYPE => ActorContext::ACTOR_INTEGRATION,
                AuthToken::schema_fields_ACTOR_ID => 77,
                AuthToken::schema_fields_AREA => 'integration',
                AuthToken::schema_fields_IS_2FA_VERIFIED => 1,
                default => null,
            };
        });

        $revokeRecord = $this->getMockBuilder(AuthToken::class)
            ->disableOriginalConstructor()
            ->addMethods(['where', 'delete', 'fetch'])
            ->getMock();
        $revokeRecord->method('where')->willReturnSelf();
        $revokeRecord->method('delete')->willReturnSelf();
        $revokeRecord->method('fetch')->willReturnSelf();

        $accessData = [];
        $accessRecord = $this->createMutableRecordMock($accessData);

        $refreshData = [];
        $refreshRecord = $this->createMutableRecordMock($refreshData);

        $authToken = $this->getMockBuilder(AuthToken::class)
            ->disableOriginalConstructor()
            ->addMethods(['reset'])
            ->getMock();
        $authToken->expects($this->exactly(4))
            ->method('reset')
            ->willReturnOnConsecutiveCalls($lookupRecord, $revokeRecord, $accessRecord, $refreshRecord);

        $service = new WeShopAuthTokenService($authToken);
        $tokens = $service->refresh('refresh-token');

        $this->assertIsArray($tokens);
        $this->assertSame('integration', $accessData[AuthToken::schema_fields_AREA] ?? null);
        $this->assertSame('integration', $refreshData[AuthToken::schema_fields_AREA] ?? null);
        $this->assertSame($accessData[AuthToken::schema_fields_TOKEN] ?? null, $tokens['access_token']);
    }

    private function createMutableRecordMock(array &$data): AuthToken
    {
        $record = $this->getMockBuilder(AuthToken::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['clearData', 'setData', 'setScopes', 'save', 'getData'])
            ->getMock();

        $record->method('clearData')->willReturnSelf();
        $record->method('setData')->willReturnCallback(function (string $key, mixed $value) use (&$data, $record) {
            $data[$key] = $value;
            return $record;
        });
        $record->method('setScopes')->willReturnCallback(function (array $scopes) use (&$data, $record) {
            $data[AuthToken::schema_fields_SCOPES] = array_values(array_unique($scopes));
            return $record;
        });
        $record->method('save')->willReturn(1);
        $record->method('getData')->willReturnCallback(static function (string $key) use (&$data) {
            return $data[$key] ?? null;
        });

        return $record;
    }
}

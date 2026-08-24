<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Service\Query;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Service\Query\Auth\BinQueryAuthContext;
use Weline\Framework\Service\Query\Auth\BinQueryAuthenticatorInterface;
use Weline\Framework\Service\Query\BinQueryCachePolicy;
use Weline\Framework\Service\Query\BinQueryGateway;
use Weline\Framework\Service\Query\FrameworkQueryService;
use Weline\Framework\Service\Query\FrontendQueryException;
use Weline\Framework\Service\Query\QueryProviderRegistry;

final class BinQueryGatewayAuthenticatorTest extends TestCase
{
    public function testConnectUsesFrameworkAuthContractAndAdvertisesV1Negotiation(): void
    {
        $registry = $this->createMock(QueryProviderRegistry::class);
        $registry->method('getAllDescriptors')->willReturn([]);
        $authenticator = new class implements BinQueryAuthenticatorInterface {
            public function authenticate(string $token): ?BinQueryAuthContext
            {
                return $token === 'valid-key'
                    ? new BinQueryAuthContext([[
                        'source_id' => 'binquery',
                        'access_mode' => 'read',
                    ]])
                    : null;
            }
        };
        $gateway = new BinQueryGateway(
            $this->createMock(FrameworkQueryService::class),
            $registry,
            new BinQueryCachePolicy(),
            $authenticator,
        );

        $response = $gateway->execute(['type' => 'connect'], 'Bearer valid-key');

        self::assertSame('binquery-v1', $response['data']['negotiation']['selected']);
        self::assertSame(['binquery-v1'], $response['data']['negotiation']['supported']);
        self::assertSame(['binquery-v2'], $response['data']['negotiation']['reserved']);
        self::assertSame(1, $response['data']['scope_count']);
    }

    public function testInvalidProviderResultDoesNotExposeProviderInternals(): void
    {
        $authenticator = new class implements BinQueryAuthenticatorInterface {
            public function authenticate(string $token): ?BinQueryAuthContext
            {
                throw new \RuntimeException('database host and credentials must stay private');
            }
        };
        $gateway = new BinQueryGateway(
            $this->createMock(FrameworkQueryService::class),
            $this->createMock(QueryProviderRegistry::class),
            new BinQueryCachePolicy(),
            $authenticator,
        );

        try {
            $gateway->execute(['type' => 'connect'], 'valid-key');
            self::fail('Expected authentication failure.');
        } catch (FrontendQueryException $exception) {
            self::assertSame('Unable to validate BinQuery API key.', $exception->getMessage());
            self::assertStringNotContainsString('database host', $exception->getMessage());
        }
    }
}

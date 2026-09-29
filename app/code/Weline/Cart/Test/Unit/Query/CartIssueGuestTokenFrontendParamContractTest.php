<?php

declare(strict_types=1);

namespace Weline\Cart\Test\Unit\Query;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Service\Query\FrontendQueryException;
use Weline\Framework\Service\Query\FrontendQueryGateway;
use Weline\Framework\Service\Query\QueryProviderRegistry;

/**
 * UC-regression-cookie-empty: FrontendQueryGateway must accept optional
 * params.guest_token on cart.issueGuestToken (no Unknown frontend worker param).
 */
final class CartIssueGuestTokenFrontendParamContractTest extends TestCase
{
    public function testIssueGuestTokenDescriptorDeclaresGuestToken(): void
    {
        $src = (string)file_get_contents(
            dirname(__DIR__, 3)
            . '/extends/module/Weline_Framework/Query/CartQueryProvider.php'
        );
        self::assertMatchesRegularExpression(
            "/'name' => 'issueGuestToken'[\s\S]*?'params' => \\\$this->guestTokenParam\(\)/",
            $src,
        );
    }

    public function testCompiledRegistryAndGatewayAcceptGuestTokenParam(): void
    {
        $registry = new QueryProviderRegistry();
        $descriptor = $registry->getOperationDescriptor('cart', 'issueGuestToken');
        self::assertIsArray($descriptor);
        self::assertTrue(($descriptor['frontend'] ?? false) === true);
        self::assertArrayHasKey('guest_token', $descriptor['params'] ?? []);
        self::assertSame('string', (string)($descriptor['params']['guest_token']['type'] ?? ''));

        $gateway = (new \ReflectionClass(FrontendQueryGateway::class))->newInstanceWithoutConstructor();
        $validate = new \ReflectionMethod(FrontendQueryGateway::class, 'validateParams');
        $validate->setAccessible(true);

        try {
            $normalized = $validate->invoke(
                $gateway,
                ['guest_token' => 'f469a7c915b971724fc75ced907e90b5'],
                $descriptor,
            );
        } catch (FrontendQueryException $e) {
            self::fail(
                'FrontendQueryGateway must not reject guest_token on issueGuestToken: '
                . $e->getMessage()
            );
        }

        self::assertSame('f469a7c915b971724fc75ced907e90b5', $normalized['guest_token'] ?? null);
    }
}

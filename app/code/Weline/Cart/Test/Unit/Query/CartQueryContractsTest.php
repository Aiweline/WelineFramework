<?php

declare(strict_types=1);

namespace Weline\Cart\Test\Unit\Query;

use PHPUnit\Framework\TestCase;
use Weline\Cart\Extends\Module\Weline_Framework\Query\CartQueryProvider;
use Weline\Cart\Service\CartCurrentCustomerResolver;
use Weline\Cart\Service\CartScopeResolver;
use Weline\Cart\Service\CartService;
use Weline\Framework\Service\Query\FrontendQueryException;
use Weline\Framework\Service\Query\FrontendQueryGateway;
use Weline\Framework\Service\Query\QueryProviderRegistry;

/**
 * Aggregated contract suite (merged from 3 *ContractTest files).
 * Observe one overall pass/fail via testAllCartQueryContractsPass().
 */
final class CartQueryContractsTest extends TestCase
{
    public function testAllCartQueryContractsPass(): void
    {
        $failures = [];
        foreach ($this->contractChecks() as $name => $fn) {
            try {
                $fn();
            } catch (\Throwable $e) {
                $failures[] = $name . ": " . $e->getMessage();
            }
        }
        self::assertSame([], $failures, implode("\n", $failures));
    }

    /** @return array<string, callable(): void> */
    private function contractChecks(): array
    {
        return [
            'CartIssueGuestTokenFrontendParam::testIssueGuestTokenDescriptorDeclaresGuestToken' => function () {
            $this->assert_CartIssueGuestTokenFrontendParam_testIssueGuestTokenDescriptorDeclaresGuestToken();
        },
            'CartIssueGuestTokenFrontendParam::testCompiledRegistryAndGatewayAcceptGuestTokenParam' => function () {
            $this->assert_CartIssueGuestTokenFrontendParam_testCompiledRegistryAndGatewayAcceptGuestTokenParam();
        },
            'CartMiniDrawerQuery::testMiniItemsDeclaresHumanAttackRuleWithoutCache' => function () {
            $this->assert_CartMiniDrawerQuery_testMiniItemsDeclaresHumanAttackRuleWithoutCache();
        },
            'CartQueryProviderDiscountPreviewRecursion::testEnrichPassesExistingSummaryIntoDiscountPreview' => function () {
            $this->assert_CartQueryProviderDiscountPreviewRecursion_testEnrichPassesExistingSummaryIntoDiscountPreview();
        },
        ];
    }

private function assert_CartIssueGuestTokenFrontendParam_testIssueGuestTokenDescriptorDeclaresGuestToken(): void
{
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Cart' . '/extends/module/Weline_Framework/Query/CartQueryProvider.php');
    self::assertMatchesRegularExpression("/'name' => 'issueGuestToken'[\\s\\S]*?'params' => \\\$this->guestTokenParam\\(\\)/", $src);
}

private function assert_CartIssueGuestTokenFrontendParam_testCompiledRegistryAndGatewayAcceptGuestTokenParam(): void
{
    $registry = new QueryProviderRegistry();
    $descriptor = $registry->getOperationDescriptor('cart', 'issueGuestToken');
    self::assertIsArray($descriptor);
    self::assertTrue(($descriptor['frontend'] ?? false) === true);
    self::assertArrayHasKey('guest_token', $descriptor['params'] ?? []);
    self::assertSame('string', (string) ($descriptor['params']['guest_token']['type'] ?? ''));
    $gateway = (new \ReflectionClass(FrontendQueryGateway::class))->newInstanceWithoutConstructor();
    $validate = new \ReflectionMethod(FrontendQueryGateway::class, 'validateParams');
    $validate->setAccessible(true);
    try {
        $normalized = $validate->invoke($gateway, ['guest_token' => 'f469a7c915b971724fc75ced907e90b5'], $descriptor);
    } catch (FrontendQueryException $e) {
        self::fail('FrontendQueryGateway must not reject guest_token on issueGuestToken: ' . $e->getMessage());
    }
    self::assertSame('f469a7c915b971724fc75ced907e90b5', $normalized['guest_token'] ?? null);
}

private function assert_CartMiniDrawerQuery_testMiniItemsDeclaresHumanAttackRuleWithoutCache(): void
{
    $provider = new CartQueryProvider($this->createMock(CartService::class), new CartScopeResolver(), new CartCurrentCustomerResolver(static fn(): ?int => null));
    $operations = [];
    foreach ($provider->getDescriptor()['operations'] as $operation) {
        $operations[(string) $operation['name']] = $operation;
    }
    self::assertArrayHasKey('miniItems', $operations);
    self::assertSame('read', $operations['miniItems']['mode']);
    self::assertArrayNotHasKey('cache', $operations['miniItems']);
    self::assertArrayHasKey('guest_token', $operations['miniItems']['params']);
    self::assertArrayHasKey('guest_token', $operations['count']['params']);
    self::assertSame(['enabled' => true, 'rate_limit' => '5/10s', 'challenge' => 'human', 'description' => '迷你购物车抽屉异步加载限流'], $operations['miniItems']['attack']);
}

private function assert_CartQueryProviderDiscountPreviewRecursion_testEnrichPassesExistingSummaryIntoDiscountPreview(): void
{
    $source = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Cart' . '/extends/module/Weline_Framework/Query/CartQueryProvider.php');
    self::assertStringContainsString('function buildDiscountPreview(array $params, ?array $existingSummary = null)', $source);
    self::assertStringContainsString('$existingSummary ?? $this->resolveStorefrontSummary($params)', $source);
    self::assertStringContainsString('buildDiscountPreview($params, $summary)', $source);
    self::assertStringContainsString('enrichSummaryWithDiscountPreview', $source);
    self::assertStringContainsString('try {', $source);
    // getCart must accept coupon_code so MiniCart can request discount_preview without session lag.
    self::assertStringContainsString("'name' => 'getCart'", $source);
    self::assertMatchesRegularExpression("/'name'\\s*=>\\s*'getCart'[\\s\\S]*?'coupon_code'\\s*=>\\s*\\['type'\\s*=>\\s*'string'/", $source);
    // update/remove share mutationParams; MiniCart cartQueryParams may pass pending coupon.
    self::assertStringContainsString('function couponCodeParam()', $source);
    self::assertMatchesRegularExpression("/'name'\\s*=>\\s*'summary'[\\s\\S]*?couponCodeParam\\(\\)/", $source);
    self::assertMatchesRegularExpression("/function mutationParams\\(bool \\\$includeQty = false\\): array[\\s\\S]*?couponCodeParam\\(\\)/", $source);
}
}

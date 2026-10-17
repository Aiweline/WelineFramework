<?php

declare(strict_types=1);

namespace Weline\Cart\Test\Unit\Api;

use PHPUnit\Framework\TestCase;
use Weline\Cart\Api\CommerceCartOfferRoutingInterface;

/**
 * Aggregated contract suite (merged from 1 *ContractTest files).
 * Observe one overall pass/fail via testAllCartApiContractsPass().
 */
final class CartApiContractsTest extends TestCase
{
    public function testAllCartApiContractsPass(): void
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
            'CommerceCartOfferRouting::testInterfaceDeclaresResolveAddCartType' => function () {
            $this->assert_CommerceCartOfferRouting_testInterfaceDeclaresResolveAddCartType();
        },
            'CommerceCartOfferRouting::testCartServiceRemapsViaOfferRoutingHook' => function () {
            $this->assert_CommerceCartOfferRouting_testCartServiceRemapsViaOfferRoutingHook();
        },
        ];
    }

private function assert_CommerceCartOfferRouting_testInterfaceDeclaresResolveAddCartType(): void
{
    self::assertTrue(interface_exists(CommerceCartOfferRoutingInterface::class));
    $ref = new \ReflectionClass(CommerceCartOfferRoutingInterface::class);
    self::assertTrue($ref->hasMethod('resolveAddCartType'));
}

private function assert_CommerceCartOfferRouting_testCartServiceRemapsViaOfferRoutingHook(): void
{
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Cart' . '/Service/CartService.php');
    self::assertStringContainsString('function remapCartTypeForOffer(', $src);
    self::assertStringContainsString('CommerceCartOfferRoutingInterface::class', $src);
    self::assertStringContainsString('$cartType = $this->remapCartTypeForOffer($cartType, $snapshot, $scope, $customerId);', $src);
}
}

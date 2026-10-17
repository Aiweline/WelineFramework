<?php

declare(strict_types=1);

namespace Weline\Customer\Test\Unit\Query;

use PHPUnit\Framework\TestCase;


/**
 * Aggregated contract suite (merged from 1 *ContractTest files).
 * Observe one overall pass/fail via testAllCustomerQueryContractsPass().
 */
final class CustomerQueryContractsTest extends TestCase
{
    public function testAllCustomerQueryContractsPass(): void
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
            'AccountQueryProviderGuestCurrent::testGuestCurrentReturnsSuccessShapeLikeMenuSignals' => function () {
            $this->assert_AccountQueryProviderGuestCurrent_testGuestCurrentReturnsSuccessShapeLikeMenuSignals();
        },
        ];
    }

private function assert_AccountQueryProviderGuestCurrent_testGuestCurrentReturnsSuccessShapeLikeMenuSignals(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/Customer' . '/extends/module/Weline_Framework/Query/AccountQueryProvider.php';
    self::assertFileExists($path);
    $source = (string) file_get_contents($path);
    self::assertStringContainsString("return \$this->success('Not signed in.', [", $source);
    self::assertStringContainsString("'isLogin' => false,", $source);
    self::assertStringContainsString("'logged_in' => false,", $source);
    self::assertStringContainsString("'user' => null,", $source);
    self::assertDoesNotMatchRegularExpression("/'success'\\s*=>\\s*false[\\s\\S]{0,160}'message'\\s*=>\\s*'Not signed in\\.'/", $source);
}
}

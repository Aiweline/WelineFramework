<?php

declare(strict_types=1);

namespace Weline\Smtp\Test\Unit\Controller;

use PHPUnit\Framework\TestCase;
use Weline\Smtp\Controller\Router;

/**
 * Aggregated contract suite (merged from 1 *ContractTest files).
 * Observe one overall pass/fail via testAllSmtpControllerContractsPass().
 */
final class SmtpControllerContractsTest extends TestCase
{
    public function testAllSmtpControllerContractsPass(): void
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
            'LegacyFrontNameRouter::testRewritesLegacyWelineSmtpFrontName' => function () {
            $this->assert_LegacyFrontNameRouter_testRewritesLegacyWelineSmtpFrontName();
        },
            'LegacyFrontNameRouter::testIgnoresAlreadyMatchedRules' => function () {
            $this->assert_LegacyFrontNameRouter_testIgnoresAlreadyMatchedRules();
        },
            'LegacyFrontNameRouter::testLeavesCanonicalSmtpUntouched' => function () {
            $this->assert_LegacyFrontNameRouter_testLeavesCanonicalSmtpUntouched();
        },
        ];
    }

private function assert_LegacyFrontNameRouter_testRewritesLegacyWelineSmtpFrontName(): void
{
    $path = 'weline_smtp/backend/template/edit';
    $rule = [];
    Router::process($path, $rule);
    self::assertSame('smtp/backend/template/edit', $path);
    self::assertArrayNotHasKey('module', $rule);
}

private function assert_LegacyFrontNameRouter_testIgnoresAlreadyMatchedRules(): void
{
    $path = 'weline_smtp/backend/template/edit';
    $rule = ['module' => 'Other'];
    Router::process($path, $rule);
    self::assertSame('weline_smtp/backend/template/edit', $path);
}

private function assert_LegacyFrontNameRouter_testLeavesCanonicalSmtpUntouched(): void
{
    $path = 'smtp/backend/template/edit';
    $rule = [];
    Router::process($path, $rule);
    self::assertSame('smtp/backend/template/edit', $path);
}
}

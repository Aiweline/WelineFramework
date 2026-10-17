<?php

declare(strict_types=1);

namespace Weline\Marketing\Test\Unit\View\Backend\Rule;

use PHPUnit\Framework\TestCase;


/**
 * Aggregated contract suite (merged from 1 *ContractTest files).
 * Observe one overall pass/fail via testAllMarketingRuleContractsPass().
 */
final class MarketingRuleContractsTest extends TestCase
{
    public function testAllMarketingRuleContractsPass(): void
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
            'RuleIndexTemplate::testRuleIndexTemplateUsesBackendThemeComponents' => function () {
            $this->assert_RuleIndexTemplate_testRuleIndexTemplateUsesBackendThemeComponents();
        },
        ];
    }

private const TEMPLATE_RELATIVE_PATH_RuleIndexTemplate = '/view/templates/backend/rule/index.phtml';

private function assert_RuleIndexTemplate_testRuleIndexTemplateUsesBackendThemeComponents(): void
{
    $source = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Marketing' . self::TEMPLATE_RELATIVE_PATH_RuleIndexTemplate);
    self::assertStringNotContainsString('rule-index.css', $source);
    self::assertStringNotContainsString('marketing-rule-card', $source);
    self::assertStringNotContainsString('--backend-color-', $source);
    self::assertStringContainsString('w-backend-page__title', $source);
    self::assertStringContainsString('w-card', $source);
    self::assertStringContainsString('w-table', $source);
    self::assertStringContainsString('w-button', $source);
    self::assertStringContainsString('w-badge', $source);
    self::assertStringContainsString('data-testid="marketing-rule-management"', $source);
    self::assertStringContainsString('data-testid="marketing-rule-create"', $source);
    self::assertStringNotContainsString('<dd>', $source);
}
}

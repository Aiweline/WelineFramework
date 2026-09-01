<?php

declare(strict_types=1);

namespace Weline\Marketing\Test\Unit\View\Backend\Rule;

use PHPUnit\Framework\TestCase;

final class RuleIndexTemplateContractTest extends TestCase
{
    private const TEMPLATE_RELATIVE_PATH = '/view/templates/backend/rule/index.phtml';

    public function testRuleIndexTemplateUsesBackendThemeComponents(): void
    {
        $source = (string)file_get_contents(dirname(__DIR__, 5) . self::TEMPLATE_RELATIVE_PATH);

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

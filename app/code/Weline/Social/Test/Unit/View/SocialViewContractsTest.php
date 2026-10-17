<?php

declare(strict_types=1);

namespace Weline\Social\Test\Unit\View;

use PHPUnit\Framework\TestCase;


/**
 * Aggregated contract suite (merged from 1 *ContractTest files).
 * Observe one overall pass/fail via testAllSocialViewContractsPass().
 */
final class SocialViewContractsTest extends TestCase
{
    public function testAllSocialViewContractsPass(): void
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
            'SocialChoiceFloating::testAdminChoiceUsesThemeFloatingAttach' => function () {
            $this->assert_SocialChoiceFloating_testAdminChoiceUsesThemeFloatingAttach();
        },
            'SocialChoiceFloating::testAdminChoiceCssSupportsFloatingPositionedPortal' => function () {
            $this->assert_SocialChoiceFloating_testAdminChoiceCssSupportsFloatingPositionedPortal();
        },
            'SocialChoiceFloating::testScopeTypeDoesNotShowTechnicalWebsiteCodeChip' => function () {
            $this->assert_SocialChoiceFloating_testScopeTypeDoesNotShowTechnicalWebsiteCodeChip();
        },
        ];
    }

private function assert_SocialChoiceFloating_testAdminChoiceUsesThemeFloatingAttach(): void
{
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Social' . '/view/templates/backend/Social/index.phtml');
    self::assertStringContainsString('data-w-float-surface', $src);
    self::assertStringContainsString('UI.floating', $src);
    self::assertStringContainsString('floating.attach', $src);
    self::assertStringContainsString("placement: 'bottom-start'", $src);
    self::assertStringContainsString('panel.contains(target)', $src);
}

private function assert_SocialChoiceFloating_testAdminChoiceCssSupportsFloatingPositionedPortal(): void
{
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Social' . '/view/statics/css/backend/social-admin.css');
    self::assertStringContainsString('[data-w-floating-positioned]', $src);
    self::assertStringContainsString('--w-floating-top', $src);
    self::assertStringContainsString('overflow: visible', $src);
}

private function assert_SocialChoiceFloating_testScopeTypeDoesNotShowTechnicalWebsiteCodeChip(): void
{
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Social' . '/view/templates/backend/Social/index.phtml');
    self::assertStringContainsString("label: String(group.scope_label || group.scope_type || '')", $src);
    self::assertStringContainsString("meta: ''", $src);
    self::assertStringContainsString('typeWrap.hidden', $src);
    self::assertStringContainsString('meta: String(item.scope_code', $src);
    self::assertStringNotContainsString("meta: String(group.scope_type || '')", $src);
}
}

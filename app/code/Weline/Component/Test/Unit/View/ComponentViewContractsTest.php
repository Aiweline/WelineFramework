<?php

declare(strict_types=1);

namespace Weline\Component\Test\Unit\View;

use PHPUnit\Framework\TestCase;


/**
 * Aggregated contract suite (merged from 2 *ContractTest files).
 * Observe one overall pass/fail via testAllComponentViewContractsPass().
 */
final class ComponentViewContractsTest extends TestCase
{
    public function testAllComponentViewContractsPass(): void
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
            'MessageFlashToast::testMessageTemplateConvertsFlashToToast' => function () {
            $this->assert_MessageFlashToast_testMessageTemplateConvertsFlashToToast();
        },
            'SearchBarTemplate::testSearchBarUsesCombinedThemeComponent' => function () {
            $this->assert_SearchBarTemplate_testSearchBarUsesCombinedThemeComponent();
        },
        ];
    }

private function assert_MessageFlashToast_testMessageTemplateConvertsFlashToToast(): void
{
    $src = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Component' . '/view/templates/message.phtml');
    self::assertStringContainsString('data-flash-display="toast"', $src);
    self::assertStringContainsString('Weline.UI.toast', $src);
    self::assertStringContainsString('hidden', $src);
}

private function assert_SearchBarTemplate_testSearchBarUsesCombinedThemeComponent(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/Component' . '/view/templates/form/search-bar.phtml';
    self::assertFileExists($path);
    $content = (string) file_get_contents($path);
    self::assertStringContainsString('w-search-bar', $content);
    self::assertStringContainsString('w-search-bar__control', $content);
    self::assertStringContainsString('w-search-bar__input', $content);
    self::assertStringContainsString('w-search-bar__submit', $content);
    self::assertStringContainsString('client_mode', $content);
    self::assertStringContainsString('data-w-system-config-search-input', $content);
    self::assertStringContainsString('id="wsc-search-input"', $content);
    self::assertStringContainsString('id="wsc-search-root"', $content);
    self::assertStringContainsString('w-search-bar__clear', $content);
    self::assertStringContainsString('<svg', $content);
    self::assertStringNotContainsString('w:icon', $content);
}
}

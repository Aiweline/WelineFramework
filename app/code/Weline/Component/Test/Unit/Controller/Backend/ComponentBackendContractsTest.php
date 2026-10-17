<?php

declare(strict_types=1);

namespace Weline\Component\Test\Unit\Controller\Backend;

use PHPUnit\Framework\TestCase;


/**
 * Aggregated contract suite (merged from 1 *ContractTest files).
 * Observe one overall pass/fail via testAllComponentBackendContractsPass().
 */
final class ComponentBackendContractsTest extends TestCase
{
    public function testAllComponentBackendContractsPass(): void
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
            'OffcanvasResultChrome::testOffcanvasResultPagesSuppressBlankLayoutPageHeader' => function () {
            $this->assert_OffcanvasResultChrome_testOffcanvasResultPagesSuppressBlankLayoutPageHeader();
        },
            'OffcanvasResultChrome::testOffcanvasResultTemplateDoesNotRenderBackendPageHeading' => function () {
            $this->assert_OffcanvasResultChrome_testOffcanvasResultTemplateDoesNotRenderBackendPageHeading();
        },
        ];
    }

private function assert_OffcanvasResultChrome_testOffcanvasResultPagesSuppressBlankLayoutPageHeader(): void
{
    $source = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Component' . '/Controller/Backend/Offcanvas.php');
    self::assertStringContainsString('suppressPageChromeForResult', $source);
    self::assertStringContainsString("assign('layoutShowPageHeader', false)", $source);
    self::assertStringContainsString("assign('layoutShowMessages', false)", $source);
    self::assertStringContainsString('$this->suppressPageChromeForResult()', $source);
    self::assertStringContainsString('applyOffcanvasLayout', $source);
}

private function assert_OffcanvasResultChrome_testOffcanvasResultTemplateDoesNotRenderBackendPageHeading(): void
{
    $source = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Component' . '/view/templates/Offcanvas/result.phtml');
    self::assertStringContainsString('data-w-offcanvas-result', $source);
    self::assertStringContainsString('操作成功', $source);
    self::assertStringNotContainsString('w-backend-page__heading', $source);
}
}

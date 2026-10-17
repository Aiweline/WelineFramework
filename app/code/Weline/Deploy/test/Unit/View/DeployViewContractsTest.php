<?php

declare(strict_types=1);

namespace Weline\Deploy\Test\Unit\View;

use PHPUnit\Framework\TestCase;


/**
 * Aggregated contract suite (merged from 1 *ContractTest files).
 * Observe one overall pass/fail via testAllDeployViewContractsPass().
 */
final class DeployViewContractsTest extends TestCase
{
    public function testAllDeployViewContractsPass(): void
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
            'WlsDeployEmbeddedTitle::testEmbeddedModeGatesInnerPageTitle' => function () {
            $this->assert_WlsDeployEmbeddedTitle_testEmbeddedModeGatesInnerPageTitle();
        },
            'WlsDeployEmbeddedTitle::testEmbeddedCssHidesInnerTitleAsBeltAndSuspenders' => function () {
            $this->assert_WlsDeployEmbeddedTitle_testEmbeddedCssHidesInnerTitleAsBeltAndSuspenders();
        },
        ];
    }

private function templateSource_WlsDeployEmbeddedTitle(): string
{
    $base = \defined('BP') ? (string) \BP : '/Users/weline/Project/Official/框架' . DIRECTORY_SEPARATOR;
    return (string) file_get_contents($base . 'app/code/Weline/Deploy/view/templates/backend/WlsDeploy/index.phtml');
}

private function assert_WlsDeployEmbeddedTitle_testEmbeddedModeGatesInnerPageTitle(): void
{
    $src = $this->templateSource_WlsDeployEmbeddedTitle();
    self::assertStringContainsString('<?php if (!$embedded): ?>', $src);
    self::assertStringContainsString('class="wdep-title"', $src);
    self::assertMatchesRegularExpression('/<\?php\s+if\s*\(\s*!\$embedded\s*\)\s*:\s*\?>\s*<p class="wdep-kicker">[\s\S]*?<h1 class="wdep-title">/', $src);
}

private function assert_WlsDeployEmbeddedTitle_testEmbeddedCssHidesInnerTitleAsBeltAndSuspenders(): void
{
    $src = $this->templateSource_WlsDeployEmbeddedTitle();
    self::assertStringContainsString('.wls-deploy-shell.is-embedded .wdep-title', $src);
    self::assertStringContainsString('.wls-deploy-shell.is-embedded .wdep-kicker', $src);
}
}

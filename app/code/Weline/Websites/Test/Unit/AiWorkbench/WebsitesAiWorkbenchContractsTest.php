<?php

declare(strict_types=1);

namespace Weline\Websites\Test\Unit\AiWorkbench;

use PHPUnit\Framework\TestCase;


/**
 * Aggregated contract suite (merged from 1 *ContractTest files).
 * Observe one overall pass/fail via testAllWebsitesAiWorkbenchContractsPass().
 */
final class WebsitesAiWorkbenchContractsTest extends TestCase
{
    public function testAllWebsitesAiWorkbenchContractsPass(): void
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
            'PageBuilderDirectHandoff::testPageBuilderProviderIsDisabledOnWebsiteHub' => function () {
            $this->assert_PageBuilderDirectHandoff_testPageBuilderProviderIsDisabledOnWebsiteHub();
        },
            'PageBuilderDirectHandoff::testCreateSessionRejectsPageBuilderProviderAndStaysOnWebsitesWorkspace' => function () {
            $this->assert_PageBuilderDirectHandoff_testCreateSessionRejectsPageBuilderProviderAndStaysOnWebsitesWorkspace();
        },
            'PageBuilderDirectHandoff::testWebsiteHubNeverNavigatesToPageBuilderAfterCreate' => function () {
            $this->assert_PageBuilderDirectHandoff_testWebsiteHubNeverNavigatesToPageBuilderAfterCreate();
        },
        ];
    }

private function assert_PageBuilderDirectHandoff_testPageBuilderProviderIsDisabledOnWebsiteHub(): void
{
    $source = (string) \file_get_contents(BP . '/app/code/GuoLaiRen/PageBuilder/extends/module/Weline_Websites/AiSiteBuilderProvider/PageBuilderProvider.php');
    self::assertStringContainsString('return false;', $source);
    self::assertStringContainsString('彻底分开', $source);
    self::assertStringNotContainsString('site-builder-agent/pagebuilder-handoff', $source);
}

private function assert_PageBuilderDirectHandoff_testCreateSessionRejectsPageBuilderProviderAndStaysOnWebsitesWorkspace(): void
{
    $controller = (string) \file_get_contents(BP . '/app/code/Weline/Websites/Controller/Backend/SiteBuilderAgent.php');
    $createSession = $this->sourceBetween_PageBuilderDirectHandoff($controller, 'public function postCreateSession(): string', 'private function prefillWorkspaceBriefByAi(');
    self::assertStringContainsString("\$providerCode === 'pagebuilder'", $createSession);
    self::assertStringContainsString('PAGEBUILDER_ENTRY_SEPARATED', $createSession);
    self::assertStringContainsString('只进入 Websites 自己的工作区', $createSession);
    self::assertStringNotContainsString('pagebuilder/backend/ai-site-workbench/index', $createSession);
    self::assertStringNotContainsString("source=websites&source_public_id=", $createSession);
}

private function assert_PageBuilderDirectHandoff_testWebsiteHubNeverNavigatesToPageBuilderAfterCreate(): void
{
    $source = (string) \file_get_contents(BP . '/app/code/Weline/Websites/view/templates/backend/SiteBuilderAgent/index.phtml');
    self::assertStringContainsString("workspaceUrl.indexOf('/pagebuilder/') === -1", $source);
    self::assertStringContainsString('禁止跳到 PageBuilder', $source);
    self::assertStringNotContainsString('PageBuilder 原生工作台优先', $source);
    self::assertStringNotContainsString('openDirectProvider(', $source);
}

private function sourceBetween_PageBuilderDirectHandoff(string $source, string $start, string $end): string
{
    $startPos = \strpos($source, $start);
    $endPos = \strpos($source, $end, $startPos === false ? 0 : $startPos);
    self::assertIsInt($startPos);
    self::assertIsInt($endPos);
    return \substr($source, (int) $startPos, (int) $endPos - (int) $startPos);
}
}

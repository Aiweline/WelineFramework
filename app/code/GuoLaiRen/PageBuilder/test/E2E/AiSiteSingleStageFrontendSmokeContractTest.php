<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Test\E2E;

use GuoLaiRen\PageBuilder\Test\Unit\View\Support\AiSiteWorkspaceScriptReader;
use PHPUnit\Framework\TestCase;

final class AiSiteSingleStageFrontendSmokeContractTest extends TestCase
{
    /**
     * @return list<string>
     */
    private static function workspaceSourceTemplateFiles(): array
    {
        $moduleRoot = \dirname(__DIR__, 2);
        $files = [
            $moduleRoot . '/view/templates/Backend/AiSiteAgent/workspace.phtml',
            $moduleRoot . '/view/templates/Backend/AiSiteAgent/workspace-preview-unavailable.phtml',
        ];
        $workspaceDir = $moduleRoot . '/view/templates/Backend/AiSiteAgent/workspace';
        if (\is_dir($workspaceDir)) {
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($workspaceDir, \FilesystemIterator::SKIP_DOTS));
            /** @var \SplFileInfo $file */
            foreach ($iterator as $file) {
                if ($file->isFile() && \strtolower($file->getExtension()) === 'phtml') {
                    $files[] = $file->getPathname();
                }
            }
        }
        \sort($files);

        return \array_values(\array_unique($files));
    }

    public function testWorkspaceFrontendDoesNotExposeRemovedPlanStageLabels(): void
    {
        $moduleRoot = \dirname(__DIR__, 2);
        $files = \array_merge([
            $moduleRoot . '/Controller/Backend/AiSiteAgent.php',
            $moduleRoot . '/Queue/AiSitePlanQueue.php',
            $moduleRoot . '/Service/AiSiteAgentQueueObserverStreamService.php',
        ], self::workspaceSourceTemplateFiles());

        foreach ($files as $file) {
            self::assertFileExists($file);
            $content = \file_get_contents($file);
            self::assertIsString($content);
            self::assertDoesNotMatchRegularExpression('/方案1|阶段一方案|第二阶段|第三阶段|阶段三|共享任务|整个阶段/u', $content, $file);
        }
    }

    public function testWorkspaceSourceTemplatesDoNotContainUserVisibleMojibake(): void
    {
        $failures = [];
        foreach (self::workspaceFrontendSourceFiles() as $file) {
            self::assertFileExists($file);
            $content = \file_get_contents($file);
            self::assertIsString($content);
            $visibleSource = $this->removeSourceComments($content);

            if (\preg_match_all('/Ã.|Â.|â€[\x80-\x9f]?|�|銆|俔|寮€|鍙戜|閫|浠诲|澶辫|鎵ц|绔|闃舵/u', $visibleSource, $matches, \PREG_OFFSET_CAPTURE) === false) {
                self::fail('mojibake 正则执行失败：' . $file);
            }
            foreach ($matches[0] as [$fragment, $offset]) {
                $failures[] = $file . ':' . $this->lineNumberAtOffset($visibleSource, (int)$offset) . ' => ' . $fragment;
            }
        }

        self::assertSame(
            [],
            $failures,
            "AiSiteAgent workspace 源模板包含疑似会进入用户可见文案的乱码片段：\n" . \implode("\n", $failures)
        );
    }

    public function testConfirmedBlueprintPreviewUsesNonEmptySingleStageFallbacks(): void
    {
        $moduleRoot = \dirname(__DIR__, 2);
        $script = AiSiteWorkspaceScriptReader::loadBundledJavaScript();
        self::assertStringContainsString('resolvePlanJsonArtifactsFromWorkspaceState', $script);
        self::assertStringContainsString('hasPlanJsonFlowEvidence', $script);
        self::assertStringContainsString('shouldUsePlanJsonFlow', $script);
        self::assertStringContainsString('planJson:', $script);
    }

    public function testStageOnePreviewReadsPrunedStructuredPlanFields(): void
    {
        $moduleRoot = \dirname(__DIR__, 2);
        $script = AiSiteWorkspaceScriptReader::loadBundledJavaScript();
        self::assertStringContainsString('resolvePlanJsonFromWorkspaceState', $script);
        self::assertStringContainsString('resolvePlanJsonArtifactsFromWorkspaceState', $script);
        self::assertStringContainsString('payload.plan_json', $script);
        self::assertStringContainsString('parsed.plan_json', $script);
        self::assertStringNotContainsString('plan.structured', $script);
        self::assertStringNotContainsString('payload.structured_plan', $script);
    }

    public function testConfirmedPlanModalBindsPreviewInteractionsAfterRendering(): void
    {
        $moduleRoot = \dirname(__DIR__, 2);
        $script = AiSiteWorkspaceScriptReader::loadBundledJavaScript();
        self::assertStringContainsString('bindPreviewActionButtons(planRenderedContent);', $script);
        self::assertStringContainsString('bindPreviewTabButtons(planRenderedContent);', $script);
        self::assertStringContainsString('bindPreviewSortables(planRenderedContent);', $script);
        self::assertStringContainsString('bindStageOnePlanFieldEditors(planRenderedContent);', $script);
    }

    public function testPlanPreviewBlockActionsUseDirectHandlersWithoutInlineClickFallbacks(): void
    {
        $moduleRoot = \dirname(__DIR__, 2);
        $script = AiSiteWorkspaceScriptReader::loadBundledJavaScript();
        self::assertStringContainsString('function bindPreviewActionButtonDirectHandlers(root)', $script);
        self::assertStringContainsString("root.querySelectorAll('.pb-ai-preview-action-btn')", $script);
        self::assertStringContainsString("button.dataset.pbPreviewActionButtonBound = '1';", $script);
        self::assertStringContainsString('handlePreviewActionClick(button);', $script);
        self::assertStringContainsString('document.body.appendChild(modalEl);', $script);
        self::assertStringContainsString('detail.__pbBlockOpKeepOpen !== true', $script);
        self::assertStringContainsString('payload.__pbBlockOpKeepOpen = true;', $script);
        self::assertStringContainsString('markPlanBlockMutationStatus(\'pending\', \'\');', $script);
        self::assertStringContainsString('function dispatchBlockOperationModalDetail(detail, modalEl)', $script);
        self::assertStringNotContainsString('onclick="window.PbAiWorkspacePreviewAction', $script);
    }

    public function testPlanPreviewBlockActionToolbarIsVisibleByDefault(): void
    {
        $moduleRoot = \dirname(__DIR__, 2);
        $layout = \file_get_contents($moduleRoot . '/view/templates/Backend/AiSiteAgent/workspace/layout.phtml');

        self::assertIsString($layout);
        self::assertMatchesRegularExpression('/\\.pb-ai-plan-preview-actions\\s*\\{(?<rule>.*?)\\}/s', $layout);
        \preg_match('/\\.pb-ai-plan-preview-actions\\s*\\{(?<rule>.*?)\\}/s', $layout, $matches);
        $rule = (string)($matches['rule'] ?? '');
        self::assertStringContainsString('opacity: 1;', $rule);
        self::assertStringContainsString('pointer-events: auto;', $rule);
        self::assertStringContainsString('transform: none;', $rule);
        self::assertStringNotContainsString('opacity: 0;', $rule);
        self::assertStringNotContainsString('pointer-events: none;', $rule);
    }

    /**
     * @return list<string>
     */
    private static function workspaceFrontendSourceFiles(): array
    {
        $moduleRoot = \dirname(__DIR__, 2);

        return \array_values(\array_unique(\array_merge([
            $moduleRoot . '/Controller/Backend/AiSiteAgent.php',
            $moduleRoot . '/Queue/AiSitePlanQueue.php',
            $moduleRoot . '/Service/AiSiteAgentQueueObserverStreamService.php',
        ], self::workspaceSourceTemplateFiles())));
    }

    private function removeSourceComments(string $content): string
    {
        $content = (string)\preg_replace('/<\?php\s*\/\*.*?\*\/\s*\?>/s', '', $content);
        $content = (string)\preg_replace('/\/\*.*?\*\//s', '', $content);
        $content = (string)\preg_replace('/<!--.*?-->/s', '', $content);
        $content = (string)\preg_replace('/^[ \t]*(?:\/\/|#).*$/m', '', $content);

        return $content;
    }

    private function lineNumberAtOffset(string $content, int $offset): int
    {
        return \substr_count(\substr($content, 0, $offset), "\n") + 1;
    }
}

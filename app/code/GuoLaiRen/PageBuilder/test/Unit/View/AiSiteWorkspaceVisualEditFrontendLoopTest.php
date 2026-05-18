<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Test\Unit\View;

use GuoLaiRen\PageBuilder\Model\Page;
use GuoLaiRen\PageBuilder\Service\LayoutOwnerResolver;
use PHPUnit\Framework\TestCase;

final class AiSiteWorkspaceVisualEditFrontendLoopTest extends TestCase
{
    public function testWorkspaceTemplatePassesArrayDataToNestedFetches(): void
    {
        $workspace = \file_get_contents(BP . '/app/code/GuoLaiRen/PageBuilder/view/templates/Backend/AiSiteAgent/workspace.phtml');
        self::assertIsString($workspace);

        $assignmentOffset = \strpos($workspace, '$workspaceTplData = \get_defined_vars();');
        $firstFetchOffset = \strpos($workspace, "\$this->fetch('GuoLaiRen_PageBuilder::templates/Backend/AiSiteAgent/workspace/styles-device.phtml', \$workspaceTplData)");

        self::assertIsInt($assignmentOffset);
        self::assertIsInt($firstFetchOffset);
        self::assertLessThan($firstFetchOffset, $assignmentOffset);
    }

    public function testVirtualWorkspacePreviewUsesInjectedAiThemeLayoutBeforeStyleDefault(): void
    {
        $page = new Page();
        $page->setData([
            Page::schema_fields_ID => 0,
            Page::schema_fields_TYPE => Page::TYPE_HOME,
            Page::schema_fields_STYLE => 'default',
            Page::schema_fields_LAYOUT_CONFIG => \json_encode([
                'header' => ['component' => 'header/ai-site-header', 'config' => []],
                'content' => [
                    ['code' => 'content/teenipiya-home-hero', 'enabled' => true, 'config' => []],
                ],
                'footer' => ['component' => 'footer/ai-site-footer', 'config' => []],
            ], \JSON_UNESCAPED_UNICODE),
        ]);
        $page->setData('virtual_layout_config', [
            'header' => ['component' => 'header/ai-site-header', 'config' => []],
            'content' => [
                ['code' => 'content/teenipiya-home-hero', 'enabled' => true, 'config' => []],
            ],
            'footer' => ['component' => 'footer/ai-site-footer', 'config' => []],
        ]);

        $layout = (new LayoutOwnerResolver())->getFullLayoutConfig($page, true, 'default');

        self::assertSame('header/ai-site-header', $layout['header']['component'] ?? '');
        self::assertSame('content/teenipiya-home-hero', $layout['content'][0]['code'] ?? '');
        self::assertSame('footer/ai-site-footer', $layout['footer']['component'] ?? '');
    }

    public function testWorkspaceEditModalLoadsVirtualMetadataSavesConfigAndRefreshesPreview(): void
    {
        $script = $this->workspaceScript();

        $modalBody = $this->extractFunctionBody($script, 'openWorkspaceVisualComponentConfigModal');
        $saveBody = $this->extractFunctionBody($script, 'saveWorkspaceVirtualBlockConfig');
        $collectBody = $this->extractFunctionBody($script, 'collectComponentConfigModalValues');

        self::assertStringContainsString('var context = resolveWorkspaceVisualComponentContext(payload);', $modalBody);
        self::assertStringContainsString('var currentBlock = findVirtualBlock(context.page_type, context.block_id) || findVirtualBlock(context.page_type, context.component_code);', $modalBody);
        self::assertStringContainsString('var layoutItem = findWorkspaceVisualLayoutItem(context.page_type, context.component_code, context.block_id, context.region);', $modalBody);
        self::assertStringContainsString('currentBlock = buildWorkspaceVisualFallbackBlock(context, layoutItem);', $modalBody);
        self::assertStringContainsString('var currentConfig = cloneJson(currentBlock.config || {});', $modalBody);
        self::assertStringContainsString('var fields = currentBlock.field_schema && typeof currentBlock.field_schema === \'object\'', $modalBody);
        self::assertStringContainsString('var styleCode = resolveWorkspacePageStyleCode(context.page_type);', $modalBody);
        self::assertStringNotContainsString('visualComponentLayoutFieldsUrl', $modalBody);
        self::assertStringNotContainsString('visualComponentUpdateConfigUrl', $modalBody);
        self::assertStringNotContainsString('visualComponentMetadataUrl', $modalBody);

        self::assertStringContainsString('return postJson(updateBlockConfigUrl, {', $saveBody);
        self::assertStringContainsString('public_id: context.public_id,', $saveBody);
        self::assertStringContainsString('page_type: context.page_type,', $saveBody);
        self::assertStringContainsString('block_id: context.block_id,', $saveBody);
        self::assertStringContainsString('component_code: context.component_code,', $saveBody);
        self::assertStringContainsString('region: context.region,', $saveBody);
        self::assertStringContainsString('index: context.index,', $saveBody);
        self::assertStringContainsString('block_config: blockConfig', $saveBody);
        self::assertStringContainsString("data-field=\"_ai_prompt\"", $modalBody);
        self::assertStringContainsString("data-config-helper=\"1\"", $modalBody);
        self::assertStringContainsString("input.getAttribute('data-config-helper')", $collectBody);
        self::assertStringContainsString('await saveWorkspaceVirtualBlockConfig(context, promptSaveConfig);', $modalBody);
        self::assertStringContainsString('var saveResult = await saveWorkspaceVirtualBlockConfig(context, newConfig);', $modalBody);
        self::assertStringContainsString('hydrateWorkspaceFromState(saveResult.data);', $modalBody);
        self::assertStringContainsString('updateVirtualBlockState(context.page_type, saveResult.block);', $modalBody);
        self::assertStringContainsString('replaceCurrentBlockHtml(context.page_type, saveResult.block);', $modalBody);
        self::assertStringContainsString('refreshEmbeddedPreviewPreservingScroll();', $modalBody);
    }

    public function testPlanPreviewReadsRefactoredWorkbenchContracts(): void
    {
        $script = $this->workspaceScript();
        $controller = \file_get_contents(BP . '/app/code/GuoLaiRen/PageBuilder/Controller/Backend/AiSiteAgent.php');
        self::assertIsString($controller);

        self::assertStringContainsString('function buildStructuredPlanRootFromWorkbenchContracts(contracts)', $script);
        self::assertStringContainsString('contracts.block_plan', $script);
        self::assertStringContainsString('contracts.page_contract', $script);
        self::assertStringContainsString('contracts.design_manifest', $script);
        self::assertStringContainsString('contracts.site_brief', $script);
        self::assertStringContainsString('buildStructuredPlanRootFromWorkbenchContracts(conf.contracts)', $script);
        self::assertStringContainsString('function syncStageOnePlanPreviewFromWorkspaceState(workspaceState)', $script);
        self::assertStringContainsString('syncStageOnePlanPreviewFromWorkspaceState(workspaceState);', $script);
        self::assertStringContainsString('plan_workbench: pickFirstNonEmptyPlanObject(state.plan_workbench, scope.plan_workbench, plan.plan_workbench)', $script);
        self::assertStringContainsString('extractStageOneStructuredPlanFromContracts', $controller);
        self::assertStringContainsString("\$confirmedContractsStructured = \$this->extractStageOneStructuredPlanFromContracts", $controller);
    }

    public function testVisualEditPreviewTabsHydrateFromVirtualPagesWithoutEntityPageIds(): void
    {
        $script = $this->workspaceScript();
        $syncBody = $this->extractFunctionBody($script, 'syncPreviewMetaFromState');
        $urlBody = $this->extractFunctionBody($script, 'buildVisualPageEditorUrl');

        self::assertStringContainsString('Object.keys(workspaceState.virtual_pages_by_type).forEach(function (pageType)', $syncBody);
        self::assertStringContainsString('upsertPreviewTab(pageType, label || normalizePageTypeLabel(pageType), pageId, shouldActivate);', $syncBody);
        self::assertStringContainsString("editorUrl.searchParams.set('page_type', String(pageType));", $urlBody);
        self::assertStringContainsString("editorUrl.searchParams.set('page_id', String(pageId));", $urlBody);
    }

    public function testWorkspacePreviewClickAndMessageActionsOpenTheSharedBlockEditor(): void
    {
        $script = $this->workspaceScript();
        $bridgeBody = $this->extractFunctionBody($script, 'bindEmbeddedPreviewFrameBridge');
        $messageBody = $this->extractFunctionBody($script, 'bindWorkspacePreviewMessages');

        self::assertStringContainsString("doc.querySelectorAll('.component-actions [data-pb-action]')", $bridgeBody);
        self::assertStringContainsString('button.onmousedown = handleEmbeddedPreviewActionPointerDown;', $bridgeBody);
        self::assertStringContainsString('button.onpointerdown = handleEmbeddedPreviewActionPointerDown;', $bridgeBody);
        self::assertStringContainsString('button.ontouchstart = handleEmbeddedPreviewActionPointerDown;', $bridgeBody);
        self::assertStringContainsString('button.onclick = handleEmbeddedPreviewActionClick;', $bridgeBody);
        self::assertStringContainsString("actionHost.addEventListener('click', function (event)", $bridgeBody);
        self::assertStringContainsString("source.closest('.component-actions [data-pb-action]')", $bridgeBody);
        self::assertStringContainsString("doc.addEventListener('pointerdown', handleEmbeddedPreviewActionCapture, true);", $bridgeBody);
        self::assertStringContainsString("doc.addEventListener('touchstart', handleEmbeddedPreviewActionCapture, true);", $bridgeBody);
        self::assertStringContainsString("doc.addEventListener('click', handleEmbeddedPreviewActionCapture, true);", $bridgeBody);
        self::assertStringNotContainsString("if (button.dataset.pbWorkspaceActionBound === '1')", $bridgeBody);
        self::assertStringContainsString('var payload = buildEmbeddedPreviewPayload(wrapper, button);', $bridgeBody);
        self::assertStringContainsString('dispatchEmbeddedPreviewActionPayload(action, payload);', $bridgeBody);

        self::assertStringContainsString("if (payload.type === 'pb-component-select') {", $messageBody);
        self::assertStringContainsString('showEmbeddedPreviewActionDock(Object.assign({}, payload', $messageBody);
        self::assertStringContainsString("if (payload.type === 'pb-component-action') {", $messageBody);
        self::assertStringContainsString("dispatchEmbeddedPreviewActionPayload(String(payload.action || ''), payload);", $messageBody);
        self::assertStringContainsString("document.body.dataset.pbRefineComponentOpenStatus", $script);
        self::assertStringContainsString("document.body.dataset.pbEmbeddedPreviewActionStatus", $script);
        self::assertStringContainsString("function dispatchEmbeddedPreviewActionPayload(action, payload)", $script);
        self::assertStringContainsString("function getEmbeddedActionButtonInlineDispatch()", $script);
        self::assertStringContainsString("function ensureEmbeddedPreviewActionDock()", $script);
        self::assertStringContainsString("dock.id = 'pb-ai-embedded-action-dock';", $script);
        self::assertStringContainsString("function syncEmbeddedPreviewFrameActionChrome(doc)", $script);
        self::assertStringContainsString("data-pb-workspace-mobile-action-dock", $script);
        self::assertStringContainsString("function syncEmbeddedPreviewActionDockPlacement()", $script);
        self::assertStringContainsString("dock.style.bottom = '22px';", $script);
        self::assertStringContainsString("function runEmbeddedPreviewDockAction(action)", $script);
        self::assertStringContainsString('handleEmbeddedPreviewAction: function (payload)', $script);
        self::assertStringContainsString("if (normalizedAction === 'refine') {", $script);
        self::assertStringNotContainsString('openLegacyBlockEditorModal', $script);
        self::assertStringNotContainsString('skipLegacyFallback', $script);

        $renderer = \file_get_contents(BP . '/app/code/GuoLaiRen/PageBuilder/Service/PageRenderService.php');
        self::assertIsString($renderer);
        self::assertStringContainsString('onclick="\' . $actionDispatch . \'"', $renderer);
        self::assertStringContainsString('getComponentActionInlineDispatchJs()', $renderer);
        self::assertStringContainsString('window.parent.postMessage(payload, \'*\')', $renderer);
        self::assertStringContainsString('window.__pbDispatchComponentActionFromButton = function(target, e)', $renderer);
    }

    public function testVisualPreviewImagesCanStartSingleAssetRegeneration(): void
    {
        $script = $this->workspaceScript();
        $bridgeBody = $this->extractFunctionBody($script, 'bindEmbeddedPreviewFrameBridge');
        $submitBody = $this->extractFunctionBody($script, 'submitImageRegenerateModal');

        self::assertStringContainsString('bindEmbeddedPreviewImageRegeneration(doc, wrapperSelector);', $bridgeBody);
        self::assertStringContainsString('data-pb-ai-asset-slot', $script);
        self::assertStringContainsString('data-pb-ai-image-role', $script);
        self::assertStringContainsString('current_url: String(imageRegenerateState.current_url_raw', $submitBody);
        self::assertStringContainsString('block_id: String(imageRegenerateState.block_id', $submitBody);
        self::assertStringContainsString('component_code: String(imageRegenerateState.component_code', $submitBody);
        self::assertStringContainsString("window.PbAiOperationRunner.startFromResponse(data, 'image_asset')", $submitBody);

        $runtime = \file_get_contents(BP . '/app/code/GuoLaiRen/PageBuilder/view/templates/Backend/AiSiteAgent/workspace/script-runtime.phtml');
        self::assertIsString($runtime);
        self::assertStringContainsString("source.addEventListener('asset_generation_done'", $runtime);
        self::assertStringContainsString("operation !== 'image_asset'", $runtime);

        $controller = \file_get_contents(BP . '/app/code/GuoLaiRen/PageBuilder/Controller/Backend/AiSiteAgent.php');
        self::assertIsString($controller);
        self::assertStringContainsString("'image_asset'], true", $controller);
        self::assertStringContainsString('\'stream_url\' => $streamUrl', $controller);
    }

    public function testSkillManagementDoesNotHideTheSkillSelectionAreaWhenListIsEmpty(): void
    {
        $layout = \file_get_contents(BP . '/app/code/GuoLaiRen/PageBuilder/view/templates/Backend/AiSiteAgent/workspace/layout.phtml');
        self::assertIsString($layout);
        self::assertStringContainsString('id="pb-ai-skill-option-list"', $layout);
        self::assertStringContainsString('id="pb-ai-skill-admin-close-btn"', $layout);

        $script = $this->workspaceScript();
        $renderStart = \strrpos($script, 'function renderNeedsFormSkillSelection()');
        $loadStart = \strrpos($script, 'function loadNeedsFormSkills()');
        $managerStart = \strrpos($script, 'function initNeedsFormSkillManager(');
        $resolveStart = \strrpos($script, 'function resolveSelectedSkillCodesFromWorkspaceState(');
        $persistStart = \strrpos($script, 'function persistNeedsFormSkillSelection(');
        self::assertIsInt($renderStart, 'runtime renderNeedsFormSkillSelection must exist outside legacy comments.');
        self::assertIsInt($loadStart, 'runtime loadNeedsFormSkills must exist outside legacy comments.');
        self::assertIsInt($managerStart, 'runtime initNeedsFormSkillManager must exist outside legacy comments.');
        self::assertIsInt($resolveStart, 'selected skill hydration helper must exist.');
        self::assertIsInt($persistStart, 'selected skill immediate persist helper must exist.');
        $renderBody = $this->extractFunctionBody(\substr($script, $renderStart), 'renderNeedsFormSkillSelection');
        $loadBody = $this->extractFunctionBody(\substr($script, $loadStart), 'loadNeedsFormSkills');
        $managerBody = $this->extractFunctionBody(\substr($script, $managerStart), 'initNeedsFormSkillManager');
        $resolveBody = $this->extractFunctionBody(\substr($script, $resolveStart), 'resolveSelectedSkillCodesFromWorkspaceState');
        $persistBody = $this->extractFunctionBody(\substr($script, $persistStart), 'persistNeedsFormSkillSelection');

        self::assertStringContainsString("optionList.classList.remove('d-none');", $renderBody);
        self::assertStringNotContainsString("optionList.classList.add('d-none');", $renderBody);
        self::assertStringContainsString('if (needsFormSkillOptions.length === 0) {', $renderBody);
        self::assertStringContainsString('emptyOption.textContent =', $renderBody);
        self::assertStringContainsString('return postForm(skillListUrl, {})', $loadBody);
        self::assertStringNotContainsString("method: 'GET'", $loadBody);
        self::assertStringContainsString("document.getElementById('pb-ai-skill-admin-close-btn')", $managerBody);
        self::assertStringContainsString('adminCloseBtn.addEventListener', $managerBody);
        self::assertStringContainsString('state.selected_skill_codes', $resolveBody);
        self::assertStringContainsString('scope.selected_skill_codes', $resolveBody);
        self::assertStringContainsString('scope.plan_workbench.confirmed.contract_context', $resolveBody);
        self::assertStringContainsString('resolveSelectedSkillCodesFromWorkspaceState(workspaceState, getNeedsFormSelectedSkillCodes())', $script);
        self::assertStringContainsString("postNeedsFormScopePatch({ selected_skill_codes: selectedCodes })", $persistBody);
        self::assertStringContainsString('patchGuidedScopeDefaults({ selected_skill_codes: selectedCodes })', $persistBody);
        self::assertStringContainsString('persistNeedsFormSkillSelection(current);', $script);
        self::assertStringContainsString('persistNeedsFormSkillSelection(getNeedsFormSelectedSkillCodes());', $script);
    }

    public function testWorkspacePreviewToolbarKeepsHoverVisibilityAndHidesSharedRegionSorting(): void
    {
        $script = $this->workspaceScript();
        $body = $this->extractFunctionBody($script, 'ensureWrapperActionButtons');

        self::assertStringContainsString("var region = String(wrapper.getAttribute('data-region') || '').trim().toLowerCase();", $body);
        self::assertStringContainsString("var isContentRegion = region === '' || region === 'content';", $body);
        self::assertStringContainsString("actions = document.createElement('div');", $body);
        self::assertStringContainsString("actions.className = 'component-actions';", $body);
        self::assertStringContainsString("refineBtn = document.createElement('button');", $body);
        self::assertStringContainsString("editBtn = document.createElement('button');", $body);
        self::assertStringContainsString("actions.querySelectorAll('[data-pb-action=\"move-up\"], [data-pb-action=\"move-down\"]')", $body);
        self::assertStringContainsString("button.classList.add('d-none');", $body);

        $renderService = \file_get_contents(BP . '/app/code/GuoLaiRen/PageBuilder/Service/PageRenderService.php');
        self::assertIsString($renderService);
        self::assertStringContainsString('.component-actions.pb-actions-visible', $renderService);
        self::assertStringContainsString('tabindex="0"', $renderService);
        self::assertStringContainsString('.tpmst-component-wrapper:focus-within .component-actions', $renderService);
        self::assertStringContainsString('.pb-component-wrapper:focus-within .component-actions', $renderService);
        self::assertStringContainsString('.tpmst-component-wrapper.selected .component-actions', $renderService);
        self::assertStringContainsString('.pb-component-wrapper.selected .component-actions', $renderService);
        self::assertStringContainsString('data-page-type=', $renderService);
        self::assertStringContainsString('type: "pb-component-action"', $renderService);
        self::assertStringContainsString('window.parent.PbAiWorkspacePreview.handleEmbeddedPreviewAction(payload)', $renderService);
        self::assertStringContainsString('document.addEventListener("mousedown", handleComponentActionEvent, true);', $renderService);
        self::assertStringContainsString('document.addEventListener("click", handleComponentActionEvent, true);', $renderService);
        self::assertStringContainsString('e.target.nodeType === 3', $renderService);
        self::assertStringContainsString('new URLSearchParams(window.location.search).get("page_type")', $renderService);
        self::assertStringContainsString('.tpmst-component-wrapper[data-region="header"] .component-actions', $renderService);
        self::assertStringContainsString('[data-pb-action="move-up"]', $renderService);
        self::assertStringContainsString('@media (max-width: 480px)', $renderService);
        self::assertStringContainsString('position: sticky !important;', $renderService);
        self::assertStringContainsString('display: flex !important;', $renderService);
        self::assertStringContainsString('max-width: calc(100% - 12px) !important;', $renderService);
    }

    public function testVirtualThemePreviewRequiresAiGeneratedResponsiveSupportWithoutRendererCompatCss(): void
    {
        $deviceStyles = \file_get_contents(BP . '/app/code/GuoLaiRen/PageBuilder/view/templates/Backend/AiSiteAgent/workspace/styles-device.phtml');
        self::assertIsString($deviceStyles);
        self::assertStringContainsString('width: min(390px, 100%);', $deviceStyles);
        self::assertStringContainsString('max-width: 100%;', $deviceStyles);

        $script = $this->workspaceScript();
        self::assertStringContainsString("frame.style.width = 'min(390px, 100%)';", $script);
        self::assertStringContainsString("frame.style.maxWidth = '100%';", $script);
        self::assertStringNotContainsString("frame.style.maxWidth = '390px';", $script);

        $renderService = \file_get_contents(BP . '/app/code/GuoLaiRen/PageBuilder/Service/PageRenderService.php');
        self::assertIsString($renderService);
        self::assertStringNotContainsString('data-pb-virtual-mobile-compat="1"', $renderService);
        self::assertStringNotContainsString('injectVirtualThemeMobileCompatibilityStyle', $renderService);

        $generationService = \file_get_contents(BP . '/app/code/GuoLaiRen/PageBuilder/Service/AiSitePageComponentGenerationService.php');
        self::assertIsString($generationService);
        self::assertStringContainsString('Responsive CSS is mandatory for every non-trivial component', $generationService);
        self::assertStringContainsString('the renderer will not inject compatibility CSS/JS', $generationService);

        $qualityGate = \file_get_contents(BP . '/app/code/GuoLaiRen/PageBuilder/Service/AiSiteQualityGateService.php');
        self::assertIsString($qualityGate);
        self::assertStringContainsString("'responsive_support'", $qualityGate);
        self::assertStringContainsString('matchResponsiveSignals', $qualityGate);
        self::assertStringNotContainsString('sanitizeGeneratedBrandCopy', $renderService);
        self::assertStringNotContainsString('sanitizeGeneratedLogoImages', $renderService);
        self::assertStringNotContainsString('sanitizePersistedHeroImageLayout', $renderService);
        self::assertStringNotContainsString('sanitizeAiHtmlBlockFragment', $renderService);
        self::assertStringNotContainsString('containsAiInstructionLeak', $renderService);
        self::assertStringNotContainsString('A focused highlight from this section.', $renderService);
        self::assertStringNotContainsString('Game Card|Category|Badge', $renderService);
        self::assertStringNotContainsString('websiteProfile', $renderService);
    }

    public function testBuildStageExposesFullRebuildActionAndBindsItToSchemeRebuildFlow(): void
    {
        $layout = \file_get_contents(BP . '/app/code/GuoLaiRen/PageBuilder/view/templates/Backend/AiSiteAgent/workspace/stages/sections/visual-edit-card.phtml');
        self::assertIsString($layout);
        self::assertStringContainsString('id="pb-ai-rebuild-build-stage"', $layout);

        $script = $this->workspaceScript();
        $bindBody = $this->extractFunctionBody($script, 'bindPlanStageLogic');
        self::assertStringContainsString("var rebuildBuildStageBtn = document.getElementById('pb-ai-rebuild-build-stage');", $bindBody);
        self::assertStringContainsString("rebuildBuildStageBtn.dataset.pbPlanGenerationLockBypass = '1';", $bindBody);
        self::assertStringContainsString('startFullBuildRebuild(this, selectedTypes, {});', $bindBody);

        $rebuildBody = $this->extractFunctionBody($script, 'startFullBuildRebuild');
        self::assertStringContainsString('forceBuildRebuild: true', $rebuildBody);

        $buildBody = $this->extractFunctionBody($script, 'pbAiConfirmGenerateThemeContinue');
        self::assertStringContainsString('if (opts.forceBuildRebuild === true) {', $buildBody);
        self::assertStringContainsString("requestPayload._force_rebuild = '1';", $buildBody);
    }

    public function testConfirmPlanButtonChecksOnlyPhaseOneQueueState(): void
    {
        $script = $this->workspaceScript();
        $body = $this->extractFunctionBody($script, 'isPhaseOneQueueUnfinished');

        self::assertStringContainsString('activeOperations.plan', $body);
        self::assertStringContainsString('state.plan_queue_info', $body);
        self::assertStringContainsString('isQueueUiBusyStatus', $body);
        self::assertStringNotContainsString('state.task_plan_queue_info', $body);
        self::assertStringNotContainsString('state.build_queue_info', $body);
        self::assertStringNotContainsString('hasAnyRunningQueueForUi()', $body);
    }

    public function testFrontendDeletesLegacyTaskPlanEntrypoints(): void
    {
        $templateRoot = BP . '/app/code/GuoLaiRen/PageBuilder/view/templates/Backend/AiSiteAgent';
        $sources = [
            'workspace.phtml' => \file_get_contents($templateRoot . '/workspace.phtml'),
            'workspace/layout.phtml' => \file_get_contents($templateRoot . '/workspace/layout.phtml'),
            'workspace/modals.phtml' => \file_get_contents($templateRoot . '/workspace/modals.phtml'),
            'workspace-preview-unavailable.phtml' => \file_get_contents($templateRoot . '/workspace-preview-unavailable.phtml'),
            'workspace/script-main.phtml' => $this->workspaceScript(),
            'workspace/script-runtime.phtml' => \file_get_contents($templateRoot . '/workspace/script-runtime.phtml'),
            'workspace/script-build-queue-progress.phtml' => \file_get_contents($templateRoot . '/workspace/script-build-queue-progress.phtml'),
        ];
        $legacyTokens = [
            'task_plan',
            'TaskPlan',
            'taskPlan',
            'task-plan',
            'stage2',
            'stageTwo',
            'PhaseTwo',
            'phaseTwo',
            'pb-ai-task-plan',
            'startTaskPlan',
            'confirmTaskPlan',
            'mutateTaskPlan',
            'sortTaskPlan',
            'allowTaskPlanRetry',
        ];

        foreach ($sources as $label => $source) {
            self::assertIsString($source, $label . ' must be readable.');
            foreach ($legacyTokens as $token) {
                self::assertStringNotContainsString($token, $source, $label . ' must not expose legacy task-plan frontend token ' . $token . '.');
            }
        }

        self::assertFileDoesNotExist($templateRoot . '/workspace/stages/sections/task-plan-accordion-panel.phtml');
        self::assertFileDoesNotExist($templateRoot . '/workspace/script-phase2-queue-progress.phtml');
    }

    public function testBuildQueueDetailsDoNotRemainAutoExpandedAfterTerminalStatus(): void
    {
        $source = \file_get_contents(BP . '/app/code/GuoLaiRen/PageBuilder/view/templates/Backend/AiSiteAgent/workspace/script-build-queue-progress.phtml');
        self::assertIsString($source);

        self::assertStringContainsString('function resolveQueuePanelStatus(info, payload, eventKind)', $source);
        self::assertStringContainsString("if (kind === 'error' || kind === 'failed')", $source);
        self::assertStringContainsString("var shouldOpen = activeStatus === 'queued' || activeStatus === 'pending' || activeStatus === 'running' || activeStatus === 'processing';", $source);
        self::assertStringContainsString('panelEl.open = false;', $source);
        self::assertStringContainsString('active_operation: { operation: normalized, status: queuePanelStatus }', $source);
        self::assertStringNotContainsString("active_operation: { operation: normalized, status: 'running' }", $source);
    }

    public function testGuidedQueueTelemetryDoesNotHijackPreviewViewport(): void
    {
        $runtime = \file_get_contents(BP . '/app/code/GuoLaiRen/PageBuilder/view/templates/Backend/AiSiteAgent/workspace/script-runtime.phtml');
        $styles = \file_get_contents(BP . '/app/code/GuoLaiRen/PageBuilder/view/templates/Backend/AiSiteAgent/workspace/styles-guided.phtml');
        self::assertIsString($runtime);
        self::assertIsString($styles);

        self::assertStringNotContainsString("panels[0].scrollIntoView({ behavior: 'smooth', block: 'start' });", $runtime);
        self::assertStringContainsString('.pb-guided-sidebar .pb-ai-build-queue-embed', $styles);
        self::assertStringContainsString('overflow-wrap: anywhere;', $styles);
    }

    public function testSinglePlanBuildFlowKeepsPlanAndBuildOnly(): void
    {
        $script = $this->workspaceScript();
        $runtime = \file_get_contents(BP . '/app/code/GuoLaiRen/PageBuilder/view/templates/Backend/AiSiteAgent/workspace/script-runtime.phtml');
        $layout = \file_get_contents(BP . '/app/code/GuoLaiRen/PageBuilder/view/templates/Backend/AiSiteAgent/workspace/layout.phtml');

        self::assertIsString($runtime);
        self::assertIsString($layout);

        $ensureBody = $this->extractFunctionBody($script, 'ensureBuildPlanConfirmedBeforeBuild');
        $syncBody = $this->extractFunctionBody($script, 'syncBuildPlanStartButtonState');
        $bindBody = $this->extractFunctionBody($script, 'bindPlanStageLogic');

        self::assertStringContainsString('var useBuildPlanV2Flow = shouldUseBuildPlanV2Flow();', $ensureBody);
        self::assertStringContainsString('startConfirmedBuild(triggerBtn || currentPlanTriggerButton, normalizedTypes, Object.assign({}, opts, {}));', $ensureBody);
        self::assertStringContainsString('var canStartBuildFlow = useBuildPlanV2Flow;', $syncBody);
        self::assertStringContainsString('ensureBuildPlanConfirmedBeforeBuild(startBuildSiteBtn, selectedTypes, {});', $bindBody);
        self::assertStringContainsString("guardRetryableAiFailuresBeforeProgress('plan')", $bindBody);
        self::assertStringContainsString("guardRetryableAiFailuresBeforeProgress('build')", $bindBody);
        self::assertStringNotContainsString('ensureTaskPlanConfirmedBeforeBuild', $script);
        self::assertStringNotContainsString('confirmCurrentTaskPlanAndMaybeBuild', $script);
        self::assertStringNotContainsString('startTaskPlanGenerationForBuild', $script);

        self::assertStringContainsString("renderStageStatusCard('plan'", $runtime);
        self::assertStringContainsString("renderStageStatusCard('build'", $runtime);
        self::assertStringContainsString('data-task-progress-summary="build"', $layout);
        self::assertStringNotContainsString('data-stage-status-card="task_plan"', $layout);
        self::assertStringNotContainsString('pb-ai-workspace-track-btn', $layout);
        self::assertStringNotContainsString('pb-ai-workspace-track-btn', $script);
        self::assertStringNotContainsString('pb-ai-site-ready-dev', $layout);
        self::assertStringNotContainsString('pb-ai-site-ready-dev', $script);
        self::assertStringNotContainsString('site_ready', $layout);
        self::assertStringNotContainsString('site_ready', $script);
        self::assertStringNotContainsString('site_ready', $runtime);
    }

    public function testControllerWorkspacePayloadDoesNotExposeLegacyTaskPlanState(): void
    {
        $source = \file_get_contents(BP . '/app/code/GuoLaiRen/PageBuilder/Controller/Backend/AiSiteAgent.php');
        self::assertIsString($source);
        $body = $this->extractPhpMethodBody($source, 'buildWorkspaceState');

        self::assertStringContainsString("'plan' => true,", $body);
        self::assertStringContainsString("'build' => true,", $body);
        self::assertStringContainsString("'publish' => true,", $body);
        self::assertStringContainsString("'regenerate_page' => true,", $body);
        self::assertStringContainsString("'block_regenerate' => true,", $body);
        self::assertStringContainsString("'block_partial_patch' => true,", $body);
        self::assertStringContainsString("'image_asset' => true,", $body);
        self::assertStringContainsString("'plan' => \$planQueueInfo", $body);
        self::assertStringContainsString("'build' => \$buildQueueInfo", $body);
        self::assertStringNotContainsString("'task_plan' => [", $body);
        self::assertStringNotContainsString("'task_plan_queue_info'", $body);
        self::assertStringNotContainsString('task_plan_stage_entry', $body);
        self::assertStringNotContainsString('has_virtual_theme_plan', $body);
        self::assertStringNotContainsString('initializeTaskPlanActiveOperationFromQueueInfo', $body);
        self::assertStringNotContainsString('autoRerunTaskPlanQueueWhenQueueDoneButDraftMissing', $body);
        self::assertStringNotContainsString('&& $siteReady;', $body);

        $operationPayloadBody = $this->extractPhpMethodBody($source, 'buildWorkspaceOperationPayload');
        self::assertStringContainsString("'page_type_layouts' => \$this->pruneWorkspacePageTypeLayoutsForPayload(\$state)", $operationPayloadBody);
        self::assertStringContainsString('private function pruneWorkspacePageTypeLayoutsForPayload(array $state): array', $source);
    }

    public function testBlockRefineUsesQueuedPartialPatchWithoutLegacySseFallback(): void
    {
        $script = $this->workspaceScript();
        $modalBody = $this->extractFunctionBody($script, 'openRefineComponentModal');
        $submitBody = $this->extractFunctionBody($script, 'submitRefineComponent');

        self::assertStringContainsString('document.body.appendChild(modalEl);', $modalBody);
        self::assertStringContainsString('showBootstrapModal(modalEl);', $modalBody);
        self::assertStringNotContainsString('modalInstance.show();', $modalBody);
        self::assertStringContainsString("if (!startPatchBlockUrl || !window.PbAiOperationRunner", $submitBody);
        self::assertStringContainsString('hideBootstrapModal(modalEl);', $submitBody);
        self::assertStringContainsString('blockRefreshState.blockId = refineComponentState.componentCode;', $submitBody);
        self::assertStringContainsString('renderBlockStreamingState(messages.refineQueued);', $submitBody);
        self::assertStringContainsString('postForm(startPatchBlockUrl, {', $submitBody);
        self::assertStringContainsString('block_id: refineComponentState.blockId,', $submitBody);
        self::assertStringContainsString('component_code: refineComponentState.componentCode,', $submitBody);
        self::assertStringContainsString('window.PbAiOperationRunner.startFromResponse(data)', $submitBody);
        self::assertStringNotContainsString('openBlockSseModal(', $submitBody);
        self::assertStringNotContainsString('startBlockRefineSseUrl', $submitBody);
    }

    public function testBlockRegenerateUsesQueuedOperationRunnerWithoutLegacySseModal(): void
    {
        $script = $this->workspaceScript();
        $regenerateBody = $this->extractFunctionBody($script, 'startBlockRegenerate');

        self::assertStringContainsString("if (!startRefineComponentUrl || !window.PbAiOperationRunner", $regenerateBody);
        self::assertStringContainsString('postForm(startRefineComponentUrl, {', $regenerateBody);
        self::assertStringContainsString("instruction: ''", $regenerateBody);
        self::assertStringContainsString('window.PbAiOperationRunner.startFromResponse(data)', $regenerateBody);
        self::assertStringNotContainsString('openBlockSseModal(', $regenerateBody);
        self::assertStringNotContainsString('startBlockRegenerateSseUrl', $regenerateBody);
    }

    public function testRuntimePartialPatchEventRefreshesOnlyCurrentBlock(): void
    {
        $runtime = \file_get_contents(BP . '/app/code/GuoLaiRen/PageBuilder/view/templates/Backend/AiSiteAgent/workspace/script-runtime.phtml');
        self::assertIsString($runtime);

        self::assertStringContainsString("'build', 'visual_edit', 'regenerate_page', 'block_regenerate', 'block_partial_patch', 'block_refine'", $runtime);
        self::assertStringContainsString("source.addEventListener('block_partial_patch_applied'", $runtime);
        self::assertStringContainsString('hydrateWorkspaceFromState(payload.state);', $runtime);
        self::assertStringContainsString('previewBridge.setVirtualPagesByType(payload.state.virtual_pages_by_type);', $runtime);
        self::assertStringContainsString('runtimeFindNextBlock(pageType, pageState.blocks, blockId)', $runtime);
        self::assertStringContainsString('previewBridge.replaceCurrentBlockHtml(blockRefreshState.pageType, nextBlock)', $runtime);
        self::assertStringContainsString("source.addEventListener('block_partial_patch_failed'", $runtime);
        self::assertStringContainsString("['regenerate_page', 'block_regenerate', 'block_partial_patch', 'block_refine']", $runtime);
    }

    public function testBlockStreamingStateCleanupResolvesThroughWorkspaceApiBridge(): void
    {
        $script = $this->workspaceScript();
        $clearBody = $this->extractFunctionBody($script, 'clearBlockStreamingState');

        self::assertStringContainsString('var workspaceApi = window.__pbWorkspaceApi', $clearBody);
        self::assertStringContainsString("typeof workspaceApi.clearBlockStreamingState === 'function'", $clearBody);
        self::assertStringContainsString('return workspaceApi.clearBlockStreamingState();', $clearBody);
        self::assertStringContainsString("typeof window.clearBlockStreamingState === 'function'", $clearBody);
        self::assertStringContainsString('blockRefreshState.active = false;', $clearBody);

        $runtime = \file_get_contents(BP . '/app/code/GuoLaiRen/PageBuilder/view/templates/Backend/AiSiteAgent/workspace/script-runtime.phtml');
        self::assertIsString($runtime);
        self::assertStringContainsString('workspaceApiRef.clearBlockStreamingState = clearBlockStreamingState;', $runtime);
        self::assertStringContainsString('window.clearBlockStreamingState = clearBlockStreamingState;', $runtime);
    }

    public function testBlockRefreshUsesOperationRunnerWithoutLegacyBlockSseSnapshotFallback(): void
    {
        $script = $this->workspaceScript();

        self::assertStringNotContainsString('fetchWorkspaceSnapshotStateForBlockRefresh', $script);
        self::assertStringNotContainsString('resolvePendingBlockSseResultTarget', $script);
        self::assertStringNotContainsString('applyPendingBlockSseResultWithSnapshot', $script);
        self::assertStringNotContainsString('pendingBlockSseResult', $script);
        self::assertStringNotContainsString('pendingBlockSseStart', $script);

        $runtime = \file_get_contents(BP . '/app/code/GuoLaiRen/PageBuilder/view/templates/Backend/AiSiteAgent/workspace/script-runtime.phtml');
        self::assertIsString($runtime);
        self::assertStringContainsString('function fetchWorkspaceSnapshotState()', $runtime);
        self::assertStringContainsString('workspaceApiRef.fetchWorkspaceSnapshotState = fetchWorkspaceSnapshotState;', $runtime);
    }

    public function testVisualPreviewEmitsComponentActionPayloadWithBlockIdentity(): void
    {
        $script = $this->workspaceScript();
        $bridgeBody = $this->extractFunctionBody($script, 'bindEmbeddedPreviewFrameBridge');
        $payloadBody = $this->extractFunctionBody($script, 'buildEmbeddedPreviewPayload');

        self::assertStringContainsString('var payload = buildEmbeddedPreviewPayload(wrapper, button);', $bridgeBody);
        self::assertStringContainsString('var blockId = resolvePayloadBlockId(wrapper, sourceEl);', $payloadBody);
        self::assertStringContainsString('var componentCode = resolvePayloadComponentCode(wrapper, sourceEl);', $payloadBody);
        self::assertStringContainsString('component: componentCode || blockId,', $payloadBody);
        self::assertStringContainsString('component_code: componentCode || blockId,', $payloadBody);
        self::assertStringContainsString('block_id: blockId || componentCode,', $payloadBody);
        self::assertStringContainsString("page_type: String(", $payloadBody);
        self::assertStringContainsString("|| getActivePreviewPageType()", $payloadBody);
    }

    public function testQueuedVirtualThemeBlockOperationsKeepSourceInTopLevelPayload(): void
    {
        $controller = \file_get_contents(BP . '/app/code/GuoLaiRen/PageBuilder/Controller/Backend/AiSiteAgent.php');
        self::assertIsString($controller);

        $readBody = $this->extractFunctionBody($controller, 'handleStartPatchBlock');
        $detailKeysBody = $this->extractFunctionBody($controller, 'getQueuedOperationDetailKeys');

        self::assertStringContainsString("'source' => \$readSource,", $readBody);
        self::assertStringContainsString("\$targetScope = \$readSource === 'virtual_theme_component'", $readBody);
        self::assertStringContainsString("'source',", $detailKeysBody);
        self::assertStringContainsString("'retry_of_queue_id',", $detailKeysBody);
        self::assertStringContainsString("'retry_source',", $detailKeysBody);
    }

    public function testVisualPreviewFrameUsesAdaptiveHeightWithoutDirectDocumentScrollHeight(): void
    {
        $script = $this->workspaceScript();
        $syncBody = $this->extractFunctionBody($script, 'syncVisualPreviewFrameHeight');

        self::assertStringNotContainsString('function measureVisualPreviewDocumentHeight', $script);
        self::assertStringContainsString('var minHeight = getPreviewDeviceMinHeight();', $syncBody);
        self::assertStringContainsString('var contentHeight = resolveVisualPreviewFrameContentHeight(frame);', $syncBody);
        self::assertStringContainsString('var nextHeight = Math.max(minHeight, contentHeight || 0);', $syncBody);
        self::assertStringContainsString("frame.style.minHeight = minHeight + 'px';", $syncBody);
        self::assertStringContainsString("frame.style.height = nextHeight + 'px';", $syncBody);
        self::assertStringNotContainsString('scrollHeight', $syncBody);
        self::assertStringNotContainsString('clientHeight', $syncBody);
    }

    public function testWorkspaceStateReconcilesGeneratedArtifactsBeforeTaskSummary(): void
    {
        $source = \file_get_contents(BP . '/app/code/GuoLaiRen/PageBuilder/Controller/Backend/AiSiteAgent.php');
        self::assertIsString($source);
        $body = $this->extractFunctionBody($source, 'buildWorkspaceState');

        $virtualPagesAssigned = \strpos($body, '$normalized[\'virtual_pages_by_type\'] = $virtualPagesByType;');
        $reconcile = \strpos($body, '$normalized = $this->buildTaskService->reconcileGeneratedArtifactsWithTaskState($normalized);');
        $summary = \strpos($body, '$taskSummary = $this->buildTaskService->summarize($normalized);');

        self::assertIsInt($virtualPagesAssigned);
        self::assertIsInt($reconcile);
        self::assertIsInt($summary);
        self::assertGreaterThan(
            $virtualPagesAssigned,
            $reconcile,
            'Generated page and shared-component artifacts must be visible before task-state reconciliation.'
        );
        self::assertLessThan(
            $summary,
            $reconcile,
            'Workspace task progress must summarize the reconciled build_tasks state.'
        );
    }

    public function testPublishedVirtualThemeComponentsOverrideDefaultComponentRegistry(): void
    {
        $source = \file_get_contents(BP . '/app/code/GuoLaiRen/PageBuilder/Service/PageRenderService.php');
        self::assertIsString($source);

        $virtualRenderCall = '$componentPath = $modelResolution[\'path\'];';
        $componentConfigAssign = '$this->assign(\'component_config\', $config);';
        self::assertStringContainsString($virtualRenderCall, $source);
        self::assertStringContainsString($componentConfigAssign, $source);
        self::assertStringContainsString('$componentPath = null;', $source);
        self::assertLessThan(
            \strpos($source, $componentConfigAssign),
            \strpos($source, $virtualRenderCall),
            'Resolved component path selection must happen after component config assignment and before template fetch.'
        );
    }

    public function testAiHtmlRenderModePrecedesVisualThemeRendering(): void
    {
        $source = \file_get_contents(BP . '/app/code/GuoLaiRen/PageBuilder/Service/PageRenderService.php');
        self::assertIsString($source);
        $finalizeBody = $this->extractFunctionBody($source, 'finalizeOutput');

        $aiHtmlBranch = \strpos($finalizeBody, 'if ($page->isAiHtmlRenderMode())');
        $visualBranch = \strpos($finalizeBody, 'if ($mode === self::MODE_VISUAL)');

        self::assertIsInt($aiHtmlBranch);
        self::assertIsInt($visualBranch);
        self::assertLessThan(
            $visualBranch,
            $aiHtmlBranch,
            'AI HTML pages must render generated blocks before visual mode can fall back to theme components.'
        );
    }

    public function testPublishCompletionStaysInWorkspaceInsteadOfRedirectingToPageIndex(): void
    {
        $runtime = \file_get_contents(BP . '/app/code/GuoLaiRen/PageBuilder/view/templates/Backend/AiSiteAgent/workspace/script-runtime.phtml');
        self::assertIsString($runtime);
        $finishBody = $this->extractFunctionBody($runtime, 'finishOperation');

        self::assertStringContainsString("if (operation === 'publish') {", $finishBody);
        self::assertLessThan(
            \strpos($finishBody, "var redirectUrl = '';"),
            \strpos($finishBody, "if (operation === 'publish') {"),
            'Publish completion must hydrate workspace state before generic redirect handling.'
        );
    }

    public function testPublishChecklistButtonIsBoundOnWorkspaceLoad(): void
    {
        $script = $this->workspaceScript();
        $definition = \strpos($script, 'function bindPublishStageLogic()');
        $boot = \strrpos($script, "safeWorkspaceBootStep('publish_stage', bindPublishStageLogic);");

        self::assertIsInt($definition);
        self::assertIsInt($boot);
        self::assertGreaterThan(
            $definition,
            $boot,
            'Publish checklist and preview buttons must be bound after the function is defined.'
        );
    }

    public function testWorkspaceBootBindsCurrentStageActions(): void
    {
        $script = $this->workspaceScript();
        $definition = \strpos($script, 'function bindVisualEditStageLogic()');
        $boot = \strpos($script, "var bootStage = String(currentStageCode || currentWorkspaceStage || '').trim().toLowerCase();");

        self::assertIsInt($definition);
        self::assertIsInt($boot);
        self::assertLessThan($boot, $definition, 'Stage binding must run after visual-edit handlers are defined.');
        self::assertStringContainsString("if (bootStage === 'visual_edit')", $script);
        self::assertStringContainsString("['pending', 'queued', 'running', 'processing']", $script);
        self::assertStringContainsString("safeWorkspaceBootStep('visual_edit_stage', bindVisualEditStageLogic);", \substr($script, $boot));
        self::assertStringContainsString("safeWorkspaceBootStep('plan_stage', bindPlanStageLogic);", \substr($script, $boot));
        self::assertStringContainsString("safeWorkspaceBootStep('publish_stage', bindPublishStageLogic);", \substr($script, $boot));
    }

    public function testStartBuildButtonUsesSingleBuildPlanFlow(): void
    {
        $script = $this->workspaceScript();
        $bindBody = $this->extractFunctionBody($script, 'bindPlanStageLogic');
        $continueBody = $this->extractFunctionBody($script, 'continueToBuildAfterPlanConfirm');
        $resumeBody = $this->extractFunctionBody($script, 'startOrObserveBuildFromVisualEditEntry');

        self::assertStringContainsString('var selectedTypes = selectedPageTypes();', $bindBody);
        self::assertStringContainsString('ensureBuildPlanConfirmedBeforeBuild(startBuildSiteBtn, selectedTypes, {});', $bindBody);
        self::assertStringContainsString('buildPlanConfirmedState = !!(state && shouldUseBuildPlanV2Flow(state));', $continueBody);
        self::assertStringContainsString('window.location.href = resolveWorkspaceRedirectUrl(state);', $continueBody);
        self::assertStringContainsString('renderBuildPlanProjectionSummary(state);', $continueBody);
        self::assertStringContainsString("['pending', 'queued', 'running', 'processing'].indexOf(activeStatus) !== -1", $resumeBody);
        self::assertStringNotContainsString("document.getElementById('pb-ai-confirm-task-plan')", $bindBody);
        self::assertStringNotContainsString('ensureTaskPlanConfirmedBeforeBuild', $script);
        self::assertStringNotContainsString('confirmCurrentTaskPlanAndMaybeBuild', $script);
        self::assertStringNotContainsString('startTaskPlanGenerationForBuild', $script);
        self::assertStringNotContainsString("guardRetryableAiFailuresBeforeProgress('task_plan')", $script);
    }

    public function testBuildPlanProjectionFrontendReplacesLegacyTaskPlanPanel(): void
    {
        $script = $this->workspaceScript();
        $planBody = \file_get_contents(BP . '/app/code/GuoLaiRen/PageBuilder/view/templates/Backend/AiSiteAgent/workspace/stages/sections/plan-inline-panel-body.phtml');
        $visualEditCard = \file_get_contents(BP . '/app/code/GuoLaiRen/PageBuilder/view/templates/Backend/AiSiteAgent/workspace/stages/sections/visual-edit-card.phtml');
        $workspace = \file_get_contents(BP . '/app/code/GuoLaiRen/PageBuilder/view/templates/Backend/AiSiteAgent/workspace.phtml');
        $layout = \file_get_contents(BP . '/app/code/GuoLaiRen/PageBuilder/view/templates/Backend/AiSiteAgent/workspace/layout.phtml');
        $runtime = \file_get_contents(BP . '/app/code/GuoLaiRen/PageBuilder/view/templates/Backend/AiSiteAgent/workspace/script-runtime.phtml');

        self::assertIsString($planBody);
        self::assertIsString($visualEditCard);
        self::assertIsString($workspace);
        self::assertIsString($layout);
        self::assertIsString($runtime);

        self::assertStringContainsString('function resolveBuildPlanV2ArtifactsFromWorkspaceState', $script);
        self::assertStringContainsString('function renderBuildPlanProjectionSummary', $script);
        self::assertStringContainsString('function shouldUseBuildPlanV2Flow', $script);
        self::assertStringContainsString('workspaceApi.getBuildPlanConfirmedState = function ()', $script);
        self::assertStringContainsString('workspaceApi.hasBuildPlanV2FlowEvidence = function ()', $script);
        self::assertStringContainsString('renderBuildPlanProjectionSummary(state);', $script);
        self::assertStringNotContainsString('workspaceApi.isLegacyTaskPlanFlowAllowed = function ()', $script);

        self::assertStringContainsString('id="pb-ai-build-plan-v2-summary"', $planBody);
        self::assertStringContainsString('id="pb-ai-confirm-plan"', $planBody);
        self::assertStringContainsString('id="pb-ai-start-build-site"', $visualEditCard);
        self::assertStringContainsString('id="pb-ai-build-queue-embed"', $layout);
        self::assertStringContainsString('data-plan-type="build_plan"', $layout);
        self::assertStringContainsString('$hasConfirmedBuildPlan = $currentStage !== \'plan\' && (', $layout);
        self::assertStringContainsString('!empty($state[\'build_plan_confirmed\'])', $layout);
        self::assertStringContainsString('!empty($state[\'build_plan_confirmed_at\'])', $layout);
        self::assertStringContainsString('var buildReadyForBuild = buildPlanConfirmed || planConfirmed || hasBuildPlanV2;', $runtime);
        self::assertStringContainsString("getWorkspaceFlagState('getBuildPlanConfirmedState', false)", $runtime);

        self::assertStringNotContainsString('$showLegacyTaskPlanPanel', $workspace);
        self::assertStringNotContainsString('$pbAiTaskPlanStageEntryDecision', $workspace);
        self::assertStringNotContainsString('$pbAiPhaseTwoTaskPlanPresent', $workspace);
        self::assertStringNotContainsString('$hasConfirmedTaskPlan', $layout);
        self::assertStringNotContainsString('data-stage-status-card="task_plan"', $layout);
        self::assertStringNotContainsString('legacyTaskPlanFlowEnabled', $runtime);
        self::assertStringNotContainsString('task_plan_confirmed', $runtime);
    }

    public function testLegacyTaskPlanFrontendMutationEntrypointsAreDeleted(): void
    {
        $script = $this->workspaceScript();
        $deletedPanel = BP . '/app/code/GuoLaiRen/PageBuilder/view/templates/Backend/AiSiteAgent/workspace/stages/sections/task-plan-accordion-panel.phtml';
        $deletedProgress = BP . '/app/code/GuoLaiRen/PageBuilder/view/templates/Backend/AiSiteAgent/workspace/script-phase2-queue-progress.phtml';
        $deletedFunctions = [
            'persistTaskPlanSort',
            'showTaskPlanPreviewActionFlow',
            'performStage2BlockOperation',
            'saveTaskPlanDraftThen',
            'startTaskPlanQueueRegenerationFromPanel',
            'startTaskPlanDetectBootstrapSse',
            'startTaskPlanTaskMutationStream',
            'startTaskPlanModeStream',
            'startTaskPlanGenerationForBuild',
            'confirmCurrentTaskPlanAndMaybeBuild',
            'syncTaskPlanConfirmButtonState',
            'applyTaskPlanEditingLockFromActiveOperation',
        ];

        foreach ($deletedFunctions as $functionName) {
            self::assertStringNotContainsString('function ' . $functionName . '(', $script, $functionName . ' must be deleted from the frontend.');
        }
        self::assertStringNotContainsString('buildPlanV2LegacyTaskPlanBlocked', $script);
        self::assertStringNotContainsString('state.show_legacy_task_plan', $script);
        self::assertStringNotContainsString('scope.allow_legacy_task_plan', $script);
        self::assertStringNotContainsString('state.debug_legacy_task_plan', $script);
        self::assertFileDoesNotExist($deletedPanel);
        self::assertFileDoesNotExist($deletedProgress);
    }

    public function testQueuedOperationSseKeepsObserverOpenWhileWaitingForScheduler(): void
    {
        $controller = \file_get_contents(BP . '/app/code/GuoLaiRen/PageBuilder/Controller/Backend/AiSiteAgent.php');
        self::assertIsString($controller);
        $body = $this->extractFunctionBody($controller, 'handleOperationSse');

        self::assertStringContainsString("['pending', 'queued', 'running', 'processing']", $body);
        self::assertStringContainsString('!$this->shouldKeepQueuedObserverStreamOpen($operation)', $body);
        self::assertStringNotContainsString('$queueWaitingForScheduler || !$this->shouldKeepQueuedObserverStreamOpen($operation)', $body);
        self::assertStringContainsString('$maxObserveResumeCycles = 720', $body);
    }

    public function testRuntimeResumesOperationStreamWhenDeferredQueueDoneArrivesBeforeQueueTerminal(): void
    {
        $runtime = \file_get_contents(BP . '/app/code/GuoLaiRen/PageBuilder/view/templates/Backend/AiSiteAgent/workspace/script-runtime.phtml');
        self::assertIsString($runtime);
        $doneBody = $this->extractFunctionBody($runtime, 'startOperationStream');
        $doneHandlerOffset = \strpos($doneBody, "source.addEventListener('done'");
        self::assertNotFalse($doneHandlerOffset, 'operation stream done handler missing');
        $doneHandler = \substr($doneBody, $doneHandlerOffset, 2600);

        self::assertStringContainsString('resumeOperationStreamForQueueWatch(operation, payload', $doneHandler);
        self::assertStringContainsString('deferred-queue-handoff', $doneHandler);
        self::assertStringContainsString('workspaceStateHasBlockingPlanFailures', $runtime);
    }

    public function testRuntimeStartsSseWhenStreamParametersArePresentDespiteQueueWaitHints(): void
    {
        $runtime = \file_get_contents(BP . '/app/code/GuoLaiRen/PageBuilder/view/templates/Backend/AiSiteAgent/workspace/script-runtime.phtml');
        self::assertIsString($runtime);
        $startBody = $this->extractFunctionBody($runtime, 'startFromResponse');
        $observeBody = $this->extractFunctionBody($runtime, 'observeExistingDraftOperationOnLoad');

        self::assertStringContainsString('var hasObservableStreamStart = !!(executionToken && streamUrl);', $startBody);
        self::assertStringContainsString('var shouldDeferToQueuePoll = !hasObservableStreamStart && !!(', $startBody);
        self::assertStringContainsString("['queued', 'pending', 'running', 'processing'].indexOf(status) === -1", $observeBody);
        self::assertStringNotContainsString('!!active.queue_waiting_for_scheduler || !!active.can_close_stream', $observeBody);
    }

    public function testRetryableAiFailuresExposeManualContinueButtonsForPlanAndBuildOnly(): void
    {
        $planPanel = \file_get_contents(BP . '/app/code/GuoLaiRen/PageBuilder/view/templates/Backend/AiSiteAgent/workspace/stages/sections/plan-inline-panel-body.phtml');
        $visualEditPanel = \file_get_contents(BP . '/app/code/GuoLaiRen/PageBuilder/view/templates/Backend/AiSiteAgent/workspace/stages/sections/visual-edit-card.phtml');
        $publishCard = \file_get_contents(BP . '/app/code/GuoLaiRen/PageBuilder/view/templates/Backend/AiSiteAgent/workspace/stages/sections/publish-card.phtml');
        $script = $this->workspaceScript();
        $controller = \file_get_contents(BP . '/app/code/GuoLaiRen/PageBuilder/Controller/Backend/AiSiteAgent.php');

        self::assertIsString($planPanel);
        self::assertIsString($visualEditPanel);
        self::assertIsString($publishCard);
        self::assertIsString($controller);
        self::assertStringContainsString('id="pb-ai-plan-retry-generate"', $planPanel);
        self::assertStringContainsString('重试失败项', $planPanel);
        self::assertStringContainsString('id="pb-ai-retry-build-failures"', $visualEditPanel);
        self::assertStringContainsString('data-retryable-ai-operation="build"', $visualEditPanel);
        self::assertStringContainsString('data-retryable-ai-operation="build"', $publishCard);
        self::assertStringContainsString('完全重建', $visualEditPanel);
        self::assertStringContainsString('buildFullRebuildConfirmMessage', $script);
        self::assertStringContainsString('buildResumeFailedTasks', $script);
        self::assertStringContainsString('function bindRetryableAiFailureButtons()', $script);
        self::assertStringContainsString("operation !== 'plan' && operation !== 'build'", $script);
        self::assertStringContainsString('workspaceApi.retryPhaseOnePlanGeneration = function (options)', $script);
        self::assertStringContainsString('var retryAiOperationUrl =', $script);
        self::assertStringContainsString('function resolveRetryableAiOperationResumePayload(operation)', $script);
        self::assertStringContainsString('postForm(retryAiOperationUrl, retryPayload)', $script);
        self::assertStringContainsString('if (retryPayload && retryAiOperationUrl)', $script);
        self::assertStringNotContainsString('if (retryPayload && isRetryableVisualAiOperation(retryPayload.operation))', $script);
        self::assertStringContainsString('public function postRetryAiOperation(): string', $controller);
        self::assertStringContainsString("post-retry-ai-operation", $controller);
        self::assertStringContainsString('resolveRetryAiOperationFromQueueId', $controller);
        self::assertStringContainsString('startOrObserveBuildFromVisualEditEntry();', $script);
        self::assertStringNotContainsString('pb-ai-retry-task-plan-failures', $planPanel . $visualEditPanel . $publishCard);
        self::assertStringNotContainsString('retryPhaseTwoTaskPlanGeneration', $script);
    }

    public function testStartBuildSiteGuardsPlanThenBuildRetryFailures(): void
    {
        $script = $this->workspaceScript();
        $bindBody = $this->extractFunctionBody($script, 'bindPlanStageLogic');
        $idx = \strpos($bindBody, "document.getElementById('pb-ai-start-build-site')");
        self::assertNotFalse($idx, 'start build button binding missing');
        $snippet = \substr($bindBody, $idx, 1300);

        self::assertStringContainsString("guardRetryableAiFailuresBeforeProgress('plan')", $snippet);
        self::assertStringContainsString("guardRetryableAiFailuresBeforeProgress('build')", $snippet);
        self::assertStringContainsString('ensureBuildPlanConfirmedBeforeBuild(startBuildSiteBtn, selectedTypes, {});', $snippet);
        self::assertStringNotContainsString("guardRetryableAiFailuresBeforeProgress('task_plan')", $snippet);
    }

    public function testHydrateWorkspaceSyncsSingleBuildPlanStateWithoutTaskPlanSse(): void
    {
        $script = $this->workspaceScript();
        $runtime = \file_get_contents(BP . '/app/code/GuoLaiRen/PageBuilder/view/templates/Backend/AiSiteAgent/workspace/script-runtime.phtml');
        self::assertIsString($runtime);
        $hydrateBody = $this->extractFunctionBody($script, 'hydrateWorkspaceFromState');

        self::assertStringContainsString('syncBuildPlanStartButtonState();', $hydrateBody);
        self::assertStringContainsString('syncRetryableAiFailureActionGuards(workspaceState);', $hydrateBody);
        self::assertStringContainsString('build_plan_confirmed', $script);
        self::assertStringContainsString('hasBuildTaskEvidence', $script);
        self::assertStringContainsString('build_plan_confirmed_at', \file_get_contents(BP . '/app/code/GuoLaiRen/PageBuilder/Controller/Backend/AiSiteAgent.php'));
        self::assertStringNotContainsString('syncTaskPlanSseRunningFromWorkspaceState', $script . $runtime);
        self::assertStringNotContainsString('task_plan_queue_info', $script . $runtime);
    }

    public function testBuildStartDoesNotFakeRunningQueueBeforeBackendResponse(): void
    {
        $script = $this->workspaceScript();
        $body = $this->extractFunctionBody($script, 'pbAiConfirmGenerateThemeContinue');
        $postOffset = \strpos($body, 'postForm(runVirtualThemeUrl, requestPayload)');
        self::assertNotFalse($postOffset, 'build request postForm call missing');

        $beforePost = \substr($body, 0, $postOffset);
        self::assertStringNotContainsString('markBuildStageGenerationStarting', $beforePost);
        self::assertStringContainsString('showBuildGuard(messages.buildPreparing);', $beforePost);
    }

    public function testPublishStageStillKeepsPublishControlsAfterRemovingAiQualityRepairEntry(): void
    {
        $layout = \file_get_contents(BP . '/app/code/GuoLaiRen/PageBuilder/view/templates/Backend/AiSiteAgent/workspace/layout.phtml');
        self::assertIsString($layout);
        self::assertStringNotContainsString('id="pb-ai-rebuild-publish-quality"', $layout);

        $visualEditCard = \file_get_contents(BP . '/app/code/GuoLaiRen/PageBuilder/view/templates/Backend/AiSiteAgent/workspace/stages/sections/visual-edit-card.phtml');
        self::assertIsString($visualEditCard);
        self::assertStringNotContainsString('id="pb-ai-rebuild-publish-quality"', $visualEditCard);

        $publishCard = \file_get_contents(BP . '/app/code/GuoLaiRen/PageBuilder/view/templates/Backend/AiSiteAgent/workspace/stages/sections/publish-card.phtml');
        self::assertIsString($publishCard);
        self::assertStringNotContainsString('id="pb-ai-rebuild-publish-quality"', $publishCard);
        self::assertStringContainsString('$pbAiLatestBuildFailed', $publishCard);
        self::assertStringContainsString('$pbAiPublishDisabled', $publishCard);

        $script = $this->workspaceScript();
        $bindBody = $this->extractFunctionBody($script, 'bindPublishStageLogic');
        $visualEditBindBody = $this->extractFunctionBody($script, 'bindVisualEditStageLogic');
        $syncBody = $this->extractFunctionBody($script, 'syncPublishRepairButtonDisabledState');
        $publishStartSyncBody = $this->extractFunctionBody($script, 'syncPublishStartButtonFromWorkspaceState');
        $terminalBody = $this->extractFunctionBody($script, 'markBuildOperationTerminalForUi');
        $resetBody = $this->extractFunctionBody($script, 'resetBuildStartUi');

        self::assertStringContainsString('bindPublishRepairButton();', $bindBody);
        self::assertStringContainsString('bindPublishRepairButton();', $visualEditBindBody);
        $publishRepairBindBody = $this->extractFunctionBody($script, 'bindPublishRepairButton');
        self::assertStringContainsString('bindRetryableAiFailureButtons();', $publishRepairBindBody);
        self::assertStringContainsString('bindRetryableAiFailureButtons();', $syncBody);
        self::assertStringContainsString('syncRetryableAiFailureActionGuards(getLatestWorkspaceStateForQueuePrompt());', $syncBody);
        self::assertStringNotContainsString("document.getElementById('pb-ai-rebuild-publish-quality')", $syncBody);
        self::assertStringContainsString('isOperationRunning = false;', $terminalBody);
        self::assertStringContainsString('isPublishBlockingOperationName(normalizedOperation)', $terminalBody);
        self::assertStringContainsString("previousOperation === 'build'", $terminalBody);
        self::assertStringContainsString('latestWorkspaceState.active_operation = active;', $terminalBody);
        self::assertStringContainsString('latestWorkspaceState.publish_blocked_by_latest_ai_failure = true;', $terminalBody);
        self::assertStringContainsString('syncPublishRepairButtonDisabledState();', $terminalBody);
        self::assertStringContainsString('syncPublishStageEntryFromWorkspaceState(latestWorkspaceState);', $terminalBody);
        self::assertStringContainsString('syncPublishRepairButtonDisabledState();', $resetBody);
        self::assertStringContainsString('hasPublishBlockingLatestBuildFailureFromWorkspaceState(state)', $publishStartSyncBody);
        self::assertStringContainsString("document.querySelectorAll('.pb-ai-set-stage[data-stage=\"publish\"]')", $publishStartSyncBody);
        self::assertStringContainsString('function hasPublishBlockingLatestBuildFailureFromWorkspaceState', $script);
        self::assertStringContainsString('function hasPublishBlockingAiOperationRunningFromWorkspaceState', $script);
        self::assertStringContainsString('messages.publishBlockedLatestBuildFailure', $visualEditBindBody);
        self::assertStringContainsString('hasPublishBlockingAiOperationRunning: function (state)', $script);
        self::assertStringContainsString('markBuildOperationTerminalForUi: markBuildOperationTerminalForUi', $script);

        $runtime = \file_get_contents(BP . '/app/code/GuoLaiRen/PageBuilder/view/templates/Backend/AiSiteAgent/workspace/script-runtime.phtml');
        self::assertIsString($runtime);
        self::assertStringContainsString('function resolveLivePreviewBridge()', $runtime);
        self::assertStringContainsString('function syncTerminalActiveOperationForRuntimeUi(active)', $runtime);
        self::assertStringContainsString('var workspaceSnapshotUrl =', $runtime);
        self::assertStringContainsString('function resolveRuntimeQueueInfoKey(operation)', $runtime);
        self::assertStringContainsString('function startDeferredQueueStatePoll(operation)', $runtime);
        self::assertStringContainsString('submitWorkspaceForm(workspaceSnapshotUrl, { public_id: publicId })', $runtime);
        self::assertStringContainsString('startDeferredQueueStatePoll(operation);', $runtime);
        self::assertStringContainsString('stopDeferredQueueStatePoll();', $runtime);
        self::assertStringContainsString('offerRetryForFailedOperation(op, failurePayload);', $runtime);
        self::assertStringContainsString('var transportErrorSnapshotProbeStarted = false;', $runtime);
        self::assertStringContainsString('fetchWorkspaceSnapshotState().then(function (workspaceState)', $runtime);
        self::assertStringContainsString('syncDeferredQueueWorkspaceState(operation, workspaceState)', $runtime);
        self::assertStringContainsString('function normalizeTerminalOperationStatus(status)', $runtime);
        self::assertStringContainsString("return 'error';", $runtime);
        self::assertStringContainsString('syncTerminalActiveOperationForRuntimeUi(data.active_operation);', $runtime);
        self::assertStringContainsString("markBuildOperationTerminalForRuntimeUi(normalizedDoneOperation, 'error', lastServerError);", $runtime);
        self::assertStringContainsString("markBuildOperationTerminalForRuntimeUi(operation, 'error', lastServerError);", $runtime);
        self::assertStringContainsString('function hasPublishBlockingAiStateForRuntime()', $runtime);
        self::assertStringContainsString('function syncPublishStartButtonForRuntime()', $runtime);
        self::assertStringContainsString("messages.publishBlockedLatestBuildFailure", $runtime);

        $controller = \file_get_contents(BP . '/app/code/GuoLaiRen/PageBuilder/Controller/Backend/AiSiteAgent.php');
        self::assertIsString($controller);
        self::assertStringContainsString('resolvePublishBlockingAiFailureFromWorkspaceState', $controller);
        self::assertStringContainsString('publish_blocked_by_latest_ai_failure', $controller);
        self::assertStringContainsString('Latest AI build completed successfully', $controller);
        self::assertStringContainsString('Current workspace is not ready to publish. Finish AI page generation first.', $controller);

        $sessionService = \file_get_contents(BP . '/app/code/GuoLaiRen/PageBuilder/Service/AiSiteAgentSessionService.php');
        self::assertIsString($sessionService);
        self::assertStringContainsString("'latest_build_failed'", $sessionService);
        self::assertStringContainsString("'latest_build_failure'", $sessionService);
        self::assertStringContainsString("'publish_blocked_by_latest_ai_failure'", $sessionService);
        self::assertStringContainsString("'publish_blocked_reason'", $sessionService);
    }

    public function testPublishStageEntryPrefersRunningBuildStateOverRetryableFailureBanner(): void
    {
        $script = $this->workspaceScript();
        $failureBody = $this->extractFunctionBody($script, 'hasPublishBlockingLatestBuildFailureFromWorkspaceState');
        $entryBody = $this->extractFunctionBody($script, 'syncPublishStageEntryFromWorkspaceState');

        self::assertStringNotContainsString("hasRetryableAiFailures(state, 'build')", $failureBody);
        self::assertStringContainsString('var blockedByRunning = hasPublishBlockingAiOperationRunningFromWorkspaceState(state);', $entryBody);
        self::assertStringContainsString("var building = workspaceStatus === 'building'", $entryBody);
        self::assertStringContainsString('|| blockedByRunning', $entryBody);
    }

    public function testRuntimeFailureDialogShowsDecisionSummaryInsteadOfRawQueueLog(): void
    {
        $runtime = \file_get_contents(BP . '/app/code/GuoLaiRen/PageBuilder/view/templates/Backend/AiSiteAgent/workspace/script-runtime.phtml');
        self::assertIsString($runtime);

        $summaryBody = $this->extractFunctionBody($runtime, 'collectQueueFailureSummaryLines');
        $modalBody = $this->extractFunctionBody($runtime, 'showOperationFailureDetailModal');

        self::assertStringContainsString('function collectQueueFailureSummaryLines(payload, operation)', $runtime);
        self::assertStringContainsString('extractQueueFailureSummary(opMessage) || extractQueueFailureSummary(resultLog)', $summaryBody);
        self::assertStringContainsString('详细日志已保留在队列日志区域，确认弹窗只展示可决策摘要。', $summaryBody);
        self::assertStringContainsString('var lines = collectQueueFailureSummaryLines(payload, operation);', $modalBody);
        self::assertStringNotContainsString("lines.push('队列日志：\\n' + resultLog);", $runtime);
        self::assertStringNotContainsString('var lines = collectQueueFailureDetailLines(payload, operation);', $modalBody);
    }

    public function testLegacyTaskPlanControllerEndpointsAreDeletedInsteadOfRunningOldFlow(): void
    {
        $source = \file_get_contents(BP . '/app/code/GuoLaiRen/PageBuilder/Controller/Backend/AiSiteAgent.php');
        self::assertIsString($source);
        foreach (['postStartTaskPlan', 'postConfirmTaskPlan', 'postSortTaskPlanTasks', 'postMutateTaskPlanTask'] as $methodName) {
            self::assertStringNotContainsString('function ' . $methodName . '(', $source, $methodName . ' must be deleted, not kept as a legacy shim.');
        }
        self::assertStringNotContainsString('legacyTaskPlanEndpointRemovedResponse', $source);
        self::assertStringNotContainsString('handleStartTaskPlan', $source);
        self::assertStringNotContainsString('handleConfirmTaskPlan', $source);
        self::assertStringNotContainsString('handleSortTaskPlanTasks', $source);
        self::assertStringNotContainsString('handleMutateTaskPlanTask', $source);
    }

    private function extractPhpMethodBody(string $source, string $methodName): string
    {
        $needle = 'function ' . $methodName . '(';
        $start = \strpos($source, $needle);
        self::assertIsInt($start, $methodName . ' must exist.');
        $brace = \strpos($source, '{', $start);
        self::assertIsInt($brace, $methodName . ' opening brace must exist.');
        $length = \strlen($source);
        $depth = 0;
        $state = 'normal';
        for ($i = $brace; $i < $length; $i++) {
            $ch = $source[$i];
            $next = $source[$i + 1] ?? '';
            if ($state === 'normal') {
                if ($ch === "'") {
                    $state = 'single';
                    continue;
                }
                if ($ch === '"') {
                    $state = 'double';
                    continue;
                }
                if ($ch === '/' && $next === '/') {
                    $state = 'line_comment';
                    $i++;
                    continue;
                }
                if ($ch === '/' && $next === '*') {
                    $state = 'block_comment';
                    $i++;
                    continue;
                }
                if ($ch === '{') {
                    $depth++;
                } elseif ($ch === '}') {
                    $depth--;
                    if ($depth === 0) {
                        return \substr($source, $start, $i - $start + 1);
                    }
                }
                continue;
            }
            if ($state === 'single') {
                if ($ch === '\\') {
                    $i++;
                    continue;
                }
                if ($ch === "'") {
                    $state = 'normal';
                }
                continue;
            }
            if ($state === 'double') {
                if ($ch === '\\') {
                    $i++;
                    continue;
                }
                if ($ch === '"') {
                    $state = 'normal';
                }
                continue;
            }
            if ($state === 'line_comment') {
                if ($ch === "\n") {
                    $state = 'normal';
                }
                continue;
            }
            if ($state === 'block_comment') {
                if ($ch === '*' && $next === '/') {
                    $state = 'normal';
                    $i++;
                }
                continue;
            }
        }
        self::fail($methodName . ' closing brace must exist.');
    }

    private function workspaceScript(): string
    {
        $script = \file_get_contents(BP . '/app/code/GuoLaiRen/PageBuilder/view/templates/Backend/AiSiteAgent/workspace/script-main.phtml');
        self::assertIsString($script);

        return $script;
    }

    private function extractFunctionBody(string $script, string $functionName): string
    {
        $needle = 'function ' . $functionName . '(';
        $start = \strpos($script, $needle);
        self::assertIsInt($start, $functionName . ' must exist.');
        $next = \strpos($script, "\n    function ", $start + \strlen($needle));
        if ($next === false) {
            return \substr($script, $start);
        }

        return \substr($script, $start, $next - $start);
    }
}

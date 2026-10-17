<?php

declare(strict_types=1);

namespace Weline\Eav\Test\Unit\View;

use PHPUnit\Framework\TestCase;


/**
 * Aggregated contract suite (merged from 9 *ContractTest files).
 * Observe one overall pass/fail via testAllEavViewContractsPass().
 */
final class EavViewContractsTest extends TestCase
{
    public function testAllEavViewContractsPass(): void
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
            'EavManagerAddMenu::testAddButtonIsContextualSingleAction' => function () {
            $this->assert_EavManagerAddMenu_testAddButtonIsContextualSingleAction();
        },
            'EavManagerAttributeEditLock::testAttributeEditLockSurfacesAcrossBackendAndUi' => function () {
            $this->assert_EavManagerAttributeEditLock_testAttributeEditLockSurfacesAcrossBackendAndUi();
        },
            'EavManagerDragDrop::testManagerExposesMoveEndpointAndDragDropUi' => function () {
            $this->assert_EavManagerDragDrop_testManagerExposesMoveEndpointAndDragDropUi();
        },
            'EavManagerEntityScope::testManagerSupportsEntityCodeScopedMode' => function () {
            $this->assert_EavManagerEntityScope_testManagerSupportsEntityCodeScopedMode();
        },
            'EavManagerIndexTemplate::testManagerConfigUsesResolvedBackendUrlNotTaglibLiteral' => function () {
            $this->assert_EavManagerIndexTemplate_testManagerConfigUsesResolvedBackendUrlNotTaglibLiteral();
        },
            'EavManagerLocalTranslation::testManagerIntegratesLocalTranslationAndI18nUi' => function () {
            $this->assert_EavManagerLocalTranslation_testManagerIntegratesLocalTranslationAndI18nUi();
        },
            'EavManagerOptionsSurface::testManagerUiListsOptionsAndStructureBadges' => function () {
            $this->assert_EavManagerOptionsSurface_testManagerUiListsOptionsAndStructureBadges();
        },
            'EavManagerSelectAttribute::testSelectAttributeModeSupportsMultiPick' => function () {
            $this->assert_EavManagerSelectAttribute_testSelectAttributeModeSupportsMultiPick();
        },
            'EavManagerSelectionMode::testSelectSetModeAllowsExpandButOnlySetIsSelectable' => function () {
            $this->assert_EavManagerSelectionMode_testSelectSetModeAllowsExpandButOnlySetIsSelectable();
        },
        ];
    }

private function assert_EavManagerAddMenu_testAddButtonIsContextualSingleAction(): void
{
    $surface = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Eav' . '/view/templates/backend/Manager/surface.phtml');
    $js = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline' . '/Theme/view/statics/ui/pages/weline-eav-manager.js');
    $css = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline' . '/Theme/view/statics/ui/pages/weline-eav-manager.css');
    self::assertStringContainsString('data-w-eav-add', $surface);
    self::assertStringContainsString('data-w-eav-add-label', $surface);
    self::assertStringContainsString('data-w-eav-detail-header', $surface);
    self::assertStringContainsString('选中实体添加属性集', $surface);
    self::assertStringNotContainsString('data-w-eav-add-menu', $surface);
    self::assertStringNotContainsString('data-w-eav-attribute-dialog', $surface);
    self::assertStringContainsString('appendQuickAddPanel', $js);
    self::assertStringNotContainsString('data-w-eav-add hidden', $surface);
    self::assertStringContainsString('addChildTypeForSelection', $js);
    self::assertStringContainsString('resolveAddContext', $js);
    self::assertStringContainsString('updateAddButton', $js);
    self::assertStringNotContainsString('updateAddMenu', $js);
    self::assertStringNotContainsString('openAttributeAddDialog', $js);
    self::assertStringContainsString('w-eav-manager__detail-actions', $css);
}

private function assert_EavManagerAttributeEditLock_testAttributeEditLockSurfacesAcrossBackendAndUi(): void
{
    $js = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline' . '/Theme/view/statics/ui/pages/weline-eav-manager.js');
    $tpl = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Eav' . '/view/templates/backend/Manager/surface.phtml');
    $controller = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Eav' . '/Controller/Backend/Manager.php');
    self::assertStringContainsString('assertAttributeEditable', $controller);
    self::assertStringContainsString('enrichAttributeDetail', $controller);
    self::assertStringContainsString("'can_edit'", $controller);
    self::assertStringContainsString("'can_edit_options'", $controller);
    self::assertStringContainsString("'edit_lock_reason'", $controller);
    self::assertStringContainsString('系统属性不可修改', $controller);
    self::assertStringContainsString('属性已有实例数据，不可修改', $controller);
    self::assertStringContainsString('属性选项已更新', $controller);
    self::assertStringContainsString('editLockedSystem', $tpl);
    self::assertStringContainsString('editLockedInUse', $tpl);
    self::assertStringContainsString('appendEditLockNotice', $js);
    self::assertStringContainsString('w-eav-manager__edit-lock', $js);
    self::assertStringContainsString('values.can_edit === false', $js);
    self::assertStringContainsString('can_edit_options', $js);
    self::assertStringContainsString('optionsEditable', $js);
    self::assertStringContainsString('dataset.wEavReadonly', $js);
    self::assertStringContainsString('attributeReadOnly', $js);
}

private function assert_EavManagerDragDrop_testManagerExposesMoveEndpointAndDragDropUi(): void
{
    $controller = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Eav' . '/Controller/Backend/Manager.php');
    $js = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline' . '/Theme/view/statics/ui/pages/weline-eav-manager.js');
    $css = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline' . '/Theme/view/statics/ui/pages/weline-eav-manager.css');
    self::assertStringContainsString('postMove', $controller);
    self::assertStringContainsString('moveGroupToSet', $controller);
    self::assertStringContainsString('moveAttributeToGroup', $controller);
    self::assertStringContainsString("request('move', 'POST'", $js);
    self::assertStringContainsString('data-w-eav-drag', $js);
    self::assertStringContainsString('data-w-eav-drop', $js);
    self::assertStringContainsString('group: \'set\'', $js);
    self::assertStringContainsString('attribute: \'group\'', $js);
    self::assertStringContainsString('.is-drop-target', $css);
}

private function assert_EavManagerEntityScope_testManagerSupportsEntityCodeScopedMode(): void
{
    $controller = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Eav' . '/Controller/Backend/Manager.php');
    $tpl = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Eav' . '/view/templates/backend/Manager/surface.phtml');
    $index = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Eav' . '/view/templates/backend/Manager/index.phtml');
    $js = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline' . '/Theme/view/statics/ui/pages/weline-eav-manager.js');
    self::assertStringContainsString('resolveEntityScopeFromRequest', $controller);
    self::assertStringContainsString('entity_code', $controller);
    self::assertStringContainsString('getEntityChildren($scope[\'entityId\'])', $controller);
    self::assertStringContainsString("'entityScope'", $index);
    self::assertStringContainsString('templates/backend/Manager/surface.phtml', $index);
    self::assertStringContainsString('w-eav-manager--scoped', $tpl);
    self::assertStringContainsString('entityScope', $js);
    self::assertStringContainsString('entity_code', $js);
    self::assertStringContainsString('entityScope?.entityId', $js);
}

private function assert_EavManagerIndexTemplate_testManagerConfigUsesResolvedBackendUrlNotTaglibLiteral(): void
{
    $index = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Eav' . '/view/templates/backend/Manager/index.phtml');
    $surface = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Eav' . '/view/templates/backend/Manager/surface.phtml');
    self::assertStringContainsString('Weline_Eav::templates/backend/Manager/surface.phtml', $index);
    self::assertStringContainsString("'apiBase' => \$this->getUrl('eav/backend/manager')", $surface);
    self::assertStringNotContainsString("@backend-url(\"eav/backend/manager\")", $surface);
}

private function assert_EavManagerLocalTranslation_testManagerIntegratesLocalTranslationAndI18nUi(): void
{
    $controller = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Eav' . '/Controller/Backend/Manager.php');
    $service = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Eav' . '/Service/LocalTranslation/EavLocalTranslationService.php');
    $queue = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Eav' . '/Queue/EavLocalTranslationQueue.php');
    $tpl = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Eav' . '/view/templates/backend/Manager/surface.phtml');
    $js = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Eav' . '/view/statics/js/eav-manager-native.js');
    self::assertStringContainsString('postLocalAiTranslate', $controller);
    self::assertStringContainsString('EAV 定时翻译入口已移除', $controller);
    self::assertStringContainsString('normalizeLocalizedStructureRow', $controller);
    self::assertStringContainsString('source_name', $controller);
    self::assertStringContainsString('saveLocalizedField', $controller);
    self::assertStringContainsString('isSourceLocale', $service);
    self::assertStringContainsString('I18nAiTranslationAdapter', $service);
    self::assertStringContainsString('collectEntityWorkItems', $service);
    self::assertStringContainsString('EavLocalTranslationQueue', $queue);
    self::assertStringContainsString('"localBase"', $tpl);
    self::assertStringNotContainsString('data-w-eav-i18n-schedule', $tpl);
    self::assertStringContainsString('mountLocalTranslationActions', $js);
    self::assertStringContainsString('values.local_name', $js);
    self::assertStringContainsString('sourceValue', $js);
    self::assertStringNotContainsString('scheduleLocalTranslation', $js);
    self::assertStringNotContainsString('updateI18nScheduleButton', $js);
}

private function assert_EavManagerOptionsSurface_testManagerUiListsOptionsAndStructureBadges(): void
{
    $js = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline' . '/Theme/view/statics/ui/pages/weline-eav-manager.js');
    $tpl = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Eav' . '/view/templates/backend/Manager/surface.phtml');
    $controller = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Eav' . '/Controller/Backend/Manager.php');
    self::assertStringContainsString('optionsPanel', $js);
    self::assertStringContainsString('structureLabels', $js);
    self::assertStringContainsString('w-eav-manager__options-list', $js);
    self::assertStringContainsString("'structures'", $tpl);
    self::assertStringContainsString('optionsTitle', $tpl);
    self::assertStringContainsString('optionStructureStats', $controller);
    self::assertStringContainsString("'structures'", $controller);
    self::assertStringContainsString('swatch_color', $controller);
}

private function assert_EavManagerSelectAttribute_testSelectAttributeModeSupportsMultiPick(): void
{
    $script = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Eav' . '/view/statics/js/eav-manager-native.js');
    self::assertStringContainsString('selectionPicks', $script);
    self::assertStringContainsString('data-w-eav-pick', $script);
    self::assertStringContainsString('toggleAttributePick', $script);
    self::assertStringContainsString('weline:eav:reload-tree', $script);
}

private function assert_EavManagerSelectionMode_testSelectSetModeAllowsExpandButOnlySetIsSelectable(): void
{
    $controller = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Eav' . '/Controller/Backend/Manager.php');
    $js = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Eav' . '/view/statics/js/eav-manager-native.js');
    $tpl = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Eav' . '/view/templates/backend/Manager/surface.phtml');
    $css = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Eav' . '/view/statics/css/eav-manager.css');
    self::assertStringNotContainsString('applySelectionModeToTree', $controller);
    self::assertStringNotContainsString("resolveSelectionModeFromRequest() === 'select-set'", $controller);
    self::assertStringContainsString('selection_mode', $js);
    self::assertStringContainsString("uiMode === 'select-set'", $js);
    self::assertStringContainsString('is-selectable', $js);
    self::assertStringContainsString('is-previewable', $js);
    self::assertStringContainsString('resolveSetTreeItem', $js);
    self::assertStringContainsString('pickSelectSetNode', $js);
    self::assertStringNotContainsString('selectSetPreview', $js);
    self::assertStringNotContainsString('is-readonly-preview', $js);
    self::assertStringContainsString("uiMode === 'select-attribute'", $js);
    self::assertStringContainsString('updateSelectConfirmState', $js);
    self::assertStringContainsString('data-w-eav-tree-toolbar', $tpl);
    self::assertStringContainsString('selectSetHint', $tpl);
    self::assertStringContainsString('可展开并编辑属性组、属性与选项', $tpl);
    self::assertStringContainsString('w-eav-manager--select-set', $css);
    self::assertStringContainsString('.is-selectable', $css);
    self::assertStringContainsString('.is-previewable', $css);
}
}

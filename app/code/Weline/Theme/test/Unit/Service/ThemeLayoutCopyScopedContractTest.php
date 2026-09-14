<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\Service;

use PHPUnit\Framework\TestCase;

\defined('BP') || \define('BP', \dirname(__DIR__, 7) . \DIRECTORY_SEPARATOR);

/**
 * Greenfield: layout identity copy must use scoped workspace writes, not theme_layout rows.
 */
final class ThemeLayoutCopyScopedContractTest extends TestCase
{
    public function testCopyLayoutIdentityUsesScopedWriter(): void
    {
        $source = (string)\file_get_contents(
            BP . 'app/code/Weline/Theme/Service/ThemeLayoutService.php',
        );
        self::assertStringContainsString('ThemeScopedLayoutWriteService', $source);
        self::assertStringContainsString('replaceDraftFromSnapshot', $source);
        self::assertStringContainsString('function copyLayoutIdentityBetweenThemes', $source);

        $methodStart = \strpos($source, 'function copyLayoutIdentityBetweenThemes');
        self::assertNotFalse($methodStart);
        $nextFn = \strpos($source, "\n    public function ", $methodStart + 10);
        $methodBody = $nextFn === false
            ? \substr($source, (int)$methodStart)
            : \substr($source, (int)$methodStart, $nextFn - (int)$methodStart);
        self::assertStringContainsString('replaceDraftFromSnapshot', $methodBody);
        self::assertStringNotContainsString('$this->saveLayout(', $methodBody);
        self::assertStringNotContainsString('$this->saveWidget(', $methodBody);
    }

    public function testFactoryResetAvoidsMysqlBackticks(): void
    {
        $source = (string)\file_get_contents(
            BP . 'app/code/Weline/Theme/Service/ThemeFactoryResetService.php',
        );
        self::assertStringNotContainsString('TRUNCATE TABLE `', $source);
        self::assertStringContainsString('RESTART IDENTITY CASCADE', $source);
        self::assertStringContainsString("IN ('theme.frontend','theme.backend')", $source);
    }

    public function testSaveLayoutUsesScopedWriterNotThemeLayoutRows(): void
    {
        $source = (string)\file_get_contents(
            BP . 'app/code/Weline/Theme/Service/ThemeLayoutService.php',
        );
        $methodStart = \strpos($source, 'function saveLayout(');
        self::assertNotFalse($methodStart);
        $nextFn = \strpos($source, "\n    public function ", $methodStart + 10);
        $methodBody = $nextFn === false
            ? \substr($source, (int)$methodStart)
            : \substr($source, (int)$methodStart, $nextFn - (int)$methodStart);
        self::assertStringContainsString('replaceDraftFromSnapshot', $methodBody);
        self::assertStringContainsString('ThemeScopedLayoutWriteService', $methodBody);
        self::assertStringNotContainsString('$this->saveWidget(', $methodBody);
        self::assertStringNotContainsString('$this->deleteLayoutRows(', $methodBody);
    }

    public function testPublishLayoutUsesScopedWorkspacePublish(): void
    {
        $source = (string)\file_get_contents(
            BP . 'app/code/Weline/Theme/Service/ThemeLayoutService.php',
        );
        $methodStart = \strpos($source, 'function publishLayout(');
        self::assertNotFalse($methodStart);
        $nextFn = \strpos($source, "\n    public function ", $methodStart + 10);
        $methodBody = $nextFn === false
            ? \substr($source, (int)$methodStart)
            : \substr($source, (int)$methodStart, $nextFn - (int)$methodStart);
        self::assertStringContainsString('$workspace->publish(', $methodBody);
        self::assertStringNotContainsString('$this->saveWidget(', $methodBody);
        self::assertStringNotContainsString('$this->deleteLayoutRows(', $methodBody);
    }

    public function testLegacyLayoutLoaderAlwaysEmpty(): void
    {
        $source = (string)\file_get_contents(
            BP . 'app/code/Weline/Theme/Service/Scoped/ThemeScopedResourceProjector.php',
        );
        $methodStart = \strpos($source, 'function loadLegacyLayout(');
        self::assertNotFalse($methodStart);
        $nextFn = \strpos($source, "\n    private function ", $methodStart + 10);
        $methodBody = $nextFn === false
            ? \substr($source, (int)$methodStart)
            : \substr($source, (int)$methodStart, $nextFn - (int)$methodStart);
        self::assertStringContainsString("'nodes' => []", $methodBody);
        self::assertStringNotContainsString('fetchArray()', $methodBody);
    }

    public function testLegacyAdapterDropsThemeLayoutDependency(): void
    {
        $projector = (string)\file_get_contents(
            BP . 'app/code/Weline/Theme/Service/Scoped/ThemeScopedResourceProjector.php',
        );
        self::assertStringNotContainsString('use Weline\\Theme\\Model\\ThemeLayout;', $projector);
        self::assertStringNotContainsString('ThemeLayout $layouts', $projector);
        self::assertStringContainsString('class ThemeScopedResourceProjector', $projector);
        self::assertStringContainsString('ThemeScopedAppearanceProjector $appearanceProjector', $projector);
        self::assertStringContainsString('ThemeScopedI18nProjector $i18nProjector', $projector);

        $i18n = (string)\file_get_contents(
            BP . 'app/code/Weline/Theme/Service/Scoped/ThemeScopedI18nProjector.php',
        );
        self::assertStringContainsString('identifyForNodeOwner', $i18n);
        self::assertStringContainsString('nodeUidFromTranslationWord', $i18n);

        self::assertFileDoesNotExist(
            BP . 'app/code/Weline/Theme/Service/Scoped/ThemeScopedCompatibilityAdapter.php',
        );
        self::assertFileDoesNotExist(
            BP . 'app/code/Weline/Theme/Service/Scoped/ThemeLegacyResourceAdapter.php',
        );

        $module = (string)\file_get_contents(BP . 'app/code/Weline/Theme/etc/module.php');
        self::assertStringContainsString('ThemeScopedResourceProjector::class', $module);
        self::assertStringNotContainsString('ThemeScopedCompatibilityAdapter', $module);
        self::assertStringNotContainsString('ThemeLegacyResourceAdapter', $module);
    }

    public function testThemeEditorJsPrefersNodeUidIdentityAttrs(): void
    {
        foreach ([
            BP . 'app/code/Weline/Theme/view/statics/js/theme-editor.js',
            BP . 'app/code/Weline/Theme/view/statics/ui/pages/weline-theme-editor.js',
        ] as $path) {
            $source = (string)\file_get_contents($path);
            self::assertStringContainsString('function resolveDomWidgetIdentity', $source, $path);
            self::assertStringContainsString('function widgetIdentityDataAttrs', $source, $path);
            self::assertStringContainsString('data-node-uid=', $source, $path);
            self::assertStringContainsString('resolveDomWidgetIdentity(i18nBtn)', $source, $path);
            self::assertStringContainsString("return dataAttributeSelector('data-node-uid', nodeUid);", $source, $path);
            self::assertStringNotContainsString(':is(${dataAttributeSelector(\'data-node-uid\'', $source, $path);
            self::assertStringContainsString('widgetIdentityDataAttrs(layoutId)', $source, $path);
            self::assertStringContainsString('.widget-wrapper[data-node-uid]', $source, $path);
            self::assertStringContainsString('resolveDomWidgetIdentity(widget).layoutId', $source, $path);
            self::assertStringContainsString('resolveDomWidgetIdentity(targetWidget).layoutId', $source, $path);
            self::assertStringContainsString("querySelectorAll('[data-node-uid], [data-layout-id]')", $source, $path);
            self::assertStringContainsString('widgetIdentityDataAttrs(widget.layoutId)', $source, $path);
            self::assertStringContainsString("querySelectorAll('.widget-wrapper[data-node-uid], .widget-wrapper[data-layout-id]')", $source, $path);
            self::assertStringContainsString("querySelectorAll('[data-layout-id], [data-node-uid]')", $source, $path);
            self::assertStringContainsString('resolveDomWidgetIdentity(widgetEl).layoutId', $source, $path);
            self::assertStringContainsString('resolveDomWidgetIdentity(form).layoutId', $source, $path);
            self::assertStringContainsString('resolveDomWidgetIdentity(selectedWidget).layoutId', $source, $path);
            self::assertStringContainsString('resolveDomWidgetIdentity(widgetElement).layoutId', $source, $path);
            self::assertStringContainsString('function resolveWidgetDeleteTarget', $source, $path);
            self::assertStringContainsString('resolveDomWidgetIdentity(bar || button).layoutId', $source, $path);
            self::assertStringContainsString('const WIDGET_WRAPPER_MATCH', $source, $path);
            self::assertStringContainsString('const WIDGET_IDENTITY_MATCH', $source, $path);
            self::assertStringContainsString('const PREVIEW_OR_CODE_WIDGET_MATCH', $source, $path);
            self::assertStringContainsString('dataLayoutIdSelector(sourceLayoutId)', $source, $path);
            self::assertStringContainsString('function applyWidgetIdentityToElement', $source, $path);
            self::assertStringContainsString('Hex identity is node_uid only', $source, $path);
            self::assertStringContainsString('element.removeAttribute(\'data-layout-id\')', $source, $path);
        }
    }

    public function testThemeEditorFixtureFailClosedWithoutLayoutTable(): void
    {
        $source = (string)\file_get_contents(
            BP . 'app/code/Weline/Theme/test/e2e/backend/theme-editor-fixture.php',
        );
        self::assertStringContainsString('theme_layout_table_exists', $source);
        self::assertStringContainsString('theme_layout_missing_scoped_authority', $source);
        self::assertStringContainsString('cleanup_scoped_layout_workspaces', $source);
        self::assertStringContainsString('snapshot_scoped_layout_workspaces', $source);
        self::assertStringContainsString('prepare_scoped_layout_fixture', $source);
        self::assertStringContainsString("action === 'prepare_scoped_layout'", $source);
    }

    public function testSwapWidgetOrderAcceptsNodeUidKeys(): void
    {
        $source = (string)\file_get_contents(
            BP . 'app/code/Weline/Theme/Controller/Backend/ThemeEditor.php',
        );
        $methodStart = \strpos($source, 'function postSwapWidgetOrder(');
        self::assertNotFalse($methodStart);
        $nextFn = \strpos($source, "\n    public function ", $methodStart + 10);
        $methodBody = $nextFn === false
            ? \substr($source, (int)$methodStart)
            : \substr($source, (int)$methodStart, $nextFn - (int)$methodStart);
        self::assertStringContainsString('node_uid_1', $methodBody);
        self::assertStringContainsString('resolveSortKeyToNodeUid', $methodBody);
        self::assertStringNotContainsString('$layoutId1 = (int)($body[\'layout_id_1\']', $methodBody);
    }

    public function testLegacyMutatorsFailClosedWithoutLayoutTable(): void
    {
        $source = (string)\file_get_contents(
            BP . 'app/code/Weline/Theme/Service/ThemeLayoutService.php',
        );
        foreach ([
            'function updateWidgetConfig(',
            'function updateSortOrder(',
            'function moveWidget(',
            'function swapWidgetOrder(',
            'function getSlotWidgets(',
            'function updateSlotWidgetsOrder(',
        ] as $signature) {
            $methodStart = \strpos($source, $signature);
            self::assertNotFalse($methodStart, $signature . ' missing');
            $nextFn = \strpos($source, "\n    public function ", $methodStart + 10);
            $methodBody = $nextFn === false
                ? \substr($source, (int)$methodStart)
                : \substr($source, (int)$methodStart, $nextFn - (int)$methodStart);
            self::assertStringContainsString('legacyLayoutTableExists()', $methodBody, $signature);
        }
    }

    public function testApplyDefaultInjectionRecognizesScopedNodeUid(): void
    {
        $source = (string)\file_get_contents(
            BP . 'app/code/Weline/Theme/Controller/Backend/ThemeEditor.php',
        );
        $methodStart = \strpos($source, 'function postApplyDefaultInjection(');
        self::assertNotFalse($methodStart);
        $nextFn = \strpos($source, "\n    public function ", $methodStart + 10);
        $methodBody = $nextFn === false
            ? \substr($source, (int)$methodStart)
            : \substr($source, (int)$methodStart, $nextFn - (int)$methodStart);
        self::assertStringContainsString('hasNodeUid', $methodBody);
        self::assertStringContainsString("\$item['node_uid']", $methodBody);
        self::assertStringNotContainsString("empty(\$item['layout_id'])", $methodBody);
    }

    public function testClearTemplateTombstonesFailClosedWithoutLayoutTable(): void
    {
        $source = (string)\file_get_contents(
            BP . 'app/code/Weline/Theme/Service/ThemeLayoutService.php',
        );
        $methodStart = \strpos($source, 'function clearTemplateDeletedTombstonesForSlot(');
        self::assertNotFalse($methodStart);
        $nextFn = \strpos($source, "\n    public function ", $methodStart + 10);
        $methodBody = $nextFn === false
            ? \substr($source, (int)$methodStart)
            : \substr($source, (int)$methodStart, $nextFn - (int)$methodStart);
        self::assertStringContainsString('legacyLayoutTableExists()', $methodBody);
    }

    public function testMetaProjectionDelegatesToMetaProjector(): void
    {
        $projector = (string)\file_get_contents(
            BP . 'app/code/Weline/Theme/Service/Scoped/ThemeScopedResourceProjector.php',
        );
        self::assertStringContainsString('ThemeScopedMetaProjector $metaProjector', $projector);
        self::assertStringContainsString('ThemeScopedProjectionSupport $support', $projector);
        self::assertStringContainsString('$this->metaProjector->load(', $projector);
        self::assertStringContainsString('$this->metaProjector->project(', $projector);
        self::assertStringNotContainsString('private function projectMeta(', $projector);

        $meta = (string)\file_get_contents(
            BP . 'app/code/Weline/Theme/Service/Scoped/ThemeScopedMetaProjector.php',
        );
        self::assertStringContainsString('class ThemeScopedMetaProjector', $meta);
        self::assertStringContainsString('function load(', $meta);
        self::assertStringContainsString('function project(', $meta);

        $support = (string)\file_get_contents(
            BP . 'app/code/Weline/Theme/Service/Scoped/ThemeScopedProjectionSupport.php',
        );
        self::assertStringContainsString('class ThemeScopedProjectionSupport', $support);
        self::assertStringContainsString('function metaResourceIdentity(', $support);
    }

    public function testAppearanceAndI18nProjectionDelegates(): void
    {
        $projector = (string)\file_get_contents(
            BP . 'app/code/Weline/Theme/Service/Scoped/ThemeScopedResourceProjector.php',
        );
        self::assertStringContainsString('$this->appearanceProjector->load(', $projector);
        self::assertStringContainsString('$this->appearanceProjector->project(', $projector);
        self::assertStringContainsString('$this->i18nProjector->load(', $projector);
        self::assertStringContainsString('$this->i18nProjector->project(', $projector);
        self::assertStringNotContainsString('private function projectAppearance(', $projector);
        self::assertStringNotContainsString('private function projectI18n(', $projector);

        $appearance = (string)\file_get_contents(
            BP . 'app/code/Weline/Theme/Service/Scoped/ThemeScopedAppearanceProjector.php',
        );
        self::assertStringContainsString('class ThemeScopedAppearanceProjector', $appearance);
        self::assertStringContainsString('disk_active.', $appearance);

        $i18n = (string)\file_get_contents(
            BP . 'app/code/Weline/Theme/Service/Scoped/ThemeScopedI18nProjector.php',
        );
        self::assertStringContainsString('class ThemeScopedI18nProjector', $i18n);
        self::assertStringContainsString('theme_i18n_projection_node_identity_missing', $i18n);
    }

    public function testBindingProjectionDelegates(): void
    {
        $projector = (string)\file_get_contents(
            BP . 'app/code/Weline/Theme/Service/Scoped/ThemeScopedResourceProjector.php',
        );
        self::assertStringContainsString('ThemeScopedBindingProjector $bindingProjector', $projector);
        self::assertStringContainsString('$this->bindingProjector->load(', $projector);
        self::assertStringContainsString('$this->bindingProjector->project(', $projector);
        self::assertStringContainsString('$this->bindingProjector->assertPayload(', $projector);
        self::assertStringNotContainsString('private function projectGlobalThemeBinding(', $projector);
        self::assertStringNotContainsString('WelineTheme $themes', $projector);

        $binding = (string)\file_get_contents(
            BP . 'app/code/Weline/Theme/Service/Scoped/ThemeScopedBindingProjector.php',
        );
        self::assertStringContainsString('class ThemeScopedBindingProjector', $binding);
        self::assertStringContainsString('theme_binding_theme_id_invalid', $binding);
        self::assertStringContainsString('IS_ACTIVE_BACKEND', $binding);
    }

    public function testServerPreviewHtmlPrefersNodeUidIdentityAttrs(): void
    {
        $slotRenderer = (string)\file_get_contents(
            BP . 'app/code/Weline/Theme/Service/SlotRendererService.php',
        );
        self::assertStringContainsString('Hex identity is node_uid only', $slotRenderer);
        self::assertStringContainsString("\$attrs['data-node-uid'] = \$nodeUid;", $slotRenderer);
        self::assertStringContainsString('} elseif ($layoutId > 0) {', $slotRenderer);
        self::assertStringContainsString("\$attrs['data-layout-id'] = (string)\$layoutId;", $slotRenderer);
        self::assertStringContainsString("attrFromTag(\$openTag, 'data-layout-id')", $slotRenderer);
        self::assertStringContainsString("attrFromTag(\$openTag, 'data-node-uid')", $slotRenderer);
        self::assertSame(
            2,
            \substr_count($slotRenderer, "attrFromTag(\$openTag, 'data-node-uid')"),
        );

        $widgetItem = (string)\file_get_contents(
            BP . 'app/code/Weline/Theme/view/templates/backend/ThemeEditor/widget-item.phtml',
        );
        self::assertStringContainsString('Hex identity is node_uid only', $widgetItem);
        self::assertStringContainsString('$identityAttrs', $widgetItem);
        self::assertStringContainsString('data-node-uid="', $widgetItem);

        $configForm = (string)\file_get_contents(
            BP . 'app/code/Weline/Theme/view/templates/backend/ThemeEditor/config-form.phtml',
        );
        self::assertStringContainsString('Hex identity is node_uid only', $configForm);
        self::assertStringContainsString('$formIdentityAttrs', $configForm);
        self::assertStringNotContainsString(
            "data-layout-id=\"@var(\$widget['layout_id'] ?? '')\"",
            $configForm,
        );

        $preview = (string)\file_get_contents(
            BP . 'app/code/Weline/Theme/view/templates/backend/ThemeEditor/preview.phtml',
        );
        self::assertStringContainsString('function themeEditorWidgetIdentityAttr', $preview);
        self::assertStringContainsString('Hex identity is node_uid only', $preview);
        self::assertStringNotContainsString('data-layout-id="%d"', $preview);

        $cacheGen = (string)\file_get_contents(
            BP . 'app/code/Weline/Theme/Service/ThemeCacheGenerator.php',
        );
        self::assertStringContainsString('Hex identity is node_uid only', $cacheGen);
        self::assertStringContainsString('data-node-uid="', $cacheGen);

        $observer = (string)\file_get_contents(
            BP . 'app/code/Weline/Theme/Observer/LayoutSlotRenderer.php',
        );
        self::assertStringContainsString("str_contains(\$html, 'data-node-uid=')", $observer);
    }

    public function testWidgetParamTypePrefersNodeUidIdentityAttrs(): void
    {
        $abstract = (string)\file_get_contents(
            BP . 'app/code/Weline/Widget/Ui/ParamType/AbstractParamType.php',
        );
        self::assertStringContainsString('function widgetIdentityAttrMap', $abstract);
        self::assertStringContainsString('function widgetIdentityAttrHtml', $abstract);
        self::assertStringContainsString('Hex identity is node_uid only', $abstract);
        self::assertStringContainsString("return ['data-node-uid' => \$nodeUid];", $abstract);
        self::assertStringContainsString("return ['data-layout-id' => \$raw];", $abstract);
        self::assertStringContainsString('self::widgetIdentityAttrHtml($layoutId)', $abstract);
        // Legacy hard dual-write of data-layout-id next to data-field must be gone.
        self::assertStringNotContainsString(
            "data-field=\"' . htmlspecialchars(\$key) . '\" data-layout-id=\"' . htmlspecialchars((string)\$layoutId)",
            $abstract,
        );

        $renderer = (string)\file_get_contents(
            BP . 'app/code/Weline/Widget/Service/ParamTypeRenderer.php',
        );
        self::assertStringContainsString('AbstractParamType::widgetIdentityAttrMap', $renderer);
        self::assertStringContainsString('AbstractParamType::widgetIdentityAttrHtml', $renderer);
        self::assertStringNotContainsString("'data-layout-id' => (string)\$layoutId", $renderer);
        self::assertStringNotContainsString(
            "data-layout-id=\"' . htmlspecialchars((string)\$layoutId) . '\"",
            $renderer,
        );
    }

    public function testPreviewIframeScriptsPreferNodeUidIdentity(): void
    {
        foreach ([
            BP . 'app/code/Weline/Theme/view/statics/js/editor-mode.js',
            BP . 'app/code/Weline/Theme/view/statics/ui/pages/weline-theme-preview.js',
        ] as $path) {
            $source = (string)\file_get_contents($path);
            self::assertStringContainsString('function resolvePreviewWidgetIdentity', $source, $path);
            self::assertStringContainsString('Hex identity is node_uid only', $source, $path);
            self::assertStringContainsString('.widget-wrapper[data-node-uid], .widget-wrapper[data-layout-id]', $source, $path);
            self::assertStringContainsString('resolvePreviewWidgetIdentity(target)', $source, $path);
            self::assertStringNotContainsString(
                "'.widget-wrapper[data-layout-id], [data-layout-id], .widget-wrapper[data-widget-code]'",
                $source,
                $path,
            );
        }

        $previewTpl = (string)\file_get_contents(
            BP . 'app/code/Weline/Theme/view/templates/backend/ThemeEditor/preview.phtml',
        );
        self::assertStringContainsString('function resolvePreviewWidgetIdentity', $previewTpl);
        self::assertStringContainsString('resolvePreviewWidgetIdentity(this)', $previewTpl);
        self::assertStringNotContainsString('const layoutId = this.dataset.layoutId;', $previewTpl);

        $e2e = (string)\file_get_contents(
            BP . 'app/code/Weline/Theme/test/e2e/backend/theme-editor-default-injections.spec.js',
        );
        self::assertStringContainsString('function rowWidgetIdentity', $e2e);
        self::assertStringContainsString('function structureWidgetLocator', $e2e);
        self::assertStringContainsString('data-node-uid="${safe}"', $e2e);
        self::assertStringNotContainsString(
            '.preview-widget-item[data-layout-id="${firstLayoutId}"]',
            $e2e,
        );
        self::assertStringNotContainsString('expect(firstLayoutId).toBeGreaterThan(0)', $e2e);
    }

    public function testThemeEditorWorkflowsE2ePrefersNodeUid(): void
    {
        $e2e = (string)\file_get_contents(
            BP . 'app/code/Weline/Theme/test/e2e/backend/theme-editor-workflows.spec.js',
        );
        self::assertStringContainsString('buttonSave.data?.node_uid', $e2e);
        self::assertStringContainsString('cardSave.data?.node_uid', $e2e);
        self::assertStringContainsString('widget-config?node_uid=${buttonNodeUid}', $e2e);
        self::assertStringContainsString('node_uid: buttonNodeUid', $e2e);
        self::assertStringContainsString('[buttonNodeUid]: 0', $e2e);
        self::assertStringContainsString('[cardNodeUid]: 1', $e2e);
        self::assertStringNotContainsString('buttonSave.data?.layout_id', $e2e);
        self::assertStringNotContainsString('widget-config?layout_id=', $e2e);

        $editor = (string)\file_get_contents(
            BP . 'app/code/Weline/Theme/Controller/Backend/ThemeEditor.php',
        );
        self::assertStringContainsString('function normalizeEditorSortDataPayload', $editor);
        self::assertStringContainsString('normalizeEditorSortDataPayload($body[\'sort_data\']', $editor);

        $welineEditor = (string)\file_get_contents(
            BP . 'app/code/Weline/Theme/view/statics/ui/pages/weline-theme-editor.js',
        );
        self::assertStringContainsString(
            ".preview-widget-item, .widget-wrapper[data-node-uid], .widget-wrapper[data-layout-id]",
            $welineEditor,
        );
        self::assertStringNotContainsString(
            "querySelectorAll('.preview-widget-item, .widget-wrapper[data-layout-id]')",
            $welineEditor,
        );
    }

    public function testMaybeWrapWidgetHtmlPrefersNodeUidIdentity(): void
    {
        $source = (string)\file_get_contents(
            BP . 'app/code/Weline/Theme/Service/SlotRendererService.php',
        );
        $methodStart = \strpos($source, 'function maybeWrapWidgetHtml(');
        self::assertNotFalse($methodStart);
        $nextFn = \strpos($source, "\n    private function ", $methodStart + 10);
        self::assertNotFalse($nextFn);
        $methodBody = \substr($source, $methodStart, $nextFn - $methodStart);
        self::assertStringContainsString("\$widget['node_uid']", $methodBody);
        self::assertStringContainsString('$hasIdentity = $nodeUid !== \'\' || $layoutId > 0;', $methodBody);
        self::assertStringContainsString('if (!$hasIdentity && $healthPayload === null)', $methodBody);
        self::assertStringNotContainsString("if (\$layoutId === '' && \$healthPayload === null)", $methodBody);
    }

    public function testPublishedLayoutDoesNotInheritAcrossLocales(): void
    {
        $workspaceSource = (string)\file_get_contents(
            BP . 'app/code/Weline/Theme/Service/Scoped/ThemeScopedWorkspace.php',
        );
        $methodStart = \strpos($workspaceSource, 'function shouldInheritDefaultLocalePublished(');
        self::assertNotFalse($methodStart);
        $nextFn = \strpos($workspaceSource, "\n    private function parentPublishedState(", $methodStart + 10);
        self::assertNotFalse($nextFn);
        $methodBody = \substr($workspaceSource, $methodStart, $nextFn - $methodStart);
        // LAYOUT/META identity is always default; inherit helper is a permanent no-op.
        self::assertStringContainsString('return false;', $methodBody);
        self::assertStringNotContainsString('RESOURCE_LAYOUT', $methodBody);
    }

    public function testScopedLayoutSnapshotAllowsInheritedEffectivePayload(): void
    {
        $source = (string)\file_get_contents(
            BP . 'app/code/Weline/Theme/Controller/Backend/ThemeEditor.php',
        );
        $methodStart = \strpos($source, 'function scopedLayoutSnapshot(');
        self::assertNotFalse($methodStart);
        $nextFn = \strpos($source, "\n    private function ", $methodStart + 10);
        self::assertNotFalse($nextFn);
        $methodBody = \substr($source, $methodStart, $nextFn - $methodStart);
        self::assertStringContainsString('effective_release_id', $methodBody);
        self::assertStringContainsString('hasInheritedEffective', $methodBody);
        self::assertStringContainsString('theme_scoped_layout_workspace_missing', $methodBody);
        self::assertStringNotContainsString(
            "if ((int)(\$state['draft_revision_id'] ?? 0) <= 0\n            && (int)(\$state['published_release_id'] ?? 0) <= 0\n        )",
            $methodBody,
        );
    }

    public function testEditorPayloadStoreModeKeepsNullForGlobalAndWebsite(): void
    {
        $ui = (string)\file_get_contents(
            BP . 'app/code/Weline/Theme/view/statics/ui/pages/weline-theme-editor.js',
        );
        $legacy = (string)\file_get_contents(
            BP . 'app/code/Weline/Theme/view/statics/js/theme-editor.js',
        );
        foreach ([$ui, $legacy] as $source) {
            self::assertStringContainsString('function payloadStoreModeFromScopeIdentity', $source);
            self::assertStringContainsString("kind === 'global' || kind === 'website'", $source);
            self::assertStringContainsString('store_mode: payloadStoreModeFromScopeIdentity(identityPayload)', $source);
            self::assertStringNotContainsString(
                "store_mode: identityPayload.store_mode || getCurrentWindowParam('store_mode') || 'normal'",
                $source,
            );
        }
    }
}

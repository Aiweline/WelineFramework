<?php

declare(strict_types=1);

namespace Weline\MediaManager\Test\Unit\View;

use PHPUnit\Framework\TestCase;


/**
 * Aggregated contract suite (merged from 4 *ContractTest files).
 * Observe one overall pass/fail via testAllMediaManagerViewContractsPass().
 */
final class MediaManagerViewContractsTest extends TestCase
{
    public function testAllMediaManagerViewContractsPass(): void
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
            'IdentityBarCollapse::testManagerTemplateDefaultsCollapsedWithToggle' => function () {
            $this->assert_IdentityBarCollapse_testManagerTemplateDefaultsCollapsedWithToggle();
        },
            'IdentityBarCollapse::testManagerJsCollapsesByDefaultAndExpandsOnToggle' => function () {
            $this->assert_IdentityBarCollapse_testManagerJsCollapsesByDefaultAndExpandsOnToggle();
        },
            'IdentityBarCollapse::testManagerCssHidesCollapsedPanel' => function () {
            $this->assert_IdentityBarCollapse_testManagerCssHidesCollapsedPanel();
        },
            'ManagerJsBinQuery::testManagerJsUsesResourceConnectorAndSameOriginMultipartUpload' => function () {
            $this->assert_ManagerJsBinQuery_testManagerJsUsesResourceConnectorAndSameOriginMultipartUpload();
        },
            'ManagerJsBinQuery::testDragPasteAndMoveUseTypedAccessibleConnectorContract' => function () {
            $this->assert_ManagerJsBinQuery_testDragPasteAndMoveUseTypedAccessibleConnectorContract();
        },
            'ManagerJsBinQuery::testDirectoryItemsRemainAccessibleWhenDoubleClickDeliveryIsInterrupted' => function () {
            $this->assert_ManagerJsBinQuery_testDirectoryItemsRemainAccessibleWhenDoubleClickDeliveryIsInterrupted();
        },
            'ManagerJsBinQuery::testPickerEnforcesLockedRootTypeAndSizeBeforeSelection' => function () {
            $this->assert_ManagerJsBinQuery_testPickerEnforcesLockedRootTypeAndSizeBeforeSelection();
        },
            'ManagerJsBinQuery::testStandalonePageTitleAndIframeHidesPageHeader' => function () {
            $this->assert_ManagerJsBinQuery_testStandalonePageTitleAndIframeHidesPageHeader();
        },
            'ManagerJsBinQuery::testIframeSingleSelectAlwaysRendersOneConfirmControl' => function () {
            $this->assert_ManagerJsBinQuery_testIframeSingleSelectAlwaysRendersOneConfirmControl();
        },
            'ManagerJsBinQuery::testEmbeddedPickerKeepsBottomContentReachableWithoutPixelHeightLocks' => function () {
            $this->assert_ManagerJsBinQuery_testEmbeddedPickerKeepsBottomContentReachableWithoutPixelHeightLocks();
        },
            'ManagerJsBinQuery::testDirectoryContextMenuIsProviderAwareAccessibleAndViewportBounded' => function () {
            $this->assert_ManagerJsBinQuery_testDirectoryContextMenuIsProviderAwareAccessibleAndViewportBounded();
        },
            'ManagerJsBinQuery::testDescriptorPublishesConnectorAndGenerate' => function () {
            $this->assert_ManagerJsBinQuery_testDescriptorPublishesConnectorAndGenerate();
        },
            'ManagerJsBinQuery::testConnectorDescriptorAcceptsFrontendOpenPayloadKeys' => function () {
            $this->assert_ManagerJsBinQuery_testConnectorDescriptorAcceptsFrontendOpenPayloadKeys();
        },
            'ManagerJsBinQuery::testConnectorDescriptorAcceptsFrontendLocaleAndUploadMetadataKeys' => function () {
            $this->assert_ManagerJsBinQuery_testConnectorDescriptorAcceptsFrontendLocaleAndUploadMetadataKeys();
        },
            'ManagerJsBinQuery::testLocaleCodeIsSentThroughDeclaredWorkerParameterForEveryAssetRead' => function () {
            $this->assert_ManagerJsBinQuery_testLocaleCodeIsSentThroughDeclaredWorkerParameterForEveryAssetRead();
        },
            'ManagerJsBinQuery::testFileMetadataAppearsInSelectionPanelAndContextDetailsDialog' => function () {
            $this->assert_ManagerJsBinQuery_testFileMetadataAppearsInSelectionPanelAndContextDetailsDialog();
        },
            'ManagerJsBinQuery::testUploadUiHandlesNamelessClipboardFilesAndFolderDropTargetsSafely' => function () {
            $this->assert_ManagerJsBinQuery_testUploadUiHandlesNamelessClipboardFilesAndFolderDropTargetsSafely();
        },
            'ManagerJsBinQuery::testSelectMissingAssetMetadataPromptsInsteadOfToastOnly' => function () {
            $this->assert_ManagerJsBinQuery_testSelectMissingAssetMetadataPromptsInsteadOfToastOnly();
        },
            'ManagerJsBinQuery::testTypedSelectionDoesNotExposeStorageLocationsOrResolvedUrls' => function () {
            $this->assert_ManagerJsBinQuery_testTypedSelectionDoesNotExposeStorageLocationsOrResolvedUrls();
        },
            'ManagerJsBinQuery::testRuntimeConfigAndTranslationsAreJsonEncodedForJavascriptContext' => function () {
            $this->assert_ManagerJsBinQuery_testRuntimeConfigAndTranslationsAreJsonEncodedForJavascriptContext();
        },
            'ManagerJsBinQuery::testGenerateDescriptorAcceptsFrontendAiPayloadKeys' => function () {
            $this->assert_ManagerJsBinQuery_testGenerateDescriptorAcceptsFrontendAiPayloadKeys();
        },
            'OptionalOverwriteTargetPick::testManagerSurfacesOptionalOverwritePickBeforeConflictResolution' => function () {
            $this->assert_OptionalOverwriteTargetPick_testManagerSurfacesOptionalOverwritePickBeforeConflictResolution();
        },
            'OptionalOverwriteTargetPick::testAiDrawServiceAllowsOverwriteViaReplaceExistingFile' => function () {
            $this->assert_OptionalOverwriteTargetPick_testAiDrawServiceAllowsOverwriteViaReplaceExistingFile();
        },
            'UploadConflictAutoExtension::testManagerJsDefinesAndUsesEnsureUploadFileExtension' => function () {
            $this->assert_UploadConflictAutoExtension_testManagerJsDefinesAndUsesEnsureUploadFileExtension();
        },
        ];
    }

private function assert_IdentityBarCollapse_testManagerTemplateDefaultsCollapsedWithToggle(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/MediaManager' . '/view/templates/backend/Manager/manager.phtml';
    self::assertFileExists($path);
    $src = (string) file_get_contents($path);
    self::assertStringContainsString('mmf-identity-bar is-collapsed', $src);
    self::assertStringContainsString('data-mmf-identity-toggle', $src);
    self::assertStringContainsString('data-mmf-identity-summary', $src);
    self::assertStringContainsString('data-mmf-identity-panel', $src);
    self::assertStringContainsString('aria-expanded="false"', $src);
    self::assertStringContainsString('data-mmf-identity-panel hidden', $src);
}

private function assert_IdentityBarCollapse_testManagerJsCollapsesByDefaultAndExpandsOnToggle(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/MediaManager' . '/view/statics/js/manager.js';
    self::assertFileExists($path);
    $src = (string) file_get_contents($path);
    self::assertStringContainsString('function setIdentityBarExpanded', $src);
    self::assertStringContainsString('function identitySummaryText', $src);
    self::assertStringContainsString('setIdentityBarExpanded(false)', $src);
    self::assertStringContainsString('data-mmf-identity-toggle', $src);
}

private function assert_IdentityBarCollapse_testManagerCssHidesCollapsedPanel(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/MediaManager' . '/view/statics/css/manager.css';
    self::assertFileExists($path);
    $src = (string) file_get_contents($path);
    self::assertStringContainsString('.mmf-identity-bar__panel[hidden]', $src);
    self::assertStringContainsString('.mmf-identity-bar__summary', $src);
    self::assertStringContainsString('.mmf-identity-bar__toggle', $src);
}

private function assert_ManagerJsBinQuery_testManagerJsUsesResourceConnectorAndSameOriginMultipartUpload(): void
{
    $path = BP . '/app/code/Weline/MediaManager/view/statics/js/manager.js';
    self::assertFileExists($path);
    $source = (string) file_get_contents($path);
    self::assertStringContainsString("resource('media_manager')", $source);
    self::assertStringContainsString("mmResource('connector'", $source);
    self::assertStringContainsString('function resolveBackendApiHost()', $source);
    self::assertStringContainsString('var candidate = window;', $source);
    self::assertStringContainsString('while (candidate)', $source);
    self::assertStringContainsString('candidate.location.origin !== window.location.origin', $source);
    self::assertStringContainsString('candidate.document.querySelectorAll(\'meta[name="weline-worker-backend-bootstrap"]\').length === 1', $source);
    self::assertStringContainsString('candidate = candidate.parent;', $source);
    self::assertStringNotContainsString('return window.parent;', $source);
    self::assertStringContainsString('var host = resolveBackendApiHost();', $source);
    self::assertStringContainsString('host.Weline.load(\'api\')', $source);
    self::assertStringContainsString('function uploadMultipart(fileList, metadataList, targetHash)', $source);
    self::assertStringContainsString('endpoint = new URL(CONNECTOR, document.baseURI)', $source);
    self::assertStringContainsString('endpoint.origin !== window.location.origin', $source);
    self::assertStringContainsString('CONFIG.connectorFormKey', $source);
    self::assertStringContainsString('var body = new FormData()', $source);
    self::assertStringContainsString('var xhr = new XMLHttpRequest()', $source);
    self::assertStringContainsString("xhr.open('POST', endpoint.href, true)", $source);
    self::assertStringContainsString('xhr.withCredentials = true', $source);
    self::assertStringContainsString('xhr.status === 413', $source);
    self::assertStringContainsString("t('uploadRequestTooLarge')", $source);
    self::assertStringContainsString('!response || !Array.isArray(response.added) || response.added.length !== files.length', $source);
    self::assertStringContainsString("t('uploadResponseMismatch')", $source);
    self::assertStringContainsString('function connectorNativeRequest(params)', $source);
    self::assertStringContainsString('function appendConnectorFormField(body, key, value)', $source);
    self::assertStringContainsString("body.append(key + '[]', String(item))", $source);
    self::assertStringContainsString('if (IFRAME_MODE)', $source);
    self::assertStringContainsString("op === 'connector'", $source);
    self::assertSame(3, substr_count($source, 'new XMLHttpRequest()'));
    self::assertStringNotContainsString('upload_base64', $source);
    self::assertStringNotContainsString('formDataToConnectorPayload', $source);
    self::assertStringContainsString('var API_MAX_UPLOAD_FILE_BYTES = 14 * 1024 * 1024', $source);
    self::assertStringContainsString('var API_MAX_UPLOAD_FILES = 100', $source);
    self::assertStringContainsString("cmd: 'tree'", $source);
    self::assertDoesNotMatchRegularExpression('/tree:\s*1\b/', $source);
    self::assertStringNotContainsString('Weline.Api.request(', $source);
    self::assertStringNotContainsString('Weline.Api.get(', $source);
    self::assertStringNotContainsString('Weline.Api.post(', $source);
    self::assertDoesNotMatchRegularExpression('/\bfetch\s*\(/', $source);
}

private function assert_ManagerJsBinQuery_testDragPasteAndMoveUseTypedAccessibleConnectorContract(): void
{
    $js = (string) \file_get_contents(BP . '/app/code/Weline/MediaManager/view/statics/js/manager.js');
    $template = (string) \file_get_contents(BP . '/app/code/Weline/MediaManager/view/templates/backend/Manager/manager.phtml');
    $style = (string) \file_get_contents(BP . '/app/code/Weline/MediaManager/view/statics/css/manager.css');
    self::assertStringContainsString('application/x-weline-media-files', $js);
    self::assertStringContainsString('function isExternalFileDrag(dataTransfer)', $js);
    self::assertStringContainsString('if (!INTERNAL_DRAG_TARGETS.length) return false;', $js);
    self::assertStringContainsString('activeTargets.indexOf(hash) >= 0', $js);
    self::assertStringContainsString('function bindMediaSearch()', $js);
    self::assertStringContainsString("cmd: 'search'", $js);
    self::assertStringContainsString("cmd: 'find_asset'", $js);
    self::assertStringContainsString('function applyFocusAssetId()', $js);
    self::assertStringContainsString("urlParams.get('asset_id')", $js);
    self::assertStringContainsString('openDetails: true', $js);
    self::assertStringContainsString('function navigateToSearchResult(entry)', $js);
    self::assertStringContainsString('mmf-search-input', $template);
    self::assertStringContainsString('mmf-sidebar-search', $style);
    self::assertStringContainsString('clipboard.items', $js);
    self::assertStringContainsString('API_MAX_UPLOAD_FILE_BYTES', $js);
    self::assertStringContainsString('function findOversizedUploadFile(fileList)', $js);
    self::assertStringContainsString('total > limit', $js);
    self::assertStringContainsString('function findDisallowedUploadFile(fileList)', $js);
    self::assertStringContainsString('SAFE_UPLOAD_EXTENSIONS', $js);
    self::assertStringContainsString('BLOCKED_UPLOAD_EXTENSIONS', $js);
    self::assertStringContainsString('re-intersect with SAFE_UPLOAD_EXTENSIONS', $js);
    self::assertStringContainsString('upload-ext-contract:v2', $js);
    self::assertStringContainsString("'m4a'", $js);
    self::assertStringContainsString("'flac'", $js);
    self::assertStringContainsString("'aac'", $js);
    // Regression: never re-filter explicit ext= against SAFE (would drop m4a).
    self::assertDoesNotMatchRegularExpression('/configuredUploadExtensions\(\)\s*\{[\s\S]{0,800}SAFE_UPLOAD_EXTENSIONS\.indexOf/', $js);
    self::assertStringContainsString("t('fileSizeExceeded'", $js);
    self::assertMatchesRegularExpression('/findOversizedUploadFile\(fileList\)[\s\S]*source === \'drop\' \|\| source === \'paste\'[\s\S]*promptOptionalOverwriteTargets\(files, targetHash\)[\s\S]*resolveUploadNameConflicts\(mapped\.files, targetHash, mapped\.overwriteFlags\)[\s\S]*requestUploadMetadata\(resolvedFiles, overwriteFlags\)/', $js);
    self::assertStringContainsString('function promptOptionalOverwriteTargets(fileList, targetHash)', $js);
    self::assertStringContainsString('function directoryChildFiles(targetHash)', $js);
    self::assertStringContainsString('mmf-overwrite-pick-overlay', $template);
    self::assertStringContainsString('data-mmf-overwrite-pick-rows', $template);
    self::assertStringNotContainsString('mmf-overwrite-dock', $template);
    self::assertStringContainsString('mmf-ai-save-target-pick', $template);
    self::assertStringContainsString("value=\"replace_target\"", $template);
    self::assertStringContainsString('function syncAiSaveModeFields()', $js);
    self::assertStringContainsString('function renderAiSaveTargetCandidates()', $js);
    self::assertStringContainsString("t('overwritePickDuplicateTarget'", $js);
    self::assertStringContainsString("t('uploadModePrompt')", $js);
    self::assertStringContainsString("upload: t('confirmUploadOnly')", $js);
    self::assertStringContainsString("translate: t('uploadWithOneClickTranslate')", $js);
    self::assertStringNotContainsString("label: t('confirmUploadOnly')", $js);
    self::assertStringContainsString('function setLocaleWorkbenchBusy(busy, mode)', $js);
    self::assertStringContainsString('function setLocaleActionButtonBusy(btn, busy, loadingText)', $js);
    self::assertStringContainsString('data-mmf-locale-loading-overlay', $js);
    self::assertStringContainsString("setLocaleWorkbenchBusy(true, 'translate')", $js);
    self::assertStringContainsString("t('localeTranslatingHint')", $js);
    self::assertStringContainsString('function requestLocaleTempFill(code)', $js);
    self::assertStringContainsString('function runAssetTranslateMissing(targetLocales, onSuccessMessage)', $js);
    self::assertStringContainsString("payload.target_locales = targetLocales", $js);
    self::assertStringContainsString("btn.addEventListener('dblclick'", $js);
    self::assertStringContainsString("t('localeWorkbenchHint')", $js);
    self::assertStringContainsString('data-mmf-locale-workbench-hint', $template);
    self::assertStringContainsString('function suggestUniqueUploadFileName(name, reservedNames)', $js);
    self::assertStringContainsString('function ensureUploadFileExtension(name, originalName)', $js);
    self::assertMatchesRegularExpression('/function confirmUploadName\([\s\S]*?ensureUploadFileExtension\(/', $js);
    self::assertStringContainsString('function resolveUploadNameConflicts(fileList, targetHash, presetOverwriteFlags)', $js);
    self::assertStringContainsString("t('uploadNameConflictMessage'", $js);
    self::assertStringContainsString("t('uploadOverwriteExisting')", $js);
    self::assertStringContainsString("t('uploadOverwriteConfirmMessage'", $js);
    self::assertStringContainsString("action: 'overwrite'", $js);
    self::assertStringContainsString('overwriteFlags', $js);
    self::assertStringContainsString("overwrite: true", $js);
    self::assertStringContainsString('secondaryLabel: t(\'uploadOverwriteExisting\')', $js);
    self::assertStringContainsString('mmf-dialog-secondary', $template);
    self::assertStringContainsString('function bindDirectoryDropTarget(el)', $js);
    self::assertStringContainsString("api({cmd: 'move', targets: eligible, target: destinationHash}", $js);
    self::assertStringContainsString('draggable="true"', $js);
    self::assertStringContainsString('role="status" aria-live="polite"', $template);
    self::assertStringContainsString('role="progressbar"', $template);
    self::assertStringContainsString('.mmf-item.internal-dragover', $style);
    self::assertStringContainsString('@media (prefers-reduced-motion: reduce)', $style);
    self::assertStringContainsString("body.append('upload_metadata', JSON.stringify(metadataList || []))", $js);
    self::assertStringNotContainsString("drop.classList.toggle('visible')", $js);
    self::assertDoesNotMatchRegularExpression('/\bfetch\s*\(/', $js);
}

private function assert_ManagerJsBinQuery_testDirectoryItemsRemainAccessibleWhenDoubleClickDeliveryIsInterrupted(): void
{
    $path = BP . '/app/code/Weline/MediaManager/view/statics/js/manager.js';
    self::assertFileExists($path);
    $source = (string) file_get_contents($path);
    self::assertStringContainsString('role="button" tabindex="0"', $source);
    self::assertStringContainsString('function openDirectoryFromInteraction(hash, renderedMime)', $source);
    self::assertStringContainsString('var requestSerial = ++OPEN_REQUEST_SERIAL', $source);
    self::assertStringContainsString('loadStorages().then(function(ready)', $source);
    self::assertStringContainsString('if (!ready)', $source);
    self::assertStringContainsString('requestSerial !== OPEN_REQUEST_SERIAL', $source);
    self::assertStringContainsString("wrap.dataset.openState = 'pending'", $source);
    self::assertStringContainsString("wrap.dataset.openState = 'done'", $source);
    self::assertStringContainsString('FILES[data.cwd.hash] = data.cwd', $source);
    self::assertStringContainsString('openDirectoryFromInteraction(hash, el.dataset.mime)', $source);
    self::assertStringContainsString('e.detail >= 2 && openDirectoryFromInteraction(hash, el.dataset.mime)', $source);
    self::assertStringContainsString("e.key !== 'Enter'", $source);
    self::assertStringContainsString('openDirectoryFromInteraction(selectedHash, renderedMime)', $source);
    self::assertStringContainsString("} else if (window.parent && window.parent !== window) {", $source);
    self::assertStringContainsString("CONFIG.target = String(e.data.target).trim()", $source);
    self::assertStringContainsString("target: CONFIG.target || ''", $source);
    self::assertStringContainsString('function setupIframeMode(options)', $source);
    self::assertStringContainsString('IFRAME_MODE = true', $source);
    self::assertStringNotContainsString('} else if (IFRAME_MODE && GET_FILE_CALLBACK) {', $source);
}

private function assert_ManagerJsBinQuery_testPickerEnforcesLockedRootTypeAndSizeBeforeSelection(): void
{
    $source = (string) file_get_contents(BP . '/app/code/Weline/MediaManager/view/statics/js/manager.js');
    self::assertStringContainsString('function canOpenDirectoryHash(hash)', $source);
    self::assertStringContainsString('function isPathWithinLockedRoot(path)', $source);
    self::assertStringContainsString('function isTreeItemOutsideLock(path)', $source);
    self::assertStringContainsString('mmf-tree-item--outside-lock', $source);
    self::assertStringContainsString('if (CONFIG.lockPath && !isInit && !canOpenDirectoryHash(target))', $source);
    self::assertStringContainsString('if (CONFIG.lockPath) lastHash = null;', $source);
    self::assertStringContainsString('function resolveHashForPath(path)', $source);
    self::assertStringContainsString('function updatePathLockButton()', $source);
    self::assertStringContainsString('function setPathLockEnabled(enabled)', $source);
    self::assertStringContainsString("payload.lockRoot = CONFIG.lockRoot", $source);
    self::assertStringContainsString("body.append('lockPath', CONFIG.lockPath ? '1' : '0')", $source);
    self::assertStringContainsString('CONFIG.lockRoot = normalizeBoundaryPath(CONFIG.lockRoot || \'\')', $source);
    self::assertStringContainsString('mmf-btn-path-lock', (string) file_get_contents(BP . '/app/code/Weline/MediaManager/view/templates/backend/Manager/manager.phtml'));
    self::assertStringContainsString('mmf-path-row', (string) file_get_contents(BP . '/app/code/Weline/MediaManager/view/templates/backend/Manager/manager.phtml'));
    self::assertStringContainsString('FILES[cur] || TREE[cur]', $source);
    self::assertStringContainsString('mmf-path-row--locked', $source);
    self::assertStringContainsString('lockRoot:', (string) file_get_contents(BP . '/app/code/Weline/MediaManager/view/templates/backend/Manager/manager.phtml'));
    self::assertStringContainsString('assertLockedRelativePath', (string) file_get_contents(BP . '/app/code/Weline/MediaManager/Service/ConnectorService.php'));
    self::assertStringContainsString("assign('lock_root'", (string) file_get_contents(BP . '/app/code/Weline/MediaManager/Controller/Backend/Manager.php'));
    self::assertStringContainsString('function fileSelectionIssue(file)', $source);
    self::assertStringContainsString('function configuredSelectionExtensions()', $source);
    self::assertStringContainsString('ALLOWED_MIMES.some(function(allowedMime)', $source);
    self::assertStringContainsString('mmf-item-disabled-', $source);
    self::assertStringContainsString("var itemLabel = String(f.name || '')", $source);
    self::assertStringNotContainsString('aria-disabled="true" data-selection-error=', $source);
    self::assertStringContainsString('aria-pressed="', $source);
    self::assertStringContainsString("el.setAttribute('aria-pressed', selected ? 'true' : 'false')", $source);
    self::assertStringContainsString('var issue = fileSelectionIssue(f);', $source);
    self::assertStringNotContainsString("tree.addEventListener('click'", $source);
    self::assertStringContainsString('function configuredAspectRatio()', $source);
    self::assertStringContainsString("kind: 'aspect_ratio'", $source);
    self::assertStringContainsString('function updateAspectRatioHint()', $source);
    self::assertStringContainsString("['aspect_ratio', 'aspectRatio']", $source);
    self::assertStringContainsString('(absDiff / target) <= tolerance', $source);
    self::assertStringContainsString('aspectRatio:', (string) file_get_contents(BP . '/app/code/Weline/MediaManager/view/templates/backend/Manager/manager.phtml'));
    self::assertStringContainsString('var hasSelectionError = !!(selectionError && FILES[hash] && FILES[hash].mime !== \'directory\');', $source);
    self::assertStringContainsString('if (hasSelectionError) showError(selectionError);', $source);
    self::assertStringContainsString('function updatePreviewPanel()', $source);
    self::assertStringContainsString('updatePreviewPanel();', $source);
    self::assertStringContainsString("previewRoot.classList.add('mmf-preview--active')", $source);
    self::assertStringContainsString('emptyEl.hidden = true;', $source);
    self::assertStringContainsString('mmf-preview--active', (string) file_get_contents(BP . '/app/code/Weline/MediaManager/view/statics/css/manager.css'));
    self::assertStringContainsString('.mmf-item.selected.mmf-item-disabled', (string) file_get_contents(BP . '/app/code/Weline/MediaManager/view/statics/css/manager.css'));
    self::assertStringContainsString('function pickerSelectionIsEligible()', $source);
    self::assertStringNotContainsString("showError(selectionError);\n                    return;\n                }\n                \n                if (SELECTION_MODE)", $source);
}

private function assert_ManagerJsBinQuery_testStandalonePageTitleAndIframeHidesPageHeader(): void
{
    $controller = (string) file_get_contents(BP . '/app/code/Weline/MediaManager/Controller/Backend/Manager.php');
    $menu = (string) file_get_contents(BP . '/app/code/Weline/MediaManager/etc/backend/menu.xml');
    self::assertStringContainsString("assign('title', __('媒体管理器'))", $controller);
    self::assertStringContainsString('suppressPageChromeForPicker()', $controller);
    self::assertStringContainsString("assign('layoutShowPageHeader', false)", $controller);
    self::assertStringContainsString("__('选择媒体')", $controller);
    self::assertStringContainsString('title="媒体管理器"', $menu);
    self::assertStringNotContainsString('title="文件管理"', $menu);
}

private function assert_ManagerJsBinQuery_testIframeSingleSelectAlwaysRendersOneConfirmControl(): void
{
    $templatePath = BP . '/app/code/Weline/MediaManager/view/templates/backend/Manager/manager.phtml';
    $stylePath = BP . '/app/code/Weline/MediaManager/view/statics/css/manager.css';
    $template = (string) file_get_contents($templatePath);
    $style = (string) file_get_contents($stylePath);
    self::assertStringContainsString('type="button" class="mmf-btn mmf-btn-primary mmf-iframe-confirm" id="mmf-btn-select"', $template);
    self::assertStringContainsString('id="mmf-btn-confirm-select"', $template);
    self::assertDoesNotMatchRegularExpression('/<notempty name="is_iframe">(?:(?!<\/notempty>).)*id="mmf-btn-select"/s', $template);
    self::assertDoesNotMatchRegularExpression('/<notempty name="is_iframe">(?:(?!<\/notempty>).)*id="mmf-select-bar"/s', $template);
    self::assertStringContainsString('.mmf-iframe-confirm {', $style);
    self::assertStringContainsString('.mmf-iframe-mode .mmf-iframe-confirm {', $style);
    self::assertStringContainsString('body:has(.mmf-wrap:not(.mmf-iframe-mode)) .w-backend-shell', $style);
    self::assertStringContainsString('height: 100dvh;', $style);
    self::assertStringContainsString('body:has(.mmf-wrap:not(.mmf-iframe-mode)) .w-backend-footer', $style);
    self::assertStringNotContainsString('height: calc(100vh - 120px)', $style);
}

private function assert_ManagerJsBinQuery_testEmbeddedPickerKeepsBottomContentReachableWithoutPixelHeightLocks(): void
{
    $pickerStyle = (string) file_get_contents(BP . '/app/code/Weline/FileManager/view/statics/css/file-picker.css');
    $pickerScript = (string) file_get_contents(BP . '/app/code/Weline/FileManager/view/statics/js/file-picker.js');
    $managerStyle = (string) file_get_contents(BP . '/app/code/Weline/MediaManager/view/statics/css/manager.css');
    $managerScript = (string) file_get_contents(BP . '/app/code/Weline/MediaManager/view/statics/js/manager.js');
    $template = (string) file_get_contents(BP . '/app/code/Weline/MediaManager/view/templates/backend/Manager/manager.phtml');
    self::assertStringContainsString('block-size: min(90dvh, 52rem);', $pickerStyle);
    self::assertStringContainsString('.w-file-picker__dialog.w-dialog:not([open])', $pickerStyle);
    self::assertStringContainsString('.w-file-picker__dialog.w-dialog[open]', $pickerStyle);
    self::assertMatchesRegularExpression('/\.w-file-picker__dialog \.w-dialog__body\.w-file-picker__body \{[\s\S]*?flex: 1 1 0;[\s\S]*?min-block-size: 0;[\s\S]*?overflow: hidden !important;/', $pickerStyle);
    self::assertMatchesRegularExpression('/\.w-file-picker__frame \{[\s\S]*?flex: 1 1 0;[\s\S]*?block-size: auto;/', $pickerStyle);
    self::assertStringNotContainsString('frame.style.height = heightPx', $pickerScript);
    self::assertStringNotContainsString('frame.style.minHeight = heightPx', $pickerScript);
    self::assertStringContainsString('.mmf-wrap.mmf-iframe-mode .mmf-content {', $managerStyle);
    self::assertStringContainsString('-webkit-overflow-scrolling: touch;', $managerStyle);
    self::assertStringContainsString("document.documentElement.style.removeProperty('height')", $managerScript);
    self::assertStringNotContainsString('document.documentElement.style.height = heightPx', $managerScript);
    self::assertStringContainsString("d.style.removeProperty('height')", $template);
    self::assertStringNotContainsString("var heightPx=Math.floor(h)+'px'", $template);
}

private function assert_ManagerJsBinQuery_testDirectoryContextMenuIsProviderAwareAccessibleAndViewportBounded(): void
{
    $script = (string) file_get_contents(BP . '/app/code/Weline/MediaManager/view/statics/js/manager.js');
    $template = (string) file_get_contents(BP . '/app/code/Weline/MediaManager/view/templates/backend/Manager/manager.phtml');
    $style = (string) file_get_contents(BP . '/app/code/Weline/MediaManager/view/statics/css/manager.css');
    self::assertStringContainsString('payload.storage = CURRENT_STORAGE', $script);
    self::assertStringContainsString('data-mmf-item-menu', $script);
    self::assertStringContainsString('data-mmf-tree-menu', $script);
    self::assertStringContainsString('item.oncontextmenu = function (e)', $script);
    self::assertStringContainsString('selectTreeItemForMenu(item)', $script);
    self::assertStringContainsString('directoryContainsCurrent(file)', $script);
    self::assertStringContainsString("file.path === ''", $script);
    self::assertStringContainsString('|| file.phash === null', $script);
    self::assertStringContainsString('e.clientX, e.clientY', $script);
    self::assertStringNotContainsString('showContextMenu(e.pageX, e.pageY)', $script);
    self::assertStringContainsString("window.Weline.UI.get(root, 'menu')", $script);
    self::assertStringContainsString('runtime.open(true)', $script);
    self::assertStringNotContainsString('function positionContextMenu(', $script);
    self::assertStringContainsString("item.setAttribute('role', 'menuitem')", $script);
    self::assertStringContainsString("item.className = 'w-menu__item mmf-context-item'", $script);
    self::assertStringContainsString("e.key === 'ContextMenu'", $script);
    self::assertStringContainsString("itemCapability('delete', FILES[hash])", $script);
    self::assertStringContainsString('confirmDeleteDirectory', $script);
    self::assertStringContainsString('data-w-component="menu"', $template);
    self::assertStringContainsString("setAttribute('data-w-area','backend')", $template);
    self::assertStringContainsString("setAttribute('data-w-area', 'backend')", $script);
    self::assertStringNotContainsString('data-theme-area', $template . $script);
    self::assertStringContainsString('data-w-menu-trigger', $template);
    self::assertStringContainsString('class="mmf-context-menu w-menu"', $template);
    self::assertStringContainsString('data-w-menu-panel', $template);
    self::assertStringContainsString('role="menu" aria-hidden="true" hidden', $template);
    self::assertStringContainsString('role="dialog" aria-modal="true"', $template);
    self::assertStringContainsString('.mmf-context-menu-anchor {', $style);
    self::assertStringNotContainsString('.mmf-context-menu.visible', $style);
    self::assertStringContainsString("var onViewportChange = function () {\n            // Crossing the responsive breakpoint", $script);
    self::assertStringContainsString("// opened only by a selection interaction performed in compact mode.\n            closeChromeDrawers();", $script);
    self::assertDoesNotMatchRegularExpression('/\.mmf-context-menu\s*\{[^}]*background\s*:/s', $style);
    self::assertStringContainsString('@media (hover: none), (pointer: coarse)', $style);
}

private function assert_ManagerJsBinQuery_testDescriptorPublishesConnectorAndGenerate(): void
{
    $path = BP . '/app/code/Weline/MediaManager/extends/module/Weline_Framework/Query/MediaManagerQueryProvider.php';
    $source = (string) file_get_contents($path);
    self::assertStringContainsString("'name' => 'connector'", $source);
    self::assertStringContainsString("'name' => 'generate'", $source);
    self::assertStringContainsString("'name' => 'polishPrompt'", $source);
    self::assertStringContainsString('MediaUploadBase64Hydrator', $source);
}

private function assert_ManagerJsBinQuery_testConnectorDescriptorAcceptsFrontendOpenPayloadKeys(): void
{
    $provider = new \ReflectionClass(\Weline\MediaManager\Extends\Module\Weline_Framework\Query\MediaManagerQueryProvider::class);
    $instance = $provider->newInstanceWithoutConstructor();
    $descriptor = $instance->getDescriptor();
    $connector = null;
    foreach ($descriptor['operations'] as $operation) {
        if (($operation['name'] ?? '') === 'connector') {
            $connector = $operation;
            break;
        }
    }
    self::assertNotNull($connector);
    $names = array_column($connector['params'], 'name');
    foreach (['cmd', 'target', 'path', 'storage', 'init', 'tree', 'startPath', 'upload_base64', 'upload_metadata', 'lockPath', 'lockRoot'] as $required) {
        self::assertContains($required, $names, 'connector must declare FE payload key: ' . $required);
    }
    $params = array_column($connector['params'], null, 'name');
    self::assertSame(count($names), count(array_unique($names)), 'connector parameter names must be unique because the Worker normalizer keys rules by name');
    self::assertSame([], array_values(array_filter($connector['params'], static fn(array $param): bool => ($param['required'] ?? false) === true)), 'connector command-specific requirements must be validated by ConnectorService, not globally');
    self::assertArrayNotHasKey('disk_code', $params);
    self::assertSame(190, $params['storage']['max_length'] ?? null);
    self::assertSame(2048, $params['target']['max_length'] ?? null);
    self::assertSame(100, $params['targets']['max_items'] ?? null);
    self::assertSame(100, $params['upload_base64']['max_items'] ?? null);
    self::assertSame(100, $params['upload_metadata']['max_items'] ?? null);
}

private function assert_ManagerJsBinQuery_testConnectorDescriptorAcceptsFrontendLocaleAndUploadMetadataKeys(): void
{
    $source = (string) file_get_contents(BP . '/app/code/Weline/MediaManager/extends/module/Weline_Framework/Query/MediaManagerQueryProvider.php');
    foreach (['locale_code', 'asset_revision', 'display_name', 'default_alt', 'description', 'lockPath', 'lockRoot', 'lock_path', 'lock_root'] as $required) {
        self::assertStringContainsString("['name' => '" . $required . "'", $source, 'connector must declare FE payload key: ' . $required);
    }
}

private function assert_ManagerJsBinQuery_testLocaleCodeIsSentThroughDeclaredWorkerParameterForEveryAssetRead(): void
{
    $source = (string) file_get_contents(BP . '/app/code/Weline/MediaManager/view/statics/js/manager.js');
    self::assertStringContainsString("payload.locale_code = CONFIG.localeCode || 'zh_Hans_CN'", $source);
    self::assertStringContainsString('function getConnectorResourceUrl(command, hash, extraParams)', $source);
    self::assertStringContainsString("'&locale_code=' + encodeURIComponent(CONFIG.localeCode || 'zh_Hans_CN')", $source);
    self::assertStringContainsString("getConnectorResourceUrl('file', hash, {download: '1'})", $source);
    self::assertStringContainsString("body.append('locale_code', CONFIG.localeCode || 'zh_Hans_CN')", $source);
}

private function assert_ManagerJsBinQuery_testFileMetadataAppearsInSelectionPanelAndContextDetailsDialog(): void
{
    $script = (string) file_get_contents(BP . '/app/code/Weline/MediaManager/view/statics/js/manager.js');
    $template = (string) file_get_contents(BP . '/app/code/Weline/MediaManager/view/templates/backend/Manager/manager.phtml');
    $style = (string) file_get_contents(BP . '/app/code/Weline/MediaManager/view/statics/css/manager.css');
    self::assertStringContainsString("addContextItem(menu, 'view-details', t('viewDetails'))", $script);
    self::assertStringContainsString("action === 'view-details'", $script);
    self::assertStringContainsString("t(isDir ? 'directoryActions' : 'fileActions'", $script);
    self::assertStringContainsString("qs('[data-mmf-item-menu]', el)", $script);
    self::assertStringContainsString('function metadataRows(file, full)', $script);
    self::assertStringContainsString('function openAssetDetails(hash)', $script);
    self::assertStringContainsString("if (event.key === 'Tab')", $script);
    foreach (['asset_id', 'object_key', 'display_name', 'default_alt', 'description', 'translation_state'] as $field) {
        self::assertStringContainsString('file.' . $field, $script);
    }
    self::assertStringContainsString("[t('metadataChecksum'), file.sha256", $script);
    self::assertStringContainsString("[t('metadataModifiedAt'), humanDateTime(file.ts)", $script);
    self::assertStringContainsString('asset_revision: Number(file.asset_revision)', $script);
    self::assertStringContainsString("t('assetDefaultCaptionPrompt')", $script);
    self::assertStringContainsString('default_caption: String(captionResult.value || \'\').trim()', $script);
    self::assertStringContainsString('FILES[file.hash] = Object.assign({}, FILES[file.hash] || file, changed)', $script);
    self::assertStringContainsString('updatePreviewPanel();', $script);
    self::assertStringNotContainsString("showSuccess(t('assetMetadataSaved'));\n                openDir(CWD_HASH);", $script);
    self::assertStringContainsString('mmf-iframe-layout-critical', $template);
    self::assertStringContainsString('fitIframeViewport', $template);
    self::assertStringContainsString('class="mmf-preview-info-scroll"', $template);
    self::assertStringContainsString('class="mmf-preview-actions"', $template);
    self::assertStringContainsString('.mmf-preview-info-scroll {', $style);
    self::assertStringContainsString('.mmf-preview { width: 280px; min-width: 220px; min-block-size: 0; max-block-size: 100%;', $style);
    self::assertStringContainsString('.mmf-preview-content.mmf-preview-content--with-image { grid-template-rows: auto minmax(0, 1fr);', $style);
    self::assertStringContainsString('function syncPreviewDetailScroll()', $script);
    self::assertStringContainsString("infoEl.style.display = 'grid'", $script);
    self::assertStringContainsString('.mmf-preview-info { display: grid; grid-template-rows: minmax(0, 1fr) auto;', $style);
    self::assertStringContainsString('html:has(.mmf-wrap.mmf-iframe-mode) body > main.w-backend-page', $style);
    self::assertStringContainsString('.mmf-wrap.mmf-iframe-mode {', $style);
    self::assertStringContainsString('.mmf-wrap.mmf-iframe-mode .mmf-select-bar {', $style);
    self::assertStringContainsString('function syncIframeLayoutHeight()', $script);
    self::assertStringContainsString("document.documentElement.classList.add('mmf-iframe-host')", $script);
    self::assertStringContainsString('COMPACT_MQ && COMPACT_MQ.matches', $script);
    self::assertStringNotContainsString('COMPACT_MQ && COMPACT_MQ.matches && !IFRAME_MODE', $script);
    self::assertStringContainsString('.mmf-preview-title { display: none; }', $style);
    self::assertStringContainsString('if (IFRAME_MODE && MULTI_SELECT) {', $script);
    self::assertStringContainsString('enterSelectionMode();', $script);
    self::assertStringContainsString('// 多选嵌入选择器：默认进入选择模式，单击切换勾选（无需 Ctrl/右键）', $script);
    self::assertStringContainsString('// iframe 多选选择器必须保持选择模式，否则单击会变成单选覆盖', $script);
    self::assertStringContainsString('if (SELECTED.length === 0 && !(IFRAME_MODE && MULTI_SELECT)) {', $script);
    self::assertStringContainsString('.mmf-preview-actions { flex: 0 0 auto;', $style);
    self::assertStringContainsString('data-mmf-details-list', $template);
    self::assertStringContainsString('role="dialog" aria-modal="true" aria-labelledby="mmf-details-title"', $template);
    self::assertStringContainsString('.mmf-details-overlay {', $style);
    self::assertStringContainsString('.mmf-details-list .mmf-metadata-row', $style);
    self::assertStringContainsString("btnDetails.style.display = f.mime === 'directory' ? 'none' : ''", $script);
    self::assertStringContainsString("if (!file || file.mime === 'directory' || !overlay) return", $script);
    self::assertStringContainsString('renderMetadataList(metadataEl, f, true)', $script);
    self::assertStringContainsString("image.removeAttribute('src')", $script);
    self::assertStringContainsString('function clearPreviewImage()', $script);
    self::assertStringContainsString("String(f.preview_url || getFileResourceUrl(hash) || '')", $script);
    self::assertStringContainsString('// 无 elFinder 缩略图时，图片仍用原图在网格/侧栏预览', $script);
    self::assertStringContainsString("img.dataset.fallbackSrc = '1'", $script);
    self::assertStringContainsString('LIGHTBOX_IMAGES = [];', $script);
    self::assertStringContainsString("updateAiReferencePreview('')", $script);
    self::assertStringContainsString("if (bytes === 0) return '0 B'", $script);
    self::assertStringContainsString("return String(value);", $script);
    self::assertStringNotContainsString('holder.innerHTML = text', $script);
    self::assertDoesNotMatchRegularExpression('/<button(?![^>]*\btype=)[^>]*>/', $template);
    self::assertStringNotContainsString('class="mmf-preview-img" src=""', $template);
    self::assertStringNotContainsString('class="mmf-details-image" src=""', $template);
    self::assertDoesNotMatchRegularExpression('/<img[^>]*\bsrc=""/', $template);
}

private function assert_ManagerJsBinQuery_testUploadUiHandlesNamelessClipboardFilesAndFolderDropTargetsSafely(): void
{
    $script = (string) file_get_contents(BP . '/app/code/Weline/MediaManager/view/statics/js/manager.js');
    $style = (string) file_get_contents(BP . '/app/code/Weline/MediaManager/view/statics/css/manager.css');
    self::assertStringContainsString('function normalizeIncomingFiles(fileList, source)', $script);
    self::assertStringContainsString('function inferredUploadExtension(mime)', $script);
    self::assertStringContainsString("uploadFiles(e.dataTransfer.files, 'drop', externalDestinationHash)", $script);
    self::assertStringContainsString('function uploadMultipart(fileList, metadataList, targetHash)', $script);
    self::assertStringContainsString("body.append('target', targetHash)", $script);
    self::assertStringContainsString("body.append('upload[]', file", $script);
    self::assertStringContainsString("loadingEl.appendChild(document.createTextNode(t('loading')))", $script);
    self::assertStringNotContainsString("loadingEl.innerHTML = '<span class=\"mmf-spinner\"></span>' + t('loading')", $script);
    self::assertStringContainsString("announceInteraction(t('dropUploadFolderHint'", $script);
    self::assertStringContainsString("announceInteraction(t('moveFolderHint'", $script);
    self::assertStringContainsString('outline: 3px solid', $style);
    self::assertStringContainsString('outline: 2px dashed', $style);
}

private function assert_ManagerJsBinQuery_testSelectMissingAssetMetadataPromptsInsteadOfToastOnly(): void
{
    $script = (string) file_get_contents(BP . '/app/code/Weline/MediaManager/view/statics/js/manager.js');
    $connector = (string) file_get_contents(BP . '/app/code/Weline/MediaManager/Service/ConnectorService.php');
    $controller = (string) file_get_contents(BP . '/app/code/Weline/MediaManager/Controller/Backend/Connector.php');
    self::assertStringContainsString('function supplementAssetMetadataForSelection(file)', $script);
    self::assertStringContainsString('function promptAssetLocaleMetadata(file)', $script);
    self::assertStringContainsString("cmd: 'asset_ensure'", $script);
    self::assertStringContainsString('blockedMetadataFiles', $script);
    self::assertStringContainsString('supplementAssetMetadataForSelection(file)', $script);
    self::assertDoesNotMatchRegularExpression('/if \(blockedByAssetMetadata\) \{\s*showError\(t\(\'assetMetadataRequired\'\)\);/', $script);
    self::assertStringContainsString("'asset_ensure' => \$this->handleStorageAssetEnsure", $connector);
    self::assertStringContainsString("'asset_ensure',", $controller);
}

private function assert_ManagerJsBinQuery_testTypedSelectionDoesNotExposeStorageLocationsOrResolvedUrls(): void
{
    $manager = (string) file_get_contents(BP . '/app/code/Weline/MediaManager/view/statics/js/manager.js');
    $theme = (string) file_get_contents(BP . '/app/code/Weline/Theme/view/statics/ui/pages/weline-theme-editor-widget-param.js');
    $widget = (string) file_get_contents(BP . '/app/code/Weline/Widget/view/statics/js/widget-param-types.js');
    self::assertStringContainsString('function typedAssetSelection(f)', $manager);
    self::assertMatchesRegularExpression('/function typedAssetSelection\(f\)\s*\{(?:(?!\n\s*}\n).)*asset_id:(?:(?!\n\s*}\n).)*locale_code:(?:(?!\n\s*}\n).)*default_alt:/s', $manager);
    self::assertDoesNotMatchRegularExpression('/function typedAssetSelection\(f\)\s*\{(?:(?!\n\s*}\n).)*(?:object_key|disk_code|\bpreview_url:|\bpath:|\burl:|\bthumb:)/s', $manager);
    self::assertStringContainsString('editor_preview_url:', $manager);
    self::assertStringContainsString("type: 'legacy-media-path'", $manager);
    foreach ([$theme, $widget] as $consumer) {
        self::assertStringContainsString('var typedValue = selectedFileImageValue(file)', $consumer);
        self::assertStringContainsString("file.type !== 'legacy-media-path'", $consumer);
        self::assertStringContainsString('file.editor_preview_url || file.preview_url', $consumer);
        self::assertStringContainsString('delete input.dataset.previewUrl', $consumer);
        self::assertStringNotContainsString('(file.url || file.path || file.thumb)) || value', $consumer);
    }
}

private function assert_ManagerJsBinQuery_testRuntimeConfigAndTranslationsAreJsonEncodedForJavascriptContext(): void
{
    $template = (string) file_get_contents(BP . '/app/code/Weline/MediaManager/view/templates/backend/Manager/manager.phtml');
    self::assertStringContainsString('JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT', $template);
    self::assertStringContainsString("connectorNotConfigured: <?= \$mmfJson(__('连接器URL未配置，请刷新页面')) ?>", $template);
    self::assertStringNotContainsString(": '@lang{", $template);
}

private function assert_ManagerJsBinQuery_testGenerateDescriptorAcceptsFrontendAiPayloadKeys(): void
{
    $provider = new \ReflectionClass(\Weline\MediaManager\Extends\Module\Weline_Framework\Query\MediaManagerQueryProvider::class);
    // Construct without heavy deps: only need getDescriptor().
    $instance = $provider->newInstanceWithoutConstructor();
    $descriptor = $instance->getDescriptor();
    $generate = null;
    foreach ($descriptor['operations'] as $operation) {
        if (($operation['name'] ?? '') === 'generate') {
            $generate = $operation;
            break;
        }
    }
    self::assertNotNull($generate);
    $names = array_column($generate['params'], 'name');
    foreach (['prompt', 'prompts', 'mode', 'session_id', 'target', 'size', 'output_format', 'aspect_ratio', 'source_file_hash', 'parent_generation_id', 'batch_count'] as $required) {
        self::assertContains($required, $names, 'generate must declare FE payload key: ' . $required);
    }
}

private function assert_OptionalOverwriteTargetPick_testManagerSurfacesOptionalOverwritePickBeforeConflictResolution(): void
{
    $js = (string) file_get_contents(BP . '/app/code/Weline/MediaManager/view/statics/js/manager.js');
    $template = (string) file_get_contents(BP . '/app/code/Weline/MediaManager/view/templates/backend/Manager/manager.phtml');
    $css = (string) file_get_contents(BP . '/app/code/Weline/MediaManager/view/statics/css/manager.css');
    self::assertStringContainsString('function promptOptionalOverwriteTargets(fileList, targetHash)', $js);
    self::assertStringContainsString('function directoryChildFiles(targetHash)', $js);
    self::assertStringContainsString('function filterDirectoryChildFiles(files, query)', $js);
    self::assertMatchesRegularExpression('/source === \'drop\' \|\| source === \'paste\'[\s\S]*promptOptionalOverwriteTargets\(files, targetHash\)[\s\S]*pickTargets\.then\(function\(mapped\)[\s\S]*resolveUploadNameConflicts\(mapped\.files, targetHash, mapped\.overwriteFlags\)/', $js);
    self::assertStringContainsString('if (flags[index]) {', $js);
    self::assertStringContainsString("t('overwritePickDuplicateTarget'", $js);
    self::assertStringContainsString('id="mmf-overwrite-pick-overlay" hidden', $template);
    self::assertStringContainsString('data-mmf-overwrite-pick-rows', $template);
    self::assertStringNotContainsString('id="mmf-overwrite-dock"', $template);
    self::assertStringContainsString('.mmf-overwrite-pick-overlay', $css);
    self::assertStringNotContainsString('.mmf-overwrite-dock {', $css);
    self::assertStringContainsString('.mmf-statusbar { flex: 0 0 auto', $css);
    self::assertStringContainsString('value="replace_target"', $template);
    self::assertStringContainsString('id="mmf-ai-save-target-pick"', $template);
    self::assertStringContainsString('function syncAiSaveModeFields()', $js);
    self::assertStringContainsString("saveMode = 'overwrite'", $js);
}

private function assert_OptionalOverwriteTargetPick_testAiDrawServiceAllowsOverwriteViaReplaceExistingFile(): void
{
    $service = (string) file_get_contents(BP . '/app/code/Weline/MediaManager/Service/AiDrawService.php');
    $storage = (string) file_get_contents(BP . '/app/code/Weline/MediaManager/Service/MediaStorageService.php');
    self::assertStringContainsString("if (\$saveMode === 'overwrite') {", $service);
    self::assertStringNotContainsString('统一 FileAsset 模式不允许原位覆盖共享资源', $service);
    self::assertStringContainsString('replaceExistingFile(', $service);
    self::assertStringContainsString('public function replaceExistingFile(', $storage);
    self::assertStringContainsString('$this->assets->replaceContent(', $storage);
}

private function assert_UploadConflictAutoExtension_testManagerJsDefinesAndUsesEnsureUploadFileExtension(): void
{
    $path = BP . '/app/code/Weline/MediaManager/view/statics/js/manager.js';
    self::assertFileExists($path);
    $js = (string) file_get_contents($path);
    self::assertStringContainsString('function ensureUploadFileExtension(name, originalName)', $js);
    self::assertMatchesRegularExpression('/function confirmUploadName\([\s\S]*?ensureUploadFileExtension\(/', $js);
    self::assertStringContainsString('inp.setSelectionRange(0, nameParts.base.length)', $js);
    self::assertStringContainsString('ensureUploadFileExtension: ensureUploadFileExtension', $js);
}

private function extensionCases_UploadConflictAutoExtension(): array
{
    return ['omit extension' => ['新图', 'image.png', '新图.png'], 'with matching extension' => ['新图.png', 'image.png', '新图.png'], 'matching extension case' => ['新图.PNG', 'image.png', '新图.PNG'], 'wrong extension replaced' => ['新图.jpg', 'image.png', '新图.png'], 'suggested unique basename' => ['image (1)', 'image.png', 'image (1).png'], 'empty stays empty' => ['  ', 'image.png', ''], 'original without extension' => ['新图', 'README', '新图']];
}

/**
 * PHP 镜像：与 manager.js ensureUploadFileExtension 同规则，供用例回归。
 */
private function ensureUploadFileExtension_UploadConflictAutoExtension(string $name, string $originalName): string
{
    $trimmed = trim($name);
    if ($trimmed === '') {
        return $trimmed;
    }
    $expectedExt = self::splitUploadFileName_UploadConflictAutoExtension($originalName)['ext'];
    if ($expectedExt === '') {
        return $trimmed;
    }
    if (!preg_match('/\.[A-Za-z0-9]{1,16}$/', $trimmed, $match)) {
        return $trimmed . $expectedExt;
    }
    if (strtolower($match[0]) === strtolower($expectedExt)) {
        return $trimmed;
    }
    return substr($trimmed, 0, -strlen($match[0])) . $expectedExt;
}

/**
 * @return array{base:string,ext:string}
 */
private function splitUploadFileName_UploadConflictAutoExtension(string $name): array
{
    $dot = strrpos($name, '.');
    if ($dot === false || $dot <= 0) {
        return ['base' => $name, 'ext' => ''];
    }
    return ['base' => substr($name, 0, $dot), 'ext' => substr($name, $dot)];
}
}

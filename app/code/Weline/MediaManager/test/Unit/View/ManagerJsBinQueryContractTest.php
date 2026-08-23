<?php

declare(strict_types=1);

namespace Weline\MediaManager\Test\Unit\View;

use PHPUnit\Framework\TestCase;

\defined('BP') || \define('BP', \dirname(__DIR__, 7) . \DIRECTORY_SEPARATOR);

/**
 * Protects MediaManager browser upload/connector migration to media_manager resource.
 */
final class ManagerJsBinQueryContractTest extends TestCase
{
    public function testManagerJsUsesResourceConnectorAndSameOriginMultipartUpload(): void
    {
        $path = BP . '/app/code/Weline/MediaManager/view/statics/js/manager.js';
        self::assertFileExists($path);
        $source = (string)file_get_contents($path);

        self::assertStringContainsString("resource('media_manager')", $source);
        self::assertStringContainsString("mmResource('connector'", $source);
        self::assertStringContainsString('function resolveBackendApiHost()', $source);
        self::assertStringContainsString('var candidate = window;', $source);
        self::assertStringContainsString('while (candidate)', $source);
        self::assertStringContainsString(
            'candidate.location.origin !== window.location.origin',
            $source,
        );
        self::assertStringContainsString(
            'candidate.document.querySelectorAll(\'meta[name="weline-worker-backend-bootstrap"]\').length === 1',
            $source,
        );
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
        self::assertSame(2, substr_count($source, 'new XMLHttpRequest()'));
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

    public function testDragPasteAndMoveUseTypedAccessibleConnectorContract(): void
    {
        $js = (string)\file_get_contents(
            BP . '/app/code/Weline/MediaManager/view/statics/js/manager.js'
        );
        $template = (string)\file_get_contents(
            BP . '/app/code/Weline/MediaManager/view/templates/Backend/Manager/manager.phtml'
        );
        $style = (string)\file_get_contents(
            BP . '/app/code/Weline/MediaManager/view/statics/css/manager.css'
        );

        self::assertStringContainsString('application/x-weline-media-files', $js);
        self::assertStringContainsString('function isExternalFileDrag(dataTransfer)', $js);
        self::assertStringContainsString('if (!INTERNAL_DRAG_TARGETS.length) return false;', $js);
        self::assertStringContainsString('activeTargets.indexOf(hash) >= 0', $js);
        self::assertStringContainsString('function bindClipboardPaste()', $js);
        self::assertStringContainsString('clipboard.items', $js);
        self::assertStringContainsString('API_MAX_UPLOAD_FILE_BYTES', $js);
        self::assertStringContainsString('function findOversizedUploadFile(fileList)', $js);
        self::assertStringContainsString('total > limit', $js);
        self::assertStringContainsString('function findDisallowedUploadFile(fileList)', $js);
        self::assertStringContainsString('SAFE_UPLOAD_EXTENSIONS', $js);
        self::assertStringContainsString("t('fileSizeExceeded'", $js);
        self::assertMatchesRegularExpression(
            '/findOversizedUploadFile\(fileList\)[\s\S]*requestUploadMetadata\(fileList\)/',
            $js
        );
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

    public function testDirectoryItemsRemainAccessibleWhenDoubleClickDeliveryIsInterrupted(): void
    {
        $path = BP . '/app/code/Weline/MediaManager/view/statics/js/manager.js';
        self::assertFileExists($path);
        $source = (string)file_get_contents($path);

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
        self::assertStringContainsString(
            'e.detail >= 2 && openDirectoryFromInteraction(hash, el.dataset.mime)',
            $source
        );
        self::assertStringContainsString("e.key !== 'Enter'", $source);
        self::assertStringContainsString(
            'openDirectoryFromInteraction(selectedHash, renderedMime)',
            $source
        );
        self::assertStringContainsString(
            "} else if (window.parent && window.parent !== window) {",
            $source
        );
        self::assertStringContainsString("CONFIG.target = String(e.data.target).trim()", $source);
        self::assertStringContainsString("target: CONFIG.target || ''", $source);
        self::assertStringContainsString('} else if (IFRAME_MODE) {', $source);
        self::assertStringNotContainsString(
            '} else if (IFRAME_MODE && GET_FILE_CALLBACK) {',
            $source,
        );
    }

    public function testPickerEnforcesLockedRootTypeAndSizeBeforeSelection(): void
    {
        $source = (string)file_get_contents(
            BP . '/app/code/Weline/MediaManager/view/statics/js/manager.js'
        );

        self::assertStringContainsString('function canOpenDirectoryHash(hash)', $source);
        self::assertStringContainsString('function isPathWithinLockedRoot(path)', $source);
        self::assertStringContainsString('if (CONFIG.lockPath && !isInit && !canOpenDirectoryHash(target))', $source);
        self::assertStringContainsString('if (CONFIG.lockPath) lastHash = null;', $source);
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
    }

    public function testStandalonePageTitleAndIframeHidesPageHeader(): void
    {
        $controller = (string)file_get_contents(
            BP . '/app/code/Weline/MediaManager/Controller/Backend/Manager.php'
        );
        $menu = (string)file_get_contents(
            BP . '/app/code/Weline/MediaManager/etc/backend/menu.xml'
        );

        self::assertStringContainsString("assign('title', __('媒体管理器'))", $controller);
        self::assertStringContainsString('suppressPageChromeForPicker()', $controller);
        self::assertStringContainsString("assign('layoutShowPageHeader', false)", $controller);
        self::assertStringContainsString("__('选择媒体')", $controller);
        self::assertStringContainsString('title="媒体管理器"', $menu);
        self::assertStringNotContainsString('title="文件管理"', $menu);
    }

    public function testIframeSingleSelectAlwaysRendersOneConfirmControl(): void
    {
        $templatePath = BP . '/app/code/Weline/MediaManager/view/templates/Backend/Manager/manager.phtml';
        $stylePath = BP . '/app/code/Weline/MediaManager/view/statics/css/manager.css';
        $template = (string)file_get_contents($templatePath);
        $style = (string)file_get_contents($stylePath);

        self::assertStringContainsString(
            'type="button" class="mmf-btn mmf-btn-primary mmf-iframe-confirm" id="mmf-btn-select"',
            $template
        );
        self::assertStringContainsString('id="mmf-btn-confirm-select"', $template);
        self::assertDoesNotMatchRegularExpression(
            '/<notempty name="is_iframe">(?:(?!<\/notempty>).)*id="mmf-btn-select"/s',
            $template
        );
        self::assertDoesNotMatchRegularExpression(
            '/<notempty name="is_iframe">(?:(?!<\/notempty>).)*id="mmf-select-bar"/s',
            $template
        );
        self::assertStringContainsString('.mmf-iframe-confirm {', $style);
        self::assertStringContainsString('.mmf-iframe-mode .mmf-iframe-confirm {', $style);
        self::assertStringContainsString('body:has(.mmf-wrap:not(.mmf-iframe-mode)) .w-backend-shell', $style);
        self::assertStringContainsString('height: 100dvh;', $style);
        self::assertStringContainsString('body:has(.mmf-wrap:not(.mmf-iframe-mode)) .w-backend-footer', $style);
        self::assertStringNotContainsString('height: calc(100vh - 120px)', $style);
    }

    public function testDirectoryContextMenuIsProviderAwareAccessibleAndViewportBounded(): void
    {
        $script = (string)file_get_contents(
            BP . '/app/code/Weline/MediaManager/view/statics/js/manager.js'
        );
        $template = (string)file_get_contents(
            BP . '/app/code/Weline/MediaManager/view/templates/Backend/Manager/manager.phtml'
        );
        $style = (string)file_get_contents(
            BP . '/app/code/Weline/MediaManager/view/statics/css/manager.css'
        );

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
        self::assertStringContainsString(
            "var onViewportChange = function () {\n            // Crossing the responsive breakpoint",
            $script
        );
        self::assertStringContainsString(
            "// opened only by a selection interaction performed in compact mode.\n            closeChromeDrawers();",
            $script
        );
        self::assertDoesNotMatchRegularExpression(
            '/\.mmf-context-menu\s*\{[^}]*background\s*:/s',
            $style
        );
        self::assertStringContainsString('@media (hover: none), (pointer: coarse)', $style);
    }

    public function testDescriptorPublishesConnectorAndGenerate(): void
    {
        $path = BP . '/app/code/Weline/MediaManager/extends/module/Weline_Framework/Query/MediaManagerQueryProvider.php';
        $source = (string)file_get_contents($path);
        self::assertStringContainsString("'name' => 'connector'", $source);
        self::assertStringContainsString("'name' => 'generate'", $source);
        self::assertStringContainsString("'name' => 'polishPrompt'", $source);
        self::assertStringContainsString('MediaUploadBase64Hydrator', $source);
    }

    public function testConnectorDescriptorAcceptsFrontendOpenPayloadKeys(): void
    {
        $provider = new \ReflectionClass(
            \Weline\MediaManager\Extends\Module\Weline_Framework\Query\MediaManagerQueryProvider::class
        );
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
        foreach (['cmd', 'target', 'path', 'storage', 'init', 'tree', 'startPath', 'upload_base64', 'upload_metadata'] as $required) {
            self::assertContains($required, $names, 'connector must declare FE payload key: ' . $required);
        }
        $params = array_column($connector['params'], null, 'name');
        self::assertSame(
            count($names),
            count(array_unique($names)),
            'connector parameter names must be unique because the Worker normalizer keys rules by name',
        );
        self::assertSame(
            [],
            array_values(array_filter(
                $connector['params'],
                static fn (array $param): bool => ($param['required'] ?? false) === true,
            )),
            'connector command-specific requirements must be validated by ConnectorService, not globally',
        );
        self::assertArrayNotHasKey('disk_code', $params);
        self::assertSame(190, $params['storage']['max_length'] ?? null);
        self::assertSame(2048, $params['target']['max_length'] ?? null);
        self::assertSame(100, $params['targets']['max_items'] ?? null);
        self::assertSame(100, $params['upload_base64']['max_items'] ?? null);
        self::assertSame(100, $params['upload_metadata']['max_items'] ?? null);
    }

    public function testConnectorDescriptorAcceptsFrontendLocaleAndUploadMetadataKeys(): void
    {
        $source = (string)file_get_contents(
            BP . '/app/code/Weline/MediaManager/extends/module/Weline_Framework/Query/MediaManagerQueryProvider.php'
        );
        foreach (['locale_code', 'asset_revision', 'display_name', 'default_alt', 'description'] as $required) {
            self::assertStringContainsString(
                "['name' => '" . $required . "'",
                $source,
                'connector must declare FE payload key: ' . $required,
            );
        }
    }

    public function testLocaleCodeIsSentThroughDeclaredWorkerParameterForEveryAssetRead(): void
    {
        $source = (string)file_get_contents(
            BP . '/app/code/Weline/MediaManager/view/statics/js/manager.js'
        );

        self::assertStringContainsString("payload.locale_code = CONFIG.localeCode || 'zh_Hans_CN'", $source);
        self::assertStringContainsString('function getConnectorResourceUrl(command, hash, extraParams)', $source);
        self::assertStringContainsString("'&locale_code=' + encodeURIComponent(CONFIG.localeCode || 'zh_Hans_CN')", $source);
        self::assertStringContainsString("getConnectorResourceUrl('file', hash, {download: '1'})", $source);
        self::assertStringContainsString("body.append('locale_code', CONFIG.localeCode || 'zh_Hans_CN')", $source);
    }

    public function testFileMetadataAppearsInSelectionPanelAndContextDetailsDialog(): void
    {
        $script = (string)file_get_contents(
            BP . '/app/code/Weline/MediaManager/view/statics/js/manager.js'
        );
        $template = (string)file_get_contents(
            BP . '/app/code/Weline/MediaManager/view/templates/Backend/Manager/manager.phtml'
        );
        $style = (string)file_get_contents(
            BP . '/app/code/Weline/MediaManager/view/statics/css/manager.css'
        );

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
        self::assertStringContainsString('data-mmf-preview-meta', $template);
        self::assertStringContainsString('class="mmf-preview-info-scroll"', $template);
        self::assertStringContainsString('class="mmf-preview-actions"', $template);
        self::assertStringContainsString('.mmf-preview-info-scroll {', $style);
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
        self::assertDoesNotMatchRegularExpression('/<button(?![^>]*\\btype=)[^>]*>/', $template);
        self::assertStringNotContainsString('class="mmf-preview-img" src=""', $template);
        self::assertStringNotContainsString('class="mmf-details-image" src=""', $template);
        self::assertDoesNotMatchRegularExpression('/<img[^>]*\bsrc=""/', $template);
    }

    public function testUploadUiHandlesNamelessClipboardFilesAndFolderDropTargetsSafely(): void
    {
        $script = (string)file_get_contents(
            BP . '/app/code/Weline/MediaManager/view/statics/js/manager.js'
        );
        $style = (string)file_get_contents(
            BP . '/app/code/Weline/MediaManager/view/statics/css/manager.css'
        );

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

    public function testTypedSelectionDoesNotExposeStorageLocationsOrResolvedUrls(): void
    {
        $manager = (string)file_get_contents(
            BP . '/app/code/Weline/MediaManager/view/statics/js/manager.js'
        );
        $theme = (string)file_get_contents(
            BP . '/app/code/Weline/Theme/view/statics/ui/pages/weline-theme-editor-widget-param.js'
        );
        $widget = (string)file_get_contents(
            BP . '/app/code/Weline/Widget/view/statics/js/widget-param-types.js'
        );

        self::assertStringContainsString('function typedAssetSelection(f)', $manager);
        self::assertMatchesRegularExpression(
            '/function typedAssetSelection\(f\)\s*\{(?:(?!\n\s*}\n).)*asset_id:(?:(?!\n\s*}\n).)*locale_code:(?:(?!\n\s*}\n).)*default_alt:/s',
            $manager,
        );
        self::assertDoesNotMatchRegularExpression(
            '/function typedAssetSelection\(f\)\s*\{(?:(?!\n\s*}\n).)*(?:object_key|disk_code|preview_url|\bpath:|\burl:|\bthumb:)/s',
            $manager,
        );
        self::assertStringContainsString("type: 'legacy-media-path'", $manager);
        foreach ([$theme, $widget] as $consumer) {
            self::assertStringContainsString('var typedValue = selectedFileImageValue(file)', $consumer);
            self::assertStringContainsString("file.type !== 'legacy-media-path'", $consumer);
            self::assertStringContainsString('delete input.dataset.previewUrl', $consumer);
            self::assertStringNotContainsString('(file.url || file.path || file.thumb)) || value', $consumer);
        }
    }

    public function testRuntimeConfigAndTranslationsAreJsonEncodedForJavascriptContext(): void
    {
        $template = (string)file_get_contents(
            BP . '/app/code/Weline/MediaManager/view/templates/Backend/Manager/manager.phtml'
        );

        self::assertStringContainsString('JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT', $template);
        self::assertStringContainsString("connectorNotConfigured: <?= \$mmfJson(__('连接器URL未配置，请刷新页面')) ?>", $template);
        self::assertStringNotContainsString(": '@lang{", $template);
    }

    public function testGenerateDescriptorAcceptsFrontendAiPayloadKeys(): void
    {
        $provider = new \ReflectionClass(
            \Weline\MediaManager\Extends\Module\Weline_Framework\Query\MediaManagerQueryProvider::class
        );
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
        foreach ([
            'prompt', 'prompts', 'mode', 'session_id', 'target',
            'size', 'output_format', 'aspect_ratio',
            'source_file_hash', 'parent_generation_id', 'batch_count',
        ] as $required) {
            self::assertContains($required, $names, 'generate must declare FE payload key: ' . $required);
        }
    }
}

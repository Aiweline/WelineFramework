<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\Controller\Backend;

use PHPUnit\Framework\TestCase;

/**
 * Standard publish: dirty → requires create_version; clean → mark current; HTTP batch gated.
 */
final class ThemeStandardLayoutPublishContractTest extends TestCase
{
    private function themeEditorSource(): string
    {
        $path = dirname(__DIR__, 4) . '/Controller/Backend/ThemeEditor.php';
        self::assertFileExists($path);

        return (string)file_get_contents($path);
    }

    private function extractMethod(string $source, string $functionName): string
    {
        $start = strpos($source, 'function ' . $functionName . '(');
        self::assertNotFalse($start, $functionName . ' missing');
        $end = strpos($source, "\n    private function ", $start + 1);
        if ($end === false) {
            $end = strpos($source, "\n    public function ", $start + 1);
        }
        self::assertNotFalse($end, $functionName . ' end missing');

        return substr($source, $start, $end - $start);
    }

    public function testRunStandardLayoutPublishGatesDirtyWithoutCreateVersion(): void
    {
        $fn = $this->extractMethod($this->themeEditorSource(), 'runStandardLayoutPublish');

        self::assertStringContainsString('theme_publish_requires_new_version', $fn);
        self::assertStringContainsString('hasPendingScopedChanges', $fn);
        self::assertStringContainsString('TYPE_PUBLISH', $fn);
        self::assertStringContainsString('saveScopedLayoutVersion(', $fn);
        self::assertStringContainsString('publishPendingScopedResources(', $fn);
        self::assertStringContainsString('markVersionPublished(', $fn);
        self::assertStringContainsString('theme_publish_version_mark_failed', $fn);
        self::assertStringContainsString('theme_publish_restore_version', $fn);
    }

    public function testPublishEntrypointsUseStandardKernel(): void
    {
        $source = $this->themeEditorSource();

        foreach (['postPublish', 'postPublishVersion', 'publishVersionPayload', 'postPublishAndExit'] as $name) {
            $fn = $this->extractMethod($source, $name);
            self::assertStringContainsString(
                'runStandardLayoutPublish(',
                $fn,
                $name . ' must call runStandardLayoutPublish'
            );
            self::assertStringNotContainsString(
                'createAndPublishScopedLayoutVersion(',
                $fn,
                $name
            );
        }
    }

    public function testHttpScopedPublishRejectsPendingWithSameCode(): void
    {
        $fn = $this->extractMethod($this->themeEditorSource(), 'scopedWorkspacePayload');

        self::assertStringContainsString("'publish', 'publish_batch'", $fn);
        self::assertStringContainsString('hasPendingScopedChanges($gateContext)', $fn);
        self::assertStringContainsString('theme_publish_requires_new_version', $fn);
        self::assertStringContainsString('nextLayoutVersionSuggestion($gateContext)', $fn);
    }

    public function testVersionsPayloadIncludesSuggestedVersionName(): void
    {
        $source = $this->themeEditorSource();
        $payload = $this->extractMethod($source, 'getVersionsPayload');
        $get = $this->extractMethod($source, 'getVersions');
        $suggest = $this->extractMethod($source, 'nextLayoutVersionSuggestion');

        self::assertStringContainsString("'suggested_version_name'", $suggest);
        self::assertStringContainsString("'v' . \$next", $suggest);
        self::assertStringContainsString('nextLayoutVersionSuggestion($context)', $payload);
        self::assertStringContainsString("'suggested_version_name' => \$suggestion['suggested_version_name']", $payload);
        self::assertStringContainsString('nextLayoutVersionSuggestion($context)', $get);
        self::assertStringContainsString("'suggested_version_name' => \$suggestion['suggested_version_name']", $get);
    }

    public function testEditorPublishStopsPreemptiveBatch(): void
    {
        $root = dirname(__DIR__, 4);
        foreach ([
            '/view/statics/ui/pages/weline-theme-editor.js',
            '/view/statics/js/theme-editor.js',
        ] as $relative) {
            $source = (string)file_get_contents($root . $relative);
            $start = strpos($source, 'async function publishTheme()');
            self::assertNotFalse($start, $relative);
            $end = strpos($source, 'async function detectPendingScopedChanges()', $start + 1);
            self::assertNotFalse($end, $relative);
            $fn = substr($source, $start, $end - $start);

            self::assertStringContainsString('requestStandardLayoutPublish(', $fn, $relative);
            self::assertStringContainsString('theme_publish_requires_new_version', $fn, $relative);
            self::assertStringNotContainsString(
                "publishLoadedScopedWorkspaces('theme_editor_publish')",
                $fn,
                $relative
            );
            self::assertStringNotContainsString('scoped_release_published: true', $fn, $relative);
        }
    }

    public function testPublishAndExitAppliesPreviewProgressLocale(): void
    {
        $source = $this->themeEditorSource();
        $fn = $this->extractMethod($source, 'postPublishAndExit');

        self::assertStringContainsString('resolvePublishProgressLocale(', $source);
        self::assertStringContainsString('applyPublishProgressLocale(', $fn);
        self::assertStringContainsString('clearPublishProgressLocale()', $fn);
        self::assertStringContainsString('State::setRequestLanguageOverride', $source);
        self::assertStringContainsString("\$data['locale']", $source);
    }

    public function testPreviewFloatHandlesRequiresNewVersion(): void
    {
        $path = dirname(__DIR__, 4) . '/Observer/LayoutSlotRenderer.php';
        $source = (string)file_get_contents($path);
        self::assertStringContainsString('theme_publish_requires_new_version', $source);
        self::assertStringContainsString('create_version: true', $source);
        self::assertStringContainsString('promptNewVersionName', $source);
        self::assertStringContainsString('openPreviewModalOverlay', $source);
        self::assertStringContainsString('z-index:2147483200', $source);
        self::assertStringContainsString('z-index: 2147483100', $source);
        self::assertStringContainsString('new RegExp(', $source);
        self::assertStringContainsString('text/event-stream', $source);
        self::assertStringContainsString('consumePublishSse', $source);
        self::assertStringContainsString('id="weline-preview-publish-btn"', $source);
        self::assertStringContainsString('data-w-preview-publish-lock', $source);
        self::assertStringContainsString('openPublishProgressLock', $source);
        self::assertStringContainsString('updatePublishProgress', $source);
        self::assertStringContainsString('finishPublishRedirect', $source);
        self::assertStringContainsString('buildPublishExitNavigateUrl', $source);
        self::assertStringContainsString('tearDownPreviewFloatChrome', $source);
        self::assertStringContainsString('theme_standard_publish_ok_stream_tail', $source);
        self::assertStringContainsString('z-index:2147483400', $source);
        self::assertStringContainsString('data-w-publish-bar', $source);
        self::assertStringContainsString('data-w-publish-steps', $source);
        self::assertStringContainsString('publishingTitle:', $source);
        self::assertStringContainsString('checkingGate:', $source);
        self::assertStringContainsString('stepPrefix:', $source);
        self::assertStringContainsString('progressByStep:', $source);
        self::assertStringContainsString('progressSourceMap:', $source);
        self::assertStringContainsString('resolvePublishProgressMessage', $source);
        self::assertStringContainsString('resolveStorefrontPublishLocale', $source);
        self::assertStringContainsString('previewMessages.publishingTitle', $source);
        self::assertStringContainsString('previewMessages.checkingGate', $source);
        self::assertStringContainsString('previewMessages.stepPrefix', $source);
        self::assertStringContainsString("payload.locale", $source);
        self::assertStringNotContainsString("titleEl.textContent = '正在发布主题'", $source);
        self::assertStringNotContainsString("openPublishProgressLock('检查发布条件…'", $source);
        // Publish is primary CTA (solid), exit is secondary — not ghost/disabled look.
        $publishBtnAt = strpos($source, 'id="weline-preview-publish-btn"');
        self::assertNotFalse($publishBtnAt);
        $publishChunk = substr($source, $publishBtnAt, 700);
        self::assertStringContainsString('rgba(255,255,255,0.98)', $publishChunk);
        $exitBtnAt = strpos($source, 'id="weline-preview-exit-btn"');
        self::assertNotFalse($exitBtnAt);
        $exitChunk = substr($source, $exitBtnAt, 700);
        self::assertStringContainsString('rgba(255,255,255,0.16)', $exitChunk);
        $promptStart = strpos($source, 'function promptNewVersionName(');
        self::assertNotFalse($promptStart);
        $promptEnd = strpos($source, 'publishBtn.addEventListener', $promptStart);
        self::assertNotFalse($promptEnd);
        $promptFn = substr($source, $promptStart, $promptEnd - $promptStart);
        self::assertStringNotContainsString('window.prompt(', $promptFn);
        self::assertStringNotContainsString('window.confirm(', $promptFn);
        self::assertStringNotContainsString('window.alert(', $promptFn);
        self::assertStringNotContainsString('Weline.UI.dialog.prompt', $promptFn);
        self::assertStringContainsString('openPreviewModalOverlay', $promptFn);
        self::assertStringContainsString('input.value = autoLabel', $promptFn);
        self::assertStringContainsString('suggested_version_name', $source);
    }

    public function testPublishFinalizesBakeAndCacheWithSse(): void
    {
        $source = $this->themeEditorSource();
        $finalize = $this->extractMethod($source, 'finalizeStandardLayoutPublish');
        self::assertStringContainsString('ensureCurrentPublishedLayoutBaked(', $finalize);
        self::assertStringContainsString('bumpStaticVersion($themeId)', $finalize);
        self::assertStringContainsString('clearCache($themeId)', $finalize);
        self::assertStringContainsString('flushFullPageCache(', $finalize);

        $stream = $this->extractMethod($source, 'streamStandardLayoutPublish');
        self::assertStringContainsString('SseWriter', $stream);
        self::assertStringContainsString("sendEvent('progress'", $stream);
        self::assertStringContainsString("sendEvent('done'", $stream);
        self::assertStringContainsString('compose_redirect', $stream);
        self::assertStringContainsString('正在清理预览会话并准备跳转', $source);
        self::assertStringContainsString('exit_preview', $source);

        foreach (['postPublish', 'postPublishVersion', 'publishVersionPayload', 'postPublishAndExit'] as $name) {
            $fn = $this->extractMethod($source, $name);
            self::assertStringContainsString('wantsStandardPublishStream(', $fn, $name);
            self::assertStringContainsString('streamStandardLayoutPublish(', $fn, $name);
            self::assertStringContainsString('finalizeStandardLayoutPublish(', $fn, $name);
        }

        $workspace = (string)file_get_contents(dirname(__DIR__, 4) . '/Service/Scoped/ThemeScopedWorkspace.php');
        self::assertStringContainsString('bakePublishedLayoutResourcesFromBatchReceipt(', $workspace);

        foreach ([
            '/view/statics/ui/pages/weline-theme-editor.js',
            '/view/statics/js/theme-editor.js',
        ] as $relative) {
            $js = (string)file_get_contents(dirname(__DIR__, 4) . $relative);
            self::assertStringContainsString('consumeStandardPublishSse', $js, $relative);
            self::assertStringContainsString('text/event-stream', $js, $relative);
            self::assertStringContainsString('stream: 1', $js, $relative);
        }
    }
}

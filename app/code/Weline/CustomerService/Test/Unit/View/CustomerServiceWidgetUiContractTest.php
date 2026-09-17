<?php

declare(strict_types=1);

namespace Weline\CustomerService\Test\Unit\View;

use PHPUnit\Framework\TestCase;

final class CustomerServiceWidgetUiContractTest extends TestCase
{
    public function testFrontendWidgetUsesWelineFormControls(): void
    {
        $hookFile = dirname(__DIR__, 3) . '/view/hooks/Weline_Theme/frontend/layouts/base/body-end.phtml';
        $this->assertFileExists($hookFile);
        $content = (string) file_get_contents($hookFile);

        $this->assertStringContainsString('class="w-textarea cs-message-input"', $content);
        $this->assertStringContainsString('class="w-select"', $content);
        $this->assertStringContainsString('class="w-input cs-form-input"', $content);
        $this->assertStringContainsString('class="w-button cs-send-button"', $content);
        $this->assertStringContainsString('data-cs-tool="emoji"', $content);
        $this->assertStringContainsString('data-cs-tool="image"', $content);
        $this->assertStringContainsString('data-cs-tool="file"', $content);
        $this->assertStringContainsString('data-cs-tool="screenshot"', $content);
        $this->assertStringContainsString('cs-composer-toolbar', $content);
        $this->assertStringContainsString('class="cs-chat-window w-panel"', $content);
        $this->assertStringContainsString('class="cs-chat-header w-panel-header"', $content);
        $this->assertStringContainsString('class="cs-chat-body w-panel-body"', $content);
        $this->assertStringContainsString('class="cs-chat-footer w-panel-footer"', $content);
        $this->assertStringContainsString('class="cs-modal w-modal"', $content);
        $this->assertStringContainsString('class="w-modal-dialog w-modal-sm"', $content);
        $this->assertStringContainsString('id="cs-bind-form"', $content);
        $this->assertStringContainsString('data-weline-form="1"', $content);
        $this->assertStringContainsString('data-weline-form-intent="customerservice_bind_email"', $content);
        $this->assertStringContainsString('data-weline-form-captcha-slot', $content);
        $this->assertStringContainsString('BindCaptchaGuard', $content);
    }

    public function testBodyEndHookDoesNotSkipOnPreviewOrVisualEditor(): void
    {
        $hookFile = dirname(__DIR__, 3) . '/view/hooks/Weline_Theme/frontend/layouts/base/body-end.phtml';
        $content = (string) file_get_contents($hookFile);

        $this->assertStringContainsString('$isAccountRoute', $content);
        $this->assertStringContainsString('preview_storefront_delivery_parity', $content);
        $this->assertStringNotContainsString('$isWorkspacePreview', $content);
        $this->assertStringNotContainsString("getGet('visual_editor'", $content);
        $this->assertStringNotContainsString("getGet('preview'", $content);
        $this->assertDoesNotMatchRegularExpression(
            '/if\s*\(\s*\$isAccountRoute\s*\|\|\s*\$isWorkspacePreview\s*\)/',
            $content,
            'preview must not early-return the customer-service Hook'
        );
        $this->assertMatchesRegularExpression(
            '/\/\/[^\n]*FORBIDDEN[^\n]*workspace-preview[^\n]*early-return/',
            $content,
            'comment must document the forbidden preview skip'
        );
        $this->assertMatchesRegularExpression(
            '/if\s*\(\s*\$isAccountRoute\s*\)\s*\{\s*return;/',
            $content,
            'only account-route early-return remains'
        );
    }

    public function testFrontendStylesUseThemePanelAndResponsiveChat(): void
    {
        $cssFile = dirname(__DIR__, 3) . '/view/statics/css/customer-service.css';
        $this->assertFileExists($cssFile);
        $content = (string) file_get_contents($cssFile);

        $this->assertStringContainsString('.customer-service-widget--amazon', $content);
        $this->assertStringContainsString('--weline-chrome-bg-dark', $content);
        $this->assertStringContainsString('--weline-chrome-primary', $content);
        $this->assertStringContainsString('.cs-chat-header.w-panel-header', $content);
        $this->assertStringContainsString('Unlayered: Theme .w-panel-header', $content);
        $this->assertStringContainsString('.customer-service-widget .cs-chat-header.w-panel-header', $content);
        $this->assertStringContainsString('background: var(--cs-header-bg)', $content);
        $this->assertStringContainsString('color: var(--cs-header-text)', $content);
        $this->assertStringContainsString('.cs-modal.w-modal', $content);
        $this->assertStringContainsString('@media (max-width: 720px)', $content);
        $this->assertStringContainsString('position: fixed', $content);
        $this->assertStringContainsString('.customer-service-widget.is-open .cs-chat-button', $content);
        $this->assertStringContainsString('cs-chat-button-breathe', $content);
        $this->assertStringContainsString('.cs-chat-button.has-unread', $content);
        $this->assertStringContainsString('.cs-presence-dot', $content);
        $this->assertStringContainsString('cs-bind-modal-open', $content);
        $this->assertStringContainsString('#cs-bind-modal.cs-modal', $content);
        $this->assertStringContainsString('calc(var(--weline-z-overlay) + 350)', $content);
        $this->assertStringContainsString('body.cs-bind-modal-open #customer-service-widget .cs-chat-window', $content);
        $this->assertStringContainsString('.cs-notice-alert', $content);
        $this->assertStringContainsString('.cs-notice-alert.is-open', $content);
    }

    public function testFrontendWidgetUsesAmazonChromeSurface(): void
    {
        $hookFile = dirname(__DIR__, 3) . '/view/hooks/Weline_Theme/frontend/layouts/base/body-end.phtml';
        $this->assertFileExists($hookFile);
        $content = (string) file_get_contents($hookFile);

        $this->assertStringContainsString('customer-service-widget--amazon', $content);
    }

    public function testFrontendStylesPinSurfaceBackgroundForMessageInput(): void
    {
        $cssFile = dirname(__DIR__, 3) . '/view/statics/css/customer-service.css';
        $this->assertFileExists($cssFile);
        $content = (string) file_get_contents($cssFile);

        $this->assertStringContainsString('@layer page', $content);
        $this->assertStringContainsString('background: var(--cs-surface)', $content);
        $this->assertStringContainsString('color: var(--cs-text)', $content);
        $this->assertStringContainsString('.cs-message-input', $content);
        $this->assertStringContainsString('color-scheme: inherit', $content);
        $this->assertStringContainsString('.cs-composer-toolbar', $content);
        $this->assertStringContainsString('.cs-composer-panel', $content);
        $this->assertStringContainsString('.cs-shot-crop', $content);
        $this->assertStringContainsString('.cs-shot-crop__rect', $content);
    }

    public function testFrontendWidgetJsSupportsComposerUploadAndScreenshot(): void
    {
        $jsFile = dirname(__DIR__, 3) . '/view/statics/js/customer-service.js';
        $this->assertFileExists($jsFile);
        $js = (string)file_get_contents($jsFile);
        $this->assertStringContainsString('bindComposerTools', $js);
        $this->assertStringContainsString('uploadAndSendAttachment', $js);
        $this->assertStringContainsString('captureAndSendScreenshot', $js);
        $this->assertStringContainsString('openLiveRegionPicker', $js);
        $this->assertStringContainsString('captureClientRegionDirect', $js);
        $this->assertStringContainsString('ensureModernScreenshot', $js);
        $this->assertStringContainsString('captureWithModernScreenshot', $js);
        $this->assertStringContainsString('CS_SHOT_CAPTURE_MS', $js);
        $this->assertStringContainsString('withCaptureTimeout', $js);
        $this->assertStringContainsString('region-only', $js);
        $this->assertStringContainsString('restoreChatWidgetAfterShot', $js);
        $this->assertStringContainsString('hideChatWidgetForShotCapture', $js);
        // Theme CSS color() breaks FO libs — primary path is native getDisplayMedia.
        $this->assertStringContainsString('canvas = await captureWithTabDisplayMedia(region);', $js);
        $this->assertStringContainsString('Primary: browser getDisplayMedia', $js);
        $this->assertStringContainsString('NotAllowedError', $js);
        $this->assertStringContainsString('modernScreenshot.domToCanvas', $js);
        $this->assertStringContainsString('html2CanvasScaleForRegion', $js);
        $this->assertStringContainsString('canvasLooksBlank', $js);
        $this->assertStringContainsString('cs-shot-crop--ready', $js);
        $this->assertStringContainsString('已选中区域，可点击「完成」发送', $js);
        $this->assertStringContainsString('生成中…', $js);
        $this->assertStringContainsString('ensureModernScreenshot().catch', $js);
        // Confirm must not wait on full-viewport prefetch + crop (main-thread lag).
        $this->assertStringNotContainsString('ensureSnapshotPrefetch', $js);
        $this->assertStringNotContainsString('captureVisibleViewport', $js);
        $this->assertStringNotContainsString('cropRegionFromViewportSnapshot', $js);
        $css = (string)file_get_contents(dirname(__DIR__, 3) . '/view/statics/css/customer-service.css');
        $this->assertStringContainsString('cs-shot-crop--ready', $css);
        $this->assertStringContainsString('cs-shot-confirm-pulse', $css);
        $tpl = (string)file_get_contents(dirname(__DIR__, 3) . '/view/hooks/Weline_Theme/frontend/layouts/base/body-end.phtml');
        $this->assertStringContainsString('modernScreenshotUrl', $tpl);
        $this->assertStringContainsString('js/vendor/modern-screenshot.js', $tpl);
        $this->assertFileExists(dirname(__DIR__, 3) . '/view/statics/js/vendor/modern-screenshot.js');
        $this->assertStringContainsString('html2canvasUrl', $tpl);
        $this->assertStringContainsString('js/vendor/html2canvas.min.js', $tpl);
        $this->assertFileExists(dirname(__DIR__, 3) . '/view/statics/js/vendor/html2canvas.min.js');
        $this->assertStringContainsString('.upload(', $js);
        $this->assertStringContainsString("session_id: state.sessionId", $js);
        // upload descriptor has no locale — must not send it (Unknown frontend worker param: locale).
        $this->assertMatchesRegularExpression(
            "/\\.upload\\(\\{[\\s\\S]*?data:\\s*dataUrl\\s*\\}/",
            $js
        );
        $this->assertStringNotContainsString(
            "data: dataUrl,\n                locale: state.locale\n            }, {silent: true})",
            $js
        );
        $provider = (string)file_get_contents(dirname(__DIR__, 3) . '/extends/module/Weline_Framework/Query/CustomerServiceQueryProvider.php');
        $this->assertStringContainsString("'upload' =>", $provider);
        $this->assertStringContainsString("'name' => 'upload'", $provider);
        $uploadBlockStart = strpos($provider, "'name' => 'upload'");
        $this->assertNotFalse($uploadBlockStart);
        $uploadBlock = substr($provider, (int)$uploadBlockStart, 700);
        $this->assertStringContainsString("'session_id'", $uploadBlock);
        $this->assertStringContainsString("'data'", $uploadBlock);
        $this->assertStringNotContainsString("'locale'", $uploadBlock);
        $this->assertStringContainsString('attachment_type', $provider);
        $this->assertStringContainsString('ChatMediaUploader', $provider);
        $uploader = (string)file_get_contents(dirname(__DIR__, 3) . '/Service/ChatMediaUploader.php');
        $this->assertStringContainsString('storeBase64', $uploader);
        $proto = (string)file_get_contents(dirname(__DIR__, 3) . '/view/statics/prototype/frontend-composer-screenshot.html');
        $this->assertStringContainsString('截图', $proto);
        $this->assertStringContainsString('variant=A', $proto);
        $this->assertStringContainsString('框选', $proto);
        $this->assertStringContainsString('2MB', $proto);
    }
}

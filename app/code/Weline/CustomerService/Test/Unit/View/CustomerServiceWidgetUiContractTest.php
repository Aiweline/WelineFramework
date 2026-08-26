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
        $this->assertStringContainsString('class="cs-chat-window w-panel"', $content);
        $this->assertStringContainsString('class="cs-chat-header w-panel-header"', $content);
        $this->assertStringContainsString('class="cs-chat-body w-panel-body"', $content);
        $this->assertStringContainsString('class="cs-chat-footer w-panel-footer"', $content);
        $this->assertStringContainsString('class="cs-modal w-modal"', $content);
        $this->assertStringContainsString('class="w-modal-dialog w-modal-sm"', $content);
        $this->assertStringContainsString('id="cs-bind-form"', $content);
        $this->assertStringContainsString('data-weline-form="1"', $content);
        $this->assertStringContainsString('data-weline-form-intent="customerservice.bind_email"', $content);
        $this->assertStringContainsString('data-weline-form-captcha-slot', $content);
        $this->assertStringContainsString('BindCaptchaGuard', $content);
    }

    public function testFrontendStylesUseThemePanelAndResponsiveChat(): void
    {
        $cssFile = dirname(__DIR__, 3) . '/view/statics/css/customer-service.css';
        $this->assertFileExists($cssFile);
        $content = (string) file_get_contents($cssFile);

        $this->assertStringContainsString('.cs-chat-header.w-panel-header', $content);
        $this->assertStringContainsString('.cs-modal.w-modal', $content);
        $this->assertStringContainsString('@media (max-width: 720px)', $content);
        $this->assertStringContainsString('position: fixed', $content);
        $this->assertStringContainsString('.customer-service-widget.is-open .cs-chat-button', $content);
        $this->assertStringContainsString('cs-bind-modal-open', $content);
        $this->assertStringContainsString('#cs-bind-modal.cs-modal', $content);
        $this->assertStringContainsString('calc(var(--weline-z-overlay) + 350)', $content);
        $this->assertStringContainsString('body.cs-bind-modal-open #customer-service-widget .cs-chat-window', $content);
        $this->assertStringContainsString('.cs-notice-alert', $content);
        $this->assertStringContainsString('.cs-notice-alert.is-open', $content);
    }

    public function testFrontendStylesPinSurfaceBackgroundForMessageInput(): void
    {
        $cssFile = dirname(__DIR__, 3) . '/view/statics/css/customer-service.css';
        $this->assertFileExists($cssFile);
        $content = (string) file_get_contents($cssFile);

        $this->assertStringContainsString('@layer page', $content);
        $this->assertStringContainsString('background: var(--weline-theme-surface)', $content);
        $this->assertStringContainsString('color: var(--weline-theme-text)', $content);
        $this->assertStringContainsString('.cs-message-input', $content);
        $this->assertStringContainsString('color-scheme: inherit', $content);
    }
}

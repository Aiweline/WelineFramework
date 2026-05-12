<?php

declare(strict_types=1);

namespace Weline\CustomerService\Test\Unit\View;

use PHPUnit\Framework\TestCase;

final class CustomerServiceWidgetLazyBindModalTest extends TestCase
{
    public function testBindModalIsTemplateBackedAndNotEagerDomNode(): void
    {
        $hookFile = dirname(__DIR__, 3) . '/view/hooks/Weline_Theme/frontend/layouts/base/body-end.phtml';

        $this->assertFileExists($hookFile);
        $content = (string) file_get_contents($hookFile);

        $templatePos = strpos($content, '<script type="application/json" id="cs-bind-modal-template">');
        $modalPos = strpos($content, '<div id="cs-bind-modal"');
        $templateClosePos = strpos($content, '</script>', $templatePos === false ? 0 : $templatePos);

        $this->assertIsInt($templatePos);
        $this->assertIsInt($modalPos);
        $this->assertIsInt($templateClosePos);
        $this->assertLessThan($templatePos, $modalPos);
        $this->assertStringContainsString('用户发送客服消息后再创建真实弹窗节点', $content);
        $this->assertStringContainsString('json_encode($bindModalHtml', $content);
        $this->assertStringContainsString('接收客服回复与优惠通知', $content);
        $this->assertStringContainsString('仅用于本次客服咨询相关通知，不是简报订阅。', $content);
    }

    public function testCustomerServiceScriptCreatesBindModalLazily(): void
    {
        $scriptFile = dirname(__DIR__, 3) . '/view/statics/js/customer-service.js';

        $this->assertFileExists($scriptFile);
        $content = (string) file_get_contents($scriptFile);

        $this->assertStringContainsString('function ensureBindModal()', $content);
        $this->assertStringContainsString("document.getElementById('cs-bind-modal-template')", $content);
        $this->assertStringContainsString("JSON.parse(template.textContent || '\"\"')", $content);
        $this->assertStringContainsString("wrapper.querySelector('#cs-bind-modal')", $content);
        $this->assertStringContainsString('document.body.appendChild(modal)', $content);
        $this->assertStringContainsString('const modal = ensureBindModal();', $content);
        $this->assertStringContainsString("const emailInput = modal.querySelector('#cs-bind-email');", $content);
    }
}

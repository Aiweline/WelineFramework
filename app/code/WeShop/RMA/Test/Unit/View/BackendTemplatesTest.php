<?php

declare(strict_types=1);

namespace WeShop\RMA\Test\Unit\View;

use PHPUnit\Framework\TestCase;

class BackendTemplatesTest extends TestCase
{
    public function testBackendIndexTemplateExists(): void
    {
        $templatePath = BP . '/app/code/WeShop/RMA/view/templates/Backend/RMA/Index/index.phtml';
        $this->assertFileExists($templatePath);
    }

    public function testBackendViewTemplateExists(): void
    {
        $templatePath = BP . '/app/code/WeShop/RMA/view/templates/Backend/RMA/View/index.phtml';
        $this->assertFileExists($templatePath);
    }

    public function testBackendIndexTemplateContainsLangTags(): void
    {
        $templatePath = BP . '/app/code/WeShop/RMA/view/templates/Backend/RMA/Index/index.phtml';
        $content = file_get_contents($templatePath);

        $this->assertStringContainsString('<lang>RMA Management</lang>', $content);
        $this->assertStringContainsString('__(\'Search\')', $content);
        $this->assertStringContainsString('__(\'Pending\')', $content);
        $this->assertStringContainsString('__(\'Approved\')', $content);
    }

    public function testBackendViewTemplateContainsLangTags(): void
    {
        $templatePath = BP . '/app/code/WeShop/RMA/view/templates/Backend/RMA/View/index.phtml';
        $content = file_get_contents($templatePath);

        $this->assertStringContainsString('<lang>RMA Detail</lang>', $content);
        $this->assertStringContainsString('__(\'Approve\')', $content);
        $this->assertStringContainsString('__(\'Reject\')', $content);
        $this->assertStringContainsString('__(\'RMA Information\')', $content);
    }

    public function testBackendIndexTemplateUsesStatusBadgeClassMap(): void
    {
        $templatePath = BP . '/app/code/WeShop/RMA/view/templates/Backend/RMA/Index/index.phtml';
        $content = file_get_contents($templatePath);

        $this->assertStringContainsString('RmaService::STATUS_PENDING', $content);
        $this->assertStringContainsString('RmaService::STATUS_APPROVED', $content);
        $this->assertStringContainsString('RmaService::STATUS_REJECTED', $content);
    }
}

<?php

declare(strict_types=1);

namespace WeShop\QA\Test\Unit\View;

use PHPUnit\Framework\TestCase;

class QAPageTemplateTest extends TestCase
{
    public function testFrontendQaModuleTemplateIncludesDefaultThemePage(): void
    {
        $template = file_get_contents(BP . 'app/code/WeShop/QA/view/templates/Frontend/QA/Index/index.phtml');
        $this->assertIsString($template);

        $this->assertStringContainsString("frontend' . DS . 'pages' . DS . 'qa' . DS . 'index.phtml", $template);
        $this->assertStringContainsString('include $defaultThemeTemplate', $template);
    }

    public function testDefaultThemeQaPageSupportsQuestionAnchorsAndMentions(): void
    {
        $template = file_get_contents(BP . 'app/design/WeShop/default/frontend/pages/qa/index.phtml');
        $this->assertIsString($template);

        $this->assertStringContainsString('qa-question-', $template);
        $this->assertStringContainsString('QaApi.add(payload', $template);
        $this->assertStringContainsString("qa/frontend/qa/add", $template);
    }
}

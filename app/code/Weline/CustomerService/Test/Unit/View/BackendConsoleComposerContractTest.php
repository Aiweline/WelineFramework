<?php

declare(strict_types=1);

namespace Weline\CustomerService\Test\Unit\View;

use PHPUnit\Framework\TestCase;

final class BackendConsoleComposerContractTest extends TestCase
{
    public function testComposerSupportsEmojiImageFileAndPhrases(): void
    {
        $base = dirname(__DIR__, 3);
        $js = (string)file_get_contents($base . '/view/statics/js/backend-console.js');
        $css = (string)file_get_contents($base . '/view/statics/css/backend-console.css');
        $ctrl = (string)file_get_contents($base . '/Controller/Backend/Console.php');
        $codec = (string)file_get_contents($base . '/Service/ChatAttachmentCodec.php');
        $model = (string)file_get_contents($base . '/Model/AgentPhrase.php');
        $proto = (string)file_get_contents($base . '/view/statics/prototype/console-composer-toolbar.html');

        $this->assertStringContainsString('data-cs-tool="emoji"', $js);
        $this->assertStringContainsString('data-cs-tool="image"', $js);
        $this->assertStringContainsString('data-cs-tool="file"', $js);
        $this->assertStringContainsString('data-cs-tool="phrase"', $js);
        $this->assertStringContainsString('uploadAndSendAttachment', $js);
        $this->assertStringContainsString('/phrases', $js);
        $this->assertStringContainsString('cs-composer-toolbar', $css);
        $this->assertStringContainsString('function postUpload', $ctrl);
        $this->assertStringContainsString('function getPhrases', $ctrl);
        $this->assertStringContainsString('function postPhraseSave', $ctrl);
        $this->assertStringContainsString('__CSJSON__', $codec);
        $this->assertStringContainsString('cs_agent_phrase', $model);
        $this->assertStringContainsString('话术库', $proto);
    }
}

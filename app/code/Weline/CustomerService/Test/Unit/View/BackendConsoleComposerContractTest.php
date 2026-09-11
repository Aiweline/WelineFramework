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

    public function testPhrasePanelSupportsUnifiedSearchAndCategoryTabs(): void
    {
        $base = dirname(__DIR__, 3);
        $js = (string)file_get_contents($base . '/view/statics/js/backend-console.js');
        $css = (string)file_get_contents($base . '/view/statics/css/backend-console.css');
        $ctrl = (string)file_get_contents($base . '/Controller/Backend/Console.php');
        $model = (string)file_get_contents($base . '/Model/AgentPhrase.php');
        $catModel = (string)file_get_contents($base . '/Model/AgentPhraseCategory.php');
        $proto = (string)file_get_contents($base . '/view/statics/prototype/console-phrase-search-tabs.html');

        $this->assertStringContainsString('schema_fields_CATEGORY', $model);
        $this->assertStringContainsString('cs_agent_phrase_category', $catModel);
        $this->assertStringContainsString('function postPhraseCategorySave', $ctrl);
        $this->assertStringContainsString('function postPhraseCategoryDelete', $ctrl);
        $this->assertStringContainsString("'category'", $ctrl);
        $this->assertStringContainsString('cs-phrase-search', $js);
        $this->assertStringContainsString('cs-phrase-tabs', $js);
        $this->assertStringContainsString('data-cs-phrase-tab', $js);
        $this->assertStringContainsString('filterPhraseRows', $js);
        $this->assertStringContainsString('cs-phrase-search', $css);
        $this->assertStringContainsString('cs-phrase-tabs', $css);
        $this->assertStringContainsString('variant=A', $proto);
        $this->assertStringContainsString('统一搜索', $proto);
    }
}

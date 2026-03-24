<?php

declare(strict_types=1);

namespace WeShop\Search\Test\Unit\View;

use PHPUnit\Framework\TestCase;

class BackendSearchEngineFormContractTest extends TestCase
{
    public function testBackendFormSupportsOpenSearchPanelAndSafeFieldToggling(): void
    {
        $path = BP . 'app/code/WeShop/Search/view/templates/Backend/Engine/form.phtml';
        $content = (string) file_get_contents($path);

        $this->assertStringContainsString('data-engine-panel="opensearch"', $content);
        $this->assertStringContainsString('toggleEnginePanelInputs', $content);
        $this->assertStringContainsString("input.disabled = !isActivePanel;", $content);
        $this->assertStringContainsString("input.dataset.required === '1'", $content);
        $this->assertStringContainsString("testSearchEngineConnection('opensearch')", $content);
    }
}

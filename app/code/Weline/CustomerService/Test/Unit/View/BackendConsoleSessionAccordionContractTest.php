<?php

declare(strict_types=1);

namespace Weline\CustomerService\Test\Unit\View;

use PHPUnit\Framework\TestCase;

final class BackendConsoleSessionAccordionContractTest extends TestCase
{
    public function testSessionGroupsAreExclusiveAccordion(): void
    {
        $jsFile = dirname(__DIR__, 3) . '/view/statics/js/backend-console.js';
        $tplFile = dirname(__DIR__, 3) . '/view/templates/Backend/Console/index.phtml';
        $js = (string)file_get_contents($jsFile);
        $tpl = (string)file_get_contents($tplFile);

        $this->assertStringContainsString('initSessionGroupAccordion', $js);
        $this->assertStringContainsString('weline:ui:disclosure:open', $js);
        $this->assertStringContainsString('data-cs-accordion', $js);
        $this->assertStringContainsString('data-cs-accordion="exclusive"', $tpl);
        $this->assertStringContainsString('$openSessionGroup', $tpl);
        $this->assertStringContainsString('$mineGroupOpen', $tpl);
        $this->assertStringContainsString('$transferredGroupOpen', $tpl);
        $this->assertStringContainsString('$waitingGroupOpen', $tpl);

        $initPos = strpos($js, 'function init(options)');
        $accordionCallPos = strpos($js, 'initSessionGroupAccordion();', $initPos !== false ? $initPos : 0);
        $bindPos = strpos($js, 'bindEvents();', $initPos !== false ? $initPos : 0);
        $this->assertNotFalse($initPos);
        $this->assertNotFalse($accordionCallPos);
        $this->assertNotFalse($bindPos);
        $this->assertLessThan($bindPos, $accordionCallPos, '互斥手风琴须在 bindEvents 之前绑定');
    }
}

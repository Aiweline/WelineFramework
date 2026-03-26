<?php

declare(strict_types=1);

namespace WeShop\Filters\Test\Unit\View;

use PHPUnit\Framework\TestCase;

class FiltersContainerHookTemplateTest extends TestCase
{
    public function testCanonicalContainerHookDelegatesToSharedFilterTemplate(): void
    {
        $template = file_get_contents(__DIR__ . '/../../../view/hooks/WeShop_Filters/frontend/partials/filters/container.phtml');

        $this->assertIsString($template);
        $this->assertStringContainsString('WeShop_Filters::templates/Frontend/filters.phtml', $template);
        $this->assertStringContainsString("'filters'", $template);
        $this->assertStringContainsString("'applied_filters'", $template);
        $this->assertStringContainsString("'clear_all_url'", $template);
    }

    public function testSharedFilterTemplateInjectsDynamicAjaxEndpoint(): void
    {
        $template = file_get_contents(__DIR__ . '/../../../view/templates/Frontend/filters.phtml');

        $this->assertIsString($template);
        $this->assertStringContainsString("getUrl('filters/filter')", $template);
        $this->assertStringContainsString('data-filter-api-url', $template);
    }
}

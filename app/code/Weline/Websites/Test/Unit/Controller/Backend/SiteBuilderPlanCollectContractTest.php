<?php

declare(strict_types=1);

namespace Weline\Websites\Test\Unit\Controller\Backend;

use PHPUnit\Framework\TestCase;

final class SiteBuilderPlanCollectContractTest extends TestCase
{
    public function testTypedSiteBuilderWorkbenchReplacesRawBrowserTransport(): void
    {
        $provider = $this->source(
            '/app/code/Weline/Websites/extends/module/Weline_Framework/Query/WebsitesQueryProvider.php'
        );
        $handler = $this->source(
            '/app/code/Weline/Websites/Service/AiWorkbench/SiteBuilderWorkbenchQueryHandler.php'
        );
        $frontend = $this->source(
            '/app/code/Weline/Websites/view/templates/Backend/SiteBuilderAgent/index-v1.phtml'
        );
        $workspace = $this->source(
            '/app/code/Weline/Websites/view/templates/Backend/SiteBuilderAgent/workspace.phtml'
        );

        self::assertStringContainsString("'siteBuilderWorkbench'", $provider);
        self::assertStringContainsString('SiteBuilderWorkbenchQueryHandler::class', $provider);
        self::assertStringContainsString("'enum' => SiteBuilderWorkbenchQueryHandler::COMMANDS", $provider);
        self::assertStringContainsString('public const COMMANDS', $handler);
        self::assertStringContainsString('throw new \\InvalidArgumentException', $handler);
        self::assertStringNotContainsString('AdminControllerBridge', $handler);

        foreach ([$frontend, $workspace] as $template) {
            self::assertStringContainsString("resource('websites').siteBuilderWorkbench", $template);
            self::assertStringContainsString('siteBuilderWorkbenchRequest', $template);
            self::assertStringNotContainsString('fetch(', $template);
            self::assertStringNotContainsString('XMLHttpRequest(', $template);
            self::assertStringNotContainsString('new EventSource(', $template);
            self::assertStringNotContainsString('bqAdmin', $template);
            self::assertStringNotContainsString("resource('websites').adminRequest", $template);
        }

        self::assertStringContainsString('websites.site_builder.v1.intake_draft', $frontend);
        self::assertStringContainsString("resource('websites').polishSiteBrief", $frontend);
        self::assertStringContainsString("'polishSiteBrief'", $provider);
    }

    private function source(string $path): string
    {
        $source = \file_get_contents(BP . $path);
        self::assertIsString($source, 'Unable to read ' . $path);

        return $source;
    }
}

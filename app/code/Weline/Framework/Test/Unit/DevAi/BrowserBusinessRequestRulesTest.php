<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\DevAi;

use PHPUnit\Framework\TestCase;

final class BrowserBusinessRequestRulesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        require_once BP . '/dev/ai/scripts/BrowserBusinessRequestRules.php';
    }

    public function testBackendCreateStreamPathsAreFlagged(): void
    {
        self::assertTrue(\BrowserBusinessRequestRules::isBackendBusinessPath(
            '/app/code/Weline/Theme/view/templates/backend/index.phtml'
        ));
        self::assertTrue(\BrowserBusinessRequestRules::isBackendBusinessPath(
            '/app/code/Weline/Websites/view/templates/Backend/SiteBuilderAgent/workspace.phtml'
        ));
        self::assertFalse(\BrowserBusinessRequestRules::isBackendBusinessPath(
            '/app/code/Weline/Ai/view/templates/Frontend/Chat/index.phtml'
        ));
    }

    public function testInstallerAndVisitorPanelAreExcluded(): void
    {
        self::assertTrue(\BrowserBusinessRequestRules::isExcludedPath(
            '/app/code/Weline/Installer/view/templates/Frontend/Install/index.phtml',
            []
        ));
        self::assertTrue(\BrowserBusinessRequestRules::isExcludedPath(
            '/app/code/Weline/Visitor/view/statics/js/weline-panel-visitor.js',
            []
        ));
        self::assertFalse(\BrowserBusinessRequestRules::isExcludedPath(
            '/app/code/Weline/Theme/view/templates/backend/index.phtml',
            []
        ));
    }

    public function testExpiredAllowlistEntriesAreIgnored(): void
    {
        $path = 'app/code/Weline/Foo/view/x.js';
        self::assertFalse(\BrowserBusinessRequestRules::isAllowlisted($path, 10, [[
            'path' => $path,
            'remove_by' => '2020-01-01',
            'line_from' => 1,
            'line_to' => 99,
        ]]));
        self::assertTrue(\BrowserBusinessRequestRules::isAllowlisted($path, 10, [[
            'path' => $path,
            'remove_by' => '2099-01-01',
            'line_from' => 1,
            'line_to' => 99,
        ]]));
    }

    public function testMaintenanceProbeLinesAreHeaderOnly(): void
    {
        self::assertTrue(\BrowserBusinessRequestRules::isHeaderOnlyLine(
            "headers: { 'X-Maintenance-Recovery-Check': '1' }"
        ));
        self::assertFalse(\BrowserBusinessRequestRules::isHeaderOnlyLine(
            "return fetch('/api/foo');"
        ));
    }
}

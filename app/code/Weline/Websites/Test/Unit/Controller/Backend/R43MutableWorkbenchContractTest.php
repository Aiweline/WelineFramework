<?php

declare(strict_types=1);

namespace Weline\Websites\Test\Unit\Controller\Backend;

use PHPUnit\Framework\TestCase;

final class R43MutableWorkbenchContractTest extends TestCase
{
    private string $spec;
    private string $fixture;
    private string $storeCopySpec;
    private string $backupTemplate;
    private string $backupService;

    protected function setUp(): void
    {
        $moduleRoot = dirname(__DIR__, 4);
        $this->spec = (string)file_get_contents(
            $moduleRoot . '/Test/e2e/backend/Weline_Websites-r43-mutable-workbenches.spec.js'
        );
        $this->fixture = (string)file_get_contents(
            $moduleRoot . '/Test/e2e/backend/Weline_Websites-r43-mutable-workbenches-fixture.php'
        );
        $this->storeCopySpec = (string)file_get_contents(
            $moduleRoot . '/Test/e2e/backend/plan-p2c-copy.spec.js'
        );
        $this->backupTemplate = (string)file_get_contents(
            $moduleRoot . '/view/templates/Backend/Backup/index.phtml'
        );
        $this->backupService = (string)file_get_contents(
            $moduleRoot . '/Service/WebsiteBackupService.php'
        );
    }

    public function testEveryMutableWorkbenchHasAnExactBrowserMutationCase(): void
    {
        foreach ([
            'CK-R43-WEBSITES-WEBSITE-001',
            'CK-R43-WEBSITES-DOMAIN-001',
            'CK-R43-WEBSITES-SITE-BUILDER-001',
            'CK-R43-WEBSITES-MAINTENANCE-001',
            'CK-R43-WEBSITES-BACKUP-001',
        ] as $caseId) {
            self::assertStringContainsString($caseId, $this->spec);
        }

        foreach ([
            'Weline_Websites::website',
            'Weline_Websites::domain_service',
            'Weline_Websites::site_builder_agent',
            'Weline_Websites::website_maintenance',
            'Weline_Websites::website_backup',
        ] as $sourceId) {
            self::assertStringContainsString($sourceId, $this->spec);
        }

        self::assertSame(5, substr_count($this->spec, 'openCapability(page, CAPABILITIES.'));
        self::assertStringContainsString('openBackendMenuBySource(page, capability.sourceId', $this->spec);
    }

    public function testDecisiveWritesUseBrowserControlsAndDurableAssertions(): void
    {
        foreach ([
            '#weline-manual-domain-submit',
            '#sbv1-create-session',
            '[data-maintenance-action="toggle"]',
            '[data-backup-submit]',
            'button[type="submit"]',
        ] as $selector) {
            self::assertStringContainsString($selector, $this->spec);
        }

        self::assertStringContainsString("fixture('inspect'", $this->spec);
        self::assertStringContainsString("fixture('cleanup'", $this->spec);
        self::assertStringContainsString('PostgreSQL', $this->spec);
        self::assertStringContainsString("selectOption('none')", $this->spec);
        self::assertStringContainsString("searchParams.set('fake_mode', '1')", $this->spec);
    }

    public function testEveryWriteCaseFinalizesBackendBrowserGuards(): void
    {
        self::assertStringContainsString('installBackendBrowserGuards', $this->spec);
        self::assertStringContainsString('installBackendBrowserGuards', $this->storeCopySpec);
        self::assertSame(
            5,
            preg_match_all('/finally\\s*\\{\\s*guards\\.assertClean\\(\\);/', $this->spec),
        );
        self::assertSame(
            1,
            preg_match_all('/finally\\s*\\{\\s*guards\\.assertClean\\(\\);/', $this->storeCopySpec),
        );
    }

    public function testFixtureFailsClosedAndCleansOnlyTaskOwnedState(): void
    {
        self::assertStringContainsString("getenv('WELINE_E2E_ISOLATED_DB') !== '1'", $this->fixture);
        self::assertStringContainsString("/^mig_clone_[a-z0-9_]+$/D", $this->fixture);
        self::assertStringContainsString('requires_postgresql', $this->fixture);
        self::assertStringContainsString('refusing Websites cleanup outside r43 namespace', $this->fixture);
        self::assertStringContainsString("str_ends_with(\$domain, '.test')", $this->fixture);
        self::assertStringContainsString('deleteSessionByPublicId', $this->fixture);
        self::assertStringContainsString('deleteBackup', $this->fixture);
        self::assertStringContainsString('JSON.stringify({ ...payload, action })', $this->spec);
        self::assertStringNotContainsString('JSON.stringify({ action, ...payload })', $this->spec);
    }

    public function testBackupWorkbenchUsesTypedQueryOperationAndReportsTerminalState(): void
    {
        self::assertStringContainsString("resource('websites').manageWebsiteBackup", $this->backupTemplate);
        self::assertStringNotContainsString("resource('websites').adminRequest", $this->backupTemplate);
        self::assertStringContainsString("data-backup-state', 'created'", $this->backupTemplate);
        self::assertStringContainsString("data-backup-state', 'failed'", $this->backupTemplate);
        self::assertStringContainsString('data-backup-error', $this->backupTemplate);
    }

    public function testBackupTableDiscoveryIsBoundedBeforeAutoloadingModels(): void
    {
        $sourceFilter = strpos($this->backupService, "file_get_contents(\$file->getPathname())");
        $autoload = strpos($this->backupService, 'class_exists($class)');
        self::assertIsInt($sourceFilter);
        self::assertIsInt($autoload);
        self::assertLessThan($autoload, $sourceFilter);
        self::assertStringContainsString('websiteScopedTablesCache', $this->backupService);
    }
}

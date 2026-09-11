<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\Service\Scoped;

use PHPUnit\Framework\TestCase;

final class FooterHelpCenterLinkScopedMigratorContractTest extends TestCase
{
    public function testUpgradeInvokesScopedFooterHelpCenterMigration(): void
    {
        $themeRoot = \dirname(__DIR__, 4);
        $upgradePath = $themeRoot . '/Setup/Upgrade.php';
        $migratorPath = $themeRoot . '/Service/Scoped/FooterHelpCenterLinkScopedMigrator.php';
        self::assertFileExists($upgradePath, $upgradePath);
        self::assertFileExists($migratorPath, $migratorPath);

        $upgrade = (string)\file_get_contents($upgradePath);
        self::assertStringContainsString('migrateScopedFooterHelpCenterLinkNodes()', $upgrade);
        self::assertStringContainsString('FooterHelpCenterLinkScopedMigrator', $upgrade);

        $migrator = (string)\file_get_contents($migratorPath);
        self::assertStringContainsString("LEGACY_WIDGET_CODE = 'footer-help-center-link'", $migrator);
        self::assertStringContainsString("CURRENT_WIDGET_CODE = 'footer-faq-link'", $migrator);
        self::assertStringContainsString('skipContentValidation: true', $migrator);
        self::assertStringContainsString('migrate_footer_help_center_link_to_faq', $migrator);
    }
}

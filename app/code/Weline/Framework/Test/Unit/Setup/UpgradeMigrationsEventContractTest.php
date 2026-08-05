<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Setup;

use PHPUnit\Framework\TestCase;

final class UpgradeMigrationsEventContractTest extends TestCase
{
    public function testMigrationEventIsCataloguedSubscribedAndOrdered(): void
    {
        $frameworkRoot = \dirname(__DIR__, 3);
        $databaseRoot = \dirname($frameworkRoot) . '/Database';
        $catalog = (string)\file_get_contents($frameworkRoot . '/event.php');
        $upgrade = (string)\file_get_contents(
            $frameworkRoot . '/Setup/Console/Setup/Upgrade.php',
        );
        $databaseEvents = (string)\file_get_contents($databaseRoot . '/etc/event.xml');

        self::assertStringContainsString(
            "'Weline_Framework_Setup::upgrade_migrations' => [",
            $catalog,
        );
        self::assertSame(1, \preg_match(
            '/<event name="Weline_Framework_Setup::upgrade_migrations">.*?'
            . 'Weline\\\\Database\\\\Observer\\\\SetupUpgradeObserver.*?<\\/event>/s',
            $databaseEvents,
        ));
        self::assertStringContainsString(
            "\$eventsManager->dispatch('Weline_Framework_Setup::upgrade_migrations'",
            $upgrade,
        );

        $migrationCall = \strpos(
            $upgrade,
            '$this->runDatabaseFileMigrations($args, $argsModule);',
        );
        self::assertIsInt($migrationCall);

        $schemaCommit = \strrpos(
            \substr($upgrade, 0, $migrationCall),
            '$schemaDiffStage->commit();',
        );
        $moduleSetupCommit = \strpos(
            $upgrade,
            '$moduleSetupStage->commit();',
            $migrationCall,
        );

        self::assertIsInt($schemaCommit);
        self::assertIsInt($moduleSetupCommit);
        self::assertLessThan($migrationCall, $schemaCommit);
        self::assertLessThan($moduleSetupCommit, $migrationCall);
    }

    public function testUpgradeAfterPayloadIsPassedThroughAReferenceCompatibleVariable(): void
    {
        $frameworkRoot = \dirname(__DIR__, 3);
        $upgrade = (string)\file_get_contents(
            $frameworkRoot . '/Setup/Console/Setup/Upgrade.php',
        );

        self::assertSame(2, \substr_count($upgrade, '$upgradeAfterPayload = ['));
        self::assertSame(
            2,
            \substr_count(
                $upgrade,
                "\$eventsManager->dispatch('Weline_Framework_Setup::upgrade_after', \$upgradeAfterPayload);",
            ),
        );
        self::assertStringNotContainsString(
            "\$eventsManager->dispatch('Weline_Framework_Setup::upgrade_after', [",
            $upgrade,
        );
    }
}

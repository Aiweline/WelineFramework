<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Setup;

use PHPUnit\Framework\TestCase;

final class UpgradeRouteSchemaRefreshContractTest extends TestCase
{
    public function testRouteOnlySchemaDiffUsesAHandleCreatedAfterModuleRegistryRefresh(): void
    {
        $source = (string)file_get_contents(
            BP . 'app/code/Weline/Framework/Setup/Console/Setup/Upgrade.php',
        );
        $routeBranch = strpos($source, 'if ($doRoute) {');
        $refresh = strpos($source, 'Env::getInstance()->getModuleList(true);', $routeBranch);
        $discard = strpos($source, 'ObjectManager::removeInstance(Handle::class);', $refresh);
        $handle = strpos(
            $source,
            '$routeModuleHandle = ObjectManager::getInstance(Handle::class);',
            $discard,
        );
        $schemaPrepare = strpos($source, '$schemaDiffStage->prepare($setupStageContext);', $handle);

        self::assertIsInt($routeBranch);
        self::assertIsInt($refresh);
        self::assertIsInt($discard);
        self::assertIsInt($handle);
        self::assertIsInt($schemaPrepare);
        self::assertLessThan($discard, $refresh);
        self::assertLessThan($handle, $discard);
        self::assertLessThan($schemaPrepare, $handle);
    }

    public function testRegistryBootstrapSyncsCodeVersionWithoutAdvancingSetupCursor(): void
    {
        $source = (string)file_get_contents(
            BP . 'app/code/Weline/Framework/Setup/Console/Setup/Upgrade.php',
        );
        $bootstrap = strpos(
            $source,
            'private function preRegisterDiscoveredModulesForRegistryBootstrap',
        );
        $manifest = strpos(
            $source,
            "\$manifestFile = \$localBasePath . 'etc'",
            $bootstrap,
        );
        $versionWrite = strpos(
            $source,
            "\$currentModules[\$moduleName]['version'] = \$codeVersion;",
            $manifest,
        );
        $moduleWrite = strpos($source, '$moduleData->updateModules($currentModules);', $versionWrite);

        self::assertIsInt($bootstrap);
        self::assertIsInt($manifest);
        self::assertIsInt($versionWrite);
        self::assertIsInt($moduleWrite);
        self::assertLessThan($versionWrite, $manifest);
        self::assertLessThan($moduleWrite, $versionWrite);
        self::assertStringNotContainsString(
            "\$currentModules[\$moduleName]['setup_version'] = \$codeVersion;",
            substr($source, $bootstrap, $moduleWrite - $bootstrap),
        );
    }
}

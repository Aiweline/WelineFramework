<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Deploy;

use PHPUnit\Framework\TestCase;
use ReflectionClass as ReflectionClass;
use Weline\Framework\App\Env;
use Weline\Framework\App\System;
use Weline\Framework\Console\Console\Deploy\Mode\Set as ModeSet;
use Weline\Framework\Console\Console\Deploy\Upgrade;
use Weline\Framework\Deploy\DeployFpcInvalidation;
use Weline\Framework\Deploy\HostProcessPoolPolicy;
use Weline\Framework\Deploy\StaticPublicSurface;
use Weline\Framework\Deploy\StaticPublishExclusion;
use Weline\Framework\Event\Changed\InvalidationEffect;
use Weline\Framework\Extends\Module\Weline_Framework\Changed\Type\DeployStaticChangedType;
use Weline\Framework\Test\ProjectRoot;

/**
 * Aggregated contract suite (merged from 7 *ContractTest files).
 * Observe one overall pass/fail via testAllDeployContractsPass().
 */
final class DeployContractsTest extends TestCase
{
    public function testAllDeployContractsPass(): void
    {
        $failures = [];
        foreach ($this->contractChecks() as $name => $fn) {
            try {
                $fn();
            } catch (\Throwable $e) {
                $failures[] = $name . ": " . $e->getMessage();
            }
        }
        self::assertSame([], $failures, implode("\n", $failures));
    }

    /** @return array<string, callable(): void> */
    private function contractChecks(): array
    {
        return [
            'DeployFpcInvalidation::testDeployNamespaceConstantMatchesCanonicalPath' => function () {
            $this->assert_DeployFpcInvalidation_testDeployNamespaceConstantMatchesCanonicalPath();
        },
            'DeployFpcInvalidation::testChangedTypeRecipePurgesOnlyWhenFlagged' => function () {
            $this->assert_DeployFpcInvalidation_testChangedTypeRecipePurgesOnlyWhenFlagged();
        },
            'DeployFpcInvalidation::testModeSetProdWiresHelperAndSkipsUpgradeInvalidation' => function () {
            $this->assert_DeployFpcInvalidation_testModeSetProdWiresHelperAndSkipsUpgradeInvalidation();
        },
            'DeployFpcInvalidation::testUpgradeCallsHelperUnlessSkipped' => function () {
            $this->assert_DeployFpcInvalidation_testUpgradeCallsHelperUnlessSkipped();
        },
            'DeployFpcInvalidation::testEmptyUpgradeSkipsInvalidationSemantics' => function () {
            $this->assert_DeployFpcInvalidation_testEmptyUpgradeSkipsInvalidationSemantics();
        },
            'DeployFpcInvalidation::testAfterReleaseHelperExistsAndReleaseTriggerConstant' => function () {
            $this->assert_DeployFpcInvalidation_testAfterReleaseHelperExistsAndReleaseTriggerConstant();
        },
            'DeployFpcInvalidation::testResolverSourceIncludesDeployNamespace' => function () {
            $this->assert_DeployFpcInvalidation_testResolverSourceIncludesDeployNamespace();
        },
            'DeployModeSetStaging::testProdUsesStagingPipelineWithoutLiveWipeFirst' => function () {
            $this->assert_DeployModeSetStaging_testProdUsesStagingPipelineWithoutLiveWipeFirst();
        },
            'StaticAssetMode::testModeSetDelegatesProdStaticPublishWithoutThemeTypes' => function () {
            $this->assert_StaticAssetMode_testModeSetDelegatesProdStaticPublishWithoutThemeTypes();
        },
            'DeployModeSetStaging::testUpgradeAndTraitTemplateRespectStaging' => function () {
            $this->assert_DeployModeSetStaging_testUpgradeAndTraitTemplateRespectStaging();
        },
            'DeployStaticConcurrency::testUpgradeExposesModuleStaticProcessPool' => function () {
            $this->assert_DeployStaticConcurrency_testUpgradeExposesModuleStaticProcessPool();
        },
            'DeployStaticConcurrency::testConcurrencyClampCeilingConstants' => function () {
            $this->assert_DeployStaticConcurrency_testConcurrencyClampCeilingConstants();
        },
            'DeployStaticConcurrency::testResolveUsesHostPolicyWhenEnvUnset' => function () {
            $this->assert_DeployStaticConcurrency_testResolveUsesHostPolicyWhenEnvUnset();
        },
            'DeployStaticConcurrency::testWorkerScriptExists' => function () {
            $this->assert_DeployStaticConcurrency_testWorkerScriptExists();
        },
            'DeployStaticConcurrency::testHostProcessPoolPolicyClassExists' => function () {
            $this->assert_DeployStaticConcurrency_testHostProcessPoolPolicyClassExists();
        },
            'ModeSetCompileCleanup::testModeSetFlushesPoolsThenDeletesCompileTreesOnce' => function () {
            $this->assert_ModeSetCompileCleanup_testModeSetFlushesPoolsThenDeletesCompileTreesOnce();
        },
            'ModeSetCompileCleanup::testCacheClearSupportsPoolsOnlySkipViewCompile' => function () {
            $this->assert_ModeSetCompileCleanup_testCacheClearSupportsPoolsOnlySkipViewCompile();
        },
            'ModeSetCompileCleanup::testTemplateCacheManagerClearAllDeletesTreeOnce' => function () {
            $this->assert_ModeSetCompileCleanup_testTemplateCacheManagerClearAllDeletesTreeOnce();
        },
            'ModeSetSystemDeployKey::testPersistDeployModeWritesSystemDeployAuthoritativeKey' => function () {
            $this->setUp_ModeSetSystemDeployKey();
            try {
                $this->assert_ModeSetSystemDeployKey_testPersistDeployModeWritesSystemDeployAuthoritativeKey();
            } finally {
                $this->tearDown_ModeSetSystemDeployKey();
            }
        },
            'ModeSetSystemDeployKey::testPersistDeployModeRejectsInvalidType' => function () {
            $this->setUp_ModeSetSystemDeployKey();
            try {
                $this->assert_ModeSetSystemDeployKey_testPersistDeployModeRejectsInvalidType();
            } finally {
                $this->tearDown_ModeSetSystemDeployKey();
            }
        },
            'ModeSetSystemDeployKey::testModeSetSourceUsesSystemDeployNotOnlyTopLevel' => function () {
            $this->setUp_ModeSetSystemDeployKey();
            try {
                $this->assert_ModeSetSystemDeployKey_testModeSetSourceUsesSystemDeployNotOnlyTopLevel();
            } finally {
                $this->tearDown_ModeSetSystemDeployKey();
            }
        },
            'NginxSampleBinQueryAllow::testSampleNginxConfAllowsBinQueryWhileDenyingCliBinTree' => function () {
            $this->assert_NginxSampleBinQueryAllow_testSampleNginxConfAllowsBinQueryWhileDenyingCliBinTree();
        },
            'StaticPublicSurface::testFastPathExtensionsAreASubsetOfServableExtensions' => function () {
            $this->setUp_StaticPublicSurface();
            $this->assert_StaticPublicSurface_testFastPathExtensionsAreASubsetOfServableExtensions();
        },
            'StaticPublicSurface::testServableExtensionsContainNoScriptExtension' => function () {
            $this->setUp_StaticPublicSurface();
            $this->assert_StaticPublicSurface_testServableExtensionsContainNoScriptExtension();
        },
            'StaticPublicSurface::testPublishExcludedPathsAreNeverServable' => function () {
            $this->setUp_StaticPublicSurface();
            $this->assert_StaticPublicSurface_testPublishExcludedPathsAreNeverServable();
        },
            'StaticPublicSurface::testRuntimeAssetsRemainServable' => function () {
            $this->setUp_StaticPublicSurface();
            $this->assert_StaticPublicSurface_testRuntimeAssetsRemainServable();
        },
            'StaticPublicSurface::testAnythingOutsideThePublicSurfaceIsDenied' => function () {
            $this->setUp_StaticPublicSurface();
            $this->assert_StaticPublicSurface_testAnythingOutsideThePublicSurfaceIsDenied();
        },
            'StaticPublicSurface::testNginxSampleAllowlistMatchesTheAuthoritativePattern' => function () {
            $this->setUp_StaticPublicSurface();
            $this->assert_StaticPublicSurface_testNginxSampleAllowlistMatchesTheAuthoritativePattern();
        },
            'StaticPublicSurface::testWlsWorkerFastPathListsMatchTheAuthoritativeSet' => function () {
            $this->setUp_StaticPublicSurface();
            $this->assert_StaticPublicSurface_testWlsWorkerFastPathListsMatchTheAuthoritativeSet();
        },
            'StaticPublicSurface::testFrameworkFallbackActuallyEnforcesThePolicy' => function () {
            $this->setUp_StaticPublicSurface();
            $this->assert_StaticPublicSurface_testFrameworkFallbackActuallyEnforcesThePolicy();
        },
        ];
    }

private function assert_DeployFpcInvalidation_testDeployNamespaceConstantMatchesCanonicalPath(): void
{
    self::assertSame('global/storefront/deploy', DeployFpcInvalidation::NS_STOREFRONT_DEPLOY);
}

private function assert_DeployFpcInvalidation_testChangedTypeRecipePurgesOnlyWhenFlagged(): void
{
    $type = new DeployStaticChangedType();
    self::assertSame('deploy_static', $type->code());
    $bumpOnly = $this->fakeChange_DeployFpcInvalidation(['purge_fpc_all' => false]);
    $codesBump = array_map(static fn(InvalidationEffect $e): string => $e->code, $type->recipe($bumpOnly));
    self::assertContains(InvalidationEffect::CODE_BUMP_NAMESPACES, $codesBump);
    self::assertNotContains(InvalidationEffect::CODE_PURGE_FPC_ALL, $codesBump);
    $withPurge = $this->fakeChange_DeployFpcInvalidation(['purge_fpc_all' => true, 'reason' => 'deploy_mode_set_prod']);
    $effects = $type->recipe($withPurge);
    $codes = array_map(static fn(InvalidationEffect $e): string => $e->code, $effects);
    self::assertContains(InvalidationEffect::CODE_BUMP_NAMESPACES, $codes);
    self::assertContains(InvalidationEffect::CODE_PURGE_FPC_ALL, $codes);
    $purge = null;
    foreach ($effects as $effect) {
        if ($effect->code === InvalidationEffect::CODE_PURGE_FPC_ALL) {
            $purge = $effect;
            break;
        }
    }
    self::assertNotNull($purge);
    self::assertSame('Weline_Framework', $purge->payload['source_module'] ?? null);
}

private function assert_DeployFpcInvalidation_testModeSetProdWiresHelperAndSkipsUpgradeInvalidation(): void
{
    $src = (string) file_get_contents(BP . 'app/code/Weline/Framework/Console/Console/Deploy/Mode/Set.php');
    self::assertStringContainsString('DeployFpcInvalidation', $src);
    self::assertStringContainsString('afterModeSetProd', $src);
    self::assertStringContainsString('DATA_SKIP_INVALIDATION', $src);
    self::assertStringContainsString('prod_after', $src);
    $helper = (string) file_get_contents(BP . 'app/code/Weline/Framework/Deploy/DeployFpcInvalidation.php');
    self::assertStringContainsString('FpcStaticDiskStore::purgeAllLive', $helper);
}

private function assert_DeployFpcInvalidation_testUpgradeCallsHelperUnlessSkipped(): void
{
    $src = (string) file_get_contents(BP . 'app/code/Weline/Framework/Console/Console/Deploy/Upgrade.php');
    self::assertStringContainsString('DeployFpcInvalidation', $src);
    self::assertStringContainsString('afterUpgrade', $src);
    self::assertStringContainsString('DATA_SKIP_INVALIDATION', $src);
    self::assertStringContainsString('treeChanged', $src);
    self::assertStringContainsString('publishModuleFlatStatics', $src);
}

private function assert_DeployFpcInvalidation_testEmptyUpgradeSkipsInvalidationSemantics(): void
{
    $helper = (new ReflectionClass(DeployFpcInvalidation::class))->newInstanceWithoutConstructor();
    $method = new \ReflectionMethod(DeployFpcInvalidation::class, 'afterUpgrade');
    // afterUpgrade 需要 ObjectManager for publish；空跑分支在 publish 前 return
    $result = $method->invoke($helper, false);
    self::assertTrue($result['skipped']);
    self::assertFalse($result['invalidated']);
    self::assertFalse($result['purged']);
}

private function assert_DeployFpcInvalidation_testAfterReleaseHelperExistsAndReleaseTriggerConstant(): void
{
    self::assertSame('release', DeployFpcInvalidation::TRIGGER_RELEASE);
    $src = (string) file_get_contents(BP . 'app/code/Weline/Framework/Deploy/DeployFpcInvalidation.php');
    self::assertStringContainsString('function afterRelease', $src);
    // System release: stamp only — must not publishInvalidation with purge_fpc_all.
    self::assertStringNotContainsString("'deploy_release'", $src);
    $afterRelease = (string) (preg_match('/function afterRelease\(\): array\s*\{(.*?)\n    \}/s', $src, $m) === 1 ? $m[1] : '');
    self::assertStringContainsString('ensureObservableDeployStamp', $afterRelease);
    self::assertStringNotContainsString('publishInvalidation', $afterRelease);
    self::assertStringNotContainsString('purgeFpcAll: true', $afterRelease);
}

private function assert_DeployFpcInvalidation_testResolverSourceIncludesDeployNamespace(): void
{
    $src = (string) file_get_contents(BP . 'app/code/Weline/Framework/Cache/StorefrontCacheKeyContextResolver.php');
    self::assertStringContainsString("global('storefront', ['deploy'])", $src);
}

/**
 * @param array<string,mixed> $after
 */
private function fakeChange_DeployFpcInvalidation(array $after): \Weline\Framework\Event\ResourceChange\ResourceChange
{
    return \Weline\Framework\Event\ResourceChange\ResourceChange::fromArray(['schema_version' => 1, 'event_id' => str_repeat('a', 32), 'event_name' => \Weline\Framework\Event\ResourceChange\ResourceChange::EVENT_NAME, 'occurred_at' => '2026-09-24T00:00:00.000000Z', 'resource' => ['type' => 'deploy_static', 'id' => 'storefront:test', 'action' => 'publish', 'revision' => 1], 'website' => ['id' => 0, 'code' => 'default', 'previous_code' => null, 'site_id' => 0], 'impact' => ['namespaces' => [DeployFpcInvalidation::NS_STOREFRONT_DEPLOY], 'previous_namespaces' => [], 'urls' => [], 'previous_urls' => []], 'changed_fields' => ['deploy_version'], 'before' => ['trigger' => 'test'], 'after' => $after, 'origin' => ['area' => 'cli', 'entry' => 'test', 'request_id' => '', 'instance' => '', 'trigger_by' => ['type' => 'system', 'id' => null]], 'context' => ['website_id' => 0, 'website_code' => 'default', 'lang' => 'zh_Hans_CN', 'currency' => 'CNY', 'area' => 'cli', 'timezone' => 'UTC', 'user' => ['type' => 'system', 'id' => null]]]);
}

private function assert_StaticAssetMode_testModeSetDelegatesProdStaticPublishWithoutThemeTypes(): void
{
    $mode = (string) file_get_contents(BP . 'app/code/Weline/Framework/Deploy/StaticAssetMode.php');
    self::assertStringContainsString('publishForModeSet', $mode);
    self::assertStringContainsString('publishForThemeUpgrade', $mode);
    self::assertStringContainsString('publishStaticTree', $mode);
    self::assertStringContainsString('DeployPublicUrlMode::useProduction', $mode);
    self::assertStringContainsString("deploy.static_asset_mode.", $mode);
    self::assertStringNotContainsString('Weline\\Theme\\', $mode);
    $set = (string) file_get_contents(BP . 'app/code/Weline/Framework/Console/Console/Deploy/Mode/Set.php');
    self::assertStringContainsString('StaticAssetMode', $set);
    self::assertStringContainsString('publishForModeSet', $set);
    $upgrade = (string) file_get_contents(BP . 'app/code/Weline/Framework/Console/Console/Deploy/Upgrade.php');
    self::assertStringContainsString('function publishStaticTree', $upgrade);
}

private function assert_DeployModeSetStaging_testProdUsesStagingPipelineWithoutLiveWipeFirst(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/Framework' . '/Console/Console/Deploy/Mode/Set.php';
    $src = (string) file_get_contents($path);
    self::assertStringContainsString('DeployStagingSession', $src);
    self::assertStringContainsString('deployProdViaStaging', $src);
    self::assertStringContainsString('commitSwap', $src);
    self::assertStringContainsString('purgePrevAndResidue', $src);
    self::assertStringContainsString('abort', $src);
    // Prod path must not wipe live trees before staging pipeline.
    // Scoop only until the next case (prod ends with return, not break).
    self::assertSame(1, preg_match('/case\s+[\'"]prod[\'"]\s*:\s*(.*?)(?=\n\s*case\s+)/s', $src, $prodCase));
    $prodBody = (string) ($prodCase[1] ?? '');
    self::assertStringContainsString('deployProdViaStaging', $prodBody);
    self::assertMatchesRegularExpression('/persistDeployMode\(\s*[\'"]prod[\'"]\s*\).*deployProdViaStaging/s', $prodBody);
    self::assertStringNotContainsString('cleanTplComDir', $prodBody);
    self::assertStringNotContainsString('clearGeneratedComplicateDir', $prodBody);
    self::assertStringNotContainsString('cleanThemeDir', $prodBody);
    // Dev still cleans compile dirs.
    self::assertSame(1, preg_match('/case\s+[\'"]dev[\'"]\s*:\s*(.*?)break;/s', $src, $devCase));
    self::assertStringContainsString('cleanTplComDir', (string) ($devCase[1] ?? ''));
}

private function assert_DeployModeSetStaging_testUpgradeAndTraitTemplateRespectStaging(): void
{
    $upgrade = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Framework' . '/Console/Console/Deploy/Upgrade.php');
    self::assertStringContainsString('DeployStagingSession::staticRoot()', $upgrade);
    self::assertStringNotContainsString("resolveFlatStaticModuleRoot(\$moduleName, PUB . 'static')", $upgrade);
    $trait = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Framework' . '/View/TraitTemplate.php');
    self::assertStringContainsString('DeployStagingSession::isActive()', $trait);
    self::assertStringContainsString('DeployStagingSession::complicateRoot()', $trait);
    self::assertStringContainsString('DeployPublicUrlMode::useProduction', $trait);
    self::assertStringContainsString('useProductionStaticPublicUrls', $trait);
    self::assertStringContainsString('deploy_public_urls', $trait);
    self::assertStringContainsString('context-env-v10-module-static-flat', $trait);
    $publicUrlMode = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Framework' . '/Deploy/DeployPublicUrlMode.php');
    self::assertStringContainsString('forcesProductionPublicUrls', $publicUrlMode);
    self::assertStringContainsString("Env::system('deploy')", $publicUrlMode);
    $session = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Framework' . '/Deploy/DeployStagingSession.php');
    self::assertStringContainsString('rollbackSwap', $session);
    self::assertStringContainsString('deploy_staging_static_empty', $session);
    self::assertStringContainsString('WELINE_DEPLOY_STAGING_ROOT', $session);
    self::assertStringContainsString('removeEmptyStagingParent', $session);
    self::assertStringContainsString('forcesProductionPublicUrls', $session);
    $modeSet = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Framework' . '/Console/Console/Deploy/Mode/Set.php');
    // deactivate before purgePrev (env cleared before deleting staging dirs).
    self::assertMatchesRegularExpression('/deactivate\(\);\s*\$session->purgePrevAndResidue\(\);/m', $modeSet);
    self::assertStringContainsString('reloadPersistentWorkersAfterModeSet', $modeSet);
    self::assertStringContainsString('server:reload', $modeSet);
}

private function assert_DeployStaticConcurrency_testUpgradeExposesModuleStaticProcessPool(): void
{
    $path = '/Users/weline/Project/Official/框架/app/code/Weline/Framework' . '/Console/Console/Deploy/Upgrade.php';
    $src = (string) file_get_contents($path);
    self::assertStringContainsString('WELINE_DEPLOY_STATIC_CONCURRENCY', $src);
    self::assertStringContainsString('HostProcessPoolPolicy', $src);
    self::assertStringContainsString('resolveStaticConcurrencyDecision', $src);
    self::assertStringContainsString('publishModuleJobs', $src);
    self::assertStringContainsString('publishOneModuleJob', $src);
    self::assertStringContainsString('publish-module-static-job.php', $src);
    self::assertStringContainsString('静态资源部署进程池', $src);
    self::assertStringContainsString('source=%s cpus=%d mem_avail_mb=%s', $src);
}

private function assert_DeployStaticConcurrency_testConcurrencyClampCeilingConstants(): void
{
    $ref = new \ReflectionClass(Upgrade::class);
    self::assertSame(10, $ref->getConstant('DEFAULT_STATIC_CONCURRENCY'));
    self::assertSame(32, $ref->getConstant('MAX_STATIC_CONCURRENCY'));
}

private function assert_DeployStaticConcurrency_testResolveUsesHostPolicyWhenEnvUnset(): void
{
    $prev = getenv(Upgrade::ENV_STATIC_CONCURRENCY);
    putenv(Upgrade::ENV_STATIC_CONCURRENCY);
    unset($_ENV[Upgrade::ENV_STATIC_CONCURRENCY], $_SERVER[Upgrade::ENV_STATIC_CONCURRENCY]);
    try {
        $upgrade = (new \ReflectionClass(Upgrade::class))->newInstanceWithoutConstructor();
        $decision = $upgrade->resolveStaticConcurrencyDecision(null);
        self::assertSame('host_auto', $decision['source']);
        self::assertGreaterThanOrEqual(1, $decision['concurrency']);
        self::assertLessThanOrEqual(Upgrade::MAX_STATIC_CONCURRENCY, $decision['concurrency']);
        self::assertSame(4, $upgrade->resolveStaticConcurrency(4));
    } finally {
        if ($prev === false) {
            putenv(Upgrade::ENV_STATIC_CONCURRENCY);
        } else {
            putenv(Upgrade::ENV_STATIC_CONCURRENCY . '=' . $prev);
            $_ENV[Upgrade::ENV_STATIC_CONCURRENCY] = $prev;
        }
    }
}

private function assert_DeployStaticConcurrency_testWorkerScriptExists(): void
{
    $script = '/Users/weline/Project/Official/框架/app/code/Weline/Framework' . '/Deploy/bin/publish-module-static-job.php';
    self::assertFileExists($script);
    $src = (string) file_get_contents($script);
    self::assertStringContainsString('publishOneModuleJob', $src);
}

private function assert_DeployStaticConcurrency_testHostProcessPoolPolicyClassExists(): void
{
    self::assertTrue(class_exists(HostProcessPoolPolicy::class));
}

private function frameworkFile_ModeSetCompileCleanup(string $relativeFromFramework): string
{
    return '/Users/weline/Project/Official/框架/app/code/Weline/Framework' . '/' . \ltrim(\str_replace('\\', '/', $relativeFromFramework), '/');
}

private function assert_ModeSetCompileCleanup_testModeSetFlushesPoolsThenDeletesCompileTreesOnce(): void
{
    $src = (string) \file_get_contents($this->frameworkFile_ModeSetCompileCleanup('Console/Console/Deploy/Mode/Set.php'));
    self::assertStringContainsString("skip_view_compile' => true", $src);
    self::assertStringContainsString('刷新缓存池（flush）', $src);
    self::assertStringContainsString('正在删除模组模板编译目录', $src);
    self::assertStringContainsString('clearGeneratedComplicateDir', $src);
    self::assertStringContainsString('escapeshellarg', $src);
    // prod/dev 路径不得再二次 cleanTplComDir（整文件仅出现一次调用链：deploy 内一次）。
    $cleanCalls = \substr_count($src, '$this->cleanTplComDir()');
    self::assertSame(1, $cleanCalls, 'cleanTplComDir must run once per deploy()');
}

private function assert_ModeSetCompileCleanup_testCacheClearSupportsPoolsOnlySkipViewCompile(): void
{
    $src = (string) \file_get_contents($this->frameworkFile_ModeSetCompileCleanup('Cache/Console/Cache/Clear.php'));
    self::assertStringContainsString('skip_view_compile', $src);
    self::assertStringContainsString('--pools-only', $src);
}

private function assert_ModeSetCompileCleanup_testTemplateCacheManagerClearAllDeletesTreeOnce(): void
{
    $src = (string) \file_get_contents($this->frameworkFile_ModeSetCompileCleanup('View/TemplateCacheManager.php'));
    self::assertStringContainsString('rm -rf', $src);
    self::assertStringContainsString('escapeshellarg($this->cacheRoot)', $src);
    // 旧慢路径：glob 后逐文件 removeDirectory
    self::assertStringNotContainsString('glob($this->cacheRoot', $src);
}

private string $envPath_ModeSetSystemDeployKey = '';

private string $originalEnvContent_ModeSetSystemDeployKey = '';

private function setUp_ModeSetSystemDeployKey(): void
{
    $this->envPath_ModeSetSystemDeployKey = Env::path_ENV_FILE;
    $this->originalEnvContent_ModeSetSystemDeployKey = \is_file($this->envPath_ModeSetSystemDeployKey) ? (string) \file_get_contents($this->envPath_ModeSetSystemDeployKey) : "<?php return [];";
}

private function tearDown_ModeSetSystemDeployKey(): void
{
    \file_put_contents($this->envPath_ModeSetSystemDeployKey, $this->originalEnvContent_ModeSetSystemDeployKey);
    Env::getInstance()->reload();
}

private function assert_ModeSetSystemDeployKey_testPersistDeployModeWritesSystemDeployAuthoritativeKey(): void
{
    \file_put_contents($this->envPath_ModeSetSystemDeployKey, <<<'PHP'
    <?php return [
        'system' => ['deploy' => 'dev'],
        'deploy' => 'dev',
    ];
    PHP);
    Env::getInstance()->reload();
    self::assertSame('dev', Env::system('deploy'));
    $modeSet = $this->newModeSetWithoutConstructor_ModeSetSystemDeployKey();
    self::assertTrue($modeSet->persistDeployMode('prod'));
    self::assertSame('prod', Env::system('deploy'));
    self::assertSame('prod', Env::getInstance()->getConfig('system.deploy'));
    self::assertSame('prod', Env::getInstance()->getConfig('deploy'));
    /** @var array<string, mixed> $persisted */
    $persisted = include $this->envPath_ModeSetSystemDeployKey;
    self::assertSame('prod', $persisted['system']['deploy'] ?? null);
    self::assertSame('prod', $persisted['deploy'] ?? null);
}

private function assert_ModeSetSystemDeployKey_testPersistDeployModeRejectsInvalidType(): void
{
    $modeSet = $this->newModeSetWithoutConstructor_ModeSetSystemDeployKey();
    self::assertFalse($modeSet->persistDeployMode('staging'));
}

private function assert_ModeSetSystemDeployKey_testModeSetSourceUsesSystemDeployNotOnlyTopLevel(): void
{
    $src = (string) \file_get_contents(BP . 'app/code/Weline/Framework/Console/Console/Deploy/Mode/Set.php');
    self::assertStringContainsString("setConfig('system.deploy'", $src);
    self::assertStringContainsString('persistDeployMode', $src);
    self::assertStringContainsString("Env::system('deploy')", $src);
}

private function newModeSetWithoutConstructor_ModeSetSystemDeployKey(): ModeSet
{
    $ref = new ReflectionClass(ModeSet::class);
    /** @var ModeSet $instance */
    $instance = $ref->newInstanceWithoutConstructor();
    $systemProp = $ref->getProperty('system');
    $systemProp->setValue($instance, $this->createMock(System::class));
    return $instance;
}

private function assert_NginxSampleBinQueryAllow_testSampleNginxConfAllowsBinQueryWhileDenyingCliBinTree(): void
{
    ProjectRoot::defineBp(__DIR__);
    $conf = BP . DIRECTORY_SEPARATOR . 'nginx.conf';
    self::assertFileExists($conf);
    $src = \file_get_contents($conf);
    self::assertIsString($src);
    self::assertMatchesRegularExpression('/location\s*=\s*\/bin\/query\s*\{/', $src, 'Sample nginx must exact-match /bin/query into the front controller.');
    self::assertStringContainsString('rewrite ^/(.*)$ /index.php?s=$1 last;', $src);
    self::assertMatchesRegularExpression('/location\s*~\s*\^\/bin\/\s*\{\s*return\s+404;/', $src, 'CLI bin/ tree must remain web-denied.');
    $exactPos = \strpos($src, 'location = /bin/query');
    $denyPos = \strpos($src, 'location ~ ^/bin/');
    self::assertIsInt($exactPos);
    self::assertIsInt($denyPos);
    self::assertLessThan($denyPos, $exactPos, 'Exact /bin/query must appear before the regex deny (readable order; = still wins).');
}

private string $repoRoot_StaticPublicSurface;

private function setUp_StaticPublicSurface(): void
{
    // .../app/code/Weline/Framework/Test/Unit/Deploy → 仓库根
    $this->repoRoot_StaticPublicSurface = '/Users/weline/Project/Official/框架';
}

// ---------------------------------------------------------------- 不变式 1
private function assert_StaticPublicSurface_testFastPathExtensionsAreASubsetOfServableExtensions(): void
{
    $fast = StaticPublicSurface::FAST_PATH_EXTENSIONS;
    $servable = StaticPublicSurface::SERVABLE_EXTENSIONS;
    $outside = array_values(array_diff($fast, $servable));
    self::assertSame([], $outside, '快路径只可收窄，不得扩大暴露面');
}

// ---------------------------------------------------------------- 不变式 2
/**
 * 服务端可执行 / 模板源码扩展名绝不得出现在服务白名单里。
 */
private function assert_StaticPublicSurface_testServableExtensionsContainNoScriptExtension(): void
{
    $scripts = ['php', 'phtml', 'pht', 'phps', 'phar', 'php3', 'php4', 'php5', 'php7', 'php8', 'cgi', 'fcgi', 'pl', 'py', 'rb', 'sh', 'bash', 'zsh', 'asp', 'aspx', 'jsp', 'jspx', 'shtml'];
    $collisions = array_values(array_intersect($scripts, StaticPublicSurface::SERVABLE_EXTENSIONS));
    self::assertSame([], $collisions, '脚本/模板扩展名不得进入服务白名单');
}

// ---------------------------------------------------------------- 不变式 3
/**
 * 「发布不了的东西，绝不可能是给人下载的东西」。
 */
private function assert_StaticPublicSurface_testPublishExcludedPathsAreNeverServable(): void
{
    $samples = ['php/connector.minimal.php', 'php/elFinder.class.php', 'php/connector.minimal.php-dist', 'frontend/layouts/homepage/default.phtml', 'register.php', 'libs/x/docs/index.html', 'libs/x/node_modules/a/index.js', 'libs/x/package.json', 'libs/x/package-lock.json', 'libs/x/.gitignore', 'libs/x/.eslintrc.json', 'libs/x/README.md', 'libs/x/LICENSE', 'test/content-slot-preservation.php', 'tools/build.py', 'scripts/deploy.sh'];
    foreach ($samples as $relative) {
        self::assertTrue(StaticPublishExclusion::isExcluded($relative), $relative . ' 应被发布侧排除（前提断言）');
        self::assertFalse(StaticPublicSurface::isServableUrlPath('static/' . $relative), $relative . ' 不得作为静态资源对外提供');
    }
}

/**
 * 运行时资源必须仍然可达（防止口径过紧误伤）。
 */
private function assert_StaticPublicSurface_testRuntimeAssetsRemainServable(): void
{
    $samples = ['static/Weline/x/view/statics/css/app.css', 'static/Weline/x/view/statics/js/app.js', 'static/Weline/x/view/statics/js/app.mjs', 'static/Weline/x/view/statics/js/bundle.js.map', 'static/Weline/x/view/statics/fonts/icon.woff2', 'static/Weline/x/view/statics/images/hero.webp', 'static/Weline/x/view/statics/libs/a.min.js', 's/20260914/Weline/x/view/statics/css/app.css', 'media/catalog/a/b.jpg', 'errors/404.html', '.well-known/acme-challenge/token'];
    foreach ($samples as $path) {
        self::assertTrue(StaticPublicSurface::isServableUrlPath($path), $path . ' 是运行时资源，必须可达');
    }
}

/**
 * 目录穿越与公共根之外的路径一律拒绝（默认拒绝）。
 */
private function assert_StaticPublicSurface_testAnythingOutsideThePublicSurfaceIsDenied(): void
{
    $denied = ['', 'app/etc/env.php', 'vendor/composer/autoload_real.php', 'static/../app/etc/env.php', 'static//../app/etc/env.php', 's/20260914/../app/etc/env.php', 'some/unknown/root/a.css', 'static/', 'static'];
    foreach ($denied as $path) {
        self::assertFalse(StaticPublicSurface::isServableUrlPath($path), '路径 ' . ($path === '' ? '(空)' : $path) . ' 必须被拒绝');
    }
}

// ---------------------------------------------------------------- 不变式 4
/**
 * nginx 样例里的每一份白名单正则必须等于 PHP 侧生成的结果。
 */
private function assert_StaticPublicSurface_testNginxSampleAllowlistMatchesTheAuthoritativePattern(): void
{
    $file = $this->repoRoot_StaticPublicSurface . '/nginx.static-surface.conf';
    self::assertFileExists($file);
    $patterns = $this->extractNginxAllowlistPatterns_StaticPublicSurface((string) file_get_contents($file));
    self::assertGreaterThanOrEqual(2, count($patterns), 'nginx 样例应含 /static/ 与 /s/ 两份白名单');
    foreach ($patterns as $pattern) {
        self::assertSame(StaticPublicSurface::servableExtensionPattern(), $pattern, 'nginx 白名单与 PHP 侧权威集合不一致，请重新生成');
    }
}

/**
 * WLS 两个 worker 的快路径白名单必须等于权威快路径集合（两份拷贝不得漂移）。
 */
private function assert_StaticPublicSurface_testWlsWorkerFastPathListsMatchTheAuthoritativeSet(): void
{
    foreach (['worker.php', 'worker_ssl.php'] as $name) {
        $file = $this->repoRoot_StaticPublicSurface . '/app/code/Weline/Server/bin/' . $name;
        self::assertFileExists($file);
        $found = $this->extractWorkerStaticExtensions_StaticPublicSurface((string) file_get_contents($file));
        self::assertNotSame([], $found, $name . ' 未找到 $staticExtensions');
        self::assertSame($this->sorted_StaticPublicSurface(StaticPublicSurface::FAST_PATH_EXTENSIONS), $this->sorted_StaticPublicSurface($found), $name . ' 的快路径白名单与权威集合不一致');
    }
}

/**
 * 框架回退层必须真的执行口径（否则「传输层不接管」= 放行）。
 */
private function assert_StaticPublicSurface_testFrameworkFallbackActuallyEnforcesThePolicy(): void
{
    $source = (string) file_get_contents($this->repoRoot_StaticPublicSurface . '/app/code/Weline/Framework/Router/Core.php');
    self::assertStringContainsString('StaticPublicSurface::isServableFile', $source, 'Router\Core::StaticFile() 必须执行静态公共面口径，否则纯 WLS 下会泄露源码');
}

// ---------------------------------------------------------------- 辅助
/**
 * 抽取 nginx 样例中**生效**（非注释）的 `if ($uri !~* "...")` 白名单正则。
 *
 * @return list<string>
 */
private function extractNginxAllowlistPatterns_StaticPublicSurface(string $conf): array
{
    $patterns = [];
    foreach (explode("\n", $conf) as $line) {
        $trimmed = ltrim($line);
        if ($trimmed === '' || str_starts_with($trimmed, '#')) {
            continue;
        }
        if (preg_match('/if\s*\(\s*\$uri\s*!~\*\s*"\\\\\\.\(\?:(?P<ext>[^"]+)\)~\$?"\s*\)/i', $trimmed, $m) !== 1 && preg_match('/if\s*\(\s*\$uri\s*!~\*\s*"\\\\\\.\(\?:(?P<ext>[^"]+)\)\$/i', $trimmed, $m) !== 1) {
            continue;
        }
        $patterns[] = $m['ext'];
    }
    return $patterns;
}

/**
 * 抽取 worker 里 `static $staticExtensions = [ ... ];` 的扩展名集合。
 *
 * @return list<string>
 */
private function extractWorkerStaticExtensions_StaticPublicSurface(string $source): array
{
    if (preg_match('/static\s+\$staticExtensions\s*=\s*\[(?P<body>.*?)\]\s*;/s', $source, $m) !== 1) {
        return [];
    }
    $extensions = [];
    if (preg_match_all("/'([a-z0-9]+)'/i", $m['body'], $matches) > 0) {
        $extensions = $matches[1];
    }
    return array_values(array_map('strval', $extensions));
}

/**
 * @param list<string> $values
 * @return list<string>
 */
private function sorted_StaticPublicSurface(array $values): array
{
    $values = array_values($values);
    sort($values);
    return $values;
}
}

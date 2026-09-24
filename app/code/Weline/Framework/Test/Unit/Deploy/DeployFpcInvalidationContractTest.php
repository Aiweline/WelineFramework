<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Deploy;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Weline\Framework\Deploy\DeployFpcInvalidation;
use Weline\Framework\Extends\Module\Weline_Framework\Changed\Type\DeployStaticChangedType;
use Weline\Framework\Event\Changed\InvalidationEffect;

/**
 * UT 护栏：DeployFpcInvalidation / Mode\Set / Upgrade / ChangedType 契约（C-HELPER/C-PURGE/C-BUMP/C-NS）。
 */
final class DeployFpcInvalidationContractTest extends TestCase
{
    public function testDeployNamespaceConstantMatchesCanonicalPath(): void
    {
        self::assertSame('global/storefront/deploy', DeployFpcInvalidation::NS_STOREFRONT_DEPLOY);
    }

    public function testChangedTypeRecipePurgesOnlyWhenFlagged(): void
    {
        $type = new DeployStaticChangedType();
        self::assertSame('deploy_static', $type->code());

        $bumpOnly = $this->fakeChange(['purge_fpc_all' => false]);
        $codesBump = array_map(static fn(InvalidationEffect $e): string => $e->code, $type->recipe($bumpOnly));
        self::assertContains(InvalidationEffect::CODE_BUMP_NAMESPACES, $codesBump);
        self::assertNotContains(InvalidationEffect::CODE_PURGE_FPC_ALL, $codesBump);

        $withPurge = $this->fakeChange(['purge_fpc_all' => true, 'reason' => 'deploy_mode_set_prod']);
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

    public function testModeSetProdWiresHelperAndSkipsUpgradeInvalidation(): void
    {
        $src = (string)file_get_contents(
            BP . 'app/code/Weline/Framework/Console/Console/Deploy/Mode/Set.php'
        );
        self::assertStringContainsString('DeployFpcInvalidation', $src);
        self::assertStringContainsString('afterModeSetProd', $src);
        self::assertStringContainsString('DATA_SKIP_INVALIDATION', $src);
        self::assertStringContainsString('prod_after', $src);
    }

    public function testUpgradeCallsHelperUnlessSkipped(): void
    {
        $src = (string)file_get_contents(
            BP . 'app/code/Weline/Framework/Console/Console/Deploy/Upgrade.php'
        );
        self::assertStringContainsString('DeployFpcInvalidation', $src);
        self::assertStringContainsString('afterUpgrade', $src);
        self::assertStringContainsString('DATA_SKIP_INVALIDATION', $src);
        self::assertStringContainsString('treeChanged', $src);
        self::assertStringContainsString('publishModuleFlatStatics', $src);
    }

    public function testEmptyUpgradeSkipsInvalidationSemantics(): void
    {
        $helper = (new ReflectionClass(DeployFpcInvalidation::class))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod(DeployFpcInvalidation::class, 'afterUpgrade');
        // afterUpgrade 需要 ObjectManager for publish；空跑分支在 publish 前 return
        $result = $method->invoke($helper, false);
        self::assertTrue($result['skipped']);
        self::assertFalse($result['invalidated']);
        self::assertFalse($result['purged']);
    }

    public function testResolverSourceIncludesDeployNamespace(): void
    {
        $src = (string)file_get_contents(
            BP . 'app/code/Weline/Framework/Cache/StorefrontCacheKeyContextResolver.php'
        );
        self::assertStringContainsString("global('storefront', ['deploy'])", $src);
    }

    /**
     * @param array<string,mixed> $after
     */
    private function fakeChange(array $after): \Weline\Framework\Event\ResourceChange\ResourceChange
    {
        return \Weline\Framework\Event\ResourceChange\ResourceChange::fromArray([
            'schema_version' => 1,
            'event_id' => str_repeat('a', 32),
            'event_name' => \Weline\Framework\Event\ResourceChange\ResourceChange::EVENT_NAME,
            'occurred_at' => '2026-09-24T00:00:00.000000Z',
            'resource' => [
                'type' => 'deploy_static',
                'id' => 'storefront:test',
                'action' => 'publish',
                'revision' => 1,
            ],
            'website' => [
                'id' => 0,
                'code' => 'default',
                'previous_code' => null,
                'site_id' => 0,
            ],
            'impact' => [
                'namespaces' => [DeployFpcInvalidation::NS_STOREFRONT_DEPLOY],
                'previous_namespaces' => [],
                'urls' => [],
                'previous_urls' => [],
            ],
            'changed_fields' => ['deploy_version'],
            'before' => ['trigger' => 'test'],
            'after' => $after,
            'origin' => [
                'area' => 'cli',
                'entry' => 'test',
                'request_id' => '',
                'instance' => '',
                'trigger_by' => ['type' => 'system', 'id' => null],
            ],
            'context' => [
                'website_id' => 0,
                'website_code' => 'default',
                'lang' => 'zh_Hans_CN',
                'currency' => 'CNY',
                'area' => 'cli',
                'timezone' => 'UTC',
                'user' => ['type' => 'system', 'id' => null],
            ],
        ]);
    }
}

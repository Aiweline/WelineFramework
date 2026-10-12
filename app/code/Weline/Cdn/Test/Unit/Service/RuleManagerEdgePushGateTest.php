<?php

declare(strict_types=1);

namespace Weline\Cdn\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Cdn\Model\ApiRule;
use Weline\Cdn\Model\Domain;
use Weline\Cdn\Service\AccountManager;
use Weline\Cdn\Service\AdapterResolver;
use Weline\Cdn\Service\AnnotationPackFlagResolver;
use Weline\Cdn\Service\AnnotationRulePacker;
use Weline\Cdn\Service\RuleManager;
use Weline\Framework\Manager\ObjectManager; // used only as mock type

/**
 * Cache Rules 推送闸门：pack 开/关 × Free/付费；注解/覆盖须前置 defaults。
 */
final class RuleManagerEdgePushGateTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (!\defined('BP')) {
            \define('BP', \dirname(__DIR__, 7) . \DIRECTORY_SEPARATOR);
        }
    }

    public function testPackOffFreePlanDefaultsOnly(): void
    {
        $manager = $this->newManager(packEnabled: false);
        $domain = $this->freeDomainWithOverride();

        self::assertFalse($manager->annotationsAllowedOnEdge($domain));
        $planned = $manager->planRulesForEdgePush($domain);
        self::assertNotEmpty($planned);
        self::assertSame($manager->getDefaultRules(), $planned);
        foreach ($planned as $rule) {
            self::assertIsArray($rule);
            self::assertStringNotContainsString(
                'matches',
                \strtolower((string)($rule['expression'] ?? '')),
            );
        }
    }

    public function testPackOnFreeAllowsAnnotationsGate(): void
    {
        $packer = new AnnotationRulePacker();
        $manager = $this->newManager(packEnabled: true, packer: $packer);
        $domain = $this->freeDomainWithOverride();

        self::assertTrue($manager->annotationsAllowedOnEdge($domain));
        // ObjectManager mock 无 ApiRule → packs 空；overrides 应进入预算
        $planned = $manager->planRulesForEdgePush($domain);
        self::assertNotEmpty($planned);
        $overrideExpr = 'starts_with(http.request.uri.path, "/custom/")';
        $foundOverride = false;
        foreach ($planned as $rule) {
            if (\is_array($rule) && ($rule['expression'] ?? '') === $overrideExpr) {
                $foundOverride = true;
            }
        }
        self::assertTrue($foundOverride, 'pack-on Free plan should include domain overrides in budget');
        self::assertSame(
            $manager->getDefaultRules(),
            \array_slice($planned, -\count($manager->getDefaultRules())),
            'defaults must remain at the end of planned rules'
        );
    }

    /**
     * A3：Free + pack 开 → plan 含 pack identity（≤3），无逐条 Demo description。
     * ObjectManager::getInstance 为静态，故以子类覆盖 getEnabledApiRuleModels 注入模型。
     */
    public function testPackOnFreePlanContainsPackIdentity(): void
    {
        $apiRule = $this->createMock(ApiRule::class);
        $apiRule->method('getData')->willReturnCallback(
            static fn(string $key) => match ($key) {
                ApiRule::schema_fields_ENABLED => 1,
                ApiRule::schema_fields_MODULE => 'Weline_Cdn',
                ApiRule::schema_fields_CLASS => 'Weline\\Cdn\\Controller\\Api\\DemoTtl',
                ApiRule::schema_fields_METHOD => 'index',
                ApiRule::schema_fields_ROUTE => '/api/cdn/demo-ttl-api',
                ApiRule::schema_fields_EXPRESSION => 'starts_with(http.request.uri.path, "/api/__weline_cdn_demo_ttl_api")',
                ApiRule::schema_fields_DESCRIPTION => 'WELINE_DEMO_TTL_777_API',
                default => null,
            }
        );
        $apiRule->method('getActionArray')->willReturn([
            'cache' => ['ttl' => 777, 'status_code' => [200], 'mode' => 'override_origin'],
        ]);

        $manager = $this->newManagerWithApiRules(packEnabled: true, apiRuleItems: [$apiRule]);
        $domain = $this->freeDomainWithOverride();

        self::assertTrue($manager->annotationsAllowedOnEdge($domain));
        $planned = $manager->planRulesForEdgePush($domain, null, true);

        $packIdentities = [];
        $hasDemoDescription = false;
        foreach ($planned as $rule) {
            if (!\is_array($rule)) {
                continue;
            }
            $identity = (string)($rule['_weline_identity'] ?? '');
            $desc = (string)($rule['description'] ?? '');
            if (\str_starts_with($identity, 'pack:')) {
                $packIdentities[] = $identity;
                self::assertStringContainsString('__weline_cdn_demo_ttl_api', (string)($rule['expression'] ?? ''));
                self::assertStringStartsWith('Weline pack:', $desc);
            }
            if (\str_contains($desc, 'WELINE_DEMO_TTL_777_API')) {
                $hasDemoDescription = true;
            }
        }

        self::assertNotEmpty($packIdentities, 'Free+pack plan must contain pack:{surface}:{ordinal}');
        self::assertLessThanOrEqual(3, \count($packIdentities));
        self::assertFalse($hasDemoDescription, 'Free+pack plan must not keep per-rule demo descriptions');

        $defaultCount = \count($manager->getDefaultRules());
        $tail = \array_slice($planned, -$defaultCount);
        $tailSansIdentity = \array_map(
            static function (array $rule): array {
                $copy = $rule;
                unset($copy['_weline_identity']);

                return $copy;
            },
            $tail
        );
        self::assertSame(
            $manager->getDefaultRules(),
            $tailSansIdentity,
            'defaults must remain at the end (identity may be added when includeIdentity=true)'
        );
    }

    public function testSanitizeDropsMatchesExpressions(): void
    {
        $manager = $this->newManager(packEnabled: false);
        self::assertNull($manager->sanitizeRuleExpressionForEdge([
            'expression' => 'http.request.uri.path matches "^/x"',
        ]));
        $ok = $manager->sanitizeRuleExpressionForEdge([
            'expression' => 'starts_with(http.request.uri.path, "/x")',
            'action' => ['cache' => false],
        ]);
        self::assertIsArray($ok);
        self::assertStringContainsString('starts_with', (string)$ok['expression']);
    }

    public function testPaidCapacityAllowsAnnotationBudgetFlag(): void
    {
        $manager = $this->newManager(packEnabled: false);
        $domain = $this->createMock(Domain::class);
        $domain->method('getCredentialsArray')->willReturn(['cache_rules_capacity' => 20]);
        $domain->method('getData')->willReturn('cloudflare');
        $domain->method('isInheritDefault')->willReturn(false);

        self::assertTrue($manager->annotationsAllowedOnEdge($domain));
        self::assertSame(20, $manager->getCacheRulesCapacity($domain));
    }

    public function testPaidPlanPlacesOverridesBeforeDefaultBridge(): void
    {
        $overrideExpr = 'starts_with(http.request.uri.path, "/api/rest/v1/product/")';
        // pack 关 + capacity>10 → 细推；ObjectManager mock 下 getApiRules 空，仍应含 override
        $manager = $this->newManager(packEnabled: false);
        $domain = $this->createMock(Domain::class);
        $domain->method('getCredentialsArray')->willReturn(['cache_rules_capacity' => 100]);
        $domain->method('getRulesOverrideArray')->willReturn([
            [
                'expression' => $overrideExpr,
                'action' => ['cache' => ['ttl' => 900, 'mode' => 'override_origin']],
            ],
        ]);
        $domain->method('getId')->willReturn(7);
        $domain->method('getData')->willReturnCallback(
            static fn(string $key) => match ($key) {
                Domain::schema_fields_ADAPTER => 'cloudflare',
                Domain::schema_fields_ZONE_ID => 'zone-1',
                Domain::schema_fields_ACCOUNT_ID => null,
                default => null,
            }
        );
        $domain->method('isInheritDefault')->willReturn(false);

        self::assertTrue($manager->annotationsAllowedOnEdge($domain));
        $planned = $manager->planRulesForEdgePush($domain);
        self::assertNotEmpty($planned);

        $overrideIndex = null;
        $bridgeIndex = null;
        foreach ($planned as $i => $rule) {
            if (!\is_array($rule)) {
                continue;
            }
            $expr = (string)($rule['expression'] ?? '');
            if ($expr === $overrideExpr) {
                $overrideIndex = $i;
            }
            $mode = $rule['action']['cache']['mode'] ?? null;
            if ($mode === 'bypass_by_default') {
                $bridgeIndex = $i;
            }
        }
        self::assertNotNull($overrideIndex, 'paid plan must include domain override in planned rules');
        self::assertNotNull($bridgeIndex, 'defaults bridge must remain in planned rules');
        self::assertLessThan(
            $bridgeIndex,
            $overrideIndex,
            'CF first-match: override/api rules must appear before bypass_by_default defaults'
        );
    }

    private function freeDomainWithOverride(): Domain
    {
        $domain = $this->createMock(Domain::class);
        $domain->method('getCredentialsArray')->willReturn([]);
        $domain->method('getRulesOverrideArray')->willReturn([
            [
                'expression' => 'starts_with(http.request.uri.path, "/custom/")',
                'action' => ['cache' => false],
            ],
        ]);
        $domain->method('getId')->willReturn(1);
        $domain->method('getData')->willReturnCallback(
            static fn(string $key) => match ($key) {
                Domain::schema_fields_ADAPTER => 'cloudflare',
                Domain::schema_fields_ZONE_ID => 'zone-1',
                Domain::schema_fields_ACCOUNT_ID => null,
                default => null,
            }
        );
        $domain->method('isInheritDefault')->willReturn(false);

        return $domain;
    }

    private function flagResolver(bool $enabled): AnnotationPackFlagResolver
    {
        return new class ($enabled) extends AnnotationPackFlagResolver {
            public function __construct(private readonly bool $forced)
            {
                parent::__construct(null);
            }

            public function isEnabled(?\Weline\Framework\Runtime\ScopeIdentity $identity = null): bool
            {
                return $this->forced;
            }
        };
    }

    private function newManager(bool $packEnabled, ?AnnotationRulePacker $packer = null): RuleManager
    {
        $om = $this->createMock(ObjectManager::class);
        $om->method('getInstance')->willReturn(null);
        $resolver = $this->createMock(AdapterResolver::class);
        $accounts = $this->createMock(AccountManager::class);

        return new RuleManager(
            $om,
            $resolver,
            $accounts,
            $this->flagResolver($packEnabled),
            $packer ?? new AnnotationRulePacker()
        );
    }

    /**
     * @param list<ApiRule> $apiRuleItems
     */
    private function newManagerWithApiRules(bool $packEnabled, array $apiRuleItems): RuleManager
    {
        $om = $this->createMock(ObjectManager::class);
        $om->method('getInstance')->willReturn(null);
        $resolver = $this->createMock(AdapterResolver::class);
        $accounts = $this->createMock(AccountManager::class);
        $flag = $this->flagResolver($packEnabled);
        $packer = new AnnotationRulePacker();

        return new class ($om, $resolver, $accounts, $flag, $packer, $apiRuleItems) extends RuleManager {
            /** @param list<ApiRule> $injectedApiRules */
            public function __construct(
                ObjectManager $objectManager,
                AdapterResolver $adapterResolver,
                AccountManager $accountManager,
                AnnotationPackFlagResolver $annotationPackFlagResolver,
                AnnotationRulePacker $annotationRulePacker,
                private readonly array $injectedApiRules,
            ) {
                parent::__construct(
                    $objectManager,
                    $adapterResolver,
                    $accountManager,
                    $annotationPackFlagResolver,
                    $annotationRulePacker
                );
            }

            protected function getEnabledApiRuleModels(?string $triggerType = null): array
            {
                return $this->injectedApiRules;
            }
        };
    }
}

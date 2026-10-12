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
use Weline\Cdn\Service\BinQueryFidelityEdgeCompressor;
use Weline\Cdn\Service\CdnRuleLane;
use Weline\Cdn\Service\RuleManager;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Service\Query\BinQueryCachePolicy;

/**
 * 保真车道：pack 不得裸 starts_with(/bin/query)；fidelity 边须 POST+marker；Free 边缘不加 custom cache_key。
 */
final class BinQueryFidelityEdgePlanContractTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (!\defined('BP')) {
            \define('BP', \dirname(__DIR__, 7) . \DIRECTORY_SEPARATOR);
        }
    }

    public function testPackerSkipsBinQueryFidelityRules(): void
    {
        $packer = new AnnotationRulePacker();
        $packs = $packer->pack([
            [
                'enabled' => true,
                'module' => 'Weline_I18n',
                'class' => 'Weline\\Frontend\\Controller\\Api\\BinQuery::i18n',
                'method' => 'getLanguageSwitcherCatalog',
                'route' => '/bin/query',
                'lane' => CdnRuleLane::PREDICATE_FIDELITY,
                'expression' => 'http.request.uri.path eq "/bin/query" and http.request.method eq "POST"'
                    . ' and http.request.uri.query contains "' . BinQueryCachePolicy::MARKER_PARAM . '=wq1.frontend.i18n.op."',
                'action' => [
                    'cache' => [
                        'ttl' => 3600,
                        'status_code' => [200],
                        'mode' => 'override_origin',
                        'cache_key' => [
                            'custom_key' => [
                                'query_string' => ['include' => [BinQueryCachePolicy::MARKER_PARAM]],
                            ],
                        ],
                    ],
                ],
            ],
            [
                'enabled' => true,
                'module' => 'Weline_Cdn',
                'class' => 'Weline\\Cdn\\Controller\\Api\\FixtureSpecialTtl',
                'method' => 'index',
                'route' => '/api/__fixture_special_ttl_api',
                'lane' => CdnRuleLane::PATH_PACKABLE,
                'expression' => 'starts_with(http.request.uri.path, "/api/__fixture_special_ttl_api")',
                'action' => ['cache' => ['ttl' => 900, 'status_code' => [200], 'mode' => 'override_origin']],
            ],
        ], 5);

        self::assertCount(1, $packs);
        $expr = (string)($packs[0]['expression'] ?? '');
        self::assertStringContainsString('__fixture_special_ttl_api', $expr);
        self::assertStringNotContainsString('/bin/query', $expr);
        self::assertStringNotContainsString('query-bin', $expr);
    }

    public function testCompressorEmitsPostMarkerAndCacheKey(): void
    {
        $compressor = new BinQueryFidelityEdgeCompressor();
        $edge = $compressor->compress([
            [
                'enabled' => true,
                'lane' => CdnRuleLane::PREDICATE_FIDELITY,
                'expression' => 'http.request.uri.path eq "/bin/query" and http.request.method eq "POST"'
                    . ' and http.request.uri.query contains "' . BinQueryCachePolicy::MARKER_PARAM . '=wq1.frontend.a.op."',
                'action' => ['cache' => ['ttl' => 100, 'status_code' => [200], 'mode' => 'override_origin']],
            ],
            [
                'enabled' => true,
                'lane' => CdnRuleLane::PREDICATE_FIDELITY,
                'expression' => 'http.request.uri.path eq "/api/framework/query-bin" and http.request.method eq "POST"'
                    . ' and http.request.uri.query contains "' . BinQueryCachePolicy::MARKER_PARAM . '=wq1.frontend.a.op."',
                'action' => ['cache' => ['ttl' => 300, 'status_code' => [200], 'mode' => 'override_origin']],
            ],
        ]);

        self::assertCount(1, $edge);
        $rule = $edge[0];
        self::assertSame('fidelity:binquery:0', $rule['_weline_identity']);
        $expr = (string)$rule['expression'];
        self::assertStringContainsString('http.request.method eq "POST"', $expr);
        self::assertStringContainsString(
            'http.request.uri.query contains "' . BinQueryCachePolicy::MARKER_PARAM . '="',
            $expr
        );
        self::assertStringContainsString('http.request.uri.path eq "/bin/query"', $expr);
        self::assertStringContainsString('http.request.uri.path eq "/api/framework/query-bin"', $expr);
        self::assertStringNotContainsString('starts_with', $expr);
        self::assertSame(200, (int)$rule['action']['cache']['ttl']);
        // Free Cache Rules 拒收 custom cache_key；planned 边缘仅靠 expression marker。
        self::assertArrayNotHasKey('cache_key', $rule['action']['cache']);
    }

    public function testPlanSeparatesFidelityFromDemoPack(): void
    {
        $binQuery = $this->mockApiRule([
            ApiRule::schema_fields_ENABLED => 1,
            ApiRule::schema_fields_MODULE => 'Weline_I18n',
            ApiRule::schema_fields_CLASS => 'Weline\\Frontend\\Controller\\Api\\BinQuery::i18n',
            ApiRule::schema_fields_METHOD => 'getLanguageSwitcherCatalog',
            ApiRule::schema_fields_ROUTE => '/bin/query',
            ApiRule::schema_fields_LANE => CdnRuleLane::PREDICATE_FIDELITY,
            ApiRule::schema_fields_EXPRESSION => 'http.request.uri.path eq "/bin/query" and http.request.method eq "POST"'
                . ' and http.request.uri.query contains "' . BinQueryCachePolicy::MARKER_PARAM . '=wq1.frontend.i18n.op."',
            ApiRule::schema_fields_DESCRIPTION => 'BinQuery i18n [/bin/query]',
        ], [
            'cache' => [
                'ttl' => 3600,
                'status_code' => [200],
                'mode' => 'override_origin',
                'cache_key' => [
                    'custom_key' => [
                        'query_string' => ['include' => [BinQueryCachePolicy::MARKER_PARAM]],
                    ],
                ],
            ],
        ]);
        $specialTtl = $this->mockApiRule([
            ApiRule::schema_fields_ENABLED => 1,
            ApiRule::schema_fields_MODULE => 'Weline_Cdn',
            ApiRule::schema_fields_CLASS => 'Weline\\Cdn\\Controller\\Api\\FixtureSpecialTtl',
            ApiRule::schema_fields_METHOD => 'index',
            ApiRule::schema_fields_ROUTE => '/api/__fixture_special_ttl_api',
            ApiRule::schema_fields_LANE => CdnRuleLane::PATH_PACKABLE,
            ApiRule::schema_fields_EXPRESSION => 'starts_with(http.request.uri.path, "/api/__fixture_special_ttl_api")',
            ApiRule::schema_fields_DESCRIPTION => 'FIXTURE_SPECIAL_TTL_API',
        ], [
            'cache' => ['ttl' => 900, 'status_code' => [200], 'mode' => 'override_origin'],
        ]);

        $manager = $this->newManagerWithApiRules([$binQuery, $specialTtl]);
        $domain = $this->freeDomain();
        $planned = $manager->planRulesForEdgePush($domain, null, true);

        $fidelityExprs = [];
        $packExprs = [];
        foreach ($planned as $rule) {
            if (!\is_array($rule)) {
                continue;
            }
            $identity = (string)($rule['_weline_identity'] ?? '');
            $expr = (string)($rule['expression'] ?? '');
            if (\str_starts_with($identity, 'fidelity:')) {
                $fidelityExprs[] = $expr;
                self::assertStringContainsString('POST', $expr);
                self::assertStringContainsString(BinQueryCachePolicy::MARKER_PARAM . '=', $expr);
                self::assertStringNotContainsString('starts_with(http.request.uri.path, "/bin/query")', $expr);
            }
            if (\str_starts_with($identity, 'pack:')) {
                $packExprs[] = $expr;
                self::assertStringNotContainsString('/bin/query', $expr);
                self::assertStringNotContainsString('query-bin', $expr);
            }
            // No planned rule may path-OR bin/query with starts_with
            if (\str_contains($expr, 'starts_with(http.request.uri.path, "/bin/query")')) {
                self::fail('planned must not emit bare starts_with(/bin/query)');
            }
            if (\str_contains($expr, 'starts_with(http.request.uri.path, "/api/framework/query-bin")')) {
                self::fail('planned must not emit bare starts_with(/api/framework/query-bin)');
            }
        }

        self::assertNotEmpty($fidelityExprs, 'plan must include fidelity:binquery edge');
        self::assertNotEmpty($packExprs, 'plan must still pack special-TTL path annotation');
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed> $action
     */
    private function mockApiRule(array $data, array $action): ApiRule
    {
        $apiRule = $this->createMock(ApiRule::class);
        $apiRule->method('getData')->willReturnCallback(
            static fn(string $key) => $data[$key] ?? null
        );
        $apiRule->method('getActionArray')->willReturn($action);

        return $apiRule;
    }

    private function freeDomain(): Domain
    {
        $domain = $this->createMock(Domain::class);
        $domain->method('getCredentialsArray')->willReturn([]);
        $domain->method('getRulesOverrideArray')->willReturn([]);
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

    /**
     * @param list<ApiRule> $apiRuleItems
     */
    private function newManagerWithApiRules(array $apiRuleItems): RuleManager
    {
        $om = $this->createMock(ObjectManager::class);
        $om->method('getInstance')->willReturn(null);
        $resolver = $this->createMock(AdapterResolver::class);
        $accounts = $this->createMock(AccountManager::class);
        $flag = new class extends AnnotationPackFlagResolver {
            public function __construct()
            {
                parent::__construct(null);
            }

            public function isEnabled(?\Weline\Framework\Runtime\ScopeIdentity $identity = null): bool
            {
                return true;
            }
        };

        return new class ($om, $resolver, $accounts, $flag, $apiRuleItems) extends RuleManager {
            /** @param list<ApiRule> $injectedApiRules */
            public function __construct(
                ObjectManager $objectManager,
                AdapterResolver $adapterResolver,
                AccountManager $accountManager,
                AnnotationPackFlagResolver $annotationPackFlagResolver,
                private readonly array $injectedApiRules,
            ) {
                parent::__construct(
                    $objectManager,
                    $adapterResolver,
                    $accountManager,
                    $annotationPackFlagResolver,
                    new AnnotationRulePacker(),
                    new BinQueryFidelityEdgeCompressor()
                );
            }

            protected function getEnabledApiRuleModels(?string $triggerType = null): array
            {
                return $this->injectedApiRules;
            }
        };
    }
}

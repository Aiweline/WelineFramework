<?php

declare(strict_types=1);

namespace Weline\Cdn\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Cdn\Model\Domain;
use Weline\Cdn\Service\AccountManager;
use Weline\Cdn\Service\AdapterResolver;
use Weline\Cdn\Service\RuleManager;
use Weline\Framework\Manager\ObjectManager;

/**
 * Free Cache Rules 推送闸门：默认仅 defaults，不含 matches 注解。
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

    public function testPlanRulesForEdgePushDefaultsOnlyWhenCapacityIsFree(): void
    {
        $manager = $this->newManager();
        $domain = $this->createMock(Domain::class);
        $domain->method('getCredentialsArray')->willReturn([]);
        $domain->method('getRulesOverrideArray')->willReturn([
            [
                'expression' => 'starts_with(http.request.uri.path, "/custom/")',
                'action' => ['cache' => false],
            ],
        ]);
        $domain->method('getData')->willReturnCallback(
            static fn(string $key) => match ($key) {
                Domain::schema_fields_ADAPTER => 'cloudflare',
                Domain::schema_fields_ZONE_ID => 'zone-1',
                Domain::schema_fields_ACCOUNT_ID => null,
                default => null,
            }
        );
        $domain->method('isInheritDefault')->willReturn(false);

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

    public function testSanitizeDropsMatchesExpressions(): void
    {
        $manager = $this->newManager();
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
        $manager = $this->newManager();
        $domain = $this->createMock(Domain::class);
        $domain->method('getCredentialsArray')->willReturn(['cache_rules_capacity' => 20]);
        $domain->method('getData')->willReturn('cloudflare');
        $domain->method('isInheritDefault')->willReturn(false);

        self::assertTrue($manager->annotationsAllowedOnEdge($domain));
        self::assertSame(20, $manager->getCacheRulesCapacity($domain));
    }

    private function newManager(): RuleManager
    {
        $om = $this->createMock(ObjectManager::class);
        $resolver = $this->createMock(AdapterResolver::class);
        $accounts = $this->createMock(AccountManager::class);

        return new RuleManager($om, $resolver, $accounts);
    }
}

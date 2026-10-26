<?php

declare(strict_types=1);

namespace Weline\Cdn\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Cdn\Model\Domain;
use Weline\Cdn\Service\AccountManager;
use Weline\Cdn\Service\AdapterResolver;
use Weline\Cdn\Service\AnnotationPackFlagResolver;
use Weline\Cdn\Service\AnnotationRulePacker;
use Weline\Cdn\Service\RuleManager;
use Weline\Framework\Http\Fpc\FpcPierceStamp;
use Weline\Framework\Manager\ObjectManager;

/**
 * 穿章 Bypass 规则：按当前 secret 现算、永远排第一、不落库。
 */
final class RuleManagerPierceRuleTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (!\defined('BP')) {
            \define('BP', \dirname(__DIR__, 7) . \DIRECTORY_SEPARATOR);
        }
    }

    public function testPierceRuleFollowsCurrentSecret(): void
    {
        $secret = FpcPierceStamp::readSecret();
        $rules = $this->manager()->getPierceBypassRules();

        if ($secret === '') {
            self::assertSame([], $rules, '无穿章 secret 时不得凭空造规则');

            return;
        }

        self::assertCount(1, $rules);
        $rule = $rules[0];
        self::assertSame('weline_cdn_fpc_pierce_bypass', $rule['ref']);
        self::assertSame('set_cache_settings', $rule['action']);
        self::assertSame(['cache' => false], $rule['action_parameters']);
        self::assertTrue($rule['enabled']);
        self::assertStringContainsString('w_fpc_pierce=' . $secret, (string)$rule['expression']);
    }

    public function testPierceRuleLeadsEdgePlanAndInventory(): void
    {
        $manager = $this->manager();
        $pierce = $manager->getPierceBypassRules();
        $domain = $this->domain();

        $planned = $manager->planRulesForEdgePush($domain);
        self::assertSame(
            $pierce,
            \array_slice($planned, 0, \count($pierce)),
            '穿章 Bypass 必须在计划最前（CF 先匹配先生效）'
        );

        $inventory = $manager->getMergedRules($domain);
        self::assertSame($pierce, \array_slice($inventory, 0, \count($pierce)));
    }

    public function testSecretIsNotPersistedInDefaultRules(): void
    {
        $file = BP . 'app/code/Weline/Cdn/etc/default-rules.json';
        self::assertFileExists($file);
        self::assertStringNotContainsString(
            'w_fpc_pierce=',
            (string)\file_get_contents($file),
            '穿章 secret 禁止落进 default-rules.json（轮换会失真）'
        );
    }

    private function manager(): RuleManager
    {
        $om = $this->createMock(ObjectManager::class);
        $om->method('getInstance')->willReturn(null);
        $flag = new class (false) extends AnnotationPackFlagResolver {
            public function __construct(private readonly bool $forced)
            {
                parent::__construct(null);
            }

            public function isEnabled(?\Weline\Framework\Runtime\ScopeIdentity $identity = null): bool
            {
                return $this->forced;
            }
        };

        return new RuleManager(
            $om,
            $this->createMock(AdapterResolver::class),
            $this->createMock(AccountManager::class),
            $flag,
            new AnnotationRulePacker()
        );
    }

    private function domain(): Domain
    {
        $domain = $this->createMock(Domain::class);
        $domain->method('getCredentialsArray')->willReturn([]);
        $domain->method('getRulesOverrideArray')->willReturn([]);
        $domain->method('getData')->willReturn('cloudflare');
        $domain->method('getId')->willReturn(1);
        $domain->method('isInheritDefault')->willReturn(false);

        return $domain;
    }
}

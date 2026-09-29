<?php

declare(strict_types=1);

namespace Weline\Cdn\Test\Unit\Service;

use PHPUnit\Framework\TestCase;

/**
 * 后台「全局默认规则」必须读/写 RuleManager::getDefaultRules / saveDefaultRules，
 * 禁止 method_exists 探测不存在的 getGlobalRules/saveGlobalRules 后静默返回 []。
 */
final class CdnAdminQueryGlobalRulesContractTest extends TestCase
{
    public function testAdminQueryServiceUsesDefaultRulesMethods(): void
    {
        $source = (string)\file_get_contents(
            \dirname(__DIR__, 3) . '/Service/CdnAdminQueryService.php'
        );

        self::assertStringContainsString('function getGlobalRules', $source);
        self::assertStringContainsString('function saveGlobalRules', $source);
        self::assertStringContainsString('$manager->getDefaultRules()', $source);
        self::assertStringContainsString('$manager->saveDefaultRules($rules)', $source);

        self::assertStringNotContainsString(
            "method_exists(\$manager, 'getGlobalRules')",
            $source
        );
        self::assertStringNotContainsString(
            "method_exists(\$manager, 'saveGlobalRules')",
            $source
        );
    }

    public function testDefaultRulesFileIsNonEmptyArray(): void
    {
        $path = \dirname(__DIR__, 3) . '/etc/default-rules.json';
        self::assertFileExists($path);
        $rules = \json_decode((string)\file_get_contents($path), true);
        self::assertIsArray($rules);
        self::assertGreaterThanOrEqual(1, \count($rules));
    }
}

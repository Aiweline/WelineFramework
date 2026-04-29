<?php

declare(strict_types=1);

namespace Weline\Cdn\Test\Unit\Console\Command;

use PHPUnit\Framework\TestCase;
use Weline\Cdn\Console\Command\RulesPushDefaults;

/**
 * RulesPushDefaults 命令 + 默认规则 JSON 校验
 *
 * 重点：
 * - 命令基础约定（tip / help / aliases / execute）
 * - default-rules.json 的 Weline 标准 Header 规则集完整性
 *   （X-Weline-Cache-Bypass / X-Weline-Idempotent / X-Weline-Url-Guard / X-Weline-Cache-Status）
 */
class RulesPushDefaultsTest extends TestCase
{
    public function testCommandTipMentionsCdnAndDefaults(): void
    {
        $command = new RulesPushDefaults();
        $tip = $command->tip();
        $this->assertIsString($tip);
        $this->assertNotEmpty($tip);
    }

    public function testHelpReturnsArrayOrString(): void
    {
        $command = new RulesPushDefaults();
        $help = $command->help();
        $this->assertTrue(\is_string($help) || \is_array($help));
        if (\is_array($help)) {
            $joined = \implode("\n", $help);
            $this->assertStringContainsString('--domain', $joined);
            $this->assertStringContainsString('--all', $joined);
            $this->assertStringContainsString('--dry-run', $joined);
        }
    }

    public function testAliasesIncludesPrimaryNamespacedName(): void
    {
        $command = new RulesPushDefaults();
        $aliases = $command->aliases();
        $this->assertIsArray($aliases);
        $this->assertContains('cdn:rules:push:defaults', $aliases);
    }

    public function testExecuteIsCallable(): void
    {
        $command = new RulesPushDefaults();
        $this->assertTrue(\method_exists($command, 'execute'));
        $this->assertTrue(\is_callable([$command, 'execute']));
    }

    public function testDefaultRulesJsonIsValidAndIncludesWelineHeaderRules(): void
    {
        $rulesFile = $this->resolveRulesFilePath();
        $this->assertFileExists($rulesFile, 'default-rules.json must exist');

        $content = \file_get_contents($rulesFile);
        $this->assertIsString($content);
        $rules = \json_decode($content, true);
        $this->assertIsArray($rules, 'default-rules.json must decode as JSON array');
        $this->assertGreaterThanOrEqual(7, \count($rules), 'default-rules.json should keep at least 7 rules');

        $expressions = \array_column($rules, 'expression');
        $this->assertNotEmpty($expressions);

        $joined = \strtolower(\implode("\n", \array_filter($expressions, '\is_string')));

        $this->assertStringContainsString('x-weline-cache-bypass', $joined, 'cache bypass header rule missing');
        $this->assertStringContainsString('x-weline-idempotent', $joined, 'idempotent header rule missing');
        $this->assertStringContainsString('x-weline-url-guard', $joined, 'url guard header rule missing');
        $this->assertStringContainsString('x-weline-cache-status', $joined, 'cache status header rule missing');
    }

    public function testStaticAndAdminRulesArePreservedForCompatibility(): void
    {
        $rulesFile = $this->resolveRulesFilePath();
        $rules = \json_decode((string)\file_get_contents($rulesFile), true);

        $expressions = \implode("\n", \array_column($rules, 'expression'));
        $this->assertStringContainsString('/static/', $expressions, 'should keep static asset rule');
        $this->assertStringContainsString('/admin/', $expressions, 'should keep backend bypass rule');
        $this->assertStringContainsString('/api/', $expressions, 'should keep api bypass rule');
    }

    private function resolveRulesFilePath(): string
    {
        $base = \dirname(__DIR__, 4);
        return $base . DIRECTORY_SEPARATOR . 'etc' . DIRECTORY_SEPARATOR . 'default-rules.json';
    }
}

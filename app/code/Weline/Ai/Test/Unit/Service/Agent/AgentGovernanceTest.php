<?php

declare(strict_types=1);

namespace Weline\Ai\Test\Unit\Service\Agent;

use PHPUnit\Framework\TestCase;
use Weline\Ai\Service\Agent\AgentExecutionContext;
use Weline\Ai\Service\Agent\AgentGovernance;

final class AgentGovernanceTest extends TestCase
{
    protected function tearDown(): void
    {
        AgentExecutionContext::reset();
        parent::tearDown();
    }

    public function testDisabledOrMissingToolsAreRemovedAndOverrideWins(): void
    {
        $defs = AgentGovernance::applyPolicyToToolDefs(
            [
                ['name' => 'inspect_site_state', 'description' => 'source inspect', 'parameters' => ['type' => 'object']],
                ['name' => 'publish_site', 'description' => 'source publish', 'parameters' => ['type' => 'object']],
                ['name' => 'retry_plan', 'description' => 'source retry', 'parameters' => ['type' => 'object']],
                ['name' => 'unscanned_tool', 'description' => 'keep me', 'parameters' => ['type' => 'object']],
            ],
            [
                'inspect_site_state' => [
                    'enabled' => true,
                    'present' => true,
                    'description_override' => '  custom inspect  ',
                ],
                'publish_site' => [
                    'enabled' => false,
                    'present' => true,
                    'description_override' => null,
                ],
                'retry_plan' => [
                    'enabled' => true,
                    'present' => false,
                    'description_override' => 'should not appear',
                ],
            ],
        );

        self::assertSame(
            [
                [
                    'name' => 'inspect_site_state',
                    'description' => 'custom inspect',
                    'parameters' => ['type' => 'object'],
                ],
                [
                    'name' => 'unscanned_tool',
                    'description' => 'keep me',
                    'parameters' => ['type' => 'object'],
                ],
            ],
            $defs
        );
    }

    public function testEmptyPolicyKeepsAllNormalizedTools(): void
    {
        $defs = AgentGovernance::applyPolicyToToolDefs([
            ['name' => 'a', 'description' => 'A'],
            ['name' => '', 'description' => 'skip'],
        ], []);

        self::assertCount(1, $defs);
        self::assertSame('a', $defs[0]['name']);
        self::assertSame('A', $defs[0]['description']);
        self::assertSame('object', $defs[0]['parameters']['type'] ?? null);
        self::assertInstanceOf(\stdClass::class, $defs[0]['parameters']['properties'] ?? null);
    }

    public function testExecutionContextIsStackBasedAndResettable(): void
    {
        AgentExecutionContext::enter('alpha');
        self::assertSame('alpha', AgentExecutionContext::currentAgentCode());
        AgentExecutionContext::enter('beta');
        self::assertSame('beta', AgentExecutionContext::currentAgentCode());
        AgentExecutionContext::leave();
        self::assertSame('alpha', AgentExecutionContext::currentAgentCode());
        AgentExecutionContext::reset();
        self::assertNull(AgentExecutionContext::currentAgentCode());
    }
}

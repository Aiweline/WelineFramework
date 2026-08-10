<?php

declare(strict_types=1);

namespace Weline\Ai\Test\Unit\Service\Agent;

use PHPUnit\Framework\TestCase;
use Weline\Ai\Api\Runtime\RequestResetter;
use Weline\Ai\Service\Agent\AgentExecutionContext;

final class AgentExecutionContextResetTest extends TestCase
{
    protected function tearDown(): void
    {
        AgentExecutionContext::reset();
        parent::tearDown();
    }

    public function testRequestResetterClearsAgentExecutionContext(): void
    {
        AgentExecutionContext::enter('pagebuilder_ai_site_v2');
        self::assertSame('pagebuilder_ai_site_v2', AgentExecutionContext::currentAgentCode());

        (new RequestResetter())->resetRequest();

        self::assertNull(AgentExecutionContext::currentAgentCode());
    }
}

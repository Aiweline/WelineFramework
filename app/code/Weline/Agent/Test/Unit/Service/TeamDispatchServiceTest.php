<?php

declare(strict_types=1);

namespace Weline\Agent\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Agent\Service\TeamDispatchService;

final class TeamDispatchServiceTest extends TestCase
{
    public function testParseMentionsRecognizesRoleAndAgent(): void
    {
        $service = new TeamDispatchService();
        $mentions = $service->parseMentions('请 @role:seo_assistant 和 @agent:chat_agent 协作处理');

        $this->assertCount(2, $mentions);
        $this->assertSame(['kind' => 'role', 'code' => 'seo_assistant'], $mentions[0]);
        $this->assertSame(['kind' => 'agent', 'code' => 'chat_agent'], $mentions[1]);
    }

    public function testParseMentionsReturnsEmptyWithoutMentions(): void
    {
        $service = new TeamDispatchService();
        $this->assertSame([], $service->parseMentions('普通消息没有派单'));
        $this->assertSame([], $service->parseMentions('@unknown:foo @role:'));
    }

    public function testParseMentionsKeepsOrderAndDuplicates(): void
    {
        $service = new TeamDispatchService();
        $mentions = $service->parseMentions('@role:a @role:b @role:a');
        $this->assertCount(3, $mentions);
        $this->assertSame('a', $mentions[0]['code']);
        $this->assertSame('b', $mentions[1]['code']);
        $this->assertSame('a', $mentions[2]['code']);
    }
}

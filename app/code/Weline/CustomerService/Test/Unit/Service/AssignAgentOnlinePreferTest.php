<?php

declare(strict_types=1);

namespace Weline\CustomerService\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\CustomerService\Service\ChatService;

final class AssignAgentOnlinePreferTest extends TestCase
{
    public function testSortAssignCandidatesPrefersOnlineThenLeastLoad(): void
    {
        $sorted = ChatService::sortAssignCandidates([
            ['agent_id' => 2, 'online' => 0, 'load' => 0],
            ['agent_id' => 1, 'online' => 1, 'load' => 2],
            ['agent_id' => 3, 'online' => 1, 'load' => 0],
        ]);

        $this->assertSame([3, 1, 2], array_column($sorted, 'agent_id'));
    }

    public function testNormalizeAndOnlinePreferSurfacesInServiceAndConsole(): void
    {
        $serviceFile = dirname(__DIR__, 3) . '/Service/ChatService.php';
        $consoleFile = dirname(__DIR__, 3) . '/Controller/Backend/Console.php';
        $jsFile = dirname(__DIR__, 3) . '/view/statics/js/backend-console.js';

        $service = (string)file_get_contents($serviceFile);
        $console = (string)file_get_contents($consoleFile);
        $js = (string)file_get_contents($jsFile);

        $this->assertStringContainsString('sortAssignCandidates', $service);
        $this->assertStringContainsString('isOnline()', $service);
        $this->assertStringContainsString('normalizeSessionRows', $service);
        $this->assertStringContainsString('STATUS_WAITING', $service);
        $this->assertStringContainsString('assignAgent($session)', $service);
        $this->assertStringContainsString('normalizeSessionRows($waitingSessions)', $console);
        $this->assertStringContainsString('sessionRowId', $js);
    }
}

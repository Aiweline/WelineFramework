<?php

declare(strict_types=1);

namespace Agent\CursorBase\Test\Unit\Service;

use Agent\CursorBase\Api\AgentLockInterface;
use Agent\CursorBase\Api\CursorCliInterface;
use Agent\CursorBase\Api\KeyboardSimulatorInterface;
use Agent\CursorBase\Api\SignalFlareInterface;
use Agent\CursorBase\Service\AgentDispatcher;
use PHPUnit\Framework\TestCase;

class AgentDispatcherTest extends TestCase
{
    private string $agentBaseDir;
    private string $workspaceDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->workspaceDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'cursor-base-dispatcher-test-' . uniqid('', true);
        $this->agentBaseDir = $this->workspaceDir . DIRECTORY_SEPARATOR . 'agents' . DIRECTORY_SEPARATOR;
        mkdir($this->agentBaseDir, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->workspaceDir);
        parent::tearDown();
    }

    public function testDispatchReturnsFalseWhenLockAcquireFails(): void
    {
        $lock = $this->createMock(AgentLockInterface::class);
        $lock->expects($this->once())
            ->method('isLocked')
            ->with('Agent_Test_01')
            ->willReturn(false);
        $lock->expects($this->once())
            ->method('lock')
            ->with('Agent_Test_01', 'E:/tmp/Foo.php')
            ->willReturn(false);

        $signalFlare = $this->createMock(SignalFlareInterface::class);
        $signalFlare->expects($this->never())->method('inject');

        $cursorCli = $this->createMock(CursorCliInterface::class);
        $cursorCli->expects($this->never())->method('wake');

        $keyboard = $this->createMock(KeyboardSimulatorInterface::class);
        $keyboard->expects($this->never())->method('triggerCursorExecution');

        $dispatcher = new AgentDispatcher($cursorCli, $signalFlare, $lock, $keyboard, $this->agentBaseDir);
        $ok = $dispatcher->dispatch('Agent_Test_01', ['text' => 'demo'], ['target_file' => 'E:/tmp/Foo.php']);

        $this->assertFalse($ok);
    }

    public function testCheckTaskStatusSkipsBrokenMissionJson(): void
    {
        $agentId = 'Agent_Test_02';
        $agentDir = $this->agentBaseDir . $agentId . DIRECTORY_SEPARATOR;
        mkdir($agentDir, 0777, true);

        file_put_contents($agentDir . 'mission.json', '{broken-json');
        file_put_contents($agentDir . 'status.log', '[2026-01-01 00:00:00] RUNNING');

        $dispatcher = new AgentDispatcher(
            $this->createMock(CursorCliInterface::class),
            $this->createMock(SignalFlareInterface::class),
            $this->createMock(AgentLockInterface::class),
            $this->createMock(KeyboardSimulatorInterface::class),
            $this->agentBaseDir
        );

        $status = $dispatcher->checkTaskStatus($agentId);

        $this->assertTrue($status['exists']);
        $this->assertTrue($status['has_mission']);
        $this->assertNull($status['mission']);
        $this->assertTrue($status['has_status']);
    }

    public function testWriteStatusRepairsBrokenMissionJson(): void
    {
        $agentId = 'Agent_Test_03';
        $agentDir = $this->agentBaseDir . $agentId . DIRECTORY_SEPARATOR;
        mkdir($agentDir, 0777, true);

        file_put_contents($agentDir . 'mission.json', '{"status":');

        $dispatcher = new AgentDispatcher(
            $this->createMock(CursorCliInterface::class),
            $this->createMock(SignalFlareInterface::class),
            $this->createMock(AgentLockInterface::class),
            $this->createMock(KeyboardSimulatorInterface::class),
            $this->agentBaseDir
        );

        $dispatcher->writeStatus($agentId, 'running', 'started');

        $mission = json_decode((string) file_get_contents($agentDir . 'mission.json'), true);
        $statusLog = (string) file_get_contents($agentDir . 'status.log');

        $this->assertIsArray($mission);
        $this->assertSame('running', $mission['status'] ?? null);
        $this->assertArrayHasKey('last_update', $mission);
        $this->assertStringContainsString('running: started', strtolower($statusLog));
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $items = scandir($dir) ?: [];
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $dir . DIRECTORY_SEPARATOR . $item;
            if (is_dir($path)) {
                $this->removeDir($path);
                continue;
            }

            @unlink($path);
        }

        @rmdir($dir);
    }
}

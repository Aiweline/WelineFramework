<?php
declare(strict_types=1);

namespace Weline\Server\Test\Unit\Control;

use PHPUnit\Framework\TestCase;
use Weline\Server\Service\Control\ControlCommandResult;

final class ControlCommandResultTest extends TestCase
{
    public function testNormalizeCompletedCommand(): void
    {
        $result = ControlCommandResult::normalize(
            ['success' => true, 'message' => 'reloaded', 'data' => ['state' => 'completed']],
            'demo',
            'reload',
            'req-1'
        );

        self::assertTrue($result['accepted']);
        self::assertTrue($result['completed']);
        self::assertSame('completed', $result['status']);
        self::assertSame('req-1', $result['request_id']);
        self::assertSame('demo', $result['data']['instance']);
    }

    public function testNormalizeAsyncAcceptedCommandDoesNotPretendCompleted(): void
    {
        $result = ControlCommandResult::normalize(
            ['success' => true, 'message' => 'queued', 'data' => ['accepted' => true, 'async' => true]],
            'demo',
            'restart',
            'req-2',
            true
        );

        self::assertTrue($result['accepted']);
        self::assertFalse($result['completed']);
        self::assertSame('accepted', $result['status']);
    }

    public function testNormalizeTimeoutCommandCarriesErrorState(): void
    {
        $result = ControlCommandResult::normalize(
            ['success' => false, 'message' => 'read timeout', 'timed_out' => true],
            'demo',
            'cache_clear',
            'req-3'
        );

        self::assertFalse($result['accepted']);
        self::assertFalse($result['completed']);
        self::assertTrue($result['timed_out']);
        self::assertSame('timed_out', $result['status']);
        self::assertContains('read timeout', $result['errors']);
    }
}

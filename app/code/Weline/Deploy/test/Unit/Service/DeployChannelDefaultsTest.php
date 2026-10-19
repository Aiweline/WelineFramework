<?php

declare(strict_types=1);

namespace Weline\Deploy\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Deploy\Service\DeployWebhookExpectWatchService;

final class DeployChannelDefaultsTest extends TestCase
{
    public function testPickNewestTagCoalescesToSingleWinner(): void
    {
        $svc = $this->expectWatchStub();
        self::assertSame('v1.2.10', $svc->pickNewestTag(['v1.2.3', 'v1.2.10', 'v1.2.9']));
        self::assertSame('z-release', $svc->pickNewestTag(['a-release', 'z-release', 'm-release']));
        self::assertSame('', $svc->pickNewestTag([]));
    }

    public function testSelectCronWinnerPrefersTagOverTip(): void
    {
        $svc = $this->expectWatchStub();
        $winner = $svc->selectCronWinnerRef(['v1.0.0', 'v1.0.2'], 'abc123', 'main');
        self::assertNotNull($winner);
        self::assertSame('tag', $winner['kind']);
        self::assertSame('refs/tags/v1.0.2', $winner['ref']);

        $tipOnly = $svc->selectCronWinnerRef([], 'abc123', 'main');
        self::assertNotNull($tipOnly);
        self::assertSame('branch_tip', $tipOnly['kind']);
        self::assertSame('refs/heads/main', $tipOnly['ref']);
    }

    private function expectWatchStub(): DeployWebhookExpectWatchService
    {
        return (new \ReflectionClass(DeployWebhookExpectWatchService::class))
            ->newInstanceWithoutConstructor();
    }
}

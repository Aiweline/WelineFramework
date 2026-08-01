<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Worker;

use PHPUnit\Framework\TestCase;

final class WorkerRequestQuarantineSourceTest extends TestCase
{
    public function testActiveHttpWorkerConsumesRuntimeDrainAndClosesCurrentKeepAliveResponse(): void
    {
        $source = (string)file_get_contents(BP . 'app/code/Weline/Server/bin/worker.php');

        self::assertStringContainsString('consumeDrainAfterResponseReason()', $source);
        self::assertStringContainsString('hasDrainAfterResponseRequest()', $source);
        self::assertStringContainsString("'request_quarantine:worker='", $source);
        self::assertStringContainsString('WorkerResponseMemoryGuard::forceConnectionCloseHeader($response)', $source);
        self::assertStringContainsString('WorkerResponseMemoryGuard::shouldAwaitPeerCloseAfterDrainResponse(', $source);
    }

    public function testWorkersUseExplicitTargetFiberSnapshotsAndUnwindCancellation(): void
    {
        foreach (['worker.php', 'worker_ssl.php'] as $script) {
            $source = (string)file_get_contents(BP . 'app/code/Weline/Server/bin/' . $script);

            self::assertStringContainsString('captureForFiber(', $source, $script);
            self::assertStringNotContainsString('WlsFiberContext::capture()', $source, $script);
            self::assertStringNotContainsString("['context']->restore(false)", $source, $script);
            self::assertStringContainsString('wlsUnwindRequestFiberForCancellation(', $source, $script);
        }
    }

    public function testHttpAcceptSchedulesNewConnectionForImmediateNonBlockingRead(): void
    {
        $source = (string)file_get_contents(BP . 'app/code/Weline/Server/bin/worker.php');

        self::assertStringContainsString(
            'shared-listener reload cannot strand the',
            $source,
        );
        self::assertStringContainsString('$read[] = $conn;', $source);
    }
}

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
        self::assertStringContainsString('zend_mm_ratchet:worker=', $source);
        self::assertStringContainsString('will retire after in-flight drain', $source);
        self::assertStringContainsString('WorkerResponseMemoryGuard::forceConnectionCloseHeader($response)', $source);
        self::assertStringContainsString('WorkerResponseMemoryGuard::shouldAwaitPeerCloseAfterDrainResponse(', $source);
    }

    public function testSslWorkerDrainsOnlyAfterCompactAndWriteFlush(): void
    {
        $source = (string)file_get_contents(BP . 'app/code/Weline/Server/bin/worker_ssl.php');

        self::assertStringContainsString('zend_mm_ratchet:worker=', $source);
        self::assertStringContainsString('will retire after in-flight drain', $source);
        self::assertStringContainsString(
            'Keep-warm: zend_mm_ratchet drain only after body finalized + compact measured shell.',
            $source,
        );
        self::assertStringContainsString(
            'Write-path compact may request zend_mm_ratchet after the last buffered byte leaves.',
            $source,
        );
        self::assertMatchesRegularExpression(
            '/compactAfterRequestFiberReleased\(\s*\\\\strlen\(\$afResponse\),\s*\);\s*'
            . '\/\/ Keep-warm: zend_mm_ratchet drain only after body finalized \+ compact measured shell\.\s*'
            . 'wlsDrainAfterResponseIfRequested\(/s',
            $source,
        );
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

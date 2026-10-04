<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Runtime;

use PHPUnit\Framework\TestCase;

final class WorkerSslQueuedWriteProgressTest extends TestCase
{
    public function testQueuedSocketWriteRefreshesOuterProgressClock(): void
    {
        $source = (string)file_get_contents(BP . 'app/code/Weline/Server/bin/worker_ssl.php');
        $start = strpos($source, 'function wlsSslFlushQueuedWrites(');
        $end = strpos($source, 'function safeCloseStream(', $start);
        self::assertNotFalse($start);
        self::assertNotFalse($end);
        if (!function_exists(QueuedWriteProgressFixture::class . '\\wlsSslFlushQueuedWrites')) {
            eval('namespace ' . QueuedWriteProgressFixture::class . '; '
                . substr($source, $start, $end - $start));
        }

        [$server, $peer] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
        stream_set_blocking($server, false);
        stream_set_blocking($peer, false);
        $connId = get_resource_id($server);
        $writable = [$connId => $server];
        $buffers = [$connId => str_repeat('x', 200000)];
        $zeroProgress = [];
        $connections = [$connId => $server];
        $requests = [];
        $lastActivity = [$connId => 10.0];
        $lastProgress = [$connId => 10.0];
        $logged = $pendingClose = $longLived = $http2Adapters = [];

        QueuedWriteProgressFixture\wlsSslFlushQueuedWrites(
            0,
            $writable,
            $buffers,
            $zeroProgress,
            $connections,
            $requests,
            $lastActivity,
            $lastProgress,
            $logged,
            $pendingClose,
            $longLived,
            $http2Adapters,
        );

        self::assertLessThan(200000, strlen($buffers[$connId]));
        self::assertGreaterThan(10.0, $lastProgress[$connId]);
        fclose($server);
        fclose($peer);
    }
}

namespace Weline\Server\Test\Unit\Runtime\QueuedWriteProgressFixture;

function wlsSslArmHttp2PendingResponseWrites(mixed ...$args): void {}
function wlsWorkerMonotonicNow(): float { return microtime(true); }
function wlsDrainPostResponseTasks(mixed ...$args): void {}
function safeCloseStream(mixed $conn): void { if (is_resource($conn)) { fclose($conn); } }

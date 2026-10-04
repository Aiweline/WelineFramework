<?php
declare(strict_types=1);

namespace Weline\Server\Test\Unit\Runtime;

use PHPUnit\Framework\TestCase;
use Weline\Server\Security\WorkerPolicyDecision;
use Weline\Server\Service\WorkerStaticResponseL1;

final class WorkerStaticHttpSemanticsTest extends TestCase
{
    private const URI = '/Weline/Frontend/view/statics/js/weline-api-business.js';

    protected function setUp(): void
    {
        require_once BP . 'app/code/Weline/Server/bin/worker_runtime_common.php';
        require_once BP . 'app/code/Weline/Server/bin/worker_http_message.php';
        WorkerStaticResponseL1::clear();
        foreach (['worker', 'worker_ssl'] as $owner) {
            $namespace = __NAMESPACE__ . '\\StaticReplay\\' . $owner;
            if (!\function_exists($namespace . '\\handleStaticFile')) {
                $source = (string)\file_get_contents(BP . 'app/code/Weline/Server/bin/' . $owner . '.php');
                $start = \strpos($source, 'function handleStaticFile(');
                self::assertNotFalse($start);
                // 直接装载磁盘上的最后一个生产函数，不复制 handler 实现。
                eval('namespace ' . $namespace . '; ' . \substr($source, $start));
            }
            ($namespace . '\\handleStaticFile')('__CLEAR_CACHE__', '');
        }
    }

    public function testHeadIgnoresRangeAndHasFullRepresentationLength(): void
    {
        $size = \filesize(BP . 'app/code' . self::URI);
        foreach ($this->owners() as $handler) {
            $response = $handler(self::URI, $this->raw('HEAD', ['Range' => 'bytes=0-3']));
            self::assertStringStartsWith('HTTP/1.1 200 ', $response);
            self::assertStringContainsString('Content-Length: ' . $size . "\r\n", $response);
            self::assertStringEndsWith("\r\n\r\n", $response);
        }
    }

    public function testFailedWritePreconditionsPrecedeRangeOnColdAndHotFiles(): void
    {
        foreach ($this->owners() as $handler) {
            foreach ([false, true] as $hot) {
                if ($hot) {
                    $handler(self::URI, $this->raw());
                }
                foreach ([['If-Match' => '"wrong"', 'Range' => 'bytes=0-3'],
                    ['If-Unmodified-Since' => 'Thu, 01 Jan 1970 00:00:00 GMT']] as $headers) {
                    $response = $handler(self::URI, $this->raw('GET', $headers));
                    self::assertStringStartsWith('HTTP/1.1 412 ', $response);
                    self::assertStringEndsWith("\r\n\r\n", $response);
                }
            }
        }
    }

    public function testWeakValidatorsAndPrecedencePreserveOrdinaryRange(): void
    {
        foreach ($this->owners() as $handler) {
            $plain = $handler(self::URI, $this->raw());
            self::assertMatchesRegularExpression('/\r\nETag: W\/"[^"\r\n]+"\r\n/', $plain);
            \preg_match('/\r\nETag: ([^\r\n]+)/', $plain, $tag);
            foreach (['*', '"other", ' . $tag[1]] as $condition) {
                $response = $handler(self::URI, $this->raw('GET', ['If-None-Match' => $condition, 'Range' => 'bytes=0-3']));
                self::assertStringStartsWith('HTTP/1.1 304 ', $response);
                self::assertStringContainsString('Vary: Accept-Encoding', $response);
                self::assertStringEndsWith("\r\n\r\n", $response);
            }
            self::assertStringStartsWith('HTTP/1.1 200 ', $handler(self::URI, $this->raw('GET', [
                'If-Match' => '*', 'If-Unmodified-Since' => 'Thu, 01 Jan 1970 00:00:00 GMT',
                'If-None-Match' => '"other"', 'If-Modified-Since' => 'Thu, 01 Jan 2099 00:00:00 GMT',
            ])));
            self::assertStringStartsWith('HTTP/1.1 200 ', $handler(self::URI, $this->raw('GET', ['Range' => 'bytes=0-3', 'If-Range' => $tag[1]])));
            $range = $handler(self::URI, $this->raw('GET', ['Range' => 'bytes=0-3']));
            self::assertStringStartsWith('HTTP/1.1 206 ', $range);
            self::assertSame(4, \strlen(\explode("\r\n\r\n", $range, 2)[1]));
        }
    }

    public function testHeadSelectsGzipMetadataWithoutAnIdentityLength(): void
    {
        foreach ($this->owners() as $handler) {
            $raw = $this->raw('HEAD', ['Accept-Encoding' => 'gzip, identity;q=0']);
            $response = \wlsMaybeCompressStaticHttpResponse($handler(self::URI, $raw), $raw);
            self::assertStringContainsString('Content-Encoding: gzip', $response);
            self::assertDoesNotMatchRegularExpression('/\r\nContent-Length:/i', $response);
            self::assertStringEndsWith("\r\n\r\n", $response);
        }
    }

    public function testHotNotModifiedKeepsValidatorsAndVary(): void
    {
        $handler = $this->owners()[0];
        $handler(self::URI, $this->raw());
        $decision = WorkerPolicyDecision::allow(
            clientIp: '127.0.0.1', method: 'GET', protocol: 'HTTP/1.1', target: self::URI,
            path: self::URI, headers: ['if-none-match' => '*', 'connection' => 'keep-alive'], body: '',
            policyDigest: \str_repeat('a', 64), trustedProxy: false,
            cachePolicyFlags: WorkerPolicyDecision::CACHE_STATIC_PROCESS_L1,
        );
        $response = WorkerStaticResponseL1::lookup($decision);
        self::assertStringStartsWith('HTTP/1.1 304 ', $response);
        self::assertStringContainsString('Vary: Accept-Encoding', $response);
        self::assertStringContainsString('Last-Modified:', $response);
    }

    public function testIncompleteBodyCarriesOnlyValidatedHeaderFacts(): void
    {
        $raw = "GET /_wls/health HTTP/1.1\r\nHost: localhost\r\nContent-Length: 4\r\nExpect: 100-continue\r\n\r\n";
        $frame = \wlsParseHttpRequestFrame($raw, 8192, 1024);
        self::assertSame('incomplete', $frame['status']);
        self::assertSame('GET', $frame['method'] ?? null);
        self::assertSame('HTTP/1.1', $frame['protocol'] ?? null);
        self::assertSame('100-continue', $frame['headers']['expect'] ?? null);
        self::assertSame('', $frame['request']);
        self::assertSame('error', \wlsParseHttpRequestFrame($raw, 8192, 3)['status']);
        self::assertArrayNotHasKey('headers', \wlsParseHttpRequestFrame(\substr($raw, 0, -2), 8192, 1024));
    }

    public function testContinueQueuesOnceAndResetsForTheNextKeepAliveRequest(): void
    {
        self::assertTrue(\function_exists('wlsQueueHttpContinue'));
        [$server, $client] = \stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        try {
            $raw = "GET /_wls/health HTTP/1.1\r\nHost: localhost\r\nContent-Length: 4\r\nExpect: 100-continue\r\n\r\n";
            $id = (int)$server;
            $sent = $writes = $writable = [];
            foreach ([\substr($raw, 0, -2), $raw, $raw . 'te'] as $fragment) {
                \wlsQueueHttpContinue(\wlsParseHttpRequestFrame($fragment, 8192, 1024), $server, $id, $sent, $writes, $writable);
            }
            self::assertSame("HTTP/1.1 100 Continue\r\n\r\n", $writes[$id]);
            self::assertSame($server, $writable[$id]);
            self::assertSame($server, $sent[$id]);
            $writes[$id] = 'remaining partial write';
            \wlsQueueHttpContinue(\wlsParseHttpRequestFrame($raw . 'test', 8192, 1024), $server, $id, $sent, $writes, $writable);
            self::assertArrayNotHasKey($id, $sent);
            self::assertSame('remaining partial write', $writes[$id]);
            $writes = $writable = [];
            \wlsQueueHttpContinue(\wlsParseHttpRequestFrame($raw, 8192, 1024), $server, $id, $sent, $writes, $writable);
            self::assertSame("HTTP/1.1 100 Continue\r\n\r\n", $writes[$id]);
            \wlsQueueHttpContinue(\wlsParseHttpRequestFrame($raw, 8192, 3), $server, $id, $sent, $writes, $writable);
            self::assertArrayNotHasKey($id, $sent);
            $writes = $writable = [];
            \wlsQueueHttpContinue(\wlsParseHttpRequestFrame($raw . 'test', 8192, 1024), $server, $id, $sent, $writes, $writable);
            self::assertSame([], $writes);
        } finally {
            \fclose($server);
            \fclose($client);
        }
    }

    public function testHttpReadOwnerQueuesContinueBeforeTheBodyAndConsumesOnlyTheCompleteRequest(): void
    {
        $namespace = __NAMESPACE__ . '\\ReadReplay';
        if (!\function_exists($namespace . '\\wlsHttpReadStep')) {
            $source = (string)\file_get_contents(BP . 'app/code/Weline/Server/bin/worker.php');
            $start = \strpos($source, 'function wlsHttpReadStep(');
            $end = \strpos($source, 'function wlsDispatchRequestFiberStep(', $start);
            eval('namespace ' . $namespace . '; ' . \substr($source, $start, $end - $start));
        }
        [$server, $client] = \stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        \stream_set_blocking($server, false);
        try {
            $id = (int)$server;
            $raw = "GET /_wls/health HTTP/1.1\r\nHost: localhost\r\nContent-Length: 4\r\nExpect: 100-continue\r\n\r\n";
            $active = $longLived = $buffers = $activity = $logged = $writes = $writable = $close = $trusted = $sent = [];
            $connections = [$id => $server];
            $peers = [$id => '127.0.0.1'];
            $count = $requests = 0;
            $scheduler = new \Weline\Server\Scheduler\FiberScheduler();
            $read = static function () use ($namespace, $server, $id, $scheduler, &$active, &$longLived, &$connections,
                &$buffers, &$activity, &$logged, &$count, &$requests, &$writes, &$writable, &$close, &$peers, &$trusted, &$sent): array {
                return ($namespace . '\\wlsHttpReadStep')($server, $id, false, $scheduler, $active, $longLived,
                    $connections, $buffers, $activity, $logged, $count, $requests, $writes, $writable, $close,
                    $peers, $trusted, 8192, 1024, 9216, 'pure', '', $sent);
            };
            \fwrite($client, $raw);
            self::assertFalse($read()['request_ready']);
            self::assertSame("HTTP/1.1 100 Continue\r\n\r\n", $writes[$id]);
            self::assertSame($raw, $buffers[$id]);
            self::assertSame(0, $requests);
            // 实际写出 interim 前缀，再提前发送正文；真实 owner 必须让剩余尾部先排空。
            $prefix = \substr($writes[$id], 0, 9);
            self::assertSame(9, \fwrite($server, $prefix));
            self::assertSame($prefix, \fread($client, 9));
            $writes[$id] = \substr($writes[$id], 9);
            $tail = $writes[$id];
            \fwrite($client, 'test');
            self::assertFalse($read()['request_ready']);
            self::assertSame($tail, $writes[$id]);
            self::assertSame($raw, $buffers[$id]);
            self::assertSame($server, $sent[$id]);
            self::assertSame(\strlen($tail), \fwrite($server, $tail));
            self::assertSame($tail, \fread($client, \strlen($tail)));
            $writes = $writable = [];
            $result = $read();
            self::assertTrue($result['request_ready']);
            self::assertSame($raw . 'test', $result['raw_request']);
            self::assertSame('', $buffers[$id]);
            self::assertSame([], $sent);
        } finally {
            \fclose($server);
            \fclose($client);
        }
    }

    private function owners(): array
    {
        return [__NAMESPACE__ . '\\StaticReplay\\worker\\handleStaticFile', __NAMESPACE__ . '\\StaticReplay\\worker_ssl\\handleStaticFile'];
    }

    private function raw(string $method = 'GET', array $headers = []): string
    {
        $raw = $method . ' ' . self::URI . " HTTP/1.1\r\nHost: localhost\r\nConnection: keep-alive\r\n";
        foreach ($headers as $name => $value) {
            $raw .= $name . ': ' . $value . "\r\n";
        }
        return $raw . "\r\n";
    }
}

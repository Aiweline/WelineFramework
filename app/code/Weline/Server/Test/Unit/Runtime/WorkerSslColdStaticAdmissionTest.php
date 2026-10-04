<?php
declare(strict_types=1);

namespace Weline\Server\Test\Unit\Runtime;

use PHPUnit\Framework\TestCase;
use Weline\Server\Security\WorkerPolicyDecision;
use Weline\Server\Service\WorkerStaticResponseL1;

final class WorkerSslColdStaticAdmissionTest extends TestCase
{
    private const URI = '/Weline/Frontend/view/statics/js/weline-api-business.js';
    private const OWNER = __NAMESPACE__ . '\\ColdStaticReplay';

    protected function setUp(): void
    {
        require_once BP . 'app/code/Weline/Server/bin/worker_runtime_common.php';
        require_once BP . 'app/code/Weline/Server/bin/worker_http_message.php';
        if (!\defined('WLS_WORKER_HOT_PATH_LOGS_ENABLED')) {
            \define('WLS_WORKER_HOT_PATH_LOGS_ENABLED', false);
        }
        $source = (string)\file_get_contents(BP . 'app/code/Weline/Server/bin/worker_ssl.php');
        if (!\function_exists(self::OWNER . '\\handleStaticFile')) {
            $start = \strpos($source, 'function handleStaticFile(');
            eval('namespace ' . self::OWNER . '; ' . \substr($source, $start));
        }
        if (!\function_exists(self::OWNER . '\\wlsSslCanonicalStaticResponse')) {
            $start = \strpos($source, 'function wlsSslCanonicalStaticResponse(');
            if ($start !== false) {
                $end = \strpos($source, 'function handleRequest(', $start);
                eval('namespace ' . self::OWNER . '; use Weline\\Server\\Log\\WlsLogger; ' . \substr($source, $start, $end - $start));
            }
        }
        (self::OWNER . '\\handleStaticFile')('__CLEAR_CACHE__', '');
        WorkerStaticResponseL1::clear();
    }

    public function testColdStaticResolvesBeforeSaturatedBusinessAdmission(): void
    {
        $decision = $this->decision();
        self::assertNull(WorkerStaticResponseL1::lookup($decision));
        self::assertFalse(\wlsFiberAdmissionQueueHasRoom(12, 12, 12));
        $response = $this->resolveBeforeAdmission($decision, $this->raw());
        self::assertIsString($response, '冷静态必须在饱和业务准入前完成canonical读取');
        self::assertStringStartsWith('HTTP/1.1 200 ', $response);
        self::assertSame((string)\file_get_contents(BP . 'app/code' . self::URI), \explode("\r\n\r\n", $response, 2)[1]);
        self::assertStringContainsString('X-WLS-Static-Cache: MISS', $response);
        self::assertNotNull(WorkerStaticResponseL1::lookup($decision));
    }

    public function testColdHeadRangeAndGzipKeepCanonicalSemantics(): void
    {
        $range = $this->resolveBeforeAdmission($this->decision(headers: ['range' => 'bytes=0-3']), $this->raw(headers: ['Range' => 'bytes=0-3']));
        self::assertStringStartsWith('HTTP/1.1 206 ', $range);
        self::assertSame(4, \strlen(\explode("\r\n\r\n", $range, 2)[1]));
        $head = $this->resolveBeforeAdmission($this->decision('HEAD'), $this->raw('HEAD'));
        self::assertStringStartsWith('HTTP/1.1 200 ', $head);
        self::assertStringEndsWith("\r\n\r\n", $head);
        $gzip = \wlsMaybeCompressStaticHttpResponse($this->resolveBeforeAdmission($this->decision(), $this->raw()), $this->raw(headers: ['Accept-Encoding' => 'gzip']));
        self::assertStringContainsString('Content-Encoding: gzip', $gzip);
        self::assertSame((string)\file_get_contents(BP . 'app/code' . self::URI), \gzdecode(\explode("\r\n\r\n", $gzip, 2)[1]));
    }

    public function testDynamicPostAndDisabledStaticKeepBusinessPath(): void
    {
        foreach ([$this->decision(target: '/'), $this->decision('POST'), $this->decision(flags: 0)] as $decision) {
            self::assertNull($this->resolveBeforeAdmission($decision, $this->raw($decision->method, target: $decision->target)));
        }
    }

    public function testDeniedAndFrameworkOwnedStaticKeepOriginalOwner(): void
    {
        $denied = WorkerPolicyDecision::deny(clientIp: '127.0.0.1', method: 'GET', protocol: 'HTTP/1.1',
            target: self::URI, path: self::URI, headers: [], body: '', response: 'policy rejected',
            reason: 'static denied', policyDigest: \str_repeat('a', 64), trustedProxy: false);
        self::assertNull($this->resolveBeforeAdmission($denied, $this->raw()));
        foreach (['/media/file/example.pdf', '/sitemap.xml', '/app/etc/env.php'] as $target) {
            self::assertNull($this->resolveBeforeAdmission($this->decision(target: $target), $this->raw(target: $target)));
        }
    }

    public function testColdConditionalResponseKeepsValidatorsAndEmptyBody(): void
    {
        $response = $this->resolveBeforeAdmission($this->decision(headers: ['if-none-match' => '*']),
            $this->raw(headers: ['If-None-Match' => '*']));
        self::assertStringStartsWith('HTTP/1.1 304 ', $response);
        self::assertStringContainsString('ETag: W/', $response);
        self::assertStringContainsString('Vary: Accept-Encoding', $response);
        self::assertStringEndsWith("\r\n\r\n", $response);
    }

    public function testColdWebSocketStaticKeepsOriginalProtocolOwner(): void
    {
        $headers = ['Upgrade' => 'websocket', 'Connection' => 'Upgrade', 'Sec-WebSocket-Key' => 'test-key'];
        $raw = $this->raw(headers: $headers);
        $protocol = (new \Weline\Server\Service\Protocol\LongLived\ProtocolResolver())->detect($raw);
        self::assertSame('websocket', $protocol['protocol']);
        self::assertTrue($protocol['is_long_lived']);
        self::assertNull($this->resolveBeforeAdmission($this->decision(), $raw));
    }

    public function testColdSseStaticKeepsOriginalProtocolOwner(): void
    {
        $raw = $this->raw(headers: ['Accept' => 'text/event-stream']);
        $protocol = (new \Weline\Server\Service\Protocol\LongLived\ProtocolResolver())->detect($raw);
        self::assertSame('sse', $protocol['protocol']);
        self::assertTrue($protocol['is_long_lived']);
        self::assertNull($this->resolveBeforeAdmission($this->decision(), $raw));
    }

    public function testUpgradeHeaderAloneKeepsOrdinaryStaticSemantics(): void
    {
        $raw = $this->raw(headers: ['Upgrade' => 'websocket']);
        $protocol = (new \Weline\Server\Service\Protocol\LongLived\ProtocolResolver())->detect($raw);
        self::assertFalse($protocol['is_long_lived']);
        $response = $this->resolveBeforeAdmission($this->decision(), $raw);
        self::assertStringStartsWith('HTTP/1.1 200 ', $response);
    }

    public static function largeStaticCases(): array
    {
        return [['GET'], ['HEAD'], ['RANGE']];
    }

    public function testColdStaticAtReadLimitKeepsFastPath(): void
    {
        $target = '/wls-cold-static-' . \bin2hex(\random_bytes(8)) . '.js';
        $file = BP . 'pub' . $target;
        $size = 262_144;
        \file_put_contents($file, \str_repeat('x', $size));
        try {
            $response = $this->resolveBeforeAdmission($this->decision(target: $target), $this->raw(target: $target));
            self::assertStringStartsWith('HTTP/1.1 200 ', $response);
            self::assertSame($size, \strlen(\explode("\r\n\r\n", $response, 2)[1]));
        } finally {
            \unlink($file);
            (self::OWNER . '\\handleStaticFile')('__CLEAR_CACHE__', '');
        }
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('largeStaticCases')]
    public function testLargeColdGetHeadAndRangeKeepBusinessAdmission(string $case): void
    {
        $target = '/wls-cold-static-' . \bin2hex(\random_bytes(8)) . '.js';
        $file = BP . 'pub' . $target;
        $size = 262_145;
        \file_put_contents($file, \str_repeat('x', $size));
        try {
            $method = $case === 'HEAD' ? 'HEAD' : 'GET';
            $headers = $case === 'RANGE' ? ['Range' => 'bytes=0-262144'] : [];
            $raw = $this->raw($method, $headers, $target);
            $decision = $this->decision($method, \array_change_key_case($headers, CASE_LOWER), $target);
            self::assertTrue($this->resolveBeforeAdmission($decision, $raw) === null,
                $case . ': large static content must stay outside the pre-admission event loop');
            // 同一canonical handler仍能在原业务路径提供完整语义。
            $response = (self::OWNER . '\\wlsSslCanonicalStaticResponse')($decision, $raw, 1, 19986);
            self::assertStringStartsWith('HTTP/1.1 ' . ($case === 'RANGE' ? '206' : '200') . ' ', $response);
            self::assertSame($method === 'HEAD' ? 0 : $size, \strlen(\explode("\r\n\r\n", $response, 2)[1]));
        } finally {
            \unlink($file);
            (self::OWNER . '\\handleStaticFile')('__CLEAR_CACHE__', '');
        }
    }

    private function resolveBeforeAdmission(WorkerPolicyDecision $policyDecision, string $rawRequest): ?string
    {
        $source = (string)\file_get_contents(BP . 'app/code/Weline/Server/bin/worker_ssl.php');
        $start = \strrpos($source, '$staticFastResponse = $policyDecision->staticProcessCacheEnabled()');
        $end = \strpos($source, 'if ($staticFastResponse !== null)', $start);
        self::assertNotFalse($start);
        self::assertNotFalse($end);
        $workerId = 1;
        $port = 19986;
        $longLivedProtocolResolver = new \Weline\Server\Service\Protocol\LongLived\ProtocolResolver();
        // 直接执行当前磁盘TLS主循环的静态解析段，canonical处理器也取自生产源码。
        eval('namespace ' . self::OWNER . '; ' . \substr($source, $start, $end - $start));
        return $staticFastResponse;
    }

    private function decision(string $method = 'GET', array $headers = [], string $target = self::URI, int $flags = WorkerPolicyDecision::CACHE_STATIC_PROCESS_L1): WorkerPolicyDecision
    {
        return WorkerPolicyDecision::allow(clientIp: '127.0.0.1', method: $method, protocol: 'HTTP/1.1',
            target: $target, path: $target, headers: $headers + ['host' => 'localhost', 'connection' => 'keep-alive'],
            body: '', policyDigest: \str_repeat('a', 64), trustedProxy: false, cachePolicyFlags: $flags);
    }

    private function raw(string $method = 'GET', array $headers = [], string $target = self::URI): string
    {
        $raw = $method . ' ' . $target . " HTTP/1.1\r\nHost: localhost\r\nConnection: keep-alive\r\n";
        foreach ($headers as $name => $value) {
            $raw .= $name . ': ' . $value . "\r\n";
        }
        return $raw . "\r\n";
    }
}

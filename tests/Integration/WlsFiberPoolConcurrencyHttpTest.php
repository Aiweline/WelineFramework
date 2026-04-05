<?php

declare(strict_types=1);

/**
 * WLS Fiber / SharedState 连接池并发安全：使用 curl_multi 在同一时刻发起多路 HTTP，
 * 由操作系统与远端 Worker 形成真并发（非 PHP 单线程顺序请求）。
 *
 * 运行前：
 * 1. 启动测试实例：php bin/w server:start -p 9502 -n ai-test-fiber-probe（示例）
 * 2. 导出基址：set WLS_FIBER_CONCURRENCY_BASE_URL=http://127.0.0.1:9502
 * 3. 若 Worker 非 DEV：在运行 WLS 的环境中设置 WLS_FIBER_CONCURRENCY_PROBE=1
 *
 * 执行：
 *   php vendor/bin/phpunit tests/Integration/WlsFiberPoolConcurrencyHttpTest.php
 *
 * @group wls
 * @group integration
 */
namespace WelineFramework\Tests\Integration;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('integration')]
#[Group('wls')]
final class WlsFiberPoolConcurrencyHttpTest extends TestCase
{
    private static function baseUrl(): string
    {
        $u = \getenv('WLS_FIBER_CONCURRENCY_BASE_URL');

        return $u !== false && \trim($u) !== '' ? \rtrim(\trim($u), '/') : '';
    }

    /**
     * @return list<array{body: string, code: int}>
     */
    private static function curlMultiGet(array $urls, int $timeoutSec = 45): array
    {
        $mh = \curl_multi_init();
        if ($mh === false) {
            self::fail('curl_multi_init failed');
        }

        $handles = [];
        try {
            foreach ($urls as $i => $url) {
                $ch = \curl_init($url);
                self::assertNotFalse($ch);
                \curl_setopt($ch, \CURLOPT_RETURNTRANSFER, true);
                \curl_setopt($ch, \CURLOPT_TIMEOUT, $timeoutSec);
                \curl_setopt($ch, \CURLOPT_FOLLOWLOCATION, true);
                \curl_setopt($ch, \CURLOPT_SSL_VERIFYPEER, false);
                \curl_setopt($ch, \CURLOPT_SSL_VERIFYHOST, 0);
                $handles[$i] = $ch;
                \curl_multi_add_handle($mh, $ch);
            }

            $running = 0;
            do {
                $mrc = \curl_multi_exec($mh, $running);
                if ($mrc !== \CURLM_OK) {
                    break;
                }
                if ($running > 0) {
                    \curl_multi_select($mh, 0.05);
                }
            } while ($running > 0);

            $out = [];
            foreach ($handles as $i => $ch) {
                $out[$i] = [
                    'body' => (string) \curl_multi_getcontent($ch),
                    'code' => (int) \curl_getinfo($ch, \CURLINFO_HTTP_CODE),
                ];
                \curl_multi_remove_handle($mh, $ch);
                \curl_close($ch);
            }

            return $out;
        } finally {
            \curl_multi_close($mh);
        }
    }

    public function testConcurrentStressEchoesCorrelationIds(): void
    {
        $base = self::baseUrl();
        if ($base === '') {
            self::markTestSkipped(
                'Set WLS_FIBER_CONCURRENCY_BASE_URL (e.g. http://127.0.0.1:9502) and start WLS test instance.'
            );
        }

        $path = $base . '/server/fiber-concurrency-probe/stress';
        $concurrency = (int) (\getenv('WLS_FIBER_CONCURRENCY_CLIENTS') ?: 24);
        $concurrency = \max(8, \min(128, $concurrency));

        $cids = [];
        $urls = [];
        for ($i = 0; $i < $concurrency; $i++) {
            $cid = 'p-' . $i . '-' . bin2hex(random_bytes(8));
            $cids[$i] = $cid;
            $urls[$i] = $path . '?cid=' . \rawurlencode($cid);
        }

        $results = self::curlMultiGet($urls);

        $okCount = 0;
        foreach ($results as $i => $row) {
            self::assertSame(200, $row['code'], 'HTTP status for request #' . (string) $i);
            $json = \json_decode($row['body'], true);
            self::assertIsArray($json, 'JSON body for #' . (string) $i);
            self::assertArrayHasKey('cid', $json);
            self::assertSame($cids[$i], $json['cid'], 'Response must echo the same cid (detects response mix-up)');
            if (!empty($json['ok'])) {
                $okCount++;
            }
        }

        if ($okCount === 0) {
            self::markTestSkipped(
                'Session shared service unreachable from worker (ping failed for all). Check Session Server and pool.'
            );
        }

        self::assertGreaterThan(
            (int) \floor($concurrency * 0.5),
            $okCount,
            'Majority of pings should succeed when Session is healthy'
        );
    }
}

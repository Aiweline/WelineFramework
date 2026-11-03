<?php

declare(strict_types=1);

namespace Weline\Theme\test\Unit\Service\Cdn;

use PHPUnit\Framework\TestCase;
use Weline\Theme\Service\Cdn\CdnPurgeTransportInterface;
use Weline\Theme\Service\Cdn\HttpCdnPurgeAdapter;
use Weline\Theme\Service\Cdn\PurgeBatchPlanner;

/**
 * purge 适配器：无凭据、无网络，用 fake transport 钉死请求形状与成功判定。
 */
class HttpCdnPurgeAdapterTest extends TestCase
{
    public function testSendsPrefixesWithAuthHeader(): void
    {
        $transport = new class implements CdnPurgeTransportInterface {
            /** @var list<array{url:string,payload:array,headers:array}> */
            public array $calls = [];
            public int $status = 200;

            public function post(string $url, array $payload, array $headers): array
            {
                $this->calls[] = ['url' => $url, 'payload' => $payload, 'headers' => $headers];

                return ['status' => $this->status, 'body' => '{}'];
            }
        };

        $adapter = new HttpCdnPurgeAdapter($transport, [
            'a.test' => ['endpoint' => 'https://cdn.example/purge', 'token' => 'tk-1'],
        ]);

        self::assertTrue($adapter->purge('a.test', ['/products']));
        self::assertCount(1, $transport->calls);
        self::assertSame('https://cdn.example/purge', $transport->calls[0]['url']);
        self::assertSame(['/products'], $transport->calls[0]['payload']['prefixes']);
        self::assertSame('Bearer tk-1', $transport->calls[0]['headers']['Authorization']);
    }

    public function testNon2xxIsFailure(): void
    {
        $transport = new class implements CdnPurgeTransportInterface {
            public int $status = 500;

            public function post(string $url, array $payload, array $headers): array
            {
                return ['status' => $this->status, 'body' => 'boom'];
            }
        };

        $adapter = new HttpCdnPurgeAdapter($transport, [
            'a.test' => ['endpoint' => 'https://cdn.example/purge', 'token' => ''],
        ]);

        self::assertFalse($adapter->purge('a.test', ['/']));
    }

    public function testUnknownZoneIsFailureNotSilentSuccess(): void
    {
        $transport = new class implements CdnPurgeTransportInterface {
            public int $calls = 0;

            public function post(string $url, array $payload, array $headers): array
            {
                $this->calls++;

                return ['status' => 200, 'body' => '{}'];
            }
        };

        $adapter = new HttpCdnPurgeAdapter($transport, []);

        self::assertFalse($adapter->purge('missing.test', ['/']));
        self::assertSame(0, $transport->calls, '没配置就不该发请求，也不能算成功');
    }

    public function testAdapterComposesWithPlannerResume(): void
    {
        $transport = new class implements CdnPurgeTransportInterface {
            public int $calls = 0;

            public function post(string $url, array $payload, array $headers): array
            {
                $this->calls++;
                // 第 1 次 500，之后 200：验证「适配器判失败 → 规划器重试成功」。
                return ['status' => $this->calls === 1 ? 500 : 200, 'body' => '{}'];
            }
        };

        $adapter = new HttpCdnPurgeAdapter($transport, [
            'a.test' => ['endpoint' => 'https://cdn.example/purge', 'token' => ''],
        ]);
        $planner = new PurgeBatchPlanner();
        $plan = $planner->plan(
            ['shared' => true, 'exact' => [], 'static' => false, 'prefixes' => []],
            ['a.test'],
        );

        $result = $planner->runGuarded($plan, $adapter, 0, 2, 3);

        self::assertSame(1, $result['done']);
        self::assertNull($result['failed']);
        self::assertSame(2, $transport->calls);
    }
}

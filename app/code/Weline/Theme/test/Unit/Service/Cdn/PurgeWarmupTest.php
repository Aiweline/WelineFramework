<?php

declare(strict_types=1);

namespace Weline\Theme\test\Unit\Service\Cdn;

use PHPUnit\Framework\TestCase;
use Weline\Theme\Service\Cdn\PurgeObserverInterface;
use Weline\Theme\Service\Cdn\PurgeWarmup;

/**
 * P5 预热编排：按影响面生成 URL，注入取页函数，统计成功/失败。
 */
class PurgeWarmupTest extends TestCase
{
    public function testSharedImpactWarmsRootOfEveryHost(): void
    {
        $warmup = new PurgeWarmup();
        $urls = $warmup->urlsFor(
            ['shared' => true, 'exact' => [], 'static' => false, 'prefixes' => []],
            ['a.test', 'b.test', ''],
        );

        self::assertSame(['https://a.test/', 'https://b.test/'], $urls);
    }

    public function testExactImpactWarmsPageTypes(): void
    {
        $warmup = new PurgeWarmup();
        $urls = $warmup->urlsFor(
            ['shared' => false, 'exact' => ['products', 'about'], 'static' => false, 'prefixes' => []],
            ['a.test'],
        );

        self::assertSame(['https://a.test/products', 'https://a.test/about'], $urls);
    }

    public function testStaticOnlyImpactHasNothingToWarm(): void
    {
        $warmup = new PurgeWarmup();

        self::assertSame(
            [],
            $warmup->urlsFor(['shared' => false, 'exact' => [], 'static' => true, 'prefixes' => []], ['a.test'])
        );
    }

    public function testWarmCountsSuccessAndCollectsFailures(): void
    {
        $warmup = new PurgeWarmup();
        $calls = [];
        $result = $warmup->warm(['https://a.test/', 'https://a.test/products'], static function (string $url) use (&$calls): bool {
            $calls[] = $url;

            return $url === 'https://a.test/';
        });

        self::assertSame(1, $result['ok']);
        self::assertSame(['https://a.test/products'], $result['failed']);
        self::assertSame(['https://a.test/', 'https://a.test/products'], $calls);
    }

    public function testFetcherExceptionCountsAsFailure(): void
    {
        $warmup = new PurgeWarmup();
        $result = $warmup->warm(['https://a.test/'], static function (): bool {
            throw new \RuntimeException('boom');
        });

        self::assertSame(0, $result['ok']);
        self::assertSame(['https://a.test/'], $result['failed']);
    }

    public function testObserverContractIsStructured(): void
    {
        $observer = new class implements PurgeObserverInterface {
            /** @var list<array<string,mixed>> */
            public array $events = [];

            public function record(array $event): void
            {
                $this->events[] = $event;
            }
        };

        $observer->record(['stage' => 'impact', 'reason' => 'shared', 'count' => 1]);
        $observer->record(['stage' => 'purge', 'zone' => 'a.test', 'reason' => 'purged', 'count' => 2]);

        self::assertSame('impact', $observer->events[0]['stage']);
        self::assertSame('purged', $observer->events[1]['reason']);
        self::assertSame(2, $observer->events[1]['count']);
    }
}

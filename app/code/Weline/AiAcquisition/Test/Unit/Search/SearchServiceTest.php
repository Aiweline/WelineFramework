<?php

declare(strict_types=1);

namespace Weline\AiAcquisition\Test\Unit\Search;

use PHPUnit\Framework\TestCase;
use Weline\AiAcquisition\Service\Search\SearchProviderInterface;
use Weline\AiAcquisition\Service\Search\SearchService;

/**
 * SearchService 纯逻辑测试：只验证去重、排序、条数限制与空结果，全部使用假提供者，不发外网。
 */
final class SearchServiceTest extends TestCase
{
    public function test_dedupes_results_by_host_and_path(): void
    {
        $provider = new FakeSearchProvider([
            $this->row('https://buyer.example.com/rfq/lighting?utm_source=newsletter', 1),
            $this->row('https://buyer.example.com/rfq/lighting/', 2),
            $this->row('http://buyer.example.com/rfq/packaging', 3),
        ]);

        $results = (new SearchService([$provider]))->search('wholesale rfq', 10);

        self::assertCount(2, $results);
        self::assertSame('https://buyer.example.com/rfq/lighting?utm_source=newsletter', $results[0]['url']);
        self::assertSame('http://buyer.example.com/rfq/packaging', $results[1]['url']);
        self::assertSame([1, 2], array_column($results, 'rank'));
    }

    public function test_sorts_results_by_provider_rank(): void
    {
        $provider = new FakeSearchProvider([
            $this->row('https://third.example.com/thread', 7),
            $this->row('https://first.example.com/thread', 1),
            $this->row('https://second.example.com/thread', 3),
        ]);

        $results = (new SearchService([$provider]))->search('rfq', 10);

        self::assertSame(
            [
                'https://first.example.com/thread',
                'https://second.example.com/thread',
                'https://third.example.com/thread',
            ],
            array_column($results, 'url')
        );
        self::assertSame([1, 2, 3], array_column($results, 'rank'));
    }

    public function test_limits_results_and_clamps_to_max(): void
    {
        $rows = [];
        for ($i = 1; $i <= 25; $i++) {
            $rows[] = $this->row('https://buyer' . $i . '.example.com/rfq', $i);
        }
        $provider = new FakeSearchProvider($rows);

        $results = (new SearchService([$provider]))->search('rfq', 100);

        self::assertCount(SearchService::MAX_RESULTS, $results);
        self::assertSame(1, $results[0]['rank']);
        self::assertSame(SearchService::MAX_RESULTS, $provider->lastLimit);

        self::assertCount(2, (new SearchService([$provider]))->search('rfq', 2));
    }

    public function test_returns_empty_when_provider_chain_has_no_results(): void
    {
        $service = new SearchService([new FakeSearchProvider([]), new FakeSearchProvider([])]);

        self::assertSame([], $service->search('no matching buyer'));
        self::assertSame([], $service->search('   '));
    }

    public function test_falls_back_to_next_provider_after_empty_results(): void
    {
        $first = new FakeSearchProvider([]);
        $second = new FakeSearchProvider([$this->row('https://fallback.example.com/rfq', 1)]);

        $results = (new SearchService([$first, $second]))->search('rfq', 5);

        self::assertCount(1, $results);
        self::assertSame('https://fallback.example.com/rfq', $results[0]['url']);
    }

    /**
     * 构造一条假搜索结果。
     *
     * @return array{title:string,url:string,snippet:string,source:string,rank:int}
     */
    private function row(string $url, int $rank): array
    {
        return [
            'title' => 'Result ' . $rank,
            'url' => $url,
            'snippet' => 'buyer demand',
            'source' => 'fake',
            'rank' => $rank,
        ];
    }
}

/**
 * 测试用搜索提供者：回放固定结果，不发网络请求。
 */
final class FakeSearchProvider implements SearchProviderInterface
{
    public int $lastLimit = 0;

    /**
     * @param list<array<string,mixed>> $rows 固定回放结果
     */
    public function __construct(private readonly array $rows = [])
    {
    }

    public function search(string $query, int $limit = 10): array
    {
        $this->lastLimit = $limit;

        return array_slice($this->rows, 0, $limit);
    }
}

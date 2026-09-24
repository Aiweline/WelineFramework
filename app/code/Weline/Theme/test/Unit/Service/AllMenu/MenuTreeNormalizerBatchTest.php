<?php
declare(strict_types=1);

namespace Weline\Theme\Test\Unit\Service\AllMenu;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use Weline\Framework\Http\Request;
use Weline\Framework\Http\Url;
use Weline\Framework\Manager\ObjectManager;
use Weline\Theme\Service\AllMenu\MenuTreeNormalizer;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class MenuTreeNormalizerBatchTest extends TestCase
{
    private array $batches = [];
    private int $singles = 0;
    private bool $failBatch = false;

    protected function setUp(): void
    {
        $url = $this->getMockBuilder(Url::class)->disableOriginalConstructor()
            ->onlyMethods(['getFrontendUrl', 'getFrontendUrls'])->getMock();
        $url->method('getFrontendUrl')->willReturnCallback(function (string $path): string {
            $this->singles++;
            if ($this->failBatch && $path === 'broken') {
                throw new \RuntimeException('单项地址失败');
            }
            return '/resolved/' . $path;
        });
        $url->method('getFrontendUrls')->willReturnCallback(function (array $paths): array {
            $this->batches[] = $paths;
            if ($this->failBatch) {
                throw new \RuntimeException('批次传输失败');
            }
            return array_map(static fn(string $path): string => '/resolved/' . $path, $paths);
        });
        $request = $this->getMockBuilder(Request::class)->disableOriginalConstructor()
            ->onlyMethods(['getUrlBuilder'])->getMock();
        $request->method('getUrlBuilder')->willReturn($url);
        ObjectManager::setInstance(Request::class, $request);
        ObjectManager::setInstance(Url::class, $url);
    }

    public function testWholeTreeUsesOneBatchAndKeepsExistingUrlSemantics(): void
    {
        $normalizer = new MenuTreeNormalizer();
        $urls = ['/USD/en_US/category/a?sort=new#list', '/category/b', '/category/b', '#part', '/',
            'https://external.test/a', '//cdn.test/a', 'mailto:a@example.test', 'tel:123', 'javascript:void(0)', '', '#'];
        $expected = array_map($normalizer->localizeUrl(...), $urls);
        $this->singles = 0;
        $tree = array_map(static fn(string $url): array => ['tag' => 'category', 'name' => 'node', 'url' => $url], $urls);
        $tree[0]['children'] = [$tree[1], ['tag' => 'category', 'name' => 'deep', 'url' => '/category/c']];
        $actual = $normalizer->toNavItems($tree);
        self::assertSame($expected, array_column($actual, 'url'));
        self::assertSame($actual[1]['url'], $actual[0]['children'][0]['url']);
        self::assertSame('/resolved/category/c', $actual[0]['children'][1]['url']);
        self::assertCount(1, $this->batches);
        self::assertSame(0, $this->singles);
        self::assertCount(count(array_unique($this->batches[0])), $this->batches[0]);
    }

    public function testBatchFailureKeepsPerItemFallback(): void
    {
        $this->failBatch = true;
        $actual = (new MenuTreeNormalizer())->toNavItems([
            ['tag' => 'category', 'name' => 'a', 'url' => '/a'],
            ['tag' => 'category', 'name' => 'b', 'url' => '/b'],
            ['tag' => 'category', 'name' => 'bad', 'url' => '/broken'],
        ]);
        self::assertSame(['/resolved/a', '/resolved/b', '/broken'], array_column($actual, 'url'));
        self::assertGreaterThan(0, count($this->batches));
        self::assertSame(4, $this->singles);
    }

    public function testOnlyExternalAndEmptyTreeNeedNoBuilder(): void
    {
        $normalizer = new MenuTreeNormalizer();
        self::assertSame([], $normalizer->toNavItems([]));
        $normalizer->toNavItems([['tag' => 'category', 'name' => 'x', 'url' => 'https://outside.test']]);
        self::assertSame([], $this->batches);
        self::assertSame(0, $this->singles);
    }
}

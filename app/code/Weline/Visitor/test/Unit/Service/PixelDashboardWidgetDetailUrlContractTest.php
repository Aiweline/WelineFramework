<?php

declare(strict_types=1);

namespace Weline\Visitor\test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Http\Request;
use Weline\Framework\Http\Url;
use Weline\Visitor\Service\PixelDashboardWidgetData;
use Weline\Visitor\Service\PixelStatisticsService;
use Weline\Visitor\Service\VisitorDashboardPageInstaller;

/**
 * E06：部件 detailUrl / listUrl 契约（与 C07 list 下钻键对齐；不查库）。
 */
final class PixelDashboardWidgetDetailUrlContractTest extends TestCase
{
    /** @var list<array{path:string,query:array<string,mixed>}> */
    private array $calls = [];

    public function testDetailUrlWithWebsiteGoesToDetailReport(): void
    {
        $service = $this->newService();
        $href = $service->detailUrl(['range' => '7d', 'website_id' => 23]);

        self::assertStringContainsString('pixel-dashboard/detail', $href);
        self::assertStringNotContainsString('pixel_dashboard', $href);
        self::assertSame('visitor/backend/pixel-dashboard/detail', $this->lastPath());
        self::assertSame('23', $this->lastQuery()['websiteId'] ?? null);
        self::assertSame('7d', $this->lastQuery()['range'] ?? null);
        self::assertArrayNotHasKey('event', $this->lastQuery());
    }

    public function testDetailUrlAcceptsDefaultWebsiteIdZero(): void
    {
        $service = $this->newService();
        $service->detailUrl(['range' => '30d', 'website_id' => 0]);

        self::assertSame('visitor/backend/pixel-dashboard/detail', $this->lastPath());
        self::assertSame('0', $this->lastQuery()['websiteId'] ?? null);
        self::assertSame('30d', $this->lastQuery()['range'] ?? null);
    }

    public function testDetailUrlWithEventGoesToIndexNotDetail(): void
    {
        $service = $this->newService();
        $href = $service->detailUrl([
            'range' => 'today',
            'website_id' => 23,
            'event' => 'purchase',
        ]);

        self::assertStringContainsString('pixel-dashboard/index', $href);
        self::assertSame('visitor/backend/pixel-dashboard/index', $this->lastPath());
        self::assertSame('purchase', $this->lastQuery()['event'] ?? null);
        self::assertSame('23', $this->lastQuery()['websiteId'] ?? null);
    }

    public function testDetailUrlWithoutWebsiteGoesToIndex(): void
    {
        $service = $this->newService();
        $service->detailUrl(['range' => '7d']);

        self::assertSame('visitor/backend/pixel-dashboard/index', $this->lastPath());
        self::assertArrayNotHasKey('websiteId', $this->lastQuery());
        self::assertSame('7d', $this->lastQuery()['range'] ?? null);
    }

    public function testDetailUrlQueryKeysStayWithinContract(): void
    {
        $service = $this->newService();
        $service->detailUrl([
            'range' => '7d',
            'website_id' => 9,
            'event' => 'page_view',
            'channel_code' => 'must_not_leak',
            'foo' => 'bar',
        ]);

        foreach (\array_keys($this->lastQuery()) as $key) {
            self::assertContains($key, ['websiteId', 'event', 'range'], 'detailUrl 不得泄漏非契约键: ' . $key);
        }
    }

    public function testListUrlUsesListPathAndC07Keys(): void
    {
        $service = $this->newService();
        $href = $service->listUrl(
            ['range' => '7d', 'website_id' => 5],
            [
                'channel_code' => 'summer',
                'traffic_type' => 'paid',
                'utm_campaign' => 'july',
                'page' => '2',
            ]
        );

        self::assertStringContainsString('pixel-dashboard/list', $href);
        self::assertSame('visitor/backend/pixel-dashboard/list', $this->lastPath());
        foreach (\array_keys($this->lastQuery()) as $key) {
            self::assertContains($key, PixelStatisticsService::LIST_DRILLDOWN_QUERY_KEYS);
        }
        self::assertArrayNotHasKey('page', $this->lastQuery());
        self::assertSame('summer', $this->lastQuery()['channel_code'] ?? null);
        self::assertSame('paid', $this->lastQuery()['traffic_type'] ?? null);
        self::assertSame('july', $this->lastQuery()['utm_campaign'] ?? null);
    }

    public function testCatalogWidgetsWireDetailAndListUrls(): void
    {
        $root = dirname(__DIR__, 3);
        $templates = [
            'pixel_channels' => 'pixel-channels.phtml',
            'pixel_traffic_type' => 'pixel-traffic-type.phtml',
            'pixel_paid' => 'pixel-paid.phtml',
            'pixel_social' => 'pixel-social.phtml',
            'pixel_event_value' => 'pixel-event-value.phtml',
            'pixel_value_by_channel' => 'pixel-value-by-channel.phtml',
        ];

        self::assertSame(
            VisitorDashboardPageInstaller::CATALOG_WIDGET_CODES,
            \array_keys($templates)
        );

        foreach ($templates as $code => $file) {
            $src = (string)\file_get_contents($root . '/view/templates/dashboard/widgets/' . $file);
            self::assertStringContainsString('detailUrl', $src, $code);
            self::assertStringContainsString('listUrl', $src, $code);
            self::assertStringContainsString('详情报表', $src, $code);
            self::assertStringContainsString('事件列表', $src, $code);
            self::assertStringContainsString("\$detailUrl", $src, $code);
            self::assertStringContainsString("\$listUrl", $src, $code);
            // 详情报表链到 detailUrl，事件列表链到 listUrl（禁止交叉）
            self::assertMatchesRegularExpression(
                '/href="<\?= \$h\(\$detailUrl\) \?>"[^>]*>[\s\S]*?详情报表/',
                $src,
                $code . ' 详情报表须绑 detailUrl'
            );
            self::assertMatchesRegularExpression(
                '/href="<\?= \$h\(\$listUrl\) \?>"[^>]*>[\s\S]*?事件列表/',
                $src,
                $code . ' 事件列表须绑 listUrl'
            );
        }
    }

    public function testCatalogReportPayloadDocumentsDetailAndListFields(): void
    {
        $root = dirname(__DIR__, 3);
        $src = (string)\file_get_contents($root . '/Service/PixelDashboardWidgetData.php');
        self::assertStringContainsString("'detail_url' => \$detailUrl", $src);
        self::assertStringContainsString("'list_url' => \$listUrl", $src);
        self::assertStringContainsString('pixel-dashboard/detail', $src);
        self::assertStringContainsString('pixel-dashboard/list', $src);
        self::assertStringNotContainsString('pixel_dashboard/detail', $src);
        self::assertStringNotContainsString('pixel_dashboard/list', $src);
    }

    private function newService(): PixelDashboardWidgetData
    {
        $this->calls = [];
        $request = $this->createMock(Request::class);
        $request->method('getParam')->willReturn(null);

        $url = $this->createMock(Url::class);
        $url->method('getBackendUrlPath')->willReturnCallback(
            function (string $path, array $query = []): string {
                $this->calls[] = ['path' => $path, 'query' => $query];

                return '/backend/' . $path . ($query !== [] ? '?' . http_build_query($query) : '');
            }
        );

        return new PixelDashboardWidgetData($request, $url);
    }

    private function lastPath(): string
    {
        self::assertNotEmpty($this->calls);

        return (string)($this->calls[\array_key_last($this->calls)]['path'] ?? '');
    }

    /**
     * @return array<string, mixed>
     */
    private function lastQuery(): array
    {
        self::assertNotEmpty($this->calls);

        $query = $this->calls[\array_key_last($this->calls)]['query'] ?? [];

        return \is_array($query) ? $query : [];
    }
}

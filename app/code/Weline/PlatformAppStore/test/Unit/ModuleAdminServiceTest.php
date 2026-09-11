<?php
declare(strict_types=1);

namespace Weline\PlatformAppStore\Test\Unit;

use PHPUnit\Framework\TestCase;
use Weline\PlatformAppStore\Model\PlatformModule;
use Weline\PlatformAppStore\Service\ModuleAdminService;

/**
 * 后台模块管理展示辅助单测（不碰 DB）。
 */
class ModuleAdminServiceTest extends TestCase
{
    private ModuleAdminService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new ModuleAdminService();
    }

    public function testStatusLabelMapsKnownStatuses(): void
    {
        $this->assertSame('草稿', $this->service->statusLabel(PlatformModule::STATUS_DRAFT));
        $this->assertSame('已发布', $this->service->statusLabel(PlatformModule::STATUS_PUBLISHED));
        $this->assertSame('已归档', $this->service->statusLabel(PlatformModule::STATUS_ARCHIVED));
    }

    public function testStatusLabelFallsBackForUnknown(): void
    {
        $this->assertSame('unknown', $this->service->statusLabel('unknown'));
    }

    public function testPricingLabelMapsKnownTypes(): void
    {
        $this->assertSame('免费', $this->service->pricingLabel(PlatformModule::PRICING_FREE));
        $this->assertSame('一次性', $this->service->pricingLabel(PlatformModule::PRICING_ONE_TIME));
        $this->assertSame('订阅', $this->service->pricingLabel(PlatformModule::PRICING_SUBSCRIPTION));
    }

    public function testNormalizeFiltersTrimsAndValidates(): void
    {
        $filters = $this->service->normalizeFilters([
            'q' => '  demo  ',
            'status' => 'published',
            'pricing_type' => 'bogus',
            'page' => '2',
            'page_size' => '999',
        ]);

        $this->assertSame('demo', $filters['q']);
        $this->assertSame(PlatformModule::STATUS_PUBLISHED, $filters['status']);
        $this->assertSame('', $filters['pricing_type']);
        $this->assertSame(2, $filters['page']);
        $this->assertSame(50, $filters['page_size']);
    }

    public function testDecorateModuleRowAddsLabels(): void
    {
        $row = $this->service->decorateModuleRow([
            'module_id' => 1,
            'name' => 'Weline_Demo',
            'display_name' => 'Demo',
            'status' => PlatformModule::STATUS_PUBLISHED,
            'pricing_type' => PlatformModule::PRICING_FREE,
            'price' => 0,
            'current_version' => '1.0.0',
            'downloads' => 3,
        ]);

        $this->assertSame('已发布', $row['status_label']);
        $this->assertSame('免费', $row['pricing_label']);
        $this->assertSame('Demo', $row['title']);
    }

    public function testEmptyStateMetaProvidesNextStepCta(): void
    {
        $meta = $this->service->emptyStateMeta();

        $this->assertSame('暂无平台模块', $meta['title']);
        $this->assertNotSame('', $meta['description']);
        $this->assertSame('新建模块', $meta['cta_label']);
        $this->assertSame('create', $meta['cta_action']);

        $filtered = $this->service->emptyStateMeta(true);
        $this->assertSame('没有匹配的模块', $filtered['title']);
    }

    public function testHasActiveFilters(): void
    {
        $this->assertFalse($this->service->hasActiveFilters([]));
        $this->assertFalse($this->service->hasActiveFilters(['q' => '  ', 'status' => '', 'pricing_type' => '']));
        $this->assertTrue($this->service->hasActiveFilters(['q' => 'demo']));
        $this->assertTrue($this->service->hasActiveFilters(['status' => 'published']));
    }

    public function testNormalizeDraftInputRequiresVendorModuleName(): void
    {
        $ok = $this->service->normalizeDraftInput([
            'name' => ' Weline_Demo ',
            'display_name' => ' Demo App ',
            'description' => 'x',
        ]);
        $this->assertSame('Weline_Demo', $ok['name']);
        $this->assertSame('Demo App', $ok['display_name']);

        $this->expectException(\InvalidArgumentException::class);
        $this->service->normalizeDraftInput([
            'name' => 'bad-name',
            'display_name' => 'Demo',
        ]);
    }
}

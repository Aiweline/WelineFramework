<?php

declare(strict_types=1);

namespace WeShop\Promotion\Test\Unit\Controller\Backend\Campaign;

use PHPUnit\Framework\TestCase;
use WeShop\Promotion\Controller\Backend\Campaign\Index;
use WeShop\Promotion\Repository\CampaignRepositoryInterface;

class IndexTest extends TestCase
{
    public function testIndexReturnsCampaignList(): void
    {
        $campaigns = [
            [
                'campaign_id' => 1,
                'name' => 'Summer Sale',
                'type' => 'seasonal',
                'status' => 1,
            ],
            [
                'campaign_id' => 2,
                'name' => 'Flash Deal',
                'type' => 'flash',
                'status' => 1,
            ],
        ];

        $summary = [
            'total_count' => 2,
            'active_count' => 2,
            'ended_count' => 0,
        ];

        $repository = $this->createMock(CampaignRepositoryInterface::class);
        $repository->expects($this->once())
            ->method('listCampaigns')
            ->with([], 1, 20)
            ->willReturn($campaigns);
        $repository->expects($this->once())
            ->method('countCampaigns')
            ->with([])
            ->willReturn(2);
        $repository->expects($this->once())
            ->method('getCampaignSummary')
            ->willReturn($summary);

        $this->assertSame($campaigns, $campaigns);
        $this->assertSame($summary, $summary);
        $this->assertCount(2, $campaigns);
    }

    public function testIndexWithSearchQuery(): void
    {
        $filters = ['name' => 'Summer'];
        $campaigns = [
            [
                'campaign_id' => 1,
                'name' => 'Summer Sale',
                'type' => 'seasonal',
            ],
        ];

        $repository = $this->createMock(CampaignRepositoryInterface::class);
        $repository->expects($this->once())
            ->method('listCampaigns')
            ->with($filters, 1, 20)
            ->willReturn($campaigns);
        $repository->expects($this->once())
            ->method('countCampaigns')
            ->with($filters)
            ->willReturn(1);

        $this->assertCount(1, $campaigns);
        $this->assertSame('Summer Sale', $campaigns[0]['name']);
    }

    public function testIndexWithPagination(): void
    {
        $campaigns = [
            ['campaign_id' => 3, 'name' => 'Campaign 3'],
            ['campaign_id' => 4, 'name' => 'Campaign 4'],
        ];

        $repository = $this->createMock(CampaignRepositoryInterface::class);
        $repository->expects($this->once())
            ->method('listCampaigns')
            ->with([], 2, 20)
            ->willReturn($campaigns);
        $repository->expects($this->once())
            ->method('countCampaigns')
            ->with([])
            ->willReturn(25);

        $this->assertCount(2, $campaigns);
    }
}

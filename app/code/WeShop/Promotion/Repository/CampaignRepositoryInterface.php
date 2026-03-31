<?php

declare(strict_types=1);

namespace WeShop\Promotion\Repository;

use WeShop\Promotion\Model\Campaign;

/**
 * Campaign Repository Interface
 */
interface CampaignRepositoryInterface
{
    /**
     * Get campaign by ID
     */
    public function findCampaignById(int $campaignId): ?Campaign;

    /**
     * Create a new campaign
     */
    public function createCampaign(): Campaign;

    /**
     * Get campaign summary data
     */
    public function getCampaignSummary(): array;

    /**
     * List recent campaigns
     *
     * @return array<int, array<string, mixed>>
     */
    public function listRecentCampaigns(int $limit = 5): array;

    /**
     * List all campaigns with optional filters
     *
     * @param array<string, mixed> $filters
     * @return array<int, Campaign>
     */
    public function listCampaigns(array $filters = [], int $page = 1, int $pageSize = 20): array;

    /**
     * Count total campaigns with filters
     */
    public function countCampaigns(array $filters = []): int;

    /**
     * Delete campaign by ID
     */
    public function deleteCampaign(int $campaignId): bool;
}

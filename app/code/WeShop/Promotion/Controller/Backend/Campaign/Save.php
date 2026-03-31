<?php

declare(strict_types=1);

namespace WeShop\Promotion\Controller\Backend\Campaign;

use Weline\Framework\App\Controller\BackendController;
use WeShop\Promotion\Model\Campaign;
use WeShop\Promotion\Repository\CampaignRepository;

/**
 * Campaign backend controller - Save
 */
class Save extends BackendController
{
    /**
     * Handle form submission for creating/updating campaigns
     */
    public function index(): string
    {
        try {
            $campaignId = (int) ($this->request->getParam('campaign_id') ?? 0);
            $data = $this->getCampaignData();

            $campaign = $this->saveCampaign($data, $campaignId);

            return $this->fetchJson([
                'success' => true,
                'message' => __('促销活动保存成功'),
                'campaign_id' => $campaign->getId(),
            ]);
        } catch (\InvalidArgumentException $e) {
            return $this->fetchJson([
                'success' => false,
                'message' => $e->getMessage(),
            ]);
        } catch (\Throwable $e) {
            return $this->fetchJson([
                'success' => false,
                'message' => __('保存促销活动失败'),
            ]);
        }
    }

    /**
     * Get campaign data from request
     */
    private function getCampaignData(): array
    {
        $discountType = $this->request->getParam('discount_type', 'fixed');
        $validDiscountTypes = ['fixed', 'percent', 'buy_x_get_y'];
        if (!in_array($discountType, $validDiscountTypes, true)) {
            $discountType = 'fixed';
        }

        $type = $this->request->getParam('type', 'promotion');
        $validTypes = ['promotion', 'flash', 'seasonal'];
        if (!in_array($type, $validTypes, true)) {
            $type = 'promotion';
        }

        $startDate = $this->normalizeDate($this->request->getParam('start_date', ''));
        $endDate = $this->normalizeDate($this->request->getParam('end_date', ''));

        if ($startDate && $endDate && $startDate > $endDate) {
            throw new \InvalidArgumentException(__('结束时间必须晚于开始时间'));
        }

        $productIds = $this->parseCommaSeparatedIds($this->request->getParam('product_ids', ''));
        $categoryIds = $this->parseCommaSeparatedIds($this->request->getParam('category_ids', ''));

        return [
            Campaign::schema_fields_NAME => trim((string) $this->request->getParam('name', '')),
            Campaign::schema_fields_TYPE => $type,
            Campaign::schema_fields_DESCRIPTION => trim((string) $this->request->getParam('description', '')),
            Campaign::schema_fields_DISCOUNT_TYPE => $discountType,
            Campaign::schema_fields_DISCOUNT_VALUE => (float) ($this->request->getParam('discount_value', 0)),
            Campaign::schema_fields_MIN_PURCHASE => (float) ($this->request->getParam('min_purchase', 0)),
            Campaign::schema_fields_MAX_DISCOUNT => (float) ($this->request->getParam('max_discount', 0)),
            Campaign::schema_fields_START_DATE => $startDate,
            Campaign::schema_fields_END_DATE => $endDate,
            Campaign::schema_fields_PRODUCT_IDS => implode(',', $productIds),
            Campaign::schema_fields_CATEGORY_IDS => implode(',', $categoryIds),
            Campaign::schema_fields_STATUS => (int) ($this->request->getParam('status', 1)),
            Campaign::schema_fields_IS_FEATURED => (int) ($this->request->getParam('is_featured', 0)),
            Campaign::schema_fields_PRIORITY => (int) ($this->request->getParam('priority', 0)),
        ];
    }

    /**
     * Normalize date string to Y-m-d H:i:s format
     */
    private function normalizeDate(string $date): string
    {
        if ($date === '') {
            return '';
        }

        $timestamp = strtotime($date);
        if ($timestamp === false) {
            throw new \InvalidArgumentException(__('日期格式无效'));
        }

        return date('Y-m-d H:i:s', $timestamp);
    }

    /**
     * Parse comma-separated IDs into array of integers
     */
    private function parseCommaSeparatedIds(string $input): array
    {
        if ($input === '') {
            return [];
        }

        $ids = array_filter(array_map('intval', explode(',', $input)));
        return array_values($ids);
    }

    /**
     * Save campaign to database
     */
    private function saveCampaign(array $data, int $campaignId = 0): Campaign
    {
        $repository = new CampaignRepository();

        if ($campaignId > 0) {
            $campaign = $repository->findCampaignById($campaignId);
            if (!$campaign) {
                throw new \InvalidArgumentException(__('促销活动不存在'));
            }
        } else {
            $campaign = $repository->createCampaign();
        }

        foreach ($data as $key => $value) {
            $campaign->setData($key, $value);
        }

        $now = date('Y-m-d H:i:s');
        $campaign->setData(Campaign::schema_fields_UPDATED_AT, $now);
        if (!$campaign->getId()) {
            $campaign->setData(Campaign::schema_fields_CREATED_AT, $now);
        }

        $campaign->save();

        return $campaign;
    }
}

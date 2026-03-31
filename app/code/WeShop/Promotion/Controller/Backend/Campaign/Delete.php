<?php

declare(strict_types=1);

namespace WeShop\Promotion\Controller\Backend\Campaign;

use Weline\Framework\App\Controller\BackendController;
use WeShop\Promotion\Repository\CampaignRepository;

/**
 * Campaign backend controller - Delete
 */
class Delete extends BackendController
{
    /**
     * Handle campaign deletion
     */
    public function index(): string
    {
        try {
            $campaignId = (int) ($this->request->getParam('campaign_id') ?? 0);

            if ($campaignId <= 0) {
                return $this->fetchJson([
                    'success' => false,
                    'message' => __('无效的促销活动ID'),
                ]);
            }

            $repository = new CampaignRepository();
            $deleted = $repository->deleteCampaign($campaignId);

            if (!$deleted) {
                return $this->fetchJson([
                    'success' => false,
                    'message' => __('促销活动不存在或删除失败'),
                ]);
            }

            return $this->fetchJson([
                'success' => true,
                'message' => __('删除成功'),
            ]);
        } catch (\Throwable $e) {
            return $this->fetchJson([
                'success' => false,
                'message' => __('删除失败'),
            ]);
        }
    }
}

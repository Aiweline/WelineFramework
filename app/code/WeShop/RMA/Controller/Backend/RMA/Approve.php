<?php

declare(strict_types=1);

namespace WeShop\RMA\Controller\Backend\RMA;

use Weline\Framework\App\Controller\BackendController;
use WeShop\RMA\Service\RmaService;
use Weline\Framework\Manager\ObjectManager;

/**
 * 批准RMA控制器
 */
class Approve extends BackendController
{
    /**
     * 批准RMA
     */
    public function index(): string
    {
        try {
            $rmaId = (int)($this->request->getParam('rma_id') ?? 0);
            
            if (!$rmaId) {
                return $this->fetchJson(['success' => false, 'message' => __('RMA ID不能为空')]);
            }
            
            /** @var RmaService $rmaService */
            $rmaService = ObjectManager::getInstance(RmaService::class);
            $rmaService->approveRma($rmaId);
            
            return $this->fetchJson(['success' => true, 'message' => __('RMA已批准')]);
        } catch (\Exception $e) {
            return $this->fetchJson(['success' => false, 'message' => $e->getMessage()]);
        }
    }
}

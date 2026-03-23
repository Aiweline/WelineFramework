<?php

declare(strict_types=1);

namespace WeShop\RMA\Controller\Backend\RMA;

use WeShop\RMA\Service\RmaService;
use Weline\Framework\App\Controller\BackendController;
use Weline\Framework\Manager\ObjectManager;

class Reject extends BackendController
{
    public function index(): string
    {
        try {
            $rmaId = (int) ($this->request->getParam('rma_id') ?? 0);
            if ($rmaId <= 0) {
                return $this->fetchJson(['success' => false, 'message' => __('RMA ID is required.')]);
            }

            /** @var RmaService $rmaService */
            $rmaService = ObjectManager::getInstance(RmaService::class);
            $rmaService->rejectRma($rmaId);

            return $this->fetchJson(['success' => true, 'message' => __('RMA has been rejected.')]);
        } catch (\Throwable $throwable) {
            return $this->fetchJson(['success' => false, 'message' => $throwable->getMessage()]);
        }
    }
}

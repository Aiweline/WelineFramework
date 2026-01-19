<?php

declare(strict_types=1);

namespace WeShop\RMA\Controller\Backend\RMA;

use Weline\Framework\App\Controller\BackendController;
use WeShop\RMA\Model\Rma;
use Weline\Framework\Manager\ObjectManager;

/**
 * RMA管理控制器
 */
class Index extends BackendController
{
    /**
     * RMA列表
     */
    public function index(): string
    {
        $page = (int)($this->request->getParam('page') ?? 1);
        $pageSize = (int)($this->request->getParam('page_size') ?? 20);
        
        $filters = [
            'status' => $this->request->getParam('status'),
            'order_id' => $this->request->getParam('order_id'),
        ];
        
        /** @var Rma $rma */
        $rma = ObjectManager::getInstance(Rma::class);
        
        $rma->clear();
        
        if (!empty($filters['status'])) {
            $rma->where(Rma::fields_status, $filters['status']);
        }
        if (!empty($filters['order_id'])) {
            $rma->where(Rma::fields_order_id, $filters['order_id']);
        }
        
        $rma->order(Rma::fields_created_at, 'DESC')
            ->pagination($page, $pageSize)
            ->select();
        
        $this->assign('rmas', $rma->getItems());
        $this->assign('pagination', $rma->getPagination());
        $this->assign('filters', $filters);
        
        return $this->fetch();
    }
}

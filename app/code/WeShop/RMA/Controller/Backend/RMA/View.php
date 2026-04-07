<?php

declare(strict_types=1);

namespace WeShop\RMA\Controller\Backend\RMA;

use WeShop\RMA\Model\Rma;
use WeShop\RMA\Service\RmaService;
use Weline\Framework\App\Controller\BackendController;
use Weline\Framework\Manager\ObjectManager;

/**
 * RMA详情控制器
 */
class View extends BackendController
{
    /**
     * RMA详情
     */
    public function index(): string
    {
        $rmaId = (int) $this->request->getParam('id', 0);
        if ($rmaId <= 0) {
            $rmaId = (int) $this->request->getParam('rma_id', 0);
        }

        if (!$rmaId) {
            $this->redirect($this->_url->getBackendUrl('*/backend/rma'));
            return '';
        }

        /** @var Rma $rmaModel */
        $rmaModel = ObjectManager::getInstance(Rma::class);
        $rmaModel->load($rmaId);

        if (!$rmaModel->getId()) {
            $this->getMessageManager()->addError((string) __('RMA record not found.'));
            $this->redirect($this->_url->getBackendUrl('*/backend/rma'));
            return '';
        }

        $rmaData = [
            'rma_id' => $rmaModel->getId(),
            'order_id' => (int) $rmaModel->getData(Rma::schema_fields_ORDER_ID),
            'customer_id' => (int) $rmaModel->getData(Rma::schema_fields_CUSTOMER_ID),
            'reason' => (string) $rmaModel->getData(Rma::schema_fields_REASON),
            'description' => (string) $rmaModel->getData(Rma::schema_fields_DESCRIPTION),
            'status' => (string) $rmaModel->getData(Rma::schema_fields_STATUS),
            'created_at' => (string) $rmaModel->getData(Rma::schema_fields_CREATED_AT),
            'updated_at' => (string) $rmaModel->getData(Rma::schema_fields_UPDATED_AT),
        ];

        $statusLabelMap = [
            RmaService::STATUS_PENDING => __('Pending'),
            RmaService::STATUS_APPROVED => __('Approved'),
            RmaService::STATUS_REJECTED => __('Rejected'),
        ];

        $rmaData['status_label'] = $statusLabelMap[$rmaData['status']] ?? ucfirst($rmaData['status']);

        $this->assign('rma', $rmaData);
        $this->assign('rmaIndexUrl', $this->_url->getBackendUrl('*/backend/rma'));
        $this->assign('rmaApproveUrl', $this->_url->getBackendUrl('*/backend/rma/approve'));
        $this->assign('rmaRejectUrl', $this->_url->getBackendUrl('*/backend/rma/reject'));

        return $this->fetch();
    }
}

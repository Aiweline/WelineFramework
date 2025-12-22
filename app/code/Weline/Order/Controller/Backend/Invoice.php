<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Order\Controller\Backend;

use Weline\Framework\Acl\Acl;
use Weline\Framework\App\Controller\BackendController;
use Weline\Framework\Manager\ObjectManager;
use Weline\Order\Service\InvoiceService;

/**
 * 发票管理控制器
 */
#[Acl('Weline_Order::invoice_manage', '发票管理', 'mdi-file-document', '发票管理', 'Weline_Order::order_manage')]
class Invoice extends BackendController
{
    private InvoiceService $invoiceService;
    
    public function __construct(ObjectManager $objectManager)
    {
        $this->invoiceService = $objectManager->getInstance(InvoiceService::class);
    }
    
    /**
     * 生成发票
     */
    #[Acl('Weline_Order::invoice_generate', '生成发票', 'mdi-file-plus', '生成发票')]
    public function generate()
    {
        $orderId = (int)$this->request->getPost('order_id');
        
        if (!$orderId) {
            $this->getMessageManager()->addError(__('订单ID不能为空'));
            $this->redirect('order/backend/order/index');
            return;
        }
        
        try {
            $this->invoiceService->generateInvoice($orderId);
            $this->getMessageManager()->addSuccess(__('发票生成成功'));
        } catch (\Exception $e) {
            $this->getMessageManager()->addError($e->getMessage());
        }
        
        // 生成发票后返回订单详情
        $this->redirect('order/backend/order/view?id=' . $orderId);
    }
    
    /**
     * 打印发票
     */
    #[Acl('Weline_Order::invoice_print', '打印发票', 'mdi-printer', '打印发票')]
    public function print()
    {
        $invoiceId = (int)$this->request->getParam('id');
        
        if (!$invoiceId) {
            $this->getMessageManager()->addError(__('发票ID不能为空'));
            $this->redirect('order/backend/order/index');
            return;
        }
        
        try {
            $invoice = $this->invoiceService->printInvoice($invoiceId);
            $this->assign('invoice', $invoice);
            return $this->fetch('print');
        } catch (\Exception $e) {
            $this->getMessageManager()->addError($e->getMessage());
            $this->redirect('order/backend/order/index');
        }
    }
}


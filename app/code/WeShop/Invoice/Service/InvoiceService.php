<?php

declare(strict_types=1);

namespace WeShop\Invoice\Service;

use Weline\Framework\Manager\ObjectManager;
use WeShop\Invoice\Model\Invoice;

/**
 * 发票服务
 */
class InvoiceService
{
    /**
     * 创建发票
     * 
     * @param array $invoiceData 发票数据
     * @return Invoice
     */
    public function createInvoice(array $invoiceData): Invoice
    {
        /** @var Invoice $invoice */
        $invoice = ObjectManager::getInstance(Invoice::class);
        
        // 生成发票号
        $invoiceNumber = $this->generateInvoiceNumber();
        
        $invoice->clearData()
            ->setData('order_id', $invoiceData['order_id'] ?? 0)
            ->setData('invoice_number', $invoiceNumber)
            ->setData('amount', $invoiceData['amount'] ?? 0)
            ->setData('status', 'pending')
            ->save();
        
        return $invoice;
    }
    
    /**
     * 生成发票号
     * 
     * @return string
     */
    protected function generateInvoiceNumber(): string
    {
        return 'INV' . date('YmdHis') . rand(1000, 9999);
    }
}

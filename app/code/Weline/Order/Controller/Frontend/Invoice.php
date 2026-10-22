<?php

declare(strict_types=1);

namespace Weline\Order\Controller\Frontend;

use Weline\Framework\App\Controller\FrontendController;
use Weline\Framework\Cache\SharedResponseCachePolicy;
use Weline\Framework\Http\ResponseTerminateException;
use Weline\Framework\Manager\ObjectManager;
use Weline\Order\Service\BuyerInvoiceDownloadService;
use Weline\Order\Service\BuyerInvoiceException;

/**
 * 顾客下载自己订单上已开具的发票 PDF。
 *
 * @Extra type=fpc enabled=false
 */
final class Invoice extends FrontendController
{
    public function download(): string
    {
        SharedResponseCachePolicy::forbid('buyer_invoice_pdf');
        $customerId = $this->isLoggedIn() ? (int)$this->getLoginUserId() : 0;
        $orderUuid = trim((string)$this->request->getParam('order_uuid', ''));
        /** @var BuyerInvoiceDownloadService $downloads */
        $downloads = ObjectManager::getInstance(BuyerInvoiceDownloadService::class);
        try {
            $pdf = $downloads->bytesForCustomer($customerId, $orderUuid);
            $filename = $downloads->filenameForCustomer($customerId, $orderUuid);
        } catch (BuyerInvoiceException $exception) {
            throw new ResponseTerminateException($exception->httpStatus, $exception->getMessage(), [
                'Content-Type' => 'text/plain; charset=utf-8',
                'Cache-Control' => 'private, no-store',
                'X-Content-Type-Options' => 'nosniff',
            ]);
        }

        throw new ResponseTerminateException(200, $pdf, [
            'Content-Type' => 'application/pdf',
            'Content-Length' => (string)strlen($pdf),
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store, max-age=0',
        ]);
    }
}

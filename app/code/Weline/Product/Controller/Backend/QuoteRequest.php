<?php

declare(strict_types=1);

namespace Weline\Product\Controller\Backend;

use Weline\Framework\Acl\Acl;
use Weline\Framework\App\Controller\BackendController;
use Weline\Framework\Manager\ObjectManager;
use Weline\Product\Exception\ProductQuoteRequestStateTransitionException;
use Weline\Product\Model\ProductQuoteRequest;
use Weline\Product\Service\ProductQuoteMailReplySlotService;
use Weline\Product\Service\ProductQuoteRequestService;
use Weline\Product\Service\ProductQuoteRequestStateMachine;

final class QuoteRequest extends BackendController
{
    #[Acl('Weline_Product::commerce:catalog:quote-requests', '商品询价', 'message', '查看和处理仅询价商品的询价单')]
    public function index(): string
    {
        $status = strtolower(trim((string)$this->request->getGet('status', '')));
        /** @var ProductQuoteRequestService $service */
        $service = ObjectManager::getInstance(ProductQuoteRequestService::class);
        $items = $service->listForAdmin([
            'status' => $status,
            'limit' => 100,
        ]);
        $backendUserId = (int)($this->getLoginUserId() ?? 0);
        $items = ObjectManager::getInstance(ProductQuoteMailReplySlotService::class)
            ->attachToQuotes($items, $backendUserId);

        $this->assign('title', (string)__('商品询价'));
        $this->assign('status_filter', $status);
        $this->assign('items', $items);
        $this->assign('statuses', ProductQuoteRequest::STATUSES);
        $this->assign('status_labels', [
            ProductQuoteRequest::STATUS_NEW => (string)__('待处理'),
            ProductQuoteRequest::STATUS_PROCESSING => (string)__('处理中'),
            ProductQuoteRequest::STATUS_REPLIED => (string)__('已回复'),
            ProductQuoteRequest::STATUS_PROCESSED => (string)__('已关闭'),
            ProductQuoteRequest::STATUS_CANCELLED => (string)__('已取消'),
        ]);

        return $this->fetch('index');
    }

    #[Acl('Weline_Product::commerce:catalog:quote-requests', '标记询价已处理', 'check', '将询价单标记为已处理')]
    public function markProcessed(): string
    {
        return $this->transitionTo(ProductQuoteRequest::STATUS_PROCESSED);
    }

    #[Acl('Weline_Product::commerce:catalog:quote-requests', '询价状态流转', 'check', '按状态机规则变更询价单状态')]
    public function transition(): string
    {
        $to = strtolower(trim((string)$this->request->getPost('to_status', '')));

        return $this->transitionTo($to);
    }

    private function transitionTo(string $toStatus): string
    {
        $id = max(0, (int)$this->request->getPost('quote_request_id', 0));
        /** @var ProductQuoteRequestService $service */
        $service = ObjectManager::getInstance(ProductQuoteRequestService::class);
        try {
            if ($id <= 0 || $toStatus === '') {
                throw new ProductQuoteRequestStateTransitionException(
                    ProductQuoteRequestStateMachine::ERROR_NOT_FOUND,
                    (string)__('无法更新询价单'),
                );
            }
            $result = $service->transitionStatus($id, $toStatus, 'admin_ui');
            $this->getMessageManager()->addSuccess((string)__(
                '询价单 #%{1}：%{2} → %{3}',
                [
                    (string)$result['quote_request_id'],
                    (string)$result['old_status'],
                    (string)$result['new_status'],
                ]
            ));
        } catch (ProductQuoteRequestStateTransitionException $e) {
            $this->getMessageManager()->addError($e->getMessage() !== '' ? $e->getMessage() : (string)__('无法更新询价单'));
        } catch (\Throwable) {
            $this->getMessageManager()->addError((string)__('无法更新询价单'));
        }

        return $this->redirect('*/backend/quote-request/index');
    }
}

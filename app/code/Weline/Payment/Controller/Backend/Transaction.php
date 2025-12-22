<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Payment\Controller\Backend;

use Weline\Framework\Acl\Acl;
use Weline\Framework\App\Controller\BackendController;
use Weline\Framework\Manager\ObjectManager;
use Weline\Payment\Model\PaymentTransaction;

#[Acl('Weline_Payment::payment_transaction', '支付交易管理', 'mdi-cash-multiple', '支付交易记录管理', 'Weline_Backend::system_service')]
class Transaction extends BackendController
{
    /**
     * 交易记录列表页
     */
    #[Acl('Weline_Payment::payment_transaction_index', '查看交易记录', 'mdi-format-list-bulleted', '查看支付交易记录列表')]
    public function index()
    {
        $page = max(1, (int)($this->request->getParam('page') ?? 1));
        $limit = (int)($this->request->getParam('limit') ?? 20);
        $limit = $limit > 0 ? min($limit, 100) : 20;
        $keyword = trim((string)($this->request->getParam('keyword') ?? ''));
        $status = $this->request->getParam('status');
        $methodCode = $this->request->getParam('method_code');
        
        /** @var PaymentTransaction $transaction */
        $transaction = ObjectManager::getInstance(PaymentTransaction::class);
        $query = $transaction->select();
        
        if ($keyword) {
            $query->where(PaymentTransaction::fields_TRANSACTION_NO, 'like', "%{$keyword}%")
                ->orWhere(PaymentTransaction::fields_ORDER_ID, 'like', "%{$keyword}%");
        }
        
        if ($status) {
            $query->where(PaymentTransaction::fields_STATUS, $status);
        }
        
        if ($methodCode) {
            $query->where(PaymentTransaction::fields_METHOD_CODE, $methodCode);
        }
        
        $total = $query->count();
        $totalPages = (int)ceil($total / $limit);
        
        $transactions = $query->order(PaymentTransaction::fields_CREATED_AT, 'DESC')
            ->limit($limit, ($page - 1) * $limit)
            ->fetch();
        
        $this->assign('transactions', $transactions);
        $this->assign('total', $total);
        $this->assign('page', $page);
        $this->assign('limit', $limit);
        $this->assign('total_pages', $totalPages);
        $this->assign('keyword', $keyword);
        $this->assign('status', $status);
        $this->assign('method_code', $methodCode);
        
        return $this->fetch();
    }

    /**
     * 查看交易详情
     */
    #[Acl('Weline_Payment::payment_transaction_view', '查看交易详情', 'mdi-eye', '查看支付交易详情')]
    public function view()
    {
        $id = $this->request->getParam('id');
        
        if (!$id) {
            $this->getMessageManager()->addError(__('缺少交易ID'));
            return $this->redirect('*/backend/transaction/index');
        }
        
        /** @var PaymentTransaction $transaction */
        $transaction = ObjectManager::getInstance(PaymentTransaction::class);
        $transaction->load($id);
        
        if (!$transaction->getId()) {
            $this->getMessageManager()->addError(__('交易记录不存在'));
            return $this->redirect('*/backend/transaction/index');
        }
        
        $this->assign('transaction', $transaction);
        
        return $this->fetch();
    }

    /**
     * 查询支付状态
     */
    #[Acl('Weline_Payment::payment_transaction_query', '查询支付状态', 'mdi-refresh', '查询支付状态')]
    public function query()
    {
        $id = $this->request->getParam('id');
        
        if (!$id) {
            return $this->error(__('缺少交易ID'));
        }
        
        /** @var PaymentTransaction $transaction */
        $transaction = ObjectManager::getInstance(PaymentTransaction::class);
        $transaction->load($id);
        
        if (!$transaction->getId()) {
            return $this->error(__('交易记录不存在'));
        }
        
        try {
            /** @var \Weline\Payment\Service\PaymentService $paymentService */
            $paymentService = ObjectManager::getInstance(\Weline\Payment\Service\PaymentService::class);
            $paymentService->queryPaymentStatus($transaction->getData(PaymentTransaction::fields_TRANSACTION_NO));
            
            return $this->success(__('支付状态查询成功'));
        } catch (\Exception $e) {
            return $this->error(__('查询失败: %{1}', [$e->getMessage()]));
        }
    }
}

